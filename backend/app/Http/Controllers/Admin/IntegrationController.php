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
            'secret' => filled($d['gateway_secret'] ?? null) ? \Illuminate\Support\Facades\Crypt::encryptString(self::cleanKey($d['gateway_secret'])) : ($old['secret'] ?? null),
            'crm_id' => array_key_exists('crm_id', $d) ? ($d['crm_id'] ?: null) : ($old['crm_id'] ?? null),
            'lc_secret' => filled($d['lc_secret'] ?? null) ? \Illuminate\Support\Facades\Crypt::encryptString(self::cleanKey($d['lc_secret'])) : ($old['lc_secret'] ?? null),
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
                // A LeadGER-shaped reference (with the CRM id), in case the gateway checks the key against the CRM in the ref_id.
                ->get(rtrim((string) $cfg['cdr_url'], '/') . '/' . rawurlencode('connection-test_d_' . ($cfg['crm_id'] ?: '0') . '_0_1') . '/' . \App\Telephony\Click2CallAdapter::normalize($cfg['did']));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'detail' => 'Call-record gateway unreachable: ' . mb_substr($e->getMessage(), 0, 150)]);
        }
        // The gateway answers HTTP 200 even for a wrong key ({"status":400,"message":"Secret Key not matched"}): read the body.
        if (\App\Telephony\Click2CallAdapter::keyRefused($res->status(), $res->json())) {
            return response()->json(['ok' => false, 'detail' => 'The OBD gateway refused the Gateway secret (…' . mb_substr((string) $cfg['gateway_secret'], -4) . '). '
                . $this->sameKeyHint((string) $cfg['gateway_secret']) . 'Enter the OBD Secret-Key in Gateway secret and Save.']);
        }
        $ok = $res->status() < 500;
        return response()->json(['ok' => $ok, 'detail' => $ok
            ? 'Saved. The OBD gateway accepted the Gateway secret. Place a test call below to confirm the call itself.'
            : 'The call-record gateway failed (HTTP ' . $res->status() . '). Try again later.']);
    }

    /** A key copied from PHP/.env code: drop surrounding spaces and quotes ('abc', "abc", `abc`, trailing , or ;). */
    public static function cleanKey(string $k): string
    {
        return trim(rtrim(trim($k), ',;'), " \t\n\r\0\x0B'\"`");
    }

    /** Where the right key is (OBD guide 9): LeadGER's TELEPHONY_SECRET — not the licence-server (CRM) key. */
    private function sameKeyHint(string $gateway): string
    {
        $crm = (string) \App\Telephony\VoucherService::licenceSecret();
        return 'The OBD key is LeadGER\'s TELEPHONY_SECRET (LeadGER .env TELEPHONY_WEBHOOK_SECRET / config/services.php); it is not the licence-server key used for vouchers'
            . ($crm !== '' && hash_equals($crm, $gateway) ? ', which is what Gateway secret holds now' : '') . '. ';
    }

    /**
     * Calling options → Test call: the admin types their own phone and a customer number; a real Click2Call call is placed
     * (rings the admin first, then the customer from the company number) and follows the normal result + recording path.
     */
    public function testCall(Request $r, \App\Support\CurrentCompany $current)
    {
        $cfg = \App\Telephony\Click2CallSettings::effective($current->get());
        abort_if($cfg === null, 422, 'Switch Click2Call on and save first.');
        $d = $r->validate(['agent_number' => ['required', 'regex:/^\+?[0-9 ]{10,15}$/'], 'customer_number' => ['required', 'regex:/^\+?[0-9 ]{10,15}$/'],
            'from' => ['nullable', 'regex:/^\+?[0-9 ]{6,15}$/']]);
        // Call from: one of the company's Click2Call numbers (voucher numbers first, as real calls use them), never a free-typed one.
        $numbers = collect($this->testCallNumberList($current))->where('available', true);
        $from = isset($d['from']) ? $numbers->firstWhere('number', \App\Telephony\Click2CallAdapter::normalize($d['from'])) : $numbers->first();
        abort_if(!$from, 422, isset($d['from']) ? 'That number is not one of your Click2Call numbers (or it has expired).' : 'No Click2Call number: add a voucher (Company numbers) or a company number above.');
        $cfg['did'] = $from['number'];
        $adapter = new \App\Telephony\Click2CallAdapter($cfg);
        $h = $adapter->health();
        if (!$h['ok']) {
            return response()->json($h + ['message' => $h['detail']], 422);
        }
        $customer = \App\Telephony\Click2CallAdapter::normalize($d['customer_number']);
        $call = \Illuminate\Support\Facades\DB::transaction(function () use ($r, $customer) {
            $call = \App\Models\Call::query()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'click2call', 'user_id' => $r->user()->id, 'device_id' => null, 'is_test' => true,
                'destination' => strlen($customer) === 10 ? '+91' . $customer : '+' . $customer, 'agent_leg' => 'pstn_callback',
                'state' => 'initiated', 'initiated_at' => now(), 'recording_status' => \App\Domain\Calling\RecordingState::Pending->value,
            ]);
            \App\Models\Recording::query()->create(['call_id' => $call->id, 'status' => \App\Domain\Calling\RecordingState::Pending->value]);
            return $call;
        });
        try {
            $adapter->originate($call, ['caller_number' => $cfg['did'], 'agent_target' => $d['agent_number'], 'customer_e164' => $d['customer_number']]);
        } catch (\App\Telephony\TelephonyUnavailable $e) {
            $call->forceFill(['state' => 'failed', 'hangup_cause' => 'C2C_TEST_NOT_PLACED', 'ended_at' => now(), 'recording_status' => 'failed'])->save();
            \App\Models\Recording::query()->where('call_id', $call->id)->update(['status' => 'failed', 'last_error' => 'call not placed']);
            return response()->json(['ok' => false, 'call_id' => $call->id, 'detail' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        }
        Audit::record('click2call.test_call', $call, ['caller_number' => $call->caller_number]);
        return response()->json(['ok' => true, 'call_id' => $call->id, 'detail' => 'Your phone will ring now. Answer it; the customer is called next.']);
    }

    /**
     * Test call → Hang up. Click2Call has no hang-up API (as in LeadGER): before the customer is dialled the call is closed
     * here (the admin's own phone stops ringing when they reject it); once the customer rings, the call is cut on the phone.
     */
    public function testCallHangup(int $id)
    {
        $call = \App\Models\Call::query()->whereKey($id)->where('provider', 'click2call')->where('is_test', true)->firstOrFail();
        if ($call->isTerminal()) {
            return response()->json(['ok' => true, 'state' => $call->state]);
        }
        if (!in_array($call->state, ['initiated', 'agent_ringing'], true)) {
            return response()->json(['ok' => false, 'message' => 'The customer is already being called. Cut the call on your phone; the result and recording will show here.'], 409);
        }
        $call->forceFill(['state' => 'cancelled', 'failure_reason' => 'hung_up_before_answer', 'ended_at' => now(),
            'recording_status' => \App\Domain\Calling\RecordingState::NotRequired->value])->save();
        \App\Models\Recording::query()->where('call_id', $call->id)->update(['status' => \App\Domain\Calling\RecordingState::NotRequired->value]);
        Audit::record('click2call.test_call_cancelled', $call);
        return response()->json(['ok' => true, 'state' => 'cancelled']);
    }

    /** Calling configuration → Test calls: every test call with its result and recording (open ones are refreshed first). */
    public function testCalls(Request $r, \App\Services\CallService $svc)
    {
        \App\Models\Call::query()->where('is_test', true)->where('initiated_at', '>', now()->subHours(3))
            ->where(fn ($q) => $q->whereNotIn('state', \App\Domain\Calling\CallLifecycle::TERMINAL)->orWhere('recording_status', 'pending'))
            ->latest('initiated_at')->limit(5)->get()->each(fn ($c) => $svc->syncProvider($c));
        return response()->json(\App\Models\Call::query()->where('is_test', true)
            ->with(['user:id,name', 'recording:id,call_id,format,duration_seconds,last_error'])
            ->latest('initiated_at')->latest('id')->paginate(min(100, max(10, (int) $r->query('per_page', 25))),
                ['id', 'user_id', 'caller_number', 'destination', 'state', 'hangup_cause', 'failure_reason', 'initiated_at', 'ended_at', 'talk_seconds', 'recording_status']));
    }

    /** Numbers a test call can go out on. */
    public function testCallNumbers(\App\Support\CurrentCompany $current)
    {
        return response()->json(['numbers' => $this->testCallNumberList($current)]);
    }

    /** @return list<array{number:string,source:string,available:bool,expires:?string}> voucher numbers, then the typed company number */
    private function testCallNumberList(\App\Support\CurrentCompany $current): array
    {
        $out = \App\Models\C2c\Vmn::query()->where('click2call', true)->orderBy('virtual_number')->get()
            ->map(fn ($v) => ['number' => \App\Telephony\Click2CallAdapter::normalize($v->virtual_number), 'source' => 'voucher',
                'available' => $v->isAvailable(), 'expires' => $v->expires_at?->toDateString()])->unique('number')->values()->all();
        $typed = \App\Telephony\Click2CallAdapter::normalize(\App\Telephony\Click2CallSettings::stored($current->get())['did'] ?? null);
        if ($typed !== '' && !in_array($typed, array_column($out, 'number'), true)) {
            $out[] = ['number' => $typed, 'source' => 'company', 'available' => true, 'expires' => null];
        }
        return $out;
    }

    /** Test call progress: asks the gateway (throttled) and returns the call's state, cause and recording state. */
    public function testCallStatus(int $id, \App\Services\CallService $svc)
    {
        $call = \App\Models\Call::query()->whereKey($id)->where('provider', 'click2call')->where('is_test', true)->firstOrFail();
        $call = $svc->syncProvider($call)->refresh();   // refresh: the recording job may have finished after commit
        // Still no result: ask the gateway once more and say WHY it gives nothing (wrong key, no record yet …) instead of
        // leaving the admin on "ringing" for 15 minutes.
        $gateway = null;
        // At most one extra gateway read per 15 s (the page polls every 3 s; a single-threaded dev server would queue them).
        if (!$call->isTerminal() && in_array($call->state, ['initiated', 'agent_ringing'], true) && \Illuminate\Support\Facades\Cache::add("test-call-probe:{$call->id}", 1, 15)
            && ($cfg = \App\Telephony\Click2CallSettings::effective())) {
            $probe = new \App\Telephony\Click2CallAdapter($cfg);
            if ($probe->details($call) === null && ($why = $probe->lastProblem()) !== '') {
                $notYet = str_starts_with($why, 'HTTP200_S') && stripos($probe->lastProblemText(), 'not found') !== false;
                $gateway = ['code' => $why, 'tone' => $notYet ? 'info' : 'warn', 'text' => $notYet
                    ? 'Call placed. The provider sends the result to the OBD gateway when the call ends; the steps and the recording appear here then.'
                    : match (true) {
                    $why === 'KEY_REFUSED' || in_array($why, ['HTTP401', 'HTTP403'], true) || str_ends_with($why, '_S401') || str_ends_with($why, '_S403')
                        => 'The call was placed, but the OBD gateway refused the Gateway secret (…' . mb_substr((string) $cfg['gateway_secret'], -4) . '), so ROVEXY cannot read the result or recording. '
                            . $this->sameKeyHint((string) $cfg['gateway_secret']) . 'Enter the OBD Secret-Key in Click2Call connection → Gateway secret and Save.',
                    $why === 'UNREACHABLE' => 'The OBD gateway (obd.ametecsindia.com) did not answer this server in time; ROVEXY keeps retrying.',
                    $why === 'NO_URL' => 'No call-record gateway address is configured (C2C_CDR_URL).',
                    default => 'The OBD gateway has no record for this call yet (' . $why . '). If the call already ended, the provider has not sent it to the gateway.',
                } . (($t = $probe->lastProblemText()) !== '' ? ' Gateway says: "' . $t . '"' : '')
                  . (empty($cfg['crm_id']) ? ' No CRM id is set in Click2Call connection, so the reference is sent without it.' : '')];
            }
        }
        return response()->json(['call_id' => $call->id, 'state' => $call->state, 'terminal' => $call->isTerminal(), 'cause' => $call->hangup_cause,
            'talk_seconds' => $call->talk_seconds, 'recording_status' => $call->recording_status, 'gateway' => $gateway]);
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
