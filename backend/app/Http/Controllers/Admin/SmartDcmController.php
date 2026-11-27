<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSmartDcm;
use App\Models\ExternalRef;
use App\Models\IntegrationConnection;
use App\Services\FieldForceIntegration;
use App\Services\SmartDcm\SmartDcmClient;
use App\Services\SmartDcm\SmartDcmSync;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Administration → SmartDCM: connect this company to its SmartDCM, see what was synced, and what waits to go back. */
final class SmartDcmController extends Controller
{
    public const OPTIONS = [
        'auto_create_users' => true,   // SmartDCM users not found in ROVEXY are created (telecallers, FOS, team leaders, managers)
        'share_passwords' => true,     // same password as SmartDCM (when SmartDCM sends it)
        'use_calling' => true,         // CRM id, company Click2Call numbers and Smart31 pool from SmartDCM
        'send_results' => true,        // outcomes, PTP, payments, calls, visits, notes back to SmartDCM
        'send_allocations' => true,    // re-allocations done in ROVEXY back to SmartDCM
    ];

    public function show(CurrentCompany $ctx)
    {
        $c = $this->conn();
        $queue = DB::table('integration_outbox')->where('company_id', $ctx->id())->where('system', SmartDcmSync::SYSTEM)
            ->select('status')->selectRaw('count(*) as n')->groupBy('status')->pluck('n', 'status');
        $linked = ExternalRef::query()->where('system', SmartDcmSync::SYSTEM)->select('entity')->selectRaw('count(*) as n')->groupBy('entity')->pluck('n', 'entity');
        return response()->json([
            'in_plan' => app(\App\Services\Platform\Licensing::class)->has('smartdcm'),
            'configured' => $c !== null, 'base_url' => $c?->base_url, 'api_key_hint' => $c ? '••••' . mb_substr($c->api_key, -4) : null,
            'enabled' => (bool) $c?->enabled, 'options' => array_replace(self::OPTIONS, (array) ($c?->options ?? [])),
            'last_status' => $c?->last_status, 'last_ok_at' => $c?->last_ok_at?->toAtomString(),
            'synced_at' => $c?->state['synced_at'] ?? null, 'crm_id' => $c?->state['crm_id'] ?? null,
            'unmatched_users' => array_values(array_map(fn ($id, $u) => ['id' => (string) $id] + (array) $u, array_keys((array) ($c?->state['unmatched_users'] ?? [])), (array) ($c?->state['unmatched_users'] ?? []))),
            'linked' => ['teams' => (int) ($linked['team'] ?? 0), 'users' => (int) ($linked['user'] ?? 0), 'portfolios' => (int) ($linked['portfolio'] ?? 0), 'cases' => (int) ($linked['case'] ?? 0)],
            'queue' => $queue,
            'inbound' => $c?->uuid ? ['webhook_url' => route('smartdcm.webhook', ['uuid' => $c->uuid]),
                'recording_url' => str_replace('CALL', '{call_id}', route('smartdcm.recording', ['uuid' => $c->uuid, 'call' => 'CALL'])),
                'secret_hint' => $c->inbound_secret ? '••••' . mb_substr($c->inbound_secret, -4) : null] : null,
        ]);
    }

    public function save(Request $r)
    {
        abort_unless(app(\App\Services\Platform\Licensing::class)->has('smartdcm'), 403, 'SmartDCM integration is not part of your ROVEXY plan. Contact Ametecs to add it.');
        $c = IntegrationConnection::query()->firstOrNew(['system' => SmartDcmSync::SYSTEM]);
        $d = $r->validate([
            'base_url' => ['required', 'url:http,https', 'max:255'],
            'api_key' => [$c->exists ? 'nullable' : 'required', 'string', 'max:200'],
            'enabled' => 'boolean',
            'options' => 'array', 'options.*' => 'boolean',
        ]);
        $c->fill(['base_url' => rtrim($d['base_url'], '/'), 'enabled' => $d['enabled'] ?? true,
            'options' => array_intersect_key(array_replace(self::OPTIONS, (array) $c->options, (array) ($d['options'] ?? [])), self::OPTIONS)]);
        filled($d['api_key'] ?? null) && $c->api_key = trim($d['api_key']);
        $secret = null;
        if (!$c->uuid) {   // first save: the address and secret SmartDCM uses to reach ROVEXY (shown once)
            $c->uuid = (string) Str::uuid();
            $c->inbound_secret = $secret = Str::random(48);
        }
        $c->save();
        Audit::record('integration.saved', $c, ['system' => 'smartdcm', 'base_url' => $c->base_url, 'enabled' => $c->enabled, 'options' => $c->options, 'key_changed' => filled($d['api_key'] ?? null)]);
        $test = app(FieldForceIntegration::class)->test($c);
        if ($test['ok'] && $c->enabled) {
            $this->startSync();
        }
        return response()->json($test + ['inbound_secret' => $secret]);
    }

    public function test(FieldForceIntegration $ff)
    {
        return response()->json($ff->test($this->conn() ?? abort(404)));
    }

    /** Sync now: in the background (a queue), or right here on a PC without a queue worker. */
    public function sync()
    {
        $c = $this->conn() ?? abort(404);
        abort_unless($c->enabled, 422, 'The connection is paused. Tick Enabled and save first.');
        return response()->json($this->startSync());
    }

    /** Read everything again from the beginning (after a SmartDCM restore, or to repair links). */
    public function resync()
    {
        $c = $this->conn() ?? abort(404);
        $c->forceFill(['state' => array_diff_key((array) $c->state, ['cursors' => 1])])->save();
        Audit::record('smartdcm.resync', $c);
        return response()->json($this->startSync());
    }

    /** New secret for SmartDCM → ROVEXY (webhook signature + recordings). Shown once; paste it into SmartDCM. */
    public function secret()
    {
        $c = $this->conn() ?? abort(404);
        $c->forceFill(['inbound_secret' => $secret = Str::random(48)])->save();
        Audit::record('smartdcm.secret_rotated', $c);
        return response()->json(['inbound_secret' => $secret]);
    }

    /** Customer page: this case's history in SmartDCM, read live (nothing stored). */
    public function trail(Request $r, int $id)
    {
        $customer = CustomerController::filtered(['include_withdrawn' => 1], $r->user())->whereKey($id)->firstOrFail();   // same scope as the customer page
        $remote = ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'case', $customer->id);
        $c = $this->conn();
        if (!$remote || !$c || !$c->enabled) {
            return response()->json(['linked' => false]);
        }
        try {
            $b = (new SmartDcmClient($c))->get('/cases/' . rawurlencode($remote) . '/trail');
        } catch (\Throwable $e) {
            return response()->json(['linked' => true, 'case_id' => $remote, 'error' => mb_substr($e->getMessage(), 0, 200), 'data' => []]);
        }
        return response()->json(['linked' => true, 'case_id' => $remote, 'case_url' => $b['case_url'] ?? null, 'data' => array_slice(array_values((array) ($b['data'] ?? [])), 0, 200)]);
    }

    private function startSync(): array
    {
        $cid = app(CurrentCompany::class)->id();
        if (config('queue.default') === 'sync') {
            $c = $this->conn();
            try {
                $r = SmartDcmSync::for($c)->run(25);
                return ['ok' => true, 'detail' => ($r['done'] ? 'Up to date. ' : 'Part synced — the rest continues automatically every 5 minutes. ') . implode(' · ', $r['log'])];
            } catch (\Throwable $e) {
                return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
            }
        }
        SyncSmartDcm::dispatch($cid);
        return ['ok' => true, 'detail' => 'Sync started. Refresh in a minute to see the result.'];
    }

    private function conn(): ?IntegrationConnection
    {
        return IntegrationConnection::query()->where('system', SmartDcmSync::SYSTEM)->first();
    }
}
