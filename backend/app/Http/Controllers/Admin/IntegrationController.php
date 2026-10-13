<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\FieldForceIntegration;
use App\Services\GoogleMaps;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings → Integrations: connect this company to SmartPRS2 and SmartEPT. */
final class IntegrationController extends Controller
{
    public function index()
    {
        $conns = IntegrationConnection::query()->get()->keyBy('system');
        $queue = DB::table('integration_outbox')->where('company_id', app(\App\Support\CurrentCompany::class)->id())
            // `system` is a MySQL 8 reserved word — select() lets the builder quote it.
            ->select('system', 'status')->selectRaw('count(*) as n')->groupBy('system', 'status')->get();
        return response()->json(collect(IntegrationConnection::SYSTEMS)->map(function (string $s) use ($conns, $queue) {
            $c = $conns->get($s);
            return [
                'system' => $s,
                'configured' => $c !== null,
                'base_url' => $c?->base_url,
                'api_key_hint' => $c ? '••••' . mb_substr($c->api_key, -4) : null,
                'enabled' => (bool) $c?->enabled,
                'enforce_geofence' => (bool) ($c?->enforce_geofence ?? true),
                'last_status' => $c?->last_status,
                'last_ok_at' => $c?->last_ok_at?->toAtomString(),
                'queue' => $queue->where('system', $s)->pluck('n', 'status'),
            ];
        })->values());
    }

    public function save(Request $r, string $system)
    {
        abort_unless(in_array($system, IntegrationConnection::SYSTEMS, true), 404);
        $c = IntegrationConnection::query()->firstOrNew(['system' => $system]);
        $data = $r->validate([
            'base_url' => ['required', 'url:http,https', 'max:255'],
            'api_key' => [$c->exists ? 'nullable' : 'required', 'string', 'max:200'],   // blank on edit = keep the stored key
            'enabled' => 'boolean',
            'enforce_geofence' => 'boolean',
        ]);
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }
        $c->fill($data)->save();
        Audit::record('integration.saved', $c, ['system' => $system, 'base_url' => $c->base_url, 'enabled' => $c->enabled, 'key_changed' => isset($data['api_key'])]);
        return response()->json(app(FieldForceIntegration::class)->test($c));
    }

    public function test(string $system, FieldForceIntegration $ff)
    {
        $c = IntegrationConnection::query()->where('system', $system)->firstOrFail();
        return response()->json($ff->test($c));
    }

    /** Integrations → Google Maps: which keys are stored (last 4 characters only). */
    public function maps()
    {
        $rows = IntegrationConnection::query()->whereIn('system', [GoogleMaps::SERVER, GoogleMaps::DISPLAY])->get()->keyBy('system');
        $hint = fn ($c) => $c && filled($c->api_key) ? '••••' . mb_substr($c->api_key, -4) : null;
        return response()->json([
            'key_hint' => $hint($rows->get(GoogleMaps::SERVER)), 'display_key_hint' => $hint($rows->get(GoogleMaps::DISPLAY)),
            'enabled' => (bool) ($rows->get(GoogleMaps::SERVER)?->enabled ?? false), 'last_status' => $rows->get(GoogleMaps::SERVER)?->last_status,
        ]);
    }

    /** Save the Google Maps keys (blank = keep; remove_display_key clears the optional display key), then test the server key. */
    public function saveMaps(Request $r, GoogleMaps $maps)
    {
        $d = $r->validate(['api_key' => 'nullable|string|max:200', 'display_key' => 'nullable|string|max:200', 'remove_display_key' => 'boolean', 'enabled' => 'boolean']);
        $server = IntegrationConnection::query()->firstOrNew(['system' => GoogleMaps::SERVER]);
        abort_if(!$server->exists && blank($d['api_key'] ?? null), 422, 'Enter the Google Maps API key.');
        $server->fill(['base_url' => 'https://maps.googleapis.com', 'enabled' => $d['enabled'] ?? true] + (filled($d['api_key'] ?? null) ? ['api_key' => trim($d['api_key'])] : []));
        $test = $maps->test((string) $server->api_key);
        $server->last_status = mb_substr(($test['ok'] ? 'OK ' : 'FAILED ') . $test['detail'], 0, 255);
        if ($test['ok']) $server->last_ok_at = now();
        $server->save();
        if (!empty($d['remove_display_key'])) {
            IntegrationConnection::query()->where('system', GoogleMaps::DISPLAY)->delete();
        } elseif (filled($d['display_key'] ?? null)) {
            IntegrationConnection::query()->updateOrCreate(['system' => GoogleMaps::DISPLAY], ['base_url' => 'https://maps.googleapis.com', 'api_key' => trim($d['display_key']), 'enabled' => true]);
        }
        Audit::record('integration.saved', $server, ['system' => 'google_maps', 'key_changed' => filled($d['api_key'] ?? null),
            'display_key_changed' => filled($d['display_key'] ?? null) || !empty($d['remove_display_key']), 'enabled' => $server->enabled]);
        return response()->json($test);
    }

    /** Push anything waiting now, instead of on the next scheduler tick. */
    public function flush(FieldForceIntegration $ff, \App\Support\CurrentCompany $ctx)
    {
        DB::table('integration_outbox')->where('company_id', $ctx->id())->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null]);   // admin retry includes rejected batches
        $ff->flushCompany($ctx->get());
        return $this->index();
    }
}
