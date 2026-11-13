<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Platform\PlatformSettings;

/**
 * Estimated Google Maps cost of ROVEXY's usage, per Google billing SKU, from Google's official India price list
 * ("Google Maps Platform core services pricing list – India", developers.google.com/maps/billing-and-pricing/pricing-india,
 * page updated 11 Nov 2026). Each SKU: a free monthly cap, then US$ per 1,000 billable events up to 5,000,000, then the
 * 5,000,000+ price (Google asks for a sales quote above 10,000,000 — flagged, priced at the last tier).
 *
 * The table is data, not code: Super Admin → Google Maps → Prices replaces it (setting maps_prices) when Google changes
 * prices; on-premises installs get the same table inside their signed licence. It is an ESTIMATE: Google bills per billing
 * account (all projects and other software on it share the free caps), in its own month (from midnight US Pacific time),
 * in rupees at its own rate, plus GST. The Google Cloud billing report is the real bill.
 */
final class MapsPricing
{
    public const DEFAULT = [
        'as_of' => '2026-11-11',
        'source' => 'https://developers.google.com/maps/billing-and-pricing/pricing-india',
        'usd_inr' => 88.0,   // for showing rupees only; Google converts at its own rate
        'skus' => [
            'maps_js' => ['google' => 'Dynamic Maps (India)', 'id' => 'AA0F-63D7-9D0E', 'tier' => 'Essentials', 'free' => 70000, 'tiers' => [[5000000, 2.10], [null, 0.53]]],
            'geocode' => ['google' => 'Geocoding (India)', 'id' => 'AB12-DA89-B523', 'tier' => 'Essentials', 'free' => 70000, 'tiers' => [[5000000, 1.50], [null, 0.38]]],
            'routes' => ['google' => 'Routes: Compute Routes Essentials (India)', 'id' => '890F-D2B6-C2EA', 'tier' => 'Essentials', 'free' => 70000, 'tiers' => [[5000000, 1.50], [null, 0.38]]],
            'routes_2w' => ['google' => 'Routes: Compute Routes Enterprise (India)', 'id' => 'BFBE-DDB5-317C', 'tier' => 'Enterprise', 'free' => 7000, 'tiers' => [[5000000, 4.50], [null, 1.14]]],
            'matrix' => ['google' => 'Routes: Compute Route Matrix Essentials (India)', 'id' => 'AE55-A4D2-4177', 'tier' => 'Essentials', 'free' => 70000, 'tiers' => [[5000000, 1.50], [null, 0.38]]],
        ],
    ];

    public const SALES_ABOVE = 10000000;

    /** The price table in force: Super Admin's (cloud), else the one in this install's licence (on-prem), else the built-in one. */
    public static function table(): array
    {
        $raw = \App\Services\Licensing\LicenceClient::isClient()
            ? (app(\App\Services\Licensing\LicenceClient::class)->payload()['maps_prices'] ?? null)
            : rescue(fn () => json_decode((string) PlatformSettings::get('maps_prices', ''), true), null, false);
        return self::valid($raw) ? $raw : self::DEFAULT;
    }

    /** A usable table: every SKU ROVEXY meters, each with a free cap and at least one price tier. */
    public static function valid(mixed $t): bool
    {
        if (!is_array($t) || !is_numeric($t['usd_inr'] ?? null) || !is_array($t['skus'] ?? null)) return false;
        foreach (array_keys(MapsUsage::SKUS) as $sku) {
            $s = $t['skus'][$sku] ?? null;
            if (!is_array($s) || !is_numeric($s['free'] ?? null) || !is_array($s['tiers'] ?? null) || !$s['tiers']) return false;
            foreach ($s['tiers'] as $tier) {
                if (!is_array($tier) || count($tier) !== 2 || !is_numeric($tier[1]) || ($tier[0] !== null && !is_numeric($tier[0]))) return false;
            }
        }
        return true;
    }

    /**
     * US$ for one billing account's $units of one SKU in one Google month: free cap first, then each tier's price per
     * 1,000 events up to the tier's top (null = no top).
     */
    public static function usd(string $sku, int $units, ?array $table = null): float
    {
        $s = ($table ?? self::table())['skus'][$sku];
        $left = $units - (int) $s['free'];
        $from = (int) $s['free'];
        $usd = 0.0;
        foreach ($s['tiers'] as [$top, $price]) {
            if ($left <= 0) break;
            $n = $top === null ? $left : min($left, max(0, (int) $top - $from));
            $usd += $n * (float) $price / 1000;
            $left -= $n;
            $from += $n;
        }
        return round($usd, 2);
    }

    /**
     * Cost lines for one billing account's month: [sku => units] → per SKU {units, free, billable, usd, inr} + total.
     * $pool (optional): the whole billing account's units per SKU when several companies share one account (Ametecs Maps);
     * the account's cost is then shared out in proportion to each company's units.
     */
    public static function estimate(array $units, ?array $pool = null): array
    {
        $t = self::table();
        $lines = [];
        foreach (MapsUsage::SKUS as $sku => $_) {
            $n = (int) ($units[$sku] ?? 0);
            $all = $pool !== null ? max($n, (int) ($pool[$sku] ?? 0)) : $n;
            $usd = $all > 0 ? self::usd($sku, $all, $t) * $n / $all : 0.0;
            $s = $t['skus'][$sku];
            $lines[$sku] = ['sku' => $sku, 'google_sku' => $s['google'] ?? $sku, 'google_sku_id' => $s['id'] ?? null, 'tier' => $s['tier'] ?? null,
                'units' => $n, 'free' => (int) $s['free'], 'billable' => max(0, $all - (int) $s['free']), 'usd' => round($usd, 2),
                'inr' => round($usd * (float) $t['usd_inr'], 2), 'sales_quote' => $all > self::SALES_ABOVE];
        }
        $usd = round(array_sum(array_column($lines, 'usd')), 2);
        return ['lines' => array_values($lines), 'usd' => $usd, 'inr' => round($usd * (float) $t['usd_inr'], 2), 'usd_inr' => (float) $t['usd_inr'],
            'as_of' => $t['as_of'] ?? null, 'source' => $t['source'] ?? null, 'shared_account' => $pool !== null];
    }
}
