<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Google Maps usage reports from daily rows (maps_usage_days for a workspace, licence_maps_usage for an on-prem install):
 * per-API totals, daily / weekly / monthly series and the estimated cost of each month (MapsPricing). Used by the client's
 * Integrations page and by Super Admin → Google Maps.
 */
final class MapsReport
{
    /** Per API: requests, billable units, failed, successful — between two dates (inclusive). */
    public static function totals(Builder $q, string $from, string $to): array
    {
        $rows = (clone $q)->whereBetween('day', [$from, $to])->groupBy('sku')
            ->selectRaw('sku, SUM(requests) r, SUM(units) u, SUM(failed) f')->get()->keyBy('sku');
        $out = [];
        foreach (MapsUsage::SKUS as $sku => [$label, $unit, $api]) {
            $r = $rows[$sku] ?? null;
            $out[$sku] = ['sku' => $sku, 'label' => $label, 'unit' => $unit, 'api' => $api, 'requests' => (int) ($r->r ?? 0),
                'units' => (int) ($r->u ?? 0), 'failed' => (int) ($r->f ?? 0), 'ok' => max(0, (int) ($r->r ?? 0) - (int) ($r->f ?? 0))];
        }
        return $out;
    }

    /** One row per day: {day, requests, failed, <sku>: units…}. */
    public static function daily(Builder $q, string $from, string $to): array
    {
        $days = [];
        foreach ((clone $q)->whereBetween('day', [$from, $to])->orderBy('day')->get(['day', 'sku', 'requests', 'units', 'failed']) as $r) {
            $d = substr((string) $r->day, 0, 10);
            $days[$d] ??= ['day' => $d, 'requests' => 0, 'failed' => 0] + array_fill_keys(array_keys(MapsUsage::SKUS), 0);
            $days[$d]['requests'] += (int) $r->requests;
            $days[$d]['failed'] += (int) $r->failed;
            if (isset(MapsUsage::SKUS[$r->sku])) $days[$d][$r->sku] += (int) $r->units;
        }
        return array_values($days);
    }

    /** Daily rows folded into ISO weeks (Monday start). */
    public static function weekly(array $daily): array
    {
        $weeks = [];
        foreach ($daily as $d) {
            $w = Carbon::parse($d['day'])->startOfWeek()->toDateString();
            $weeks[$w] ??= ['week' => $w, 'requests' => 0, 'failed' => 0] + array_fill_keys(array_keys(MapsUsage::SKUS), 0);
            foreach ($d as $k => $v) if ($k !== 'day') $weeks[$w][$k] += $v;
        }
        return array_values($weeks);
    }

    /**
     * The last $months months (oldest first): {month, requests, failed, units: {sku: n}, cost: MapsPricing::estimate}.
     * $pool(month) → the whole Google billing account's units per sku, when the account is shared (Ametecs Maps).
     */
    public static function monthly(Builder $q, int $months = 12, ?string $upTo = null, ?callable $pool = null): array
    {
        $end = Carbon::parse(($upTo ?? now()->format('Y-m')) . '-01');
        $start = $end->copy()->subMonthsNoOverflow($months - 1);
        $by = [];
        foreach ((clone $q)->whereBetween('day', [$start->toDateString(), $end->copy()->endOfMonth()->toDateString()])->get(['day', 'sku', 'requests', 'units', 'failed']) as $r) {
            $m = substr((string) $r->day, 0, 7);
            $by[$m]['requests'] = ($by[$m]['requests'] ?? 0) + (int) $r->requests;
            $by[$m]['failed'] = ($by[$m]['failed'] ?? 0) + (int) $r->failed;
            $by[$m]['units'][$r->sku] = ($by[$m]['units'][$r->sku] ?? 0) + (int) $r->units;
        }
        $out = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonthNoOverflow()) {
            $k = $m->format('Y-m');
            $units = array_merge(array_fill_keys(array_keys(MapsUsage::SKUS), 0), array_intersect_key($by[$k]['units'] ?? [], MapsUsage::SKUS));
            $out[] = ['month' => $k, 'requests' => $by[$k]['requests'] ?? 0, 'failed' => $by[$k]['failed'] ?? 0, 'units' => $units,
                'cost' => MapsPricing::estimate($units, $pool ? $pool($k) : null)];
        }
        return $out;
    }

    /** Totals of monthly() rows per calendar year: {year, requests, failed, units, usd, inr}. */
    public static function yearly(array $monthly): array
    {
        $y = [];
        foreach ($monthly as $m) {
            $k = substr($m['month'], 0, 4);
            $y[$k] ??= ['year' => $k, 'requests' => 0, 'failed' => 0, 'units' => array_fill_keys(array_keys(MapsUsage::SKUS), 0), 'usd' => 0.0, 'inr' => 0.0];
            $y[$k]['requests'] += $m['requests'];
            $y[$k]['failed'] += $m['failed'];
            foreach ($m['units'] as $s => $n) $y[$k]['units'][$s] += $n;
            $y[$k]['usd'] = round($y[$k]['usd'] + $m['cost']['usd'], 2);
            $y[$k]['inr'] = round($y[$k]['inr'] + $m['cost']['inr'], 2);
        }
        return array_values($y);
    }

    /** CSV of daily rows (Excel opens it). */
    public static function csv(string $name, array $daily): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($daily) {
            $o = fopen('php://output', 'w');
            fputcsv($o, ['Date', 'Requests', 'Failed', ...array_map(fn ($s) => MapsUsage::SKUS[$s][2] . ' (' . MapsUsage::SKUS[$s][1] . ')', array_keys(MapsUsage::SKUS))]);
            foreach ($daily as $d) fputcsv($o, [$d['day'], $d['requests'], $d['failed'], ...array_map(fn ($s) => $d[$s], array_keys(MapsUsage::SKUS))]);
            fclose($o);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
