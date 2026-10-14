<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Platform\CustomPrice;
use App\Models\Platform\Licence;
use App\Models\Platform\LicenceEvent;
use App\Models\Platform\Plan;
use App\Models\Platform\SalesOrder;
use App\Services\Licensing\LicenceClient;
use App\Services\Platform\Licensing;
use App\Services\Platform\Pricing;
use App\Services\Platform\Sales;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

/**
 * Company console: Administration → Plan & licence.
 *  Cloud workspace: plan, users, expiry; renew / buy (trial → paid) / add users now — priced by Pricing, paid online; the licence changes
 *                   only when the payment is confirmed on the server (Sales::markPaid). Receipts and history.
 *  On-prem install (LICENCE_MODE=client): licence key, server ID, last check-in, Check now, licence file upload, AMC, pay link for
 *                   an open order raised by Ametecs.
 */
final class LicenceController extends Controller
{
    private const EVENT = ['issued' => 'Licence issued', 'trial_started' => 'Trial started', 'renewed' => 'Renewed', 'upgraded' => 'Users / plan upgraded',
        'superseded' => 'Trial replaced by a paid package', 'edited' => 'Changed by ROVEXY', 'amc_renewed' => 'Updates & support (AMC) renewed', 'activated' => 'Activated on a server'];

    public function show(Licensing $lic, CurrentCompany $ctx, LicenceClient $client)
    {
        if (LicenceClient::isClient()) {
            $client->ensureFresh();
            $row = $client->row();
            if (($client->payload()['open_order'] ?? null) && (!$row->last_checked_at || $row->last_checked_at->lt(now()->subMinute()))) {
                $client->check();   // a payment is pending: pick up the paid licence as soon as the page is looked at
                \Illuminate\Support\Facades\Cache::forget('rovexy.shop_offer.' . $row->licence_key);
            }
            $lic->forget();
            $row = $client->row();
            $p = $client->payload();
            return response()->json($lic->current() + ['mode' => 'onprem', 'feature_labels' => Licensing::FEATURES, 'server_id' => LicenceClient::fingerprint(), 'weak_fingerprint' => LicenceClient::weakFingerprint(),
                'holder' => $p['holder'] ?? $ctx->get()?->name, 'grace_days' => $p['grace_days'] ?? null, 'deployment' => 'onprem',
                'licence_key' => $row->licence_key, 'source' => $row->source, 'verdict' => $row->status, 'last_checked_at' => $row->last_checked_at,
                'unreachable_since' => $row->unreachable_since, 'last_error' => $row->last_error, 'server_url' => config('ammp.licence.server_url') ?: null,
                'licence_kind' => $p['kind'] ?? null, 'licence_billing' => $p['billing'] ?? null, 'amc_expires_at' => $p['amc_expires_at'] ?? null,
                'offline_until' => $row->source === 'online' && isset($p['issued_at']) && (int) config('ammp.licence.offline_days') > 0
                    ? \Illuminate\Support\Carbon::parse($p['issued_at'])->addDays((int) config('ammp.licence.offline_days'))->toAtomString() : null,
                'billing' => ['state_code' => $ctx->get()?->state_code, 'gstin' => $ctx->get()?->gstin]] + $this->shopOffer($client, $row, $p));
        }
        $licence = $this->licence($ctx);
        $company = $ctx->get();
        $open = $licence ? SalesOrder::query()->with('plan:id,name')->where('licence_id', $licence->id)->where('status', 'created')->latest('id')->first() : null;
        $ids = Licence::query()->where('company_id', $ctx->id())->pluck('id');
        return response()->json($lic->current() + [
            'mode' => 'cloud',
            'server_id' => LicenceClient::workspaceFingerprint($company),
            'holder' => $company->name, 'grace_days' => $licence?->grace_days, 'deployment' => 'cloud', 'licence_kind' => $licence?->kind,
            'starts_at' => $licence?->starts_at, 'amc_expires_at' => $licence?->amc_expires_at,
            'feature_labels' => Licensing::FEATURES,
            'can_renew' => $licence !== null && $licence->kind !== 'internal',
            'can_add_users' => $licence !== null && $licence->kind === 'subscription' && $licence->status === 'active' && $licence->expires_at?->isFuture(),
            'plan_id' => $licence?->plan_id,
            'licence_billing' => $licence?->billing,
            'cycles' => Pricing::CYCLE_LABEL,
            'billing' => ['state_code' => $company->state_code, 'gstin' => $company->gstin],
            'plans' => Plan::query()->with('cloudTiers')->where('active', true)->where('is_public', true)->orderBy('sort')->get()
                ->map(fn (Plan $p) => $p->only(['id', 'code', 'name', 'tagline', 'min_users', 'features']) + [
                    'from_rate' => $p->cloudTiers->filter(fn ($t) => $t->max_users !== null && $t->rate_inr > 0)->min('rate_inr'),
                    'negotiated' => CustomPrice::for($p, $licence?->kind === 'trial' ? 'upgrade' : 'renewal', $ctx->id(), null) !== null]),
            'open_order' => $open ? $open->only(['number', 'kind', 'total', 'users', 'created_at', 'pay_state', 'failure_reason', 'attempts', 'valid_until']) + ['plan' => $open->plan?->name, 'pay_url' => $open->payUrl()] : null,
            'paid_orders' => $licence ? SalesOrder::query()->with('plan:id,name')->whereIn('licence_id', $ids)->where('status', 'paid')->latest('id')->limit(20)->get()
                ->map(fn ($o) => $o->only(['number', 'kind', 'invoice_number', 'total', 'users', 'paid_at', 'payment_method', 'payment_ref']) + ['plan' => $o->plan?->name, 'invoice_url' => url("/pay/{$o->number}/{$o->token()}/invoice")]) : [],
            'history' => LicenceEvent::query()->whereIn('licence_id', $ids)->latest('id')->limit(30)->get()->map(fn ($e) => [
                'at' => $e->created_at, 'what' => self::EVENT[$e->type] ?? ucfirst(str_replace('_', ' ', $e->type)),
                'detail' => trim(implode(' · ', array_filter([
                    isset($e->data['plan']) ? 'plan ' . (Plan::query()->where('code', $e->data['plan'])->value('name') ?? $e->data['plan']) : null,
                    isset($e->data['users']) ? $e->data['users'] . ' users' : null,
                    isset($e->data['billing']) ? strtolower(Pricing::CYCLE_LABEL[$e->data['billing']] ?? $e->data['billing']) : null,
                    isset($e->data['expires_at']) ? 'valid until ' . \Illuminate\Support\Carbon::parse($e->data['expires_at'])->format('d M Y') : null,
                    isset($e->data['days']) ? $e->data['days'] . ' days' : null,
                ]))),
            ]),
        ]);
    }

    /** On-prem: what this licence can buy online (from the licence server, cached 10 minutes; a pending order is never cached). */
    private function shopOffer(LicenceClient $client, $row, ?array $p): array
    {
        if (!$row->licence_key || !$p || ($p['deployment'] ?? 'onprem') !== 'onprem' || !in_array($p['kind'] ?? null, ['subscription', 'perpetual'], true)) {
            return ['can_renew' => false, 'can_add_users' => false, 'can_amc' => false, 'open_order' => null, 'shop_error' => null];
        }
        try {
            $o = \Illuminate\Support\Facades\Cache::remember('rovexy.shop_offer.' . $row->licence_key, 600, fn () => $client->shop('offer'));
            if ($o['open_order'] ?? null) {   // keep the payment state live
                $o = $client->shop('offer');
            }
            return $o + ['shop_error' => null];
        } catch (DomainException $e) {
            return ['can_renew' => false, 'can_add_users' => false, 'can_amc' => false, 'open_order' => $p['open_order'] ?? null, 'shop_error' => $e->getMessage()];
        }
    }

    /** On-prem: quote / order / cancel go to the licence server, which prices and records them. */
    private function shopBody(Request $r, CurrentCompany $ctx): array
    {
        $r->merge(['buyer_gstin' => strtoupper(trim((string) $r->input('buyer_gstin'))) ?: null]);
        $d = $r->validate(['kind' => 'required|in:renewal,seats,amc', 'plan_id' => 'nullable|integer', 'users' => 'nullable|integer|min:1|max:100000',
            'billing' => 'nullable|in:annual,half_yearly,quarterly', 'buyer_gstin' => 'nullable|string|size:15', 'state_code' => 'required|digits:2', 'coupon' => 'nullable|string|max:30']);
        return $d + ['buyer_name' => $r->user()->name, 'buyer_email' => $r->user()->email];
    }

    /** The charges for a renewal / first package / more users, line by line — the same numbers the order will have. */
    public function quote(Request $r, CurrentCompany $ctx, LicenceClient $client)
    {
        if (LicenceClient::isClient()) {
            return response()->json($client->shop('quote', $this->shopBody($r, $ctx)), 200, [], JSON_PRESERVE_ZERO_FRACTION);
        }
        [$kind, $spec, $buyer, $licence] = $this->input($r, $ctx);
        [, $pr] = Sales::quote($kind, $spec, $buyer, $licence);
        $from = $kind === 'renewal' && $licence?->expires_at?->isFuture() ? $licence->expires_at : now();
        return response()->json($pr + ['kind' => $kind, 'valid_until' => in_array($kind, ['renewal', 'upgrade'], true)
            ? $from->copy()->addMonthsNoOverflow(Pricing::CYCLES[$pr['billing'] ?? 'annual'])->toDateString() : $licence?->expires_at?->toDateString()], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Creates (or returns the same open) order for this workspace's licence and the payment link. */
    public function renew(Request $r, CurrentCompany $ctx, LicenceClient $client)
    {
        if (LicenceClient::isClient()) {
            $body = $this->shopBody($r, $ctx);
            $o = $client->shop('order', $body);
            $client->check();   // the signed licence now carries the open order → this page and the 10-minute job watch for the payment
            $ctx->get()?->forceFill(['state_code' => $body['state_code'], 'gstin' => $body['buyer_gstin'] ?? $ctx->get()->gstin])->save();
            \Illuminate\Support\Facades\Cache::forget('rovexy.shop_offer.' . $client->row()->licence_key);
            Audit::record('licence.order_created', null, ['order' => $o['number'] ?? null, 'kind' => $body['kind']]);
            return response()->json($o, 201);
        }
        [$kind, $spec, $buyer, $licence] = $this->input($r, $ctx);
        $o = Sales::createOrder($kind, $spec, $buyer, $licence, 'company:' . $r->user()->email);
        $ctx->get()->forceFill(['state_code' => $buyer['state_code'], 'gstin' => $buyer['buyer_gstin'] ?? $ctx->get()->gstin])->save();   // remember GST details
        Audit::record('licence.order_created', null, ['order' => $o->number, 'kind' => $kind, 'users' => $o->users]);
        return response()->json(['number' => $o->number, 'total' => $o->total, 'pay_url' => $o->payUrl()], $o->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $r, CurrentCompany $ctx, string $number, LicenceClient $client)
    {
        if (LicenceClient::isClient()) {
            $client->shop('cancel', ['number' => $number]);
            \Illuminate\Support\Facades\Cache::forget('rovexy.shop_offer.' . $client->row()->licence_key);
            return response()->json(['ok' => true]);
        }
        $licence = $this->licence($ctx);
        $o = SalesOrder::query()->where('number', $number)->where('licence_id', $licence?->id ?? 0)->firstOrFail();
        Sales::cancel($o, 'Cancelled by the company', 'company:' . $r->user()->email);
        Audit::record('licence.order_cancelled', null, ['order' => $o->number]);
        return response()->json(['ok' => true]);
    }

    // ── on-prem install ──

    public function saveKey(Request $r, LicenceClient $client, CurrentCompany $ctx, Licensing $lic)
    {
        $d = $r->validate(['key' => 'required|string|max:40']);
        if (!LicenceClient::isClient()) {
            return $this->addCloudKey(strtoupper(trim($d['key'])), $r, $ctx, $lic);
        }
        $row = $client->saveKey($d['key']);
        Audit::record('licence.key_saved', null, ['verdict' => $row->status]);
        return response()->json(['verdict' => $row->status, 'error' => $row->last_error]);
    }

    public function checkNow(LicenceClient $client)
    {
        abort_unless(LicenceClient::isClient(), 404);
        $row = $client->check();
        return response()->json(['verdict' => $row->status, 'error' => $row->last_error, 'unreachable_since' => $row->unreachable_since]);
    }

    public function uploadFile(Request $r, LicenceClient $client, CurrentCompany $ctx, Licensing $lic)
    {
        $r->validate(['file' => 'required|file|max:64']);
        if (!LicenceClient::isClient()) {
            // cloud: a signed file locked to this workspace's fingerprint — same check as an install, then the key is added
            $p = \App\Services\Licensing\LicenceSigner::verify(trim((string) file_get_contents($r->file('file')->getRealPath())));
            $fp = LicenceClient::workspaceFingerprint($ctx->get());
            if (!$p || ($p['source'] ?? null) !== 'file') {
                return response()->json(['message' => 'This is not a valid ROVEXY licence file.', 'code' => 'bad_licence_file'], 422);
            }
            if (($p['fingerprint'] ?? null) !== $fp) {
                return response()->json(['message' => "This licence file was made for another workspace or server. This workspace's fingerprint is {$fp}.", 'code' => 'bad_licence_file'], 422);
            }
            return $this->addCloudKey((string) $p['key'], $r, $ctx, $lic, true);
        }
        $row = $client->importFile((string) file_get_contents($r->file('file')->getRealPath()));
        Audit::record('licence.file_installed', null, ['key' => $row->licence_key]);
        return response()->json(['verdict' => $row->status]);
    }

    /**
     * Cloud workspace: add a cloud licence key sold by Ametecs (issued without a workspace). It becomes this workspace's licence and
     * the current one is superseded. Keys already used by another workspace, on-prem keys, and expired / suspended keys are refused.
     */
    private function addCloudKey(string $key, Request $r, CurrentCompany $ctx, Licensing $lic, bool $viaFile = false)
    {
        $refuse = fn (string $msg) => response()->json(['message' => $msg, 'code' => 'bad_key'], 422);
        $l = Licence::query()->where('key', $key)->first();
        if (!$l) {
            return $refuse('This licence key is not recognised. Check it against the e-mail from Ametecs.');
        }
        if (($l->deployment ?? 'cloud') === 'onprem') {
            return $refuse('This is an on-premises key: enter it on your own ROVEXY server, not in a cloud workspace.');
        }
        if ($viaFile && $l->company_id === $ctx->id() && $l->status === 'active') {
            return response()->json(['verdict' => 'active', 'error' => null]);   // the same file installed again
        }
        if ($l->company_id === $ctx->id()) {
            return $refuse($l->status === 'superseded' ? 'This key was replaced by a newer licence.' : 'This key is already this workspace\'s licence.');
        }
        if ($l->company_id !== null) {
            return $refuse('This key is already used by another workspace.');
        }
        if ($l->status !== 'active' || $l->kind === 'trial' || ($l->expires_at && $l->expires_at->isPast())) {
            return $refuse('This key is not active (expired, suspended or revoked). Contact Ametecs.');
        }
        $won = \Illuminate\Support\Facades\DB::transaction(function () use ($l, $ctx, $r) {
            // claim the key atomically: two workspaces entering the same key at once → only one gets it
            if (!Licence::query()->whereKey($l->id)->whereNull('company_id')->update(['company_id' => $ctx->id(), 'holder_name' => $ctx->get()->name, 'activated_at' => now()])) {
                return false;
            }
            $actor = 'company:' . $r->user()->email;
            foreach (Licence::query()->where('company_id', $ctx->id())->where('id', '!=', $l->id)->whereNotIn('status', ['superseded', 'revoked'])->get() as $old) {
                Sales::supersede($old, 'licence key ' . $l->key, $actor);
            }
            $l->log('activated', ['users' => $l->max_users, 'expires_at' => $l->expires_at?->toDateString()], $actor);
            return true;
        });
        if (!$won) {
            return $refuse('This key is already used by another workspace.');
        }
        $lic->forget();
        Audit::record($viaFile ? 'licence.file_installed' : 'licence.key_added', null, ['key' => $l->key]);
        return response()->json(['verdict' => 'active', 'error' => null]);
    }

    /** kind = renewal (subscription) / upgrade (trial → first package) / seats (more users now). */
    private function input(Request $r, CurrentCompany $ctx): array
    {
        abort_if(LicenceClient::isClient(), 404);
        $r->merge(['buyer_gstin' => strtoupper(trim((string) $r->input('buyer_gstin'))) ?: null]);
        $d = $r->validate(['kind' => 'nullable|in:renewal,seats', 'plan_id' => 'required|integer', 'users' => 'required|integer|min:1|max:100000',
            'billing' => 'nullable|in:annual,half_yearly,quarterly', 'buyer_gstin' => 'nullable|string|size:15', 'state_code' => 'required|digits:2', 'coupon' => 'nullable|string|max:30']);
        $licence = $this->licence($ctx) ?? throw new DomainException('no_licence', 'This workspace has no licence. Contact Ametecs.');
        if ($licence->kind === 'internal') {
            throw new DomainException('internal_licence', 'This workspace is on an internal licence and does not need renewing.');
        }
        $kind = $licence->kind === 'trial' ? 'upgrade' : ($d['kind'] ?? 'renewal');
        $company = $ctx->get();
        $u = $r->user();
        return [$kind, ['plan_id' => $d['plan_id'], 'users' => $d['users'], 'billing' => $d['billing'] ?? null, 'coupon' => $d['coupon'] ?? null],
            ['buyer_company' => $company->name, 'buyer_name' => $u->name, 'buyer_email' => $company->billing_email ?: $u->email, 'buyer_phone' => $company->billing_phone,
                'buyer_gstin' => $d['buyer_gstin'] ?? null, 'state_code' => $d['state_code'], 'company_id' => $company->id], $licence];
    }

    private function licence(CurrentCompany $ctx): ?Licence
    {
        return Licence::query()->where('company_id', $ctx->id())->where('status', '!=', 'superseded')->latest('id')->first();
    }
}
