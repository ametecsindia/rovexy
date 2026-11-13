<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * G12 + Maps service management (13 Nov 2026): every Google Maps call ROVEXY makes is counted per company, per day (company
 * time zone), per Google API — requests sent, billable units (Route Matrix bills per element) and failures — and checked
 * against the company's monthly limit first. Default limits are Google's free monthly allowance (MapsPricing), so out of the
 * box a client is never billed; an admin may change a limit in Integrations → Google Maps. When a limit is reached the
 * feature falls back (straight-line distances, no new pins, straight-line plan order, no map) instead of costing money.
 *
 * Answers served from ROVEXY's cache never reach Google and are not counted. Google counts per billing account; this meter
 * counts what ROVEXY sends. If the client's Google account is also used by other software, Google's numbers will be higher.
 */
final class MapsUsage
{
    /** sku => [label, unit, Google API]. Only the APIs ROVEXY actually calls. */
    public const SKUS = [
        'geocode' => ['Address → pin', 'requests', 'Geocoding API'],
        'routes' => ['Routes on the phone', 'requests', 'Routes API (Compute Routes)'],
        'routes_2w' => ['Optimised day plans, two-wheeler', 'requests', 'Routes API (Compute Routes, Enterprise)'],
        'matrix' => ['Road distances to nearest customers', 'distance calculations', 'Routes API (Compute Route Matrix)'],
        'maps_js' => ['Map loads on the phone', 'loads', 'Maps JavaScript API'],
    ];

    public static function tz(): string
    {
        return app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata';
    }

    public static function today(): string
    {
        return now(self::tz())->toDateString();
    }

    public static function month(): string
    {
        return now(self::tz())->format('Y-m');
    }

    /** The company's limits (its own setting, else Google's free allowance). */
    public static function limits(): array
    {
        $set = (array) app(CurrentCompany::class)->get()->setting('maps_limits', []);
        $free = MapsPricing::table()['skus'];
        $out = [];
        foreach (self::SKUS as $sku => $_) $out[$sku] = max(0, (int) ($set[$sku] ?? $free[$sku]['free']));
        return $out;
    }

    /** Billable units this month, per sku. */
    public static function used(?string $month = null): array
    {
        $m = Carbon::parse(($month ?? self::month()) . '-01');
        $rows = self::rows()->whereBetween('day', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])
            ->groupBy('sku')->selectRaw('sku, SUM(units) AS u')->pluck('u', 'sku');
        $out = [];
        foreach (self::SKUS as $sku => $_) $out[$sku] = (int) ($rows[$sku] ?? 0);
        return $out;
    }

    /**
     * Reserve $n units before calling Google: refused (DomainException 'maps_limit') when it would pass the month's limit.
     * Counted before the call, so a failed call still counts — Google bills most failures too; failed() marks it.
     */
    public static function take(string $sku, int $n = 1): void
    {
        if ($n <= 0) return;
        $limit = self::limits()[$sku];
        DB::transaction(function () use ($sku, $n, $limit) {
            $key = self::key($sku);
            DB::table('maps_usage_days')->insertOrIgnore($key + ['requests' => 0, 'units' => 0, 'failed' => 0]);
            DB::table('maps_usage_days')->where($key)->lockForUpdate()->value('id');   // one reservation at a time per company + API
            if (self::used()[$sku] + $n > $limit) {
                throw new DomainException('maps_limit', match ($sku) {
                    'geocode' => 'This month\'s limit for address look-ups is reached (admin: Integrations → Google Maps).',
                    'routes_2w' => 'This month\'s limit for optimised day plans is reached. The plan keeps the straight-line order.',
                    'maps_js' => 'This month\'s limit for map loads is reached (admin: Integrations → Google Maps).',
                    default => 'This month\'s Google Maps limit is reached. Straight-line distance is shown instead.',
                });
            }
            DB::table('maps_usage_days')->where($key)->incrementEach(['requests' => 1, 'units' => $n]);
        });
    }

    /** Count a call without a limit check (the admin's "Save & test"). */
    public static function count(string $sku, int $n = 1, bool $failed = false): void
    {
        $key = self::key($sku);
        DB::table('maps_usage_days')->insertOrIgnore($key + ['requests' => 0, 'units' => 0, 'failed' => 0]);
        DB::table('maps_usage_days')->where($key)->incrementEach(['requests' => 1, 'units' => $n] + ($failed ? ['failed' => 1] : []));
    }

    /** The call reserved with take() did not succeed (Google refused it, or Google could not be reached). */
    public static function failed(string $sku): void
    {
        DB::table('maps_usage_days')->where(self::key($sku))->increment('failed');
    }

    /** Daily rows between two dates (inclusive): [{day, sku, requests, units, failed}]. */
    public static function days(string $from, string $to): array
    {
        return self::rows()->whereBetween('day', [$from, $to])->orderBy('day')->get(['day', 'sku', 'requests', 'units', 'failed'])
            ->map(fn ($r) => ['day' => substr((string) $r->day, 0, 10), 'sku' => $r->sku, 'requests' => (int) $r->requests, 'units' => (int) $r->units, 'failed' => (int) $r->failed])->all();
    }

    /** What an on-prem install reports at its licence check-in: the last 40 days. */
    public static function report40(): array
    {
        return self::days(now(self::tz())->subDays(40)->toDateString(), self::today());
    }

    /** For the admin page: one row per API with this month's used / limit / free allowance / remaining. */
    public static function report(): array
    {
        $used = self::used();
        $limits = self::limits();
        $free = MapsPricing::table()['skus'];
        return array_map(fn ($sku) => ['sku' => $sku, 'label' => self::SKUS[$sku][0], 'unit' => self::SKUS[$sku][1], 'api' => self::SKUS[$sku][2],
            'used' => $used[$sku], 'limit' => $limits[$sku], 'free' => (int) $free[$sku]['free'], 'remaining' => max(0, $limits[$sku] - $used[$sku])], array_keys(self::SKUS));
    }

    /** Licence server: store what an install reported (a count never goes down). */
    public static function recordReported(int $licenceId, mixed $rows): void
    {
        foreach (is_array($rows) ? array_slice($rows, 0, 500) : [] as $r) {
            if (!is_array($r) || !isset(self::SKUS[$r['sku'] ?? '']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['day'] ?? ''))) continue;
            $key = ['licence_id' => $licenceId, 'day' => $r['day'], 'sku' => $r['sku']];
            $old = DB::table('licence_maps_usage')->where($key)->first();
            $vals = [];
            foreach (['requests', 'units', 'failed'] as $f) $vals[$f] = max((int) ($old->$f ?? 0), min(100000000, max(0, (int) ($r[$f] ?? 0))));
            $old ? DB::table('licence_maps_usage')->where($key)->update($vals) : DB::table('licence_maps_usage')->insert($key + $vals);
        }
    }

    private static function key(string $sku): array
    {
        return ['company_id' => app(CurrentCompany::class)->id(), 'day' => self::today(), 'sku' => $sku];
    }

    private static function rows(): \Illuminate\Database\Query\Builder
    {
        return DB::table('maps_usage_days')->where('company_id', app(CurrentCompany::class)->id());
    }
}
