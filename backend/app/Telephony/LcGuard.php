<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\C2c\Vmn;
use App\Models\C2c\Voucher;
use App\Models\IntegrationConnection;
use App\Models\Smart31Number;
use App\Services\Platform\Licensing;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;

/**
 * LC (licence) control for Click2Call and Smart31, on the server — never only in the app or the console.
 *
 * SmartDCM (Common::dailyValidations + License\Helpers\Helper) checks once a day: did_voucher_validate/{code}/{crm} →
 * status ≠ 200 deletes every company number (obd_did), "deleted" numbers go; an expired voucher deletes them too;
 * Smart31 numbers come only from smart31-details/{crm} for the day. ROVEXY does the same, but also:
 *  - asks the LC Manager again at most every TTL seconds — before every Click2Call call and every 5 minutes by the
 *    scheduler — so a voucher revoked in the LC Manager stops working within a minute, not the next day;
 *  - checks every number again at call time (an app, a cached screen or an old session can't use a removed number);
 *  - only uses numbers the LC Manager gave (voucher numbers, today's Smart31 numbers) or that SmartDCM synced
 *    (SmartDCM validates its own LC); numbers typed in by hand don't count where the LC Manager is in charge.
 * If the LC Manager can't be reached, the last confirmed licence counts for GRACE_HOURS, then calling stops.
 */
final class LcGuard
{
    public const TTL = 60;
    public const GRACE_HOURS = 24;

    /** The LC Manager is in charge when the workspace has a CRM id (every Ametecs-managed Click2Call workspace). */
    public static function managed(): bool
    {
        return (bool) VoucherService::crmId();
    }

    /** Ask the LC Manager again (at most every TTL seconds per company, unless forced). Never throws. */
    public static function refresh(bool $force = false): void
    {
        $cid = app(CurrentCompany::class)->id();
        if (!$cid || !self::managed() || !VoucherService::licenceSecret()) {
            return;
        }
        $key = "lc-check:{$cid}";
        if ($force) {
            Cache::put($key, 1, self::TTL);
        } elseif (!Cache::add($key, 1, self::TTL)) {
            return;
        }
        rescue(fn () => app(VoucherService::class)->verify(), null);
    }

    /** May calls go out on this company number now? */
    public static function vmnLicensed(Vmn $v): bool
    {
        if (!$v->click2call || !$v->isAvailable()) {
            return false;
        }
        if ($v->voucher_id) {
            return (bool) Voucher::query()->find($v->voucher_id)?->licensed();
        }
        return !self::managed() || self::smartDcmCalling();   // SmartDCM's own numbers (SmartDCM checks its LC); else typed by hand
    }

    /** May calls go out on this Smart31 number now? */
    public static function smart31Licensed(Smart31Number $n): bool
    {
        $today = now(CurrentCompany::tz())->toDateString();
        if ($n->expires_on && $n->expires_on->toDateString() < $today) {
            return false;
        }
        return match ($n->source) {
            'voucher' => $n->usage_date?->toDateString() === $today && self::smart31Confirmed(),   // the LC Manager gives them per day
            'smartdcm' => self::smartDcmCalling(),
            default => !self::managed(),   // added by hand: only where the LC Manager is not in charge
        };
    }

    /** The LC Manager confirmed today's Smart31 list within the grace period. */
    public static function smart31Confirmed(): bool
    {
        $at = app(CurrentCompany::class)->get()?->setting('click2call.smart31_ok_at');
        return $at && \Illuminate\Support\Carbon::parse($at)->gte(now()->subHours(self::GRACE_HOURS));
    }

    private static function smartDcmCalling(): bool
    {
        $c = IntegrationConnection::query()->where('system', 'smartdcm')->where('enabled', true)->first();
        return $c !== null && $c->option('use_calling', true) && app(Licensing::class)->has('smartdcm');
    }
}
