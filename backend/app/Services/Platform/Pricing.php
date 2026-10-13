<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Domain\DomainException;
use App\Models\Platform\Coupon;
use App\Models\Platform\CustomPrice;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;

/**
 * The one pricing function (SmartEPT model). Every entry point — website buy page and price check, company console quote,
 * Super Admin new order / preview / custom-price preview, renewals, more users, AMC — calls build(). Nothing else prices.
 *
 *  Cloud subscription : users × ₹/user/month (size band, annual rate) × months in the cycle
 *                       annual = band rate; quarterly = band rate ÷ (1 − annual discount); half-yearly = that × (1 − half-yearly discount)
 *  On-prem perpetual  : one-time price from the band milestones, straight-line between them
 *  On-prem subscription: priced like cloud
 *  Setup              : base + (users − included) × per extra; on the FIRST order (on-prem always, cloud when switched on)
 *  More users mid-term: subscription (new − old monthly value) × remaining days ÷ 30.44; perpetual price(new) − price(old)
 *  AMC (perpetual)    : amc_pct % of the current perpetual price, 12 months
 * There is no ₹0 price: a size beyond the priced bands is a custom quote and is refused unless staff type a price
 * (or a negotiated price applies). Prices exclude GST; GST is CGST+SGST in the seller's state, IGST otherwise.
 */
final class Pricing
{
    public const CYCLES = ['annual' => 12, 'half_yearly' => 6, 'quarterly' => 3];
    public const CYCLE_LABEL = ['annual' => 'Annual', 'half_yearly' => 'Half-yearly', 'quarterly' => 'Quarterly'];
    public const KINDS = ['new', 'upgrade', 'renewal', 'seats', 'amc'];

    /** ₹ per user per month for this size and cycle. Null = beyond the priced bands (custom quote). */
    public static function cloudRate(Plan $plan, int $users, string $billing): ?float
    {
        $priced = $plan->cloudTiers->filter(fn ($t) => $t->max_users !== null && $t->rate_inr > 0)->sortBy('min_users')->values();
        if ($priced->isEmpty() || $users > $priced->last()->max_users) {
            return null;
        }
        $annual = ($priced->last(fn ($t) => $t->min_users <= $users) ?? $priced->first())->rate_inr;
        $base = $annual / (1 - (float) PlatformSettings::get('price_annual_discount_pct') / 100);
        return round(match ($billing) {
            'annual' => $annual,
            'half_yearly' => $base * (1 - (float) PlatformSettings::get('price_half_yearly_discount_pct') / 100),
            default => $base,
        }, 2);
    }

    /** One-time on-prem price. Null = beyond the priced bands (custom quote). */
    public static function perpetualPrice(Plan $plan, int $users): ?float
    {
        $b = $plan->perpetualBands->filter(fn ($x) => $x->max_users !== null && $x->price_inr > 0)->sortBy('min_users')->values();
        if ($b->isEmpty() || $users > $b->last()->max_users) {
            return null;
        }
        if ($users <= $b[0]->max_users) {
            return round($b[0]->price_inr, 2);                    // flat minimum package
        }
        for ($i = 1; $i < $b->count(); $i++) {
            [$p, $n] = [$b[$i - 1], $b[$i]];
            if ($users <= $n->max_users) {                        // straight line between the two milestones
                return round($p->price_inr + ($users - $p->max_users) * ($n->price_inr - $p->price_inr) / ($n->max_users - $p->max_users));
            }
        }
        return null;
    }

    public static function setupFee(int $users): float
    {
        return round((float) PlatformSettings::get('setup_base') + max(0, $users - (int) PlatformSettings::get('setup_included_users')) * (float) PlatformSettings::get('setup_per_extra'), 2);
    }

    /**
     * Lines and totals for one order. $s keys:
     *  plan (Plan), kind (new|upgrade|renewal|seats|amc), licence_kind (subscription|perpetual), deployment (cloud|onprem), billing, users,
     *  licence (?Licence — required for seats / amc), first (bool: no setup paid yet), setup (null = automatic | false = none | float),
     *  custom_amount (?float, staff-typed licence price), custom (?CustomPrice), coupon (?Coupon), state (?string)
     * @return array{lines:list<array{type:string,label:string,amount:float}>,amount:float,discount:float,cgst:float,sgst:float,igst:float,total:float,
     *               list_price:?float,pricing:string,custom_price_id:?int,users:int,billing:?string,licence_kind:string,deployment:string}
     */
    public static function build(array $s): array
    {
        /** @var Plan $plan */
        $plan = $s['plan'];
        $kind = $s['kind'];
        $lk = $s['licence_kind'];
        $dep = $s['deployment'];
        $users = (int) $s['users'];
        $billing = $lk === 'subscription' ? ($s['billing'] ?? 'annual') : null;
        /** @var Licence|null $l */
        $l = $s['licence'] ?? null;
        if (!in_array($kind, self::KINDS, true) || !in_array($lk, ['subscription', 'perpetual'], true) || !in_array($dep, ['cloud', 'onprem'], true)) {
            throw new DomainException('bad_order', 'Unknown order type.');
        }
        if ($lk === 'perpetual' && $dep === 'cloud') {
            throw new DomainException('bad_order', 'A perpetual licence is for on-premises installs. Cloud is sold as a subscription.');
        }
        if ($billing !== null && !isset(self::CYCLES[$billing])) {
            throw new DomainException('bad_billing', 'Choose annual, half-yearly or quarterly billing.');
        }
        if (!$plan->sellable()) {
            throw new DomainException('plan_not_sellable', 'This plan is not on sale.');
        }
        if ($kind !== 'amc' && $users < $plan->min_users) {
            throw new DomainException('below_minimum', "The {$plan->name} plan starts at {$plan->min_users} users.");
        }
        $months = $billing ? self::CYCLES[$billing] : null;
        $what = $plan->name . ($dep === 'onprem' ? ' On-premises' : ' Cloud');

        // 1. licence line at the standard price
        [$type, $label, $standard] = match ($kind) {
            'new', 'upgrade', 'renewal' => self::period($plan, $lk, $users, $billing, $what, $kind),
            'seats' => self::seats($plan, $users, $billing, $l, $what),
            'amc' => self::amc($l),
        };

        // 2. negotiated / staff price
        $line = $standard;
        $pricing = 'standard';
        $customId = null;
        $custom = $s['custom'] ?? null;
        if (($s['custom_amount'] ?? null) !== null) {
            $line = round((float) $s['custom_amount'], 2);
            $pricing = 'custom';
        } elseif ($custom instanceof CustomPrice && ($p = self::negotiated($custom, $standard, $kind, $lk, $users, $months)) !== null) {
            $line = $p;
            $pricing = 'custom';
            $customId = $custom->id;
        }
        if ($line === null) {
            throw new DomainException('custom_quote', "For {$users} users the {$plan->name} plan is priced on quotation. Ask us for a quote and we will call you.");
        }
        $lines = [['type' => $type, 'label' => $label, 'amount' => $standard ?? $line]];
        if ($standard !== null && abs($line - $standard) >= 0.005) {
            $lines[] = ['type' => 'negotiated', 'label' => $customId ? 'Negotiated price for your account' : 'Special price', 'amount' => round($line - $standard, 2)];
        }

        // 3. setup & onboarding, first order only
        if (in_array($kind, ['new', 'upgrade'], true) && ($s['first'] ?? true) && ($s['setup'] ?? null) !== false) {
            $auto = $dep === 'onprem' || PlatformSettings::get('setup_on_cloud') === '1';
            $fee = is_numeric($s['setup'] ?? null) ? round((float) $s['setup'], 2) : ($auto ? self::setupFee($users) : 0.0);
            if ($fee > 0) {
                $lines[] = ['type' => 'setup', 'label' => "Setup & onboarding ({$users} users)", 'amount' => $fee];
            }
        }

        // 4. discount code (never with a negotiated price, never on more-users / AMC orders)
        /** @var Coupon|null $coupon */
        $coupon = $s['coupon'] ?? null;
        if ($coupon) {
            if ($pricing === 'custom') {
                throw new DomainException('bad_coupon', 'A negotiated price already applies to this account, so a discount code can\'t be added.');
            }
            if (in_array($kind, ['seats', 'amc'], true)) {
                throw new DomainException('bad_coupon', 'Discount codes apply to new packages and renewals only.');
            }
            $sub = array_sum(array_column($lines, 'amount'));
            $off = min($coupon->discountOn($sub), $sub);
            $lines[] = ['type' => 'discount', 'label' => "Discount ({$coupon->code})", 'amount' => -round($off, 2)];
        }

        $amount = round(array_sum(array_column($lines, 'amount')), 2);
        if ($amount <= 0) {
            throw new DomainException('zero_price', 'The order total must be more than ₹0. For free access issue an internal licence instead.');
        }
        $rate = (float) PlatformSettings::get('gst_rate') / 100;
        $tax = round($amount * $rate, 2);
        $intra = ($s['state'] ?? null) !== null && $s['state'] === PlatformSettings::get('seller_state');
        $cgst = $intra ? round($tax / 2, 2) : 0.0;
        return [
            'lines' => $lines, 'amount' => $amount, 'discount' => round(-array_sum(array_filter(array_column($lines, 'amount'), fn ($a) => $a < 0)), 2),
            'cgst' => $cgst, 'sgst' => $intra ? round($tax - $cgst, 2) : 0.0, 'igst' => $intra ? 0.0 : $tax, 'total' => round($amount + $tax, 2),
            'list_price' => $standard, 'pricing' => $pricing, 'custom_price_id' => $customId, 'users' => $users, 'billing' => $billing, 'licence_kind' => $lk, 'deployment' => $dep,
        ];
    }

    /** New / first paid / renewal period. */
    private static function period(Plan $plan, string $lk, int $users, ?string $billing, string $what, string $kind): array
    {
        if ($lk === 'perpetual') {
            if ($kind === 'renewal') {
                throw new DomainException('bad_order', 'A perpetual licence never expires. Raise an AMC order for updates and support.');
            }
            return ['perpetual_licence', "{$what} perpetual licence — {$users} users (one-time)", self::perpetualPrice($plan, $users)];
        }
        $rate = self::cloudRate($plan, $users, $billing);
        $m = self::CYCLES[$billing];
        return ['licence', "{$what} subscription — {$users} users × " . ($rate === null ? '—' : '₹' . number_format($rate, 2)) . "/user/month × {$m} months (" . self::CYCLE_LABEL[$billing] . ')',
            $rate === null ? null : round($users * $rate * $m, 2)];
    }

    /** More users (and/or a higher plan) during the current term. Expiry does not change. */
    private static function seats(Plan $plan, int $users, ?string $billing, ?Licence $l, string $what): array
    {
        if (!$l || $l->status !== 'active' || $l->expired()) {
            throw new DomainException('bad_order', 'More users can be added to an active licence only.');
        }
        if (!in_array($l->kind, ['subscription', 'perpetual'], true)) {
            throw new DomainException('bad_order', 'A trial or internal licence is changed by buying a package, not by adding users.');
        }
        $old = $l->plan ?? $plan;
        if ($users < $l->max_users || ($users === $l->max_users && $old->id === $plan->id)) {
            throw new DomainException('bad_order', "The licence already has {$l->max_users} users. Choose more users or a higher plan.");
        }
        if ($l->kind === 'perpetual') {
            $new = self::perpetualPrice($plan, $users);
            $was = self::perpetualPrice($old, $l->max_users);
            $diff = $new === null || $was === null ? null : round($new - $was, 2);
            $label = "{$what} perpetual — from {$l->max_users} to {$users} users" . ($old->id !== $plan->id ? " ({$old->name} → {$plan->name})" : '');
        } else {
            if (!$l->expires_at || $l->expires_at->isPast()) {
                throw new DomainException('bad_order', 'The subscription has ended — renew it instead.');
            }
            $cycle = $billing ?? $l->billing ?? 'annual';
            $rn = self::cloudRate($plan, $users, $cycle);
            $ro = self::cloudRate($old, $l->max_users, $cycle);
            $days = max(1, (int) ceil(now()->diffInDays($l->expires_at, true)));
            $diff = $rn === null || $ro === null ? null : round(($users * $rn - $l->max_users * $ro) * $days / 30.44, 2);
            $label = "{$what} — from {$l->max_users} to {$users} users for the remaining {$days} days" . ($old->id !== $plan->id ? " ({$old->name} → {$plan->name})" : '');
        }
        if ($diff !== null && $diff <= 0) {
            throw new DomainException('bad_order', 'This change does not cost more than the current licence. Contact us to lower a licence.');
        }
        return ['upgrade', $label, $diff];
    }

    private static function amc(?Licence $l): array
    {
        if (!$l || $l->kind !== 'perpetual' || in_array($l->status, ['revoked', 'superseded'], true)) {
            throw new DomainException('bad_order', 'AMC is for perpetual on-premises licences.');
        }
        $p = $l->plan ? self::perpetualPrice($l->plan, $l->max_users) : null;
        $pct = (float) PlatformSettings::get('amc_pct');
        return ['amc', "Annual maintenance (updates & support), 12 months — {$pct}% of the current licence price ({$l->max_users} users)",
            $p === null ? null : round($p * $pct / 100, 2)];
    }

    /** A negotiated price, when this rule applies to this order. Null = rule doesn't apply here. */
    private static function negotiated(CustomPrice $c, ?float $standard, string $kind, string $lk, int $users, ?int $months): ?float
    {
        return match ($c->mode) {
            'percent' => $standard === null ? null : round($standard * (1 - $c->value / 100), 2),
            'fixed' => in_array($kind, ['new', 'upgrade', 'renewal'], true) ? round($c->value, 2) : null,
            'rate' => in_array($kind, ['new', 'upgrade', 'renewal'], true) && $lk === 'subscription' && $months ? round($users * $c->value * $months, 2) : null,
            default => null,
        };
    }
}
