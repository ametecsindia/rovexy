<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * G12: every billable Google call this server makes is counted per company per month and checked against the company's
 * monthly limit first. Defaults are Google's free monthly allowance in India (14 Oct 2026 price list), so out of the box a
 * client is never billed; an admin may raise a limit in Integrations → Google Maps. When a limit is reached the feature
 * falls back (straight-line distances, no new pins, straight-line plan order) instead of costing money.
 *
 * Google counts per billing account; this meter counts what ROVEXY sends. If the client's Google account is also used
 * by other software, its free allowance is shared and Google's numbers will be higher.
 */
final class MapsUsage
{
    /** sku => [label, default monthly limit (= free allowance), unit] */
    public const SKUS = [
        'geocode' => ['Address → pin (Geocoding)', 70000, 'requests'],
        'matrix' => ['Road distances (Route Matrix)', 70000, 'elements'],
        'routes' => ['Routes on the phone (Compute Routes)', 70000, 'requests'],
        'routes_2w' => ['Day plans, two-wheeler (Compute Routes Enterprise)', 7000, 'requests'],
    ];

    public static function month(): string
    {
        return now()->format('Y-m');
    }

    /** The company's limits (its own setting, else the free allowance). */
    public static function limits(): array
    {
        $set = (array) app(CurrentCompany::class)->get()->setting('maps_limits', []);
        return array_map(fn ($sku) => max(0, (int) ($set[$sku] ?? self::SKUS[$sku][1])), array_combine(array_keys(self::SKUS), array_keys(self::SKUS)));
    }

    public static function used(): array
    {
        $rows = DB::table('maps_usage')->where('company_id', app(CurrentCompany::class)->id())->where('month', self::month())->pluck('used', 'sku');
        return array_map(fn ($sku) => (int) ($rows[$sku] ?? 0), array_combine(array_keys(self::SKUS), array_keys(self::SKUS)));
    }

    /**
     * Reserve $n units before calling Google: refused (DomainException 'maps_limit') when it would pass the limit.
     * Counted before the call, so a failed call still counts — Google bills most failures too.
     */
    public static function take(string $sku, int $n = 1): void
    {
        if ($n <= 0) return;
        $limit = self::limits()[$sku];
        $key = ['company_id' => app(CurrentCompany::class)->id(), 'month' => self::month(), 'sku' => $sku];
        DB::transaction(function () use ($key, $n, $limit, $sku) {
            DB::table('maps_usage')->insertOrIgnore($key + ['used' => 0]);
            $used = (int) DB::table('maps_usage')->where($key)->lockForUpdate()->value('used');
            if ($used + $n > $limit) {
                throw new DomainException('maps_limit', match ($sku) {
                    'geocode' => 'This month\'s limit for address look-ups is reached (admin: Integrations → Google Maps).',
                    'routes_2w' => 'This month\'s limit for optimised day plans is reached. The plan keeps the straight-line order.',
                    default => 'This month\'s Google Maps limit is reached. Straight-line distance is shown instead.',
                });
            }
            DB::table('maps_usage')->where($key)->increment('used', $n);
        });
    }

    /** For the admin page: one row per service with used / limit / free allowance. */
    public static function report(): array
    {
        $used = self::used();
        $limits = self::limits();
        return array_map(fn ($sku) => ['sku' => $sku, 'label' => self::SKUS[$sku][0], 'unit' => self::SKUS[$sku][2], 'used' => $used[$sku],
            'limit' => $limits[$sku], 'free' => self::SKUS[$sku][1]], array_keys(self::SKUS));
    }
}
