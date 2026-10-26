<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Domain\DomainException;
use App\Models\Company;
use App\Models\Platform\Coupon;
use App\Models\Platform\CustomPrice;
use App\Models\Platform\Lead;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Orders, payments and provisioning. markPaid() is the ONE door for money (Razorpay callback, webhook, or staff recording
 * a bank / UPI / cheque payment): idempotent, locked, and it provisions in the same transaction. E-mails go after commit,
 * so a mail problem never undoes a payment.
 */
final class Sales
{
    /** The coupon for $code, or a DomainException saying why it can't be used. Null code = no coupon. */
    public static function coupon(?string $code, Plan $plan, bool $renewal): ?Coupon
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        $c = Coupon::query()->where('code', $code)->first() ?? throw new DomainException('bad_coupon', 'This discount code is not valid.');
        if ($why = $c->problem($plan, $renewal)) {
            throw new DomainException('bad_coupon', $why);
        }
        return $c;
    }

    /**
     * Prices (Pricing::build) and records an order. Order kinds:
     *  new      — new cloud workspace (website: workspace + admin password) or a new on-prem licence (staff, no licence yet)
     *  upgrade  — trial → first paid package for the same workspace
     *  renewal  — next period of a subscription (plan / users / cycle may change)
     *  seats    — more users (or a higher plan) during the current term; expiry unchanged
     *  amc      — 12 more months of updates & support on a perpetual licence
     * $spec: plan_id?, users?, billing?, licence_kind?, deployment?, setup? (null|false|number), custom_amount? (staff), coupon?
     * Licence-based orders default plan / users / cycle / deployment from the licence. One open order per licence.
     * @param array{buyer_company:string,buyer_name:string,buyer_email:string,buyer_phone?:?string,buyer_gstin?:?string,state_code?:?string,workspace?:?string,admin_password?:?string} $buyer
     */
    public static function createOrder(string $kind, array $spec, array $buyer, ?Licence $licence = null, ?string $createdBy = null): SalesOrder
    {
        [$plan, $pr, $gstin, $coupon] = self::quote($kind, $spec, $buyer, $licence);
        // A staff cloud order is either for an existing workspace (company_id) or sold as a key the customer adds in their workspace (no workspace yet).
        $newWorkspace = $kind === 'new' && $pr['deployment'] === 'cloud' && empty($buyer['company_id']) && empty($buyer['key_only']);
        if ($newWorkspace) {
            $ws = (string) ($buyer['workspace'] ?? '');
            if (!preg_match(Workspaces::SLUG, $ws) || Company::query()->where('slug', $ws)->exists()) {
                throw new DomainException('workspace_taken', 'Choose another workspace name (3–40 lower-case letters, numbers or dashes; not already in use).');
            }
            if (\Illuminate\Support\Facades\Validator::make(['p' => (string) ($buyer['admin_password'] ?? '')], ['p' => \Illuminate\Validation\Rules\Password::defaults()])->fails()) {
                throw new DomainException('weak_password', 'Choose an admin password of at least 8 characters.');
            }
        }
        return DB::transaction(function () use ($plan, $kind, $buyer, $licence, $createdBy, $gstin, $coupon, $pr, $newWorkspace) {
            if ($licence !== null) {
                // One open order per licence: no duplicate upgrade requests, and no licence extended twice by two paid orders.
                Licence::query()->whereKey($licence->id)->lockForUpdate()->first();
                foreach (SalesOrder::query()->where('licence_id', $licence->id)->where('status', 'created')->get() as $open) {
                    if ($open->kind === $kind && $open->plan_id === $plan->id && $open->users === $pr['users'] && $open->billing === $pr['billing'] && !$open->expiredQuote()
                        && $open->coupon_code === $coupon?->code && $open->state_code === ($buyer['state_code'] ?? null) && $open->buyer_gstin === $gstin
                        && $open->custom_price_id === $pr['custom_price_id'] && abs($open->total - $pr['total']) < 0.005) {
                        return $open;   // the same request again → the same order and pay link
                    }
                    self::cancel($open, 'Replaced by a newer order', $createdBy ?? 'system');
                }
            }
            return SalesOrder::query()->create($pr + [
                'number' => self::nextNumber('ORD'), 'kind' => $kind, 'plan_id' => $plan->id, 'licence_id' => $licence?->id, 'company_id' => $licence?->company_id ?? ($buyer['company_id'] ?? null),
                'buyer_company' => $buyer['buyer_company'], 'buyer_name' => $buyer['buyer_name'], 'buyer_email' => strtolower($buyer['buyer_email']),
                'buyer_phone' => $buyer['buyer_phone'] ?? null, 'buyer_gstin' => $gstin, 'state_code' => $buyer['state_code'] ?? null, 'coupon_code' => $coupon?->code,
                'workspace' => $newWorkspace ? $buyer['workspace'] : null,
                'admin_password' => $newWorkspace ? bcrypt($buyer['admin_password']) : null,
                'valid_until' => now(\App\Services\Accounts\Ledger::TZ)->addDays((int) PlatformSettings::get('quote_validity_days'))->toDateString(),
                'status' => 'created', 'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * The price of an order without saving it (the same numbers createOrder will store): for previews and quotes.
     * @return array{0:Plan,1:array,2:?string,3:?Coupon}
     */
    public static function quote(string $kind, array $spec, array $buyer, ?Licence $licence = null): array
    {
        if (in_array($kind, ['upgrade', 'renewal', 'seats', 'amc'], true) && $licence === null) {
            throw new DomainException('no_licence', 'This order needs the licence it is for.');
        }
        if ($licence !== null && in_array($licence->status, ['superseded', 'revoked'], true)) {
            throw new DomainException('licence_closed', 'This licence was ' . ($licence->status === 'revoked' ? 'revoked' : 'replaced by a newer one') . ': order on the current licence instead.');
        }
        if ($kind === 'new' && $licence !== null) {
            throw new DomainException('bad_order', 'This customer already has a licence: raise a renewal, more users or AMC order instead.');
        }
        $plan = Plan::query()->with('cloudTiers', 'perpetualBands')->findOrFail($spec['plan_id'] ?? $licence?->plan_id ?? 0);
        $paid = $licence && in_array($licence->kind, ['subscription', 'perpetual'], true);
        $lk = $kind === 'upgrade' ? 'subscription' : ($paid ? $licence->kind : ($spec['licence_kind'] ?? 'subscription'));
        $dep = $licence ? ($licence->deployment ?? 'cloud') : ($spec['deployment'] ?? 'cloud');
        $users = (int) ($spec['users'] ?? ($licence?->max_users ?: $plan->min_users));
        $billing = $spec['billing'] ?? ($paid ? $licence->billing : null) ?? 'annual';
        $gstin = strtoupper(trim((string) ($buyer['buyer_gstin'] ?? ''))) ?: null;
        if ($gstin !== null && (!preg_match('/^\d{2}[A-Z0-9]{13}$/', $gstin) || substr($gstin, 0, 2) !== ($buyer['state_code'] ?? null))) {
            throw new DomainException('bad_gstin', 'The GSTIN must be 15 characters and start with your state code.');
        }
        $coupon = self::coupon($spec['coupon'] ?? null, $plan, $kind !== 'new');
        $companyId = $licence?->company_id ?? ($buyer['company_id'] ?? null);
        $first = $companyId ? !Company::query()->whereKey($companyId)->value('setup_fee_paid') : $kind === 'new';
        $pr = Pricing::build(['plan' => $plan, 'kind' => $kind, 'licence_kind' => $lk, 'deployment' => $dep, 'billing' => $billing, 'users' => $users,
            'licence' => $licence, 'first' => $first, 'setup' => $spec['setup'] ?? null, 'custom_amount' => $spec['custom_amount'] ?? null,
            'custom' => CustomPrice::for($plan, $kind, $companyId, $buyer['buyer_email'] ?? $licence?->holder_email), 'coupon' => $coupon, 'state' => $buyer['state_code'] ?? null]);
        return [$plan, $pr, $gstin, $coupon];
    }

    /** Closes an unpaid order. A payment that still arrives for it later is honoured (see markPaid). */
    public static function cancel(SalesOrder $o, string $reason, string $actor): void
    {
        if ($o->status !== 'created') {
            throw new DomainException('order_not_open', 'Only an unpaid order can be cancelled.');
        }
        $o->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 200)])->save();
        PlatformAudit::record('order.cancelled', $o, ['reason' => $reason], $actor);
    }

    /** Retires a licence (replaced by a newer one) and closes its unpaid orders, so nothing can renew or extend it afterwards. */
    public static function supersede(Licence $l, string $by, string $actor): void
    {
        $l->forceFill(['status' => 'superseded'])->save();
        $l->log('superseded', ['by' => $by], $actor);
        foreach (SalesOrder::query()->where('licence_id', $l->id)->where('status', 'created')->get() as $open) {
            self::cancel($open, 'The licence was replaced (' . $by . ')', $actor);
        }
    }

    /** A gateway attempt failed. The order stays open so the buyer can try again; nothing is provisioned. */
    public static function recordFailure(SalesOrder $o, string $reason, string $actor): void
    {
        if ($o->status !== 'created') {
            return;
        }
        $o->forceFill(['failed_at' => now(), 'failure_reason' => mb_substr($reason !== '' ? $reason : 'Payment failed', 0, 200)])->save();
        PlatformAudit::record('order.payment_failed', $o, ['reason' => $o->failure_reason], $actor);
    }

    /** Records full payment and provisions. Safe to call twice (callback + webhook race): the second call does nothing. */
    public static function markPaid(SalesOrder $order, string $method, ?string $ref, ?string $razorpayPaymentId = null, ?string $actor = null): SalesOrder
    {
        $mail = null;
        $order = DB::transaction(function () use ($order, $method, $ref, $razorpayPaymentId, $actor, &$mail) {
            /** @var SalesOrder $o */
            $o = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'paid') {
                return $o;
            }
            if ($o->status === 'cancelled') {
                // Money the gateway confirmed is never left hanging: honour it (the buyer paid an order that was closed meanwhile).
                if ($razorpayPaymentId === null) {
                    throw new DomainException('order_cancelled', 'This order was cancelled.');
                }
                PlatformAudit::record('order.paid_after_cancel', $o, ['payment' => $razorpayPaymentId], $actor ?? 'payment');
            }
            $o->forceFill(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $method, 'payment_ref' => $ref, 'razorpay_payment_id' => $razorpayPaymentId,
                'invoice_number' => self::nextInvoiceNumber()])->save();
            $mail = self::provision($o, $actor ?? 'payment');
            \App\Services\Accounts\Ledger::recordOrder($o);   // the books: one income line per paid order
            return $o;
        });
        if ($mail) {
            self::send($order->buyer_email, $mail[0], $mail[1]);
        }
        return $order;
    }

    /** @return array{0:string,1:string}|null subject + html for the buyer */
    private static function provision(SalesOrder $o, string $actor): ?array
    {
        if ($o->provisioned_at) {
            return null;
        }
        $plan = $o->plan;
        $lk = $o->licence_kind ?? 'subscription';
        $users = $o->users ?? $plan->max_users;
        if ($o->kind === 'new' && $o->deployment === 'onprem') {
            $licence = LicenceIssuer::issue($plan, $o->buyer_company, $o->buyer_email, $o->company_id, $lk, $users, 'onprem', $o->billing, $actor);
            $body = '<p>Your ROVEXY on-premises licence is ready.</p><p>Licence key: <b style="font-family:monospace;font-size:15px">' . e($licence->key) . '</b><br>'
                . e($plan->name) . ', up to ' . $users . ' users, ' . ($lk === 'perpetual' ? 'perpetual (never expires; updates & support until ' . $licence->amc_expires_at->format('d M Y') . ')'
                    : 'valid until ' . $licence->expires_at->format('d M Y')) . '.</p>'
                . '<p>Enter the key in your ROVEXY console under Administration → Plan &amp; licence. The server it is first activated on is registered to this licence. '
                . 'For a server without internet access, send us its server ID from that page and we will send a licence file.</p>';
        } elseif ($o->kind === 'new' && !$o->company_id && !$o->workspace) {   // cloud licence sold as a key: the customer adds it in their workspace
            $licence = LicenceIssuer::issue($plan, $o->buyer_company, $o->buyer_email, null, 'subscription', $users, 'cloud', $o->billing, $actor);
            $body = '<p>Your ROVEXY cloud licence is ready.</p><p>Licence key: <b style="font-family:monospace;font-size:15px">' . e($licence->key) . '</b><br>'
                . e($plan->name) . ', up to ' . $users . ' users, valid until ' . $licence->expires_at->format('d M Y') . '.</p>'
                . '<p>Sign in to your ROVEXY workspace and add the key under Administration → Plan &amp; licence → Add licence key. It replaces the workspace\'s current licence.</p>';
        } elseif ($o->kind === 'new' && $o->company_id) {   // staff order: first paid licence for an existing workspace
            $company = Company::query()->findOrFail($o->company_id);
            foreach (Licence::query()->where('company_id', $company->id)->where('kind', 'trial')->where('status', 'active')->get() as $t) {
                self::supersede($t, 'paid order ' . $o->number, $actor);
            }
            $licence = LicenceIssuer::issue($plan, $company->name, $o->buyer_email, $company->id, 'subscription', $users, 'cloud', $o->billing, $actor);
            $body = '<p>Your ROVEXY licence for workspace <b>' . e($company->slug) . '</b> is active until <b>' . $licence->expires_at->format('d M Y') . '</b> (' . e($plan->name) . ', up to ' . $users . ' users).</p>';
        } elseif ($o->kind === 'new') {
            // Never fail here (money is already in): a workspace name taken since the order was placed gets a free variant.
            $slug = $o->workspace && preg_match(Workspaces::SLUG, $o->workspace) && !Company::query()->where('slug', $o->workspace)->exists() ? $o->workspace : Workspaces::slugFrom($o->buyer_company);
            $company = Workspaces::create($slug, $o->buyer_company, $o->buyer_email, $o->buyer_name, $o->admin_password ?? bcrypt(\Illuminate\Support\Str::password(16)));
            $licence = LicenceIssuer::issue($plan, $o->buyer_company, $o->buyer_email, $company->id, 'subscription', $users, 'cloud', $o->billing, $actor);
            $console = e(Workspaces::consoleUrl($company));
            $body = '<p>Your ROVEXY workspace <b>' . e($company->slug) . '</b> is ready.</p><p>Sign in: <a href="' . $console . '">' . $console . '</a> with ' . e($o->buyer_email);
            if ($o->admin_password) {
                $body .= ' and the password you chose.</p>';
            } else {   // no password chosen at checkout → one-time set-password link (72 h)
                $admin = \App\Models\User::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_code', 'admin')->firstOrFail();
                $body .= '.</p><p><a href="' . e(AccountLinks::issue($admin, 'welcome')) . '">Set your password</a> (link works once, for 72 hours).</p>';
            }
        } else {
            $licence = self::apply($o, Licence::query()->lockForUpdate()->findOrFail($o->licence_id), $plan, $actor);
            $body = match ($o->kind) {
                'amc' => '<p>Your ROVEXY updates &amp; support (AMC) now run until <b>' . $licence->amc_expires_at->format('d M Y') . '</b>.</p>',
                'seats' => '<p>Your ROVEXY licence now allows <b>' . $licence->max_users . ' users</b> (' . e($plan->name) . ').</p>',
                default => '<p>Your ROVEXY licence is renewed until <b>' . $licence->expires_at?->format('d M Y') . '</b> (' . e($plan->name) . ', up to ' . $licence->max_users . ' users).</p>',
            };
        }
        if ($o->coupon_code) {
            Coupon::query()->where('code', $o->coupon_code)->increment('used_count');
        }
        if ($licence->company_id && collect($o->lines ?? [])->contains('type', 'setup')) {
            Company::query()->whereKey($licence->company_id)->update(['setup_fee_paid' => true]);   // setup is never charged twice
        }
        $o->forceFill(['licence_id' => $licence->id, 'company_id' => $licence->company_id, 'provisioned_at' => now(), 'admin_password' => null])->save();
        Lead::query()->where('email', $o->buyer_email)->whereNotIn('status', ['won', 'lost'])->update(['status' => 'won']);
        PlatformAudit::record('order.paid', $o, ['total' => $o->total, 'method' => $o->payment_method, 'licence' => $licence->key], $actor);
        return ["ROVEXY — payment received ({$o->invoice_number})", '<p>Dear ' . e($o->buyer_name) . ',</p><p>We have received ₹' . number_format($o->total, 2)
            . ' for order ' . e($o->number) . '. Thank you.</p>' . $body . '<p>Tax invoice: <a href="' . e(url("/pay/{$o->number}/{$o->token()}/invoice")) . '">view / print</a></p><p>— Ametecs India</p>'];
    }

    /**
     * What a paid order does to an existing licence:
     *  upgrade (trial → paid) : the trial is superseded by a new subscription for the same workspace
     *  renewal                : plan / users / cycle from the order; expiry + cycle from the later of today and the current expiry
     *  seats                  : users (and plan) raised now; expiry unchanged
     *  amc                    : AMC + 12 months from the later of today and the current AMC date
     */
    private static function apply(SalesOrder $o, Licence $l, Plan $plan, string $actor): Licence
    {
        if (in_array($l->status, ['superseded', 'revoked'], true)) {
            // Paid after the licence was replaced (its order was cancelled, the buyer paid anyway — money is always honoured):
            // apply it to the workspace's current licence; a revoked licence or one with no successor goes to staff for review.
            $cur = $l->status === 'superseded' && $l->company_id ? Licence::query()->where('company_id', $l->company_id)->whereNotIn('status', ['superseded', 'revoked'])
                ->where('deployment', $l->deployment ?? 'cloud')->latest('id')->lockForUpdate()->first() : null;
            if (!$cur || $o->kind === 'amc') {
                PlatformAudit::record('order.needs_review', $o, ['licence' => $l->key, 'status' => $l->status], $actor);
                $l->log('payment_needs_review', ['order' => $o->number], $actor);
                return $l;
            }
            PlatformAudit::record('order.applied_to_current', $o, ['from' => $l->key, 'to' => $cur->key], $actor);
            $l = $cur;
        }
        $users = (int) ($o->users ?? $plan->max_users);
        if ($o->kind === 'seats') {
            $users = max($users, (int) $l->max_users);   // paying for more users never lowers them (e.g. applied to a successor licence)
        }
        if ($o->kind === 'upgrade' || in_array($l->kind, ['trial', 'internal'], true)) {
            self::supersede($l, 'paid order ' . $o->number, $actor);
            return LicenceIssuer::issue($plan, $l->holder_name, $l->holder_email, $l->company_id, 'subscription', $users, $l->deployment ?? 'cloud', $o->billing ?? 'annual', $actor);
        }
        if ($o->kind === 'amc') {
            $from = $l->amc_expires_at && $l->amc_expires_at->isFuture() ? $l->amc_expires_at : now();
            $l->forceFill(['amc_expires_at' => $from->copy()->addYear()])->save();
            $l->log('amc_renewed', ['amc_until' => $l->amc_expires_at->toDateString(), 'order' => $o->number], $actor);
            return $l;
        }
        if ($o->kind === 'seats') {
            $l->forceFill(['plan_id' => $plan->id, 'max_users' => $users, 'features' => $plan->features])->save();
            $l->log('upgraded', ['plan' => $plan->code, 'users' => $users, 'order' => $o->number], $actor);
            return $l;
        }
        $billing = $o->billing ?? $l->billing ?? 'annual';
        $from = $l->expires_at && $l->expires_at->isFuture() ? $l->expires_at : now();
        $l->forceFill(['plan_id' => $plan->id, 'max_users' => $users, 'features' => $plan->features, 'status' => 'active', 'billing' => $billing,
            'expires_at' => $from->copy()->addMonthsNoOverflow(Pricing::CYCLES[$billing] ?? 12)])->save();
        $l->log('renewed', ['plan' => $plan->code, 'users' => $users, 'billing' => $billing, 'expires_at' => $l->expires_at->toDateString()], $actor);
        return $l;
    }

    /** Why a trial can't be started with this workspace name / e-mail, or null. Checked before the e-mail code is sent. */
    public static function trialProblem(string $slug, string $email): ?string
    {
        return match (true) {
            !preg_match(Workspaces::SLUG, $slug) => 'Workspace: 3–40 lower-case letters, numbers or dashes.',
            Company::query()->where('slug', $slug)->exists() => 'That workspace name is taken. Choose another.',
            DB::table('users')->where('email', strtolower($email))->where('employee_code', 'admin')->exists() => 'This e-mail already has a ROVEXY workspace. Sign in, or use another e-mail.',
            default => null,
        };
    }

    /** Free cloud trial: workspace + admin + trial licence (trial_days, trial_users, every feature). */
    public static function startTrial(string $companyName, string $name, string $email, ?string $phone, string $slug, string $password): Company
    {
        if ($why = self::trialProblem($slug, $email)) {
            throw new DomainException('trial_refused', $why);
        }
        $days = (int) PlatformSettings::get('trial_days');
        return DB::transaction(function () use ($companyName, $name, $email, $phone, $slug, $password, $days) {
            $company = Workspaces::create($slug, $companyName, $email, $name, $password);
            $l = Licence::query()->create(['key' => LicenceIssuer::newKey(), 'company_id' => $company->id, 'plan_id' => null, 'holder_name' => $companyName,
                'holder_email' => strtolower($email), 'kind' => 'trial', 'max_users' => (int) PlatformSettings::get('trial_users'),
                'features' => array_keys(Licensing::FEATURES), 'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addDays($days), 'grace_days' => 0]);
            $l->log('trial_started', ['days' => $days], 'website');
            Lead::query()->create(['name' => $name, 'company' => $companyName, 'email' => strtolower($email), 'phone' => $phone, 'source' => 'trial', 'status' => 'new',
                'message' => "Started a {$days}-day trial (workspace {$company->slug})."]);
            PlatformAudit::record('trial.started', $company, ['email' => $email], 'website');
            return $company;
        });
    }

    /** ORD-2026-10-0001 style numbers; MAX+1 within the month prefix, UNIQUE index as the backstop. */
    private static function nextNumber(string $kind): string
    {
        $prefix = $kind . '-' . now(\App\Services\Accounts\Ledger::TZ)->format('Y-m') . '-';
        return $prefix . str_pad((string) (self::maxSuffix(SalesOrder::query()->where('number', 'like', $prefix . '%')->lockForUpdate()->pluck('number'), $prefix) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** SC/2026-27/0001: consecutive through the Indian financial year (resets 1 April). */
    private static function nextInvoiceNumber(): string
    {
        $y = \App\Services\Accounts\Ledger::fyStart()->year;   // Indian financial year, Indian date (a 00:30 IST payment on 1 April is the new year)
        $prefix = PlatformSettings::get('invoice_prefix') . '/' . $y . '-' . substr((string) ($y + 1), 2) . '/';
        return $prefix . str_pad((string) (self::maxSuffix(SalesOrder::query()->where('invoice_number', 'like', $prefix . '%')->lockForUpdate()->pluck('invoice_number'), $prefix) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Highest number after the prefix, compared as numbers (text MAX() would put 9999 above 10000). */
    private static function maxSuffix(\Illuminate\Support\Collection $numbers, string $prefix): int
    {
        return (int) ($numbers->map(fn ($n) => (int) substr((string) $n, strlen($prefix)))->max() ?? 0);
    }

    public static function send(string $to, string $subject, string $html): void
    {
        try {
            Mail::html($html, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('platform.mail_failed', ['to' => $to, 'subject' => $subject, 'error' => $e->getMessage()]);
        }
    }
}
