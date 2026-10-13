<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Platform\CustomPrice;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\Pricing;
use App\Services\Platform\Sales;
use App\Services\Licensing\LicenceAuthority;
use App\Services\Platform\PlatformAudit;
use Illuminate\Http\Request;

/** Licence server: the install check-in API, and Super Admin actions on an on-prem licence's server binding and AMC. */
final class OnPremController extends Controller
{
    /** POST /api/v1/license/validate — called by on-prem installs. The licence key is the credential. */
    public function validate(Request $r)
    {
        $d = $r->validate(['key' => 'required|string|max:40', 'fingerprint' => 'required|string|regex:/^[a-f0-9]{40,64}$/', 'users' => 'nullable|integer|min:0|max:1000000']);
        [$status, $body] = LicenceAuthority::validate($d['key'], $d['fingerprint'], $d['users'] ?? null);
        return response()->json($body, $status);
    }

    // ── self-serve purchases from an install (renew / more users / AMC). Credential: licence key + the bound server's ID. ──

    /** POST /api/v1/license/offer — what this licence can buy now. */
    public function offer(Request $r)
    {
        [$l] = $this->shopLicence($r);
        $sub = $l->kind === 'subscription';
        $live = $l->status === 'active' && !$l->expired() && (!$sub || $l->expires_at?->isFuture());
        $open = SalesOrder::query()->with('plan:id,name')->where('licence_id', $l->id)->where('status', 'created')->latest('id')->first();
        return response()->json([
            'licence_kind' => $l->kind, 'plan_id' => $l->plan_id, 'max_users' => $l->max_users, 'billing' => $l->billing,
            'can_renew' => $sub && $l->status === 'active', 'can_add_users' => $live && in_array($l->kind, ['subscription', 'perpetual'], true),
            'can_amc' => $l->kind === 'perpetual' && $l->status === 'active',
            'cycles' => Pricing::CYCLE_LABEL,
            'plans' => Plan::query()->with('cloudTiers')->where('active', true)->where('is_public', true)->orderBy('sort')->get()
                ->map(fn (Plan $p) => $p->only(['id', 'code', 'name', 'tagline', 'min_users', 'features']) + [
                    'from_rate' => $sub ? $p->cloudTiers->filter(fn ($t) => $t->max_users !== null && $t->rate_inr > 0)->min('rate_inr') : null,
                    'negotiated' => CustomPrice::for($p, 'renewal', null, $l->holder_email) !== null]),
            'open_order' => $open && !$open->expiredQuote() ? $open->only(['number', 'kind', 'total', 'users', 'created_at', 'pay_state', 'failure_reason', 'attempts', 'valid_until'])
                + ['plan' => $open->plan?->name, 'pay_url' => $open->payUrl()] : null,
        ]);
    }

    /** POST /api/v1/license/quote — the exact charges, line by line (the same numbers the order will have). */
    public function quote(Request $r)
    {
        [$l, $kind, $spec, $buyer] = $this->shopInput($r);
        [, $pr] = Sales::quote($kind, $spec, $buyer, $l);
        $from = $kind === 'renewal' && $l->expires_at?->isFuture() ? $l->expires_at : now();
        return response()->json($pr + ['kind' => $kind, 'valid_until' => $kind === 'renewal'
            ? $from->copy()->addMonthsNoOverflow(Pricing::CYCLES[$pr['billing'] ?? 'annual'])->toDateString() : $l->expires_at?->toDateString()], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /** POST /api/v1/license/order — creates (or returns the same open) order; the customer pays on our pay page. */
    public function order(Request $r)
    {
        [$l, $kind, $spec, $buyer, $fp] = $this->shopInput($r);
        $o = Sales::createOrder($kind, $spec, $buyer, $l, 'install:' . substr($fp, 0, 12));
        PlatformAudit::record('licence.order_from_install', $l, ['order' => $o->number, 'kind' => $kind], 'install');
        return response()->json(['number' => $o->number, 'total' => $o->total, 'pay_url' => $o->payUrl()], $o->wasRecentlyCreated ? 201 : 200);
    }

    /** POST /api/v1/license/cancel — closes this licence's unpaid order. */
    public function cancelOrder(Request $r)
    {
        [$l] = $this->shopLicence($r);
        $o = SalesOrder::query()->where('licence_id', $l->id)->where('number', (string) $r->input('number'))->where('status', 'created')->firstOrFail();
        Sales::cancel($o, 'Cancelled from the install', 'install');
        return response()->json(['ok' => true]);
    }

    /** @return array{0:Licence,1:string} */
    private function shopLicence(Request $r): array
    {
        $d = $r->validate(['key' => 'required|string|max:40', 'fingerprint' => 'required|string|regex:/^[a-f0-9]{40,64}$/']);
        $l = Licence::query()->where('key', strtoupper(trim($d['key'])))->where('deployment', 'onprem')->first();
        // one answer for unknown key and wrong server: nothing to learn by guessing
        if (!$l || !$l->server_fingerprint || !hash_equals($l->server_fingerprint, $d['fingerprint'])) {
            throw new DomainException('not_allowed', 'Online purchase works on the server this licence is activated on. Click Check now first, or contact Ametecs.');
        }
        if (!in_array($l->status, ['active'], true)) {
            throw new DomainException('licence_' . $l->status, 'This licence is ' . $l->status . '. Contact Ametecs.');
        }
        return [$l, $d['fingerprint']];
    }

    private function shopInput(Request $r): array
    {
        [$l, $fp] = $this->shopLicence($r);
        $r->merge(['buyer_gstin' => strtoupper(trim((string) $r->input('buyer_gstin'))) ?: null]);
        $d = $r->validate(['kind' => 'required|in:renewal,seats,amc', 'plan_id' => 'nullable|integer', 'users' => 'nullable|integer|min:1|max:100000',
            'billing' => 'nullable|in:annual,half_yearly,quarterly', 'buyer_gstin' => 'nullable|string|size:15', 'state_code' => 'required|digits:2',
            'coupon' => 'nullable|string|max:30', 'buyer_name' => 'nullable|string|max:150', 'buyer_email' => 'nullable|email|max:190']);
        $spec = ['plan_id' => $d['kind'] === 'amc' ? null : ($d['plan_id'] ?? null), 'users' => $d['kind'] === 'amc' ? null : ($d['users'] ?? null),
            'billing' => $d['kind'] === 'renewal' ? ($d['billing'] ?? null) : null, 'coupon' => $d['kind'] === 'renewal' ? ($d['coupon'] ?? null) : null];
        $buyer = ['buyer_company' => $l->holder_name, 'buyer_name' => $d['buyer_name'] ?? $l->holder_name, 'buyer_email' => $l->holder_email ?: ($d['buyer_email'] ?? ''),
            'buyer_gstin' => $d['buyer_gstin'] ?? null, 'state_code' => $d['state_code'], 'company_id' => null];
        if ($buyer['buyer_email'] === '') {
            throw new DomainException('no_email', 'This licence has no billing e-mail. Contact Ametecs.');
        }
        return [$l, $d['kind'], $spec, $buyer, $fp];
    }

    public function release(Request $r, int $id)
    {
        $d = $r->validate(['reason' => 'required|string|max:255']);
        $l = $this->onprem($id);
        $l->log('binding_released', ['from' => $l->server_fingerprint, 'reason' => $d['reason']], $this->actor());
        $l->forceFill(['server_fingerprint' => null])->save();
        PlatformAudit::record('licence.binding_released', $l, ['reason' => $d['reason']]);
        return response()->json(['ok' => true]);
    }

    /** Move the licence to a replacement server (its server ID from the install's Plan & licence page). */
    public function bind(Request $r, int $id)
    {
        $d = $r->validate(['fingerprint' => 'required|string|regex:/^[a-fA-F0-9]{40,64}$/', 'reason' => 'required|string|max:255']);
        $l = $this->onprem($id);
        $recent = $l->last_validated_at && $l->last_validated_at->gt(now()->subHours(48));
        $l->log('binding_moved', ['from' => $l->server_fingerprint, 'to' => strtolower($d['fingerprint']), 'reason' => $d['reason']], $this->actor());
        $l->forceFill(['server_fingerprint' => strtolower($d['fingerprint'])])->save();
        PlatformAudit::record('licence.binding_moved', $l, ['reason' => $d['reason'], 'old_server_seen_recently' => $recent]);
        return response()->json(['ok' => true, 'warning' => $recent ? 'The old server checked in during the last 48 hours — make sure it is really retired.' : null]);
    }

    /** +12 months of updates & support without an order (free cover: super admins only). */
    public function renewAmc(Request $r, int $id)
    {
        $this->superOnly();
        $d = $r->validate(['reason' => 'required|string|max:255']);
        $l = $this->onprem($id);
        if ($l->kind !== 'perpetual') {
            throw new DomainException('not_perpetual', 'AMC is for perpetual licences.');
        }
        $from = $l->amc_expires_at && $l->amc_expires_at->isFuture() ? $l->amc_expires_at : now();
        $l->forceFill(['amc_expires_at' => $from->copy()->addYear()])->save();
        $l->log('amc_renewed', ['amc_until' => $l->amc_expires_at->toDateString(), 'reason' => $d['reason'], 'billed' => false], $this->actor());
        PlatformAudit::record('licence.amc_renewed', $l, ['reason' => $d['reason']]);
        return response()->json(['ok' => true, 'amc_expires_at' => $l->amc_expires_at]);
    }

    /** Signed licence file download (offline install). */
    public function file(Request $r, int $id)
    {
        $d = $r->validate(['fingerprint' => 'nullable|string|max:64']);
        $l = Licence::query()->findOrFail($id);   // on-prem: locked to a server; cloud: locked to a workspace
        $token = LicenceAuthority::file($l, $d['fingerprint'] ?? null, $this->actor());
        return response($token, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="' . $l->key . '.lic"']);
    }

    private function onprem(int $id): Licence
    {
        $l = Licence::query()->findOrFail($id);
        if ($l->deployment !== 'onprem') {
            throw new DomainException('not_onprem', 'This is a cloud licence; it has no server binding.');
        }
        return $l;
    }

    private function actor(): string
    {
        return 'staff:' . (auth('platform')->user()?->email ?? '?');
    }

    private function superOnly(): void
    {
        if (!auth('platform')->user()?->isSuper()) {
            throw new DomainException('forbidden', 'Only a super admin can do this.');
        }
    }
}
