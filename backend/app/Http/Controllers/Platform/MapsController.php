<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Platform\Licence;
use App\Services\MapsPricing;
use App\Services\MapsReport;
use App\Services\MapsUsage;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin → Google Maps: every company's Google Maps calls (cloud workspaces counted here, on-premises installs as
 * reported at their licence check-in), estimated cost from Google's price list, and the price table itself.
 * The on/off switch per company is on the licence (Licences → Edit → Google Maps).
 *
 * Billing account: cloud workspaces on an "Ametecs Google Maps" plan (cloud-ametecs…) share Ametecs's Google account, so
 * their free allowance is shared too — their cost is the account's cost shared out by usage. Everyone else (own Maps,
 * on-premises) is costed as their own Google account.
 */
final class MapsController extends Controller
{
    /** GET super/maps/usage?month=YYYY-MM[&format=csv] — company-wise usage and estimated cost for one month. */
    public function index(Request $r)
    {
        $d = $r->validate(['month' => 'nullable|date_format:Y-m', 'format' => 'nullable|in:json,csv']);
        $month = $d['month'] ?? now('Asia/Kolkata')->format('Y-m');
        [$from, $to] = self::range($month);
        $rows = collect(self::entities())->map(function (array $e) use ($from, $to) {
            $t = MapsReport::totals(self::query($e), $from, $to);
            return $e + ['requests' => array_sum(array_column($t, 'requests')), 'failed' => array_sum(array_column($t, 'failed')),
                'ok' => array_sum(array_column($t, 'ok')), 'units' => array_map(fn ($x) => $x['units'], $t)];
        })->filter(fn ($e) => $e['requests'] > 0 || !$e['demo'])->values();

        $pool = self::pool($rows->where('billed_by', 'ametecs')->all());
        $rows = $rows->map(fn ($e) => $e + ['cost' => MapsPricing::estimate($e['units'], $e['billed_by'] === 'ametecs' ? $pool : null)])
            ->sortByDesc(fn ($e) => [$e['cost']['usd'], $e['requests']])->values();

        if (($d['format'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $o = fopen('php://output', 'w');
                fputcsv($o, ['Company', 'Type', 'Google account', 'Maps enabled', 'Requests', 'Successful', 'Failed',
                    ...array_map(fn ($s) => MapsUsage::SKUS[$s][2], array_keys(MapsUsage::SKUS)), 'Estimated US$', 'Estimated ₹']);
                foreach ($rows as $e) fputcsv($o, [$e['name'], $e['type'], $e['billed_by'], $e['maps_enabled'] ? 'yes' : 'no', $e['requests'], $e['ok'], $e['failed'],
                    ...array_values($e['units']), $e['cost']['usd'], $e['cost']['inr']]);
                fclose($o);
            }, "google-maps-usage-{$month}.csv", ['Content-Type' => 'text/csv']);
        }

        // whole platform, per API, and the Ametecs Google account on its own (what Ametecs will be billed)
        $apis = [];
        foreach (MapsUsage::SKUS as $sku => [$label, $unit, $api]) {
            $apis[] = ['sku' => $sku, 'label' => $label, 'unit' => $unit, 'api' => $api, 'units' => $rows->sum(fn ($e) => $e['units'][$sku]),
                'usd' => round($rows->sum(fn ($e) => collect($e['cost']['lines'])->firstWhere('sku', $sku)['usd']), 2)];
        }
        return response()->json(['month' => $month, 'rows' => $rows, 'apis' => $apis,
            'totals' => ['requests' => $rows->sum('requests'), 'ok' => $rows->sum('ok'), 'failed' => $rows->sum('failed'),
                'usd' => round($rows->sum(fn ($e) => $e['cost']['usd']), 2), 'inr' => round($rows->sum(fn ($e) => $e['cost']['inr']), 2)],
            'ametecs_account' => MapsPricing::estimate($pool), 'trend' => self::trend(), 'prices' => ['as_of' => MapsPricing::table()['as_of'] ?? null, 'source' => MapsPricing::table()['source'] ?? null]]);
    }

    /** GET super/maps/usage/{type}/{id}?from&to[&format=csv] — one company: daily, weekly, monthly with cost, yearly. */
    public function show(Request $r, string $type, int $id)
    {
        $d = $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'format' => 'nullable|in:json,csv']);
        $e = collect(self::entities())->first(fn ($x) => $x['type'] === $type && $x['id'] === $id) ?? abort(404);
        $from = $d['from'] ?? now('Asia/Kolkata')->startOfMonth()->toDateString();
        $to = $d['to'] ?? now('Asia/Kolkata')->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400, 422, 'Choose at most about a year.');
        $q = self::query($e);
        $daily = MapsReport::daily($q, $from, $to);
        if (($d['format'] ?? null) === 'csv') {
            return MapsReport::csv('google-maps-usage-' . ($e['slug'] ?? $e['id']) . "-{$from}-to-{$to}.csv", $daily);
        }
        $shared = $e['billed_by'] === 'ametecs'
            ? fn (string $m) => self::pool(collect(self::entities())->where('billed_by', 'ametecs')
                ->map(fn ($x) => $x + ['units' => array_map(fn ($t) => $t['units'], MapsReport::totals(self::query($x), ...self::range($m)))])->all())
            : null;
        $monthly = MapsReport::monthly($q, 12, now('Asia/Kolkata')->format('Y-m'), $shared);
        return response()->json(['entity' => $e, 'from' => $from, 'to' => $to, 'totals' => array_values(MapsReport::totals($q, $from, $to)),
            'daily' => $daily, 'weekly' => MapsReport::weekly($daily), 'monthly' => $monthly, 'yearly' => MapsReport::yearly($monthly)]);
    }

    /** GET super/maps/prices — the table in force, and Google's list as built in. */
    public function prices()
    {
        return response()->json(['table' => MapsPricing::table(), 'built_in' => MapsPricing::DEFAULT,
            'custom' => filled(PlatformSettings::get('maps_prices', '')), 'skus' => MapsUsage::SKUS]);
    }

    /** PUT super/maps/prices {table} (null = back to the built-in list) — when Google changes its prices. */
    public function savePrices(Request $r)
    {
        $d = $r->validate(['table' => 'nullable|array']);
        $t = $d['table'] ?? null;
        abort_if($t !== null && !MapsPricing::valid($t), 422, 'Every API needs a free allowance and at least one price (US$ per 1,000).');
        PlatformSettings::set('maps_prices', $t === null ? '' : json_encode($t));
        PlatformAudit::record('settings.maps_prices', null, ['as_of' => $t['as_of'] ?? 'built-in']);
        return $this->prices();
    }

    /**
     * Who uses Google Maps through ROVEXY: every cloud workspace (with its newest licence) and every on-premises licence.
     * @return list<array{type:string,id:int,name:string,slug:?string,licence_id:?int,maps_enabled:bool,billed_by:string,demo:bool}>
     */
    private static function entities(): array
    {
        $lic = Licence::query()->where('status', '!=', 'superseded')->with('plan:id,code')->latest('id')->get();
        $byCompany = $lic->whereNotNull('company_id')->unique('company_id')->keyBy('company_id');
        $out = [];
        foreach (Company::query()->orderBy('name')->get(['id', 'name', 'slug', 'demo']) as $c) {
            $l = $byCompany[$c->id] ?? null;
            $out[] = ['type' => 'cloud', 'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'licence_id' => $l?->id, 'demo' => $c->demo !== null,
                'maps_enabled' => (bool) ($l?->maps_enabled ?? true), 'billed_by' => str_starts_with((string) $l?->plan?->code, 'cloud-ametecs') ? 'ametecs' : 'own'];
        }
        foreach ($lic->where('deployment', 'onprem')->whereNull('company_id') as $l) {
            $out[] = ['type' => 'onprem', 'id' => $l->id, 'name' => $l->holder_name ?: $l->key, 'slug' => null, 'licence_id' => $l->id, 'demo' => false,
                'maps_enabled' => (bool) $l->maps_enabled, 'billed_by' => 'own'];
        }
        return $out;
    }

    private static function query(array $e): \Illuminate\Database\Query\Builder
    {
        return $e['type'] === 'cloud' ? DB::table('maps_usage_days')->where('company_id', $e['id']) : DB::table('licence_maps_usage')->where('licence_id', $e['id']);
    }

    /** Units per sku of all the given rows together (one shared Google account). */
    private static function pool(array $rows): array
    {
        $p = array_fill_keys(array_keys(MapsUsage::SKUS), 0);
        foreach ($rows as $e) foreach ($e['units'] as $s => $n) $p[$s] += $n;
        return $p;
    }

    private static function range(string $month): array
    {
        $m = Carbon::parse("{$month}-01");
        return [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()];
    }

    /** Last 12 months, all companies: units per API and requests. */
    private static function trend(): array
    {
        $start = now('Asia/Kolkata')->startOfMonth()->subMonthsNoOverflow(11)->toDateString();
        $by = [];
        foreach (['maps_usage_days', 'licence_maps_usage'] as $table) {
            foreach (DB::table($table)->where('day', '>=', $start)->get(['day', 'sku', 'requests', 'units']) as $r) {
                $m = substr((string) $r->day, 0, 7);
                $by[$m] ??= ['month' => $m, 'requests' => 0] + array_fill_keys(array_keys(MapsUsage::SKUS), 0);
                $by[$m]['requests'] += (int) $r->requests;
                if (isset(MapsUsage::SKUS[$r->sku])) $by[$m][$r->sku] += (int) $r->units;
            }
        }
        ksort($by);
        return array_values($by);
    }
}
