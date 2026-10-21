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

    /** Calling → Configuration → Click2Call: current settings (the secret only as its last 4 characters). */
    public function click2call(\App\Support\CurrentCompany $current)
    {
        $s = \App\Telephony\Click2CallSettings::stored($current->get());
        $secret = \App\Telephony\Click2CallSettings::effective($current->get())['gateway_secret'] ?? null;
        if ($secret === null && !empty($s['secret'])) {
            try { $secret = \Illuminate\Support\Facades\Crypt::decryptString($s['secret']); } catch (\Throwable) { $secret = null; }
        }
        return response()->json([
            'mode' => $s['mode'] ?? null, 'did' => $s['did'] ?? null, 'agent_caller_id' => $s['agent_caller_id'] ?? null,
            'secret_hint' => $secret ? '••••' . mb_substr($secret, -4) : null,
            'callback_url' => url('/api/telephony/v1/click2call'),
            'server_driver' => config('ammp.telephony.driver'),
            'in_plan' => \App\Telephony\Click2CallSettings::inPlan('click2call'),
            'crm_id' => $s['crm_id'] ?? null, 'lc_secret_set' => (bool) \App\Telephony\VoucherService::licenceSecret(), 'daily_limit' => (int) ($s['daily_limit'] ?? config('ammp.telephony.click2call.daily_limit')),
            'voucher_numbers' => \App\Models\C2c\Vmn::query()->count(),
            'webhook_secret_set' => !empty(config('ammp.telephony.click2call.webhook_secret')),
        ]);
    }

    /** Save Click2Call (blank secret = keep the stored one). Takes effect on the next call. */
    public function saveClick2call(Request $r, \App\Support\CurrentCompany $current)
    {
        $company = $current->get();
        abort_if($r->input('mode') !== 'off' && !\App\Telephony\Click2CallSettings::inPlan('click2call'), 403,
            'Click2Call is not part of your ROVEXY plan. Upgrade under Administration → Plan & licence.');
        $old = \App\Telephony\Click2CallSettings::stored($company);
        $d = $r->validate([
            'mode' => 'required|in:off,only,choice',
            // Company number: optional once numbers come from a voucher (Numbers & vouchers); the fallback otherwise.
            'did' => ['nullable', \Illuminate\Validation\Rule::requiredIf(fn () => $r->input('mode') !== 'off' && !\App\Models\C2c\Vmn::query()->exists()), 'regex:/^\+?[0-9 ]{6,15}$/'],
            'crm_id' => 'nullable|string|max:60', 'lc_secret' => 'nullable|string|max:200', 'daily_limit' => 'nullable|integer|min:1|max:1000',
            'agent_caller_id' => ['nullable', 'regex:/^\+?[0-9 ]{6,15}$/'],
            'gateway_secret' => ['nullable', 'string', 'max:200', \Illuminate\Validation\Rule::requiredIf(fn () => $r->input('mode') !== 'off' && empty($old['secret']))],
        ]);
        $new = ['mode' => $d['mode'], 'did' => isset($d['did']) ? preg_replace('/\D/', '', $d['did']) : ($old['did'] ?? null),
            'agent_caller_id' => isset($d['agent_caller_id']) ? preg_replace('/\D/', '', $d['agent_caller_id']) : null,
            'secret' => filled($d['gateway_secret'] ?? null) ? \Illuminate\Support\Facades\Crypt::encryptString($d['gateway_secret']) : ($old['secret'] ?? null),
            'crm_id' => array_key_exists('crm_id', $d) ? ($d['crm_id'] ?: null) : ($old['crm_id'] ?? null),
            'lc_secret' => filled($d['lc_secret'] ?? null) ? \Illuminate\Support\Facades\Crypt::encryptString($d['lc_secret']) : ($old['lc_secret'] ?? null),
            'daily_limit' => array_key_exists('daily_limit', $d) ? ($d['daily_limit'] ?: null) : ($old['daily_limit'] ?? null)];
        $company->forceFill(['settings' => array_replace($company->settings ?? [], ['click2call' => $new])])->save();
        Audit::record('click2call.saved', $company, ['mode' => $new['mode'], 'did' => $new['did'], 'secret_changed' => filled($d['gateway_secret'] ?? null)]);
        return $this->testClick2call($current);
    }

    /** Checks the settings and asks the gateway once (a made-up call id: "not found" still proves the secret is accepted). */
    public function testClick2call(\App\Support\CurrentCompany $current)
    {
        $cfg = \App\Telephony\Click2CallSettings::effective($current->get());
        if ($cfg === null) {
            return response()->json(['ok' => true, 'detail' => 'Click2Call is off. Phones use SIM calling.']);
        }
        $cfg['did'] = $cfg['did'] ?: \App\Models\C2c\Vmn::query()->where('click2call', true)->value('virtual_number');
        $h = (new \App\Telephony\Click2CallAdapter($cfg))->health();
        if (!$h['ok']) {
            return response()->json($h);
        }
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(8)->acceptJson()->withHeaders(['Secret-Key' => (string) $cfg['gateway_secret']])
                ->get(rtrim((string) $cfg['cdr_url'], '/') . '/connection-test/' . \App\Telephony\Click2CallAdapter::normalize($cfg['did']));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'detail' => 'Call-record gateway unreachable: ' . mb_substr($e->getMessage(), 0, 150)]);
        }
        $ok = !in_array($res->status(), [401, 403], true) && $res->status() < 500;
        return response()->json(['ok' => $ok, 'detail' => $ok
            ? 'Saved. The call-record gateway answered (HTTP ' . $res->status() . '). Place a test call to confirm the call itself.'
            : 'The call-record gateway refused the secret or failed (HTTP ' . $res->status() . '). Check the gateway secret.']);
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
