<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Platform\Coupon;
use App\Models\Platform\Lead;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Models\User;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\Licensing;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Razorpay;
use App\Services\Platform\Sales;
use App\Services\Platform\Workspaces;
use App\Services\Accounts\Ledger;
use App\Http\Middleware\PlatformAccess;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Password;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Super Admin console API (/api/super/*). Every route is behind `platform:<module>` (view for GET, manage for writes);
 * a few actions are Super-only on top of that (re-checked here). Every write is in the platform audit log.
 */
final class ConsoleController extends Controller
{
    private function actor(): string
    {
        return 'staff:' . auth('platform')->user()->email;
    }

    private function superOnly(): void
    {
        if (!auth('platform')->user()->isSuper()) {
            throw new DomainException('forbidden', 'Only a super admin can do this.');
        }
    }

    // ── Dashboard ──
    public function dashboard()
    {
        $active = fn () => Licence::query()->where('status', 'active');
        $revenue = fn (Carbon $from) => (float) SalesOrder::query()->where('status', 'paid')->where('paid_at', '>=', $from->copy()->utc())->sum('amount');   // Indian month / FY boundaries
        return response()->json([
            'companies' => Company::query()->count(),
            'licences_active' => $active()->whereIn('kind', ['subscription', 'perpetual'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'onprem_active' => $active()->where('deployment', 'onprem')->whereIn('kind', ['subscription', 'perpetual'])->count(),
            'amc_due_30d' => $active()->where('kind', 'perpetual')->whereBetween('amc_expires_at', [now(), now()->addDays(30)])->count(),
            'trials_active' => $active()->where('kind', 'trial')->where('expires_at', '>', now())->count(),
            'expiring_30d' => $active()->where('kind', 'subscription')->whereBetween('expires_at', [now(), now()->addDays(30)])->count(),
            'trials_ending_7d' => $active()->where('kind', 'trial')->whereBetween('expires_at', [now(), now()->addDays(7)])->count(),
            'expired' => $active()->whereNotNull('expires_at')->where('expires_at', '<', now())->count(),
            'revenue_month' => $revenue(now(Ledger::TZ)->startOfMonth()),
            'revenue_fy' => $revenue(Ledger::fyStart()),
            'orders_unpaid' => SalesOrder::query()->where('status', 'created')->count(),
            'leads_new' => Lead::query()->where('status', 'new')->count(),
            'recent_orders' => SalesOrder::query()->with('plan:id,name')->latest('id')->limit(8)->get(),
            'expiring' => Licence::query()->with('plan:id,name', 'company:id,slug,name')->where('status', 'active')->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->addDays(30))->orderBy('expires_at')->limit(10)->get(),
            'payments_ready' => Razorpay::configured(),
        ]);
    }

    // ── Companies (cloud workspaces) ──
    public function companies(Request $r)
    {
        $q = trim((string) $r->query('q'));
        $companies = Company::query()->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%")))
            ->orderBy('name')->paginate(50);
        $ids = $companies->pluck('id');
        $users = DB::table('users')->whereIn('company_id', $ids)->where('status', 'active')->groupBy('company_id')->selectRaw('company_id, COUNT(*) n')->pluck('n', 'company_id');
        $phones = DB::table('managed_devices')->whereIn('company_id', $ids)->where('approval_state', 'approved')->groupBy('company_id')->selectRaw('company_id, COUNT(*) n')->pluck('n', 'company_id');
        // usage: calls placed in the last 30 days and the last time any phone was heard from
        $calls = DB::table('calls')->whereIn('company_id', $ids)->where('initiated_at', '>=', now()->subDays(30))->groupBy('company_id')->selectRaw('company_id, COUNT(*) n')->pluck('n', 'company_id');
        $seen = DB::table('managed_devices')->whereIn('company_id', $ids)->groupBy('company_id')->selectRaw('company_id, MAX(last_contact_at) t')->pluck('t', 'company_id');
        $adminEmails = DB::table('users')->whereIn('company_id', $ids)->where('employee_code', 'admin')->pluck('email', 'company_id');
        $lic = Licence::query()->whereIn('company_id', $ids)->where('status', '!=', 'superseded')->with('plan:id,name')->latest('id')->get()->unique('company_id')->keyBy('company_id');
        $companies->getCollection()->transform(fn (Company $c) => $c->only(['id', 'slug', 'name', 'status', 'created_at']) + [
            'active_users' => (int) ($users[$c->id] ?? 0), 'phones' => (int) ($phones[$c->id] ?? 0), 'console_url' => Workspaces::consoleUrl($c),
            'calls_30d' => (int) ($calls[$c->id] ?? 0), 'last_seen_at' => $seen[$c->id] ?? null,
            'admin_email' => $adminEmails[$c->id] ?? null,
            'licence' => ($l = $lic[$c->id] ?? null) ? $l->only(['id', 'key', 'kind', 'status', 'max_users', 'expires_at']) + ['plan' => $l->plan?->name] : null,
        ]);
        return response()->json($companies);
    }

    /** Create a cloud workspace by hand (e.g. after a bank-transfer deal) with a licence on the chosen plan. */
    public function createCompany(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'slug' => 'nullable|string|max:40', 'admin_name' => 'required|string|max:150',
            'admin_email' => 'required|email|max:190', 'admin_password' => 'nullable|string|min:10|max:200', 'plan_id' => 'required|integer', 'kind' => 'required|in:subscription,trial,internal',
            'users' => 'nullable|integer|min:1|max:100000', 'billing' => 'nullable|in:annual,half_yearly,quarterly', 'send_email' => 'boolean', 'reason' => 'nullable|string|max:255']);
        // Workspace (= sub-domain) is made from the company name when not given, always unique.
        $d['slug'] = ($d['slug'] ?? '') !== '' ? strtolower($d['slug']) : Workspaces::slugFrom($d['name']);
        $plan = Plan::query()->findOrFail($d['plan_id']);
        $this->checkFreeAccess($d);
        [$company, $order] = DB::transaction(function () use ($d, $plan) {
            // No password typed: a random one nobody knows; the admin chooses their own from the e-mailed link.
            $c = Workspaces::create($d['slug'], $d['name'], $d['admin_email'], $d['admin_name'], ($d['admin_password'] ?? '') !== '' ? $d['admin_password'] : \Illuminate\Support\Str::password(24));
            return [$c, $this->licenceFor($c, $plan, $d, $d['admin_name'], $d['admin_email'])[1]];
        });
        PlatformAudit::record('company.created', $company, ['plan' => $plan->code, 'kind' => $d['kind'], 'slug' => $company->slug]);
        $link = null;
        if (($d['send_email'] ?? true) || ($d['admin_password'] ?? '') === '') {
            $admin = User::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_code', 'admin')->firstOrFail();
            $link = \App\Services\Platform\AccountLinks::sendWelcome($company, $admin);
            PlatformAudit::record('company.welcome_sent', $company, ['to' => $admin->email]);
        }
        return response()->json(['id' => $company->id, 'slug' => $company->slug, 'console_url' => Workspaces::consoleUrl($company), 'admin_email' => strtolower($d['admin_email']),
            'email_sent' => $link !== null, 'mail_ready' => PlatformSettings::mailReady(), 'set_password_url' => $link, 'order' => $this->orderSummary($order)], 201);
    }

    /** E-mail the workspace's main admin a fresh "set your password" link (lost the first one, or it expired). */
    public function inviteCompanyAdmin(int $id)
    {
        $c = Company::query()->findOrFail($id);
        $admin = User::withoutGlobalScopes()->where('company_id', $c->id)->where('employee_code', 'admin')->first();
        if (!$admin) {
            throw new DomainException('no_admin', 'This workspace has no main admin account.');
        }
        $link = \App\Services\Platform\AccountLinks::sendWelcome($c, $admin);
        PlatformAudit::record('company.welcome_sent', $c, ['to' => $admin->email]);
        return response()->json(['ok' => true, 'email' => $admin->email, 'mail_ready' => PlatformSettings::mailReady(), 'set_password_url' => $link]);
    }

    /** Suspend / re-activate a whole workspace (suspended = nobody can sign in, phones included). */
    public function setCompanyStatus(Request $r, int $id)
    {
        $d = $r->validate(['status' => 'required|in:active,suspended', 'reason' => 'required_if:status,suspended|nullable|string|max:255']);
        $c = Company::query()->findOrFail($id);
        $c->forceFill(['status' => $d['status']])->save();
        PlatformAudit::record('company.' . $d['status'], $c, ['reason' => $d['reason'] ?? null]);
        return response()->json(['ok' => true]);
    }

    // ── Licences ──
    public function licences(Request $r)
    {
        $q = trim((string) $r->query('q'));
        return response()->json(Licence::query()->with('plan:id,name', 'company:id,slug,name')
            ->when($r->query('status'), fn ($w, $s) => $s === 'expiring' ? $w->where('status', 'active')->whereBetween('expires_at', [now(), now()->addDays(30)])
                : ($s === 'expired' ? $w->where('status', 'active')->where('expires_at', '<', now()) : $w->where('status', $s)))
            ->when($r->query('kind'), fn ($w, $k) => $w->where('kind', $k))
            ->when($r->query('deployment'), fn ($w, $k) => $w->where('deployment', $k))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('key', 'like', "%{$q}%")->orWhere('holder_name', 'like', "%{$q}%")->orWhere('holder_email', 'like', "%{$q}%")))
            ->latest('id')->paginate(50));
    }

    public function licence(int $id)
    {
        $l = Licence::query()->with('plan:id,name,code', 'company:id,slug,name')->findOrFail($id);
        $used = $l->company_id ? DB::table('users')->where('company_id', $l->company_id)->where('status', 'active')->count() : null;
        return response()->json($l->toArray() + ['events' => $l->events()->limit(100)->get(), 'used_users' => $used,
            'orders' => SalesOrder::query()->where('licence_id', $l->id)->latest('id')->get()]);
    }

    /** Change a licence: users, expiry, grace, plan/features, status (suspend / resume / revoke). */
    public function updateLicence(Request $r, int $id)
    {
        $l = Licence::query()->findOrFail($id);
        $d = $r->validate(['max_users' => 'integer|min:0|max:100000', 'expires_at' => 'nullable|date', 'grace_days' => 'integer|min:0|max:90',
            'plan_id' => 'nullable|integer', 'features' => 'array', 'features.*' => Rule::in(array_keys(Licensing::FEATURES)),
            'status' => 'in:active,suspended,revoked', 'notes' => 'nullable|string|max:2000', 'reason' => 'nullable|string|max:255',
            'billing' => 'nullable|in:annual,half_yearly,quarterly', 'amc_expires_at' => 'nullable|date']);
        if ($l->status === 'revoked' && ($d['status'] ?? 'revoked') !== 'revoked') {
            throw new DomainException('licence_revoked', 'A revoked licence stays revoked. Issue a new one instead.');
        }
        if (array_key_exists('amc_expires_at', $d)) {
            $this->superOnly();   // free updates & support
        }
        $neverExpires = array_key_exists('expires_at', $d) && $d['expires_at'] === null && $l->expires_at !== null && $l->kind !== 'internal';
        if (($d['status'] ?? null) === 'revoked' || (($d['max_users'] ?? null) === 0 && $l->max_users !== 0 && $l->kind !== 'internal') || $neverExpires) {
            $this->superOnly();
        }
        if (!empty($d['expires_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['expires_at'])) {
            $d['expires_at'] = Carbon::parse($d['expires_at'], Ledger::TZ)->endOfDay()->utc();   // a date means "to the end of that Indian day"
        }
        $before = $l->only(['max_users', 'expires_at', 'grace_days', 'plan_id', 'features', 'status', 'billing', 'amc_expires_at']);
        if (isset($d['plan_id'])) {
            $plan = Plan::query()->findOrFail($d['plan_id']);
            $l->plan_id = $plan->id;
            $l->features = $d['features'] ?? $plan->features;
        }
        $l->fill(array_intersect_key($d, array_flip(['max_users', 'expires_at', 'grace_days', 'features', 'status', 'notes', 'billing', 'amc_expires_at'])));
        $l->save();
        $l->log('edited', ['before' => $before, 'after' => $l->only(array_keys($before)), 'reason' => $d['reason'] ?? null], $this->actor());
        PlatformAudit::record('licence.updated', $l, ['reason' => $d['reason'] ?? null]);
        if ($l->status === 'revoked') {   // nothing left to pay for on a revoked licence
            foreach (SalesOrder::query()->where('licence_id', $l->id)->where('status', 'created')->get() as $open) {
                Sales::cancel($open, 'The licence was revoked', $this->actor());
            }
        }
        return $this->licence($l->id);
    }

    // ── Plans ──
    public function plans()
    {
        return response()->json(['plans' => Plan::query()->with('cloudTiers', 'perpetualBands')->orderBy('sort')->orderBy('id')->get(), 'features' => Licensing::FEATURES,
            'cycles' => \App\Services\Platform\Pricing::CYCLE_LABEL]);
    }

    /**
     * A plan: tier identity, features, minimum users and its two price ladders, replaced as a whole.
     *  cloud_tiers: [{min_users, max_users|null, rate_inr}] — ₹/user/month on annual billing; an open last row (no max, rate 0) = custom quote above
     *  perpetual_bands: [{min_users, max_users|null, price_inr|null}] — one-time price at each band's top; only the last band may be open;
     *  no gaps or overlaps; prices never go down as users rise
     */
    public function savePlan(Request $r, ?int $id = null)
    {
        $this->superOnly();
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans')->ignore($id)], 'name' => 'required|string|max:80',
            'tagline' => 'nullable|string|max:190', 'min_users' => 'required|integer|min:1|max:100000', 'features' => 'required|array|min:1', 'features.*' => Rule::in(array_keys(Licensing::FEATURES)),
            'is_public' => 'boolean', 'active' => 'boolean', 'sort' => 'integer|min:0|max:999',
            'cloud_tiers' => 'present|array|max:20', 'cloud_tiers.*.min_users' => 'required|integer|min:1', 'cloud_tiers.*.max_users' => 'nullable|integer|min:1', 'cloud_tiers.*.rate_inr' => 'required|numeric|min:0|max:100000',
            'perpetual_bands' => 'present|array|max:30', 'perpetual_bands.*.min_users' => 'required|integer|min:1', 'perpetual_bands.*.max_users' => 'nullable|integer|min:1',
            'perpetual_bands.*.price_inr' => 'nullable|numeric|min:1|max:999999999']);
        self::checkLadder($d['cloud_tiers'], 'rate_inr', 'Cloud price bands', false);
        self::checkLadder($d['perpetual_bands'], 'price_inr', 'On-premises price bands', true);
        $plan = $id ? Plan::query()->findOrFail($id) : new Plan(['max_users' => 0, 'months' => 12]);
        $before = $id ? ['plan' => $plan->only(['name', 'min_users', 'features', 'is_public', 'active']), 'cloud' => $plan->cloudTiers()->get(['min_users', 'max_users', 'rate_inr'])->toArray(),
            'perpetual' => $plan->perpetualBands()->get(['min_users', 'max_users', 'price_inr'])->toArray()] : null;
        DB::transaction(function () use ($plan, $d) {
            $plan->fill(array_intersect_key($d, array_flip(['code', 'name', 'tagline', 'min_users', 'features', 'is_public', 'active', 'sort'])))->save();
            $plan->cloudTiers()->delete();
            $plan->perpetualBands()->delete();
            foreach ($d['cloud_tiers'] as $t) {
                $plan->cloudTiers()->create(['min_users' => $t['min_users'], 'max_users' => $t['max_users'] ?? null, 'rate_inr' => $t['rate_inr']]);
            }
            foreach ($d['perpetual_bands'] as $t) {
                $plan->perpetualBands()->create(['min_users' => $t['min_users'], 'max_users' => $t['max_users'] ?? null, 'price_inr' => $t['price_inr'] ?? null]);
            }
        });
        PlatformAudit::record($id ? 'plan.updated' : 'plan.created', $plan, ['before' => $before, 'after' => ['plan' => $plan->only(['name', 'min_users', 'features', 'is_public', 'active']),
            'cloud' => $d['cloud_tiers'], 'perpetual' => $d['perpetual_bands']]]);
        return response()->json($plan->load('cloudTiers', 'perpetualBands'), $id ? 200 : 201);
    }

    /** Ladder rules: sorted by users, no overlaps or gaps, only the last row open-ended, prices never fall as users rise. */
    private static function checkLadder(array $rows, string $price, string $what, bool $noGaps): void
    {
        $prev = null;
        foreach (array_values($rows) as $i => $t) {
            $last = $i === count($rows) - 1;
            $max = $t['max_users'] ?? null;
            $fail = fn (string $m) => throw new DomainException('bad_ladder', "{$what}, row " . ($i + 1) . ": {$m}");
            if ($max === null && !$last) {
                $fail('only the last row can be open-ended (no maximum).');
            }
            if ($max !== null && $max < $t['min_users']) {
                $fail('the maximum must be at least the minimum.');
            }
            if ($prev !== null) {
                if ($t['min_users'] <= $prev['max_users']) {
                    $fail('it overlaps the row above.');
                }
                if ($noGaps && $t['min_users'] !== $prev['max_users'] + 1) {
                    $fail('it must start right after the row above (' . ($prev['max_users'] + 1) . ' users).');
                }
                if ($max !== null && (float) ($t[$price] ?? 0) > 0 && (float) ($prev[$price] ?? 0) > 0 && $price === 'price_inr' && (float) $t[$price] < (float) $prev[$price]) {
                    $fail('the price can\'t be lower than for fewer users.');
                }
            }
            if ($max !== null && !((float) ($t[$price] ?? 0) > 0)) {
                $fail('a price is needed (leave the maximum empty on the last row for "custom quote above").');
            }
            $prev = $t;
        }
    }

    // ── Orders & payments ──
    public function orders(Request $r)
    {
        $q = trim((string) $r->query('q'));
        return response()->json(SalesOrder::query()->with('plan:id,name', 'licence:id,key')
            ->when($r->query('status'), fn ($w, $s) => match ($s) {
                'failed' => $w->where('status', 'created')->whereNotNull('failed_at'),
                'pending' => $w->where('status', 'created')->whereNull('failed_at'),
                default => $w->where('status', $s),
            })
            ->when($r->integer('company_id'), fn ($w, $id) => $w->where('company_id', $id))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$q}%")->orWhere('buyer_company', 'like', "%{$q}%")
                ->orWhere('buyer_email', 'like', "%{$q}%")->orWhere('invoice_number', 'like', "%{$q}%")))
            ->latest('id')->paginate(50)->through(fn (SalesOrder $o) => $o->toArray() + ['pay_url' => $o->payUrl(), 'invoice_url' => $o->status === 'paid' ? url("/pay/{$o->number}/{$o->token()}/invoice") : null]));
    }

    /** Staff-raised renewal / upgrade of a workspace's licence. The client pays via the link (Razorpay) or staff record the payment. */
    /**
     * Staff order: new on-prem licence, new cloud licence for an existing workspace, trial → paid, renewal, more users or AMC.
     * Priced by Pricing like every other order. A typed price (custom_amount) or "no setup" is super-only.
     */
    public function createOrder(Request $r)
    {
        [$kind, $spec, $buyer, $licence] = $this->orderInput($r);
        $o = Sales::createOrder($kind, $spec, $buyer, $licence, $this->actor());
        PlatformAudit::record('order.created', $o, ['total' => $o->total, 'kind' => $kind]);
        return response()->json($o->toArray() + ['pay_url' => $o->payUrl()], $o->wasRecentlyCreated ? 201 : 200);
    }

    /** The same numbers createOrder would store, without saving. */
    public function previewOrder(Request $r)
    {
        [$kind, $spec, $buyer, $licence] = $this->orderInput($r, true);
        [, $pr] = Sales::quote($kind, $spec, $buyer, $licence);
        return response()->json($pr, 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function orderInput(Request $r, bool $preview = false): array
    {
        if ($r->has('buyer_gstin')) {
            $r->merge(['buyer_gstin' => strtoupper(trim((string) $r->input('buyer_gstin'))) ?: null]);
        }
        $d = $r->validate(['kind' => 'required|in:new,upgrade,renewal,seats,amc', 'licence_id' => 'nullable|integer|required_unless:kind,new', 'company_id' => 'nullable|integer|exists:companies,id',
            'plan_id' => 'nullable|integer|required_if:kind,new', 'deployment' => 'nullable|in:cloud,onprem', 'licence_kind' => 'nullable|in:subscription,perpetual',
            'users' => 'nullable|integer|min:1|max:100000', 'billing' => 'nullable|in:annual,half_yearly,quarterly', 'setup' => 'nullable', 'custom_amount' => 'nullable|numeric|min:1|max:999999999',
            'coupon' => 'nullable|string|max:30', 'buyer_company' => ($preview ? 'nullable' : 'required') . '|string|max:150', 'buyer_name' => ($preview ? 'nullable' : 'required') . '|string|max:150',
            'buyer_email' => ($preview ? 'nullable' : 'required') . '|email|max:190', 'buyer_phone' => 'nullable|string|max:20', 'buyer_gstin' => 'nullable|string|max:15', 'state_code' => 'nullable|digits:2']);
        if (($d['custom_amount'] ?? null) !== null || ($d['setup'] ?? null) === 'none' || is_numeric($d['setup'] ?? null)) {
            $this->superOnly();   // typed prices and waived setup change what we charge
        }
        $licence = !empty($d['licence_id']) ? Licence::query()->findOrFail($d['licence_id']) : null;
        if ($d['kind'] === 'new' && !empty($d['company_id']) && Licence::query()->where('company_id', $d['company_id'])->whereIn('status', ['active', 'suspended'])->exists()) {
            throw new DomainException('has_licence', 'This workspace already has a licence: raise a renewal or more-users order on it.');
        }
        $spec = array_intersect_key($d, array_flip(['plan_id', 'deployment', 'licence_kind', 'users', 'billing', 'custom_amount', 'coupon']))
            + ['setup' => match (true) { ($d['setup'] ?? null) === 'none' => false, is_numeric($d['setup'] ?? null) => (float) $d['setup'], default => null }];
        if ($d['kind'] === 'new' && !empty($d['company_id'])) {
            $spec['deployment'] = 'cloud';
            $spec['licence_kind'] = 'subscription';
        }
        $buyer = ['buyer_company' => $d['buyer_company'] ?? '', 'buyer_name' => $d['buyer_name'] ?? '', 'buyer_email' => $d['buyer_email'] ?? $licence?->holder_email ?? '',
            'buyer_phone' => $d['buyer_phone'] ?? null, 'buyer_gstin' => $d['buyer_gstin'] ?? null, 'state_code' => $d['state_code'] ?? null, 'company_id' => $d['company_id'] ?? null,
            'key_only' => $d['kind'] === 'new' && ($d['deployment'] ?? 'onprem') === 'cloud' && empty($d['company_id'])];
        return [$d['kind'], $spec, $buyer, $licence];
    }

    /** Paid orders as CSV for the accountant (GST register): one row per tax invoice in the date range. */
    public function ordersCsv(Request $r)
    {
        $d = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $rows = SalesOrder::query()->with('plan:id,name')->where('status', 'paid')->whereBetween('paid_at', [Carbon::parse($d['from'], Ledger::TZ)->startOfDay()->utc(), Carbon::parse($d['to'], Ledger::TZ)->endOfDay()->utc()])
            ->orderBy('paid_at')->get();
        PlatformAudit::record('orders.exported', null, $d + ['rows' => $rows->count()]);
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;   // no spreadsheet formulas from buyer text
        return response()->streamDownload(function () use ($rows, $safe) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice', 'Date', 'Order', 'Buyer', 'GSTIN', 'State', 'Package', 'Pricing', 'Standard price', 'Discount code', 'Discount', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Total', 'Paid by', 'Reference']);
            foreach ($rows as $o) {
                fputcsv($out, array_map($safe, [$o->invoice_number, $o->paid_at->copy()->setTimezone(Ledger::TZ)->format('d-m-Y'), $o->number, $o->buyer_company, $o->buyer_gstin, $o->state_code, $o->plan?->name,
                    $o->pricing === 'custom' ? 'negotiated' : 'standard', $o->list_price, $o->coupon_code, $o->discount, $o->amount, $o->cgst, $o->sgst, $o->igst, $o->total, $o->payment_method, $o->payment_ref]));
            }
            fclose($out);
        }, "rovexy-invoices-{$d['from']}-to-{$d['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Bank transfer / UPI / cheque / cash received outside Razorpay. Same single door as online payments. */
    public function recordPayment(Request $r, int $id)
    {
        $d = $r->validate(['method' => 'required|in:bank,upi,cheque,cash', 'reference' => 'required|string|max:100']);
        $o = Sales::markPaid(SalesOrder::query()->findOrFail($id), $d['method'], $d['reference'], null, $this->actor());
        return response()->json($o->fresh());
    }

    public function cancelOrder(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'nullable|string|max:200']);
        Sales::cancel(SalesOrder::query()->findOrFail($id), ($d['reason'] ?? '') !== '' ? $d['reason'] : 'Cancelled by ROVEXY', $this->actor());
        return response()->json(['ok' => true]);
    }

    // ── Leads ──
    public function leads(Request $r)
    {
        $q = trim((string) $r->query('q'));
        return response()->json(Lead::query()->when($r->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('company', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->orderByRaw('CASE WHEN follow_up_on IS NOT NULL AND follow_up_on <= ? THEN 0 ELSE 1 END', [now()->toDateString()])->latest('id')->paginate(50));
    }

    public function saveLead(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required_without:status|string|max:150', 'company' => 'nullable|string|max:150', 'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:20', 'users' => 'nullable|integer|min:1', 'message' => 'nullable|string|max:2000', 'status' => Rule::in(Lead::STATUSES),
            'notes' => 'nullable|string|max:4000', 'follow_up_on' => 'nullable|date']);
        $lead = $id ? Lead::query()->findOrFail($id) : new Lead(['source' => 'manual', 'status' => 'new']);
        $lead->fill($d)->save();
        PlatformAudit::record($id ? 'lead.updated' : 'lead.created', $lead, array_intersect_key($d, ['status' => 1]));
        return response()->json($lead);
    }

    // ── Coupons ──
    public function coupons()
    {
        return response()->json(Coupon::query()->latest('id')->get());
    }

    public function saveCoupon(Request $r, ?int $id = null)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons')->ignore($id)], 'type' => 'required|in:percent,flat',
            'value' => ['required', 'numeric', 'min:1', $r->input('type') === 'percent' ? 'max:100' : 'max:9999999'], 'plan_ids' => 'nullable|array', 'plan_ids.*' => 'integer',
            'new_only' => 'boolean', 'max_uses' => 'nullable|integer|min:1', 'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'active' => 'boolean', 'note' => 'nullable|string|max:190']);
        $d['code'] = strtoupper($d['code']);
        $c = $id ? Coupon::query()->findOrFail($id) : new Coupon();
        $c->fill($d)->save();
        PlatformAudit::record($id ? 'coupon.updated' : 'coupon.created', $c, ['code' => $c->code, 'type' => $c->type, 'value' => $c->value]);
        return response()->json($c, $id ? 200 : 201);
    }

    /** Automatic e-mails sent (renewal reminders, trial ending, unpaid orders), newest first. */
    public function mailLog()
    {
        return response()->json(DB::table('platform_mail_log')->orderByDesc('id')->paginate(100));
    }

    // ── Staff users ──
    public function admins()
    {
        return response()->json(['staff' => PlatformAdmin::query()->orderBy('name')->get(['id', 'name', 'email', 'phone', 'role', 'status', 'last_login_at', 'mfa_enabled_at']),
            'roles' => PlatformAccess::ROLES, 'modules' => PlatformAccess::MODULES,
            'matrix' => collect(PlatformAccess::ROLES)->keys()->mapWithKeys(fn ($role) => [$role => collect(PlatformAccess::MODULES)->keys()->mapWithKeys(fn ($m) => [$m => PlatformAccess::level($role, $m)])])]);
    }

    public function saveAdmin(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => ['required', 'email', 'max:190', Rule::unique('platform_admins')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'], 'role' => ['required', Rule::in(array_keys(PlatformAccess::ROLES))], 'status' => 'in:active,disabled',
            'password' => [$id ? 'nullable' : 'required', 'string', 'max:200', Password::min(12)->letters()->numbers()]], ['phone.regex' => 'Phone: digits, spaces, + and - only.']);
        if ($id === auth('platform')->id() && (($d['role'] ?? 'super') !== 'super' || ($d['status'] ?? 'active') !== 'active')) {
            throw new DomainException('own_account', 'You cannot demote or disable your own account.');
        }
        $a = $id ? PlatformAdmin::query()->findOrFail($id) : new PlatformAdmin();
        $a->fill(array_filter(['name' => $d['name'], 'email' => strtolower($d['email']), 'role' => $d['role'], 'status' => $d['status'] ?? 'active',
            'password' => $d['password'] ?? null], fn ($v) => $v !== null) + ['phone' => $d['phone'] ?? null])->save();
        if ($id === auth('platform')->id() && isset($d['password']) && $r->hasSession()) {
            $r->session()->put('platform_pw', PlatformAccess::fingerprint((string) $a->fresh()->password));   // keep my own session; others of mine sign out
        }
        // A changed password or a disabled account signs that person out everywhere on their next request (PlatformAccess).
        PlatformAudit::record($id ? 'staff.updated' : 'staff.created', $a, ['role' => $a->role, 'status' => $a->status, 'password_changed' => isset($d['password'])]);
        return response()->json($a->only(['id', 'name', 'email', 'phone', 'role', 'status']), $id ? 200 : 201);
    }

    /** Lost phone: switch off someone's 2-step sign-in so they can enrol again at their next sign-in. */
    public function resetAdminMfa(int $id)
    {
        $this->superOnly();
        $a = PlatformAdmin::query()->findOrFail($id);
        $a->forceFill(['totp_secret' => null, 'totp_last_step' => null, 'mfa_enabled_at' => null])->save();
        PlatformAudit::record('staff.mfa_reset', $a);
        return response()->json(['ok' => true]);
    }

    // ── Delete (12 Oct 2026). Anything that money or a live workspace depends on is protected: switch it off instead. ──
    public function deleteAdmin(int $id)
    {
        $a = PlatformAdmin::query()->findOrFail($id);
        if ($a->id === auth('platform')->id()) {
            throw new DomainException('own_account', 'You cannot delete your own account.');
        }
        if ($a->role === 'super' && $a->status === 'active' && PlatformAdmin::query()->where('role', 'super')->where('status', 'active')->count() <= 1) {
            throw new DomainException('last_super', 'This is the last active super admin and cannot be deleted.');
        }
        $a->delete();
        PlatformAudit::record('staff.deleted', null, ['id' => $id, 'email' => $a->email, 'role' => $a->role]);
        return response()->json(['ok' => true]);
    }

    public function deletePlan(int $id)
    {
        $p = Plan::query()->findOrFail($id);
        $licences = Licence::query()->where('plan_id', $id)->count();
        $orders = SalesOrder::query()->where('plan_id', $id)->count();
        if ($licences || $orders) {
            throw new DomainException('plan_in_use', "This plan is used by {$licences} licence(s) and {$orders} order(s), so it can't be deleted. Turn off \"Show on website\" or \"Active\" instead.");
        }
        $p->delete();
        PlatformAudit::record('plan.deleted', null, ['id' => $id, 'code' => $p->code, 'name' => $p->name]);
        return response()->json(['ok' => true]);
    }

    public function deleteCoupon(int $id)
    {
        $c = Coupon::query()->findOrFail($id);
        if ($c->used_count > 0 || SalesOrder::query()->where('coupon_code', $c->code)->exists()) {
            throw new DomainException('coupon_used', 'This code is on orders already, so it is kept for the invoice record. Switch it off instead.');
        }
        $c->delete();
        PlatformAudit::record('coupon.deleted', null, ['id' => $id, 'code' => $c->code]);
        return response()->json(['ok' => true]);
    }

    public function deleteLead(int $id)
    {
        $l = Lead::query()->findOrFail($id);
        $l->delete();
        PlatformAudit::record('lead.deleted', null, ['id' => $id, 'name' => $l->name, 'company' => $l->company]);
        return response()->json(['ok' => true]);
    }

    public function deleteOrder(int $id)
    {
        $o = SalesOrder::query()->findOrFail($id);
        if ($o->status === 'paid' || $o->invoice_number) {
            throw new DomainException('order_paid', 'A paid order has a tax invoice and must be kept.');
        }
        $o->delete();
        PlatformAudit::record('order.deleted', null, ['id' => $id, 'number' => $o->number, 'status' => $o->status, 'total' => $o->total]);
        return response()->json(['ok' => true]);
    }

    // ── Settings ──
    public function settings()
    {
        return response()->json(PlatformSettings::forConsole() + ['webhook_url' => url('/api/webhooks/razorpay')]);
    }

    /** Sends one e-mail right now with the saved SMTP settings and reports the server's exact error if it fails. */
    public function testMail(Request $r)
    {
        $d = $r->validate(['to' => 'required|email|max:190']);
        PlatformSettings::applyMail();
        Mail::purge();   // drop any mailer built before the settings were applied
        try {
            Mail::html('<p>This is a test e-mail from ROVEXY. If you can read this, e-mail sending works.</p>',
                fn ($m) => $m->to($d['to'])->subject('ROVEXY test e-mail'));
        } catch (\Throwable $e) {
            PlatformAudit::record('settings.test_mail_failed', null, ['to' => $d['to'], 'error' => mb_substr($e->getMessage(), 0, 300)]);
            return response()->json(['error' => 'mail_failed', 'message' => 'Could not send: ' . mb_substr($e->getMessage(), 0, 300)], 422);
        }
        PlatformAudit::record('settings.test_mail', null, ['to' => $d['to'], 'mailer' => config('mail.default')]);
        return response()->json(['ok' => true, 'mailer' => config('mail.default')]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['seller_name' => 'string|max:150', 'seller_address' => 'nullable|string|max:300', 'seller_gstin' => 'nullable|string|max:15',
            'seller_state' => 'digits:2', 'seller_email' => 'nullable|email|max:190', 'seller_phone' => 'nullable|string|max:20', 'invoice_prefix' => 'string|max:8|regex:/^[A-Z0-9]+$/',
            'gst_rate' => 'numeric|min:0|max:40', 'trial_days' => 'integer|min:1|max:60', 'trial_users' => 'integer|min:1|max:100', 'grace_days' => 'integer|min:0|max:90',
            'razorpay_key_id' => 'nullable|string|max:60', 'razorpay_key_secret' => 'nullable|string|max:120', 'razorpay_webhook_secret' => 'nullable|string|max:120',
            'site_announcement' => 'nullable|string|max:200', 'site_hero_title' => 'nullable|string|max:120', 'site_hero_text' => 'nullable|string|max:400',
            'site_meta_description' => 'nullable|string|max:300', 'reminders_enabled' => 'in:0,1', 'trial_verify_email' => 'in:0,1', 'staff_mfa_required' => 'in:0,1',
            'price_annual_discount_pct' => 'numeric|min:0|max:60', 'price_half_yearly_discount_pct' => 'numeric|min:0|max:60', 'setup_base' => 'numeric|min:0|max:10000000',
            'setup_included_users' => 'integer|min:0|max:100000', 'setup_per_extra' => 'numeric|min:0|max:100000', 'setup_on_cloud' => 'in:0,1', 'amc_pct' => 'numeric|min:0|max:100',
            'quote_validity_days' => 'integer|min:1|max:90',
            'clear_secrets' => 'array', 'clear_secrets.*' => Rule::in(PlatformSettings::SECRETS),
            'mail_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'], 'mail_port' => 'nullable|integer|min:1|max:65535', 'mail_encryption' => 'in:tls,ssl,none',
            'mail_username' => 'nullable|string|max:190', 'mail_password' => 'nullable|string|max:300', 'mail_from_address' => 'nullable|email|max:190', 'mail_from_name' => 'nullable|string|max:100'],
            ['mail_host.regex' => 'SMTP server: a host name like smtp.gmail.com (no http:// or spaces).']);
        foreach ($d['clear_secrets'] ?? [] as $k) {
            PlatformSettings::set($k, '');   // "Remove" ticked in the console
        }
        unset($d['clear_secrets']);
        foreach ($d as $k => $v) {
            if (in_array($k, PlatformSettings::SECRETS, true) && ($v === null || $v === '')) {
                continue;   // write-only: blank = keep the current secret
            }
            PlatformSettings::set($k, $v === null ? '' : (string) $v);
        }
        PlatformAudit::record('settings.updated', null, ['keys' => array_keys($d)]);
        return $this->settings();
    }

    public function audit(Request $r)
    {
        return response()->json(DB::table('platform_audit as a')->leftJoin('platform_admins as p', 'p.id', '=', 'a.admin_id')
            ->when($r->query('action'), fn ($w, $x) => $w->where('a.action', 'like', $x . '%'))
            ->orderByDesc('a.id')->select('a.*', 'p.name as admin_name')->paginate(100));
    }

    /** One workspace: details, usage, licences, admins and orders — the Companies screen's detail panel. */
    public function company(int $id, CurrentCompany $ctx)
    {
        $c = Company::query()->findOrFail($id);
        return response()->json($c->only(['id', 'slug', 'name', 'status', 'created_at', 'billing_email', 'billing_phone', 'gstin', 'state_code', 'address']) + [
            'console_url' => Workspaces::consoleUrl($c),
            'users' => DB::table('users')->where('company_id', $id)->where('status', 'active')->count(),
            'phones' => DB::table('managed_devices')->where('company_id', $id)->where('approval_state', 'approved')->count(),
            'calls_30d' => DB::table('calls')->where('company_id', $id)->where('initiated_at', '>=', now()->subDays(30))->count(),
            'last_seen_at' => DB::table('managed_devices')->where('company_id', $id)->max('last_contact_at'),
            'licences' => Licence::query()->with('plan:id,name')->where('company_id', $id)->latest('id')->get(['id', 'key', 'kind', 'status', 'plan_id', 'max_users', 'expires_at', 'created_at']),
            'orders' => SalesOrder::query()->where('company_id', $id)->latest('id')->limit(20)->get(['id', 'number', 'kind', 'total', 'status', 'invoice_number', 'paid_at', 'created_at', 'failed_at', 'failure_reason']),
            'admins' => $ctx->run($c, fn () => User::query()->whereHas('roles', fn ($q) => $q->where('key', 'company_admin'))->get(['id', 'name', 'email', 'status', 'last_login_at'])),
        ]);
    }

    /** Company details: name and billing (used on renewal orders and invoices). Only sent fields change. */
    public function updateCompany(Request $r, int $id)
    {
        if ($r->has('gstin')) {
            $r->merge(['gstin' => strtoupper(trim((string) $r->input('gstin'))) ?: null]);
        }
        $d = $r->validate(['name' => 'sometimes|required|string|max:150', 'billing_email' => 'sometimes|nullable|email|max:190', 'billing_phone' => 'sometimes|nullable|string|max:20',
            'gstin' => ['sometimes', 'nullable', 'regex:/^\d{2}[A-Z0-9]{13}$/'], 'state_code' => 'sometimes|nullable|digits:2', 'address' => 'sometimes|nullable|string|max:300']);
        $c = Company::query()->findOrFail($id);
        $state = array_key_exists('state_code', $d) ? $d['state_code'] : $c->state_code;
        $gstin = array_key_exists('gstin', $d) ? $d['gstin'] : $c->gstin;
        if ($gstin !== null && $state !== null && substr($gstin, 0, 2) !== $state) {
            throw new DomainException('bad_gstin', 'The GSTIN must start with the state code (' . $state . ').');
        }
        $before = $c->only(array_keys($d));
        $c->forceFill(array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $d))->save();
        PlatformAudit::record('company.updated', $c, ['before' => $before, 'after' => $c->only(array_keys($d))]);
        return response()->json(['ok' => true]);
    }

    /** A workspace with no live licence gets one: a trial (sales may), or a package / internal licence (super only: free access). */
    public function issueLicence(Request $r, int $id)
    {
        $d = $r->validate(['plan_id' => 'required|integer', 'kind' => 'required|in:subscription,trial,internal', 'users' => 'nullable|integer|min:1|max:100000',
            'billing' => 'nullable|in:annual,half_yearly,quarterly', 'reason' => 'nullable|string|max:255']);
        $this->checkFreeAccess($d);
        $c = Company::query()->findOrFail($id);
        if (Licence::query()->where('company_id', $id)->whereIn('status', ['active', 'suspended'])->exists()) {
            throw new DomainException('has_licence', 'This workspace already has a licence. Edit it under Licences, or raise a renewal order.');
        }
        $plan = Plan::query()->findOrFail($d['plan_id']);
        $admin = DB::table('users')->where('company_id', $c->id)->where('employee_code', 'admin')->first(['name', 'email']);
        [$l, $order] = DB::transaction(fn () => $this->licenceFor($c, $plan, $d, (string) ($admin->name ?? $c->name), (string) ($c->billing_email ?: ($admin->email ?? ''))));
        PlatformAudit::record('licence.issued', $l, ['company' => $c->slug, 'kind' => $l->kind, 'plan' => $plan->code, 'reason' => $d['reason'] ?? null, 'order' => $order?->number]);
        return response()->json(['id' => $l->id, 'order' => $this->orderSummary($order)], 201);
    }

    /** Free access (internal: no expiry, no user limit) is for super admins only, and always with a written reason. */
    private function checkFreeAccess(array $d): void
    {
        if ($d['kind'] === 'internal') {
            $this->superOnly();
            if (trim((string) ($d['reason'] ?? '')) === '') {
                throw new DomainException('reason_required', 'Internal (free) access needs a reason. It is kept in the licence history.');
            }
        }
    }

    /**
     * The licence a hand-made workspace starts with. "Subscription" is never free: the workspace runs on a trial and gets an
     * unpaid upgrade order for the chosen plan, users and period; only when that order is paid (online, or Record payment)
     * does Sales::markPaid turn the trial into the subscription, with the tax invoice and the entry in the books.
     * @return array{0:Licence,1:?SalesOrder}
     */
    private function licenceFor(Company $c, Plan $plan, array $d, string $buyerName, string $buyerEmail): array
    {
        $kind = $d['kind'];
        $users = (int) ($d['users'] ?? ($kind === 'trial' ? PlatformSettings::get('trial_users') : $plan->min_users));
        $l = LicenceIssuer::issue($plan, $c->name, $buyerEmail, $c->id, $kind === 'internal' ? 'internal' : 'trial', $users, 'cloud', null, $this->actor());
        if ($kind === 'internal') {
            $l->log('free_access', ['reason' => trim((string) $d['reason'])], $this->actor());
            return [$l, null];
        }
        if ($kind === 'trial') {
            return [$l, null];
        }
        $o = Sales::createOrder('upgrade', ['plan_id' => $plan->id, 'users' => $users, 'billing' => $d['billing'] ?? 'annual'],
            ['buyer_company' => $c->name, 'buyer_name' => $buyerName, 'buyer_email' => $buyerEmail, 'state_code' => $c->state_code ?? null, 'buyer_gstin' => $c->gstin ?? null], $l, $this->actor());
        $l->log('order_raised', ['order' => $o->number, 'total' => $o->total, 'note' => 'subscription starts when this order is paid'], $this->actor());
        PlatformAudit::record('order.created', $o, ['total' => $o->total, 'kind' => 'upgrade']);
        return [$l, $o];
    }

    private function orderSummary(?SalesOrder $o): ?array
    {
        return $o ? ['id' => $o->id, 'number' => $o->number, 'total' => $o->total, 'valid_until' => $o->valid_until, 'pay_url' => $o->payUrl()] : null;
    }

    /** Company admin locked out: super admins set a new password for the workspace's main admin (they must change it after). */
    public function resetCompanyAdminPassword(Request $r, int $id)
    {
        $this->superOnly();
        $d = $r->validate(['password' => ['required', 'string', 'max:200', Password::min(10)->letters()->numbers()], 'reason' => 'required|string|max:255']);
        $c = Company::query()->findOrFail($id);
        $n = User::withoutGlobalScopes()->where('company_id', $c->id)->where('employee_code', 'admin')->first();
        if (!$n) {
            throw new DomainException('no_admin', 'This workspace has no main admin account.');
        }
        $n->forceFill(['password' => $d['password']])->save();
        PlatformAudit::record('company.admin_password_reset', $c, ['admin' => $n->email, 'reason' => $d['reason']]);
        return response()->json(['ok' => true, 'email' => $n->email]);
    }

    /** Company admin sign-in check for support: who is the admin of this workspace and when they last signed in. */
    public function companyAdmins(int $id, CurrentCompany $ctx)
    {
        $c = Company::query()->findOrFail($id);
        return response()->json($ctx->run($c, fn () => User::query()->whereHas('roles', fn ($q) => $q->where('key', 'company_admin'))->get(['id', 'name', 'email', 'status', 'last_login_at'])));
    }
}
