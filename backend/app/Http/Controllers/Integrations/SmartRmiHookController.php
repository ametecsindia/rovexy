<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Domain\Calling\RecordingState;
use App\Domain\Customers\PhoneNormaliser;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Recording;
use App\Services\Platform\Licensing;
use App\Services\SmartRmi;
use App\Support\CurrentCompany;
use App\Telephony\Click2CallAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * SmartRMI engine ⇄ ROVEXY (no user session; the connection uuid is in the URL, its secret in header X-Api-Key):
 *   GET  /api/integrations/smartrmi/{uuid}/calls?after_id=0&limit=100   recorded calls to analyse, oldest first
 *   GET  /api/integrations/smartrmi/{uuid}/recordings/{call_uuid}       the recording file
 *   POST /api/integrations/smartrmi/{uuid}/results                      {call_uuid | ref_id, rmi_status, rmi_results} (or nested "rmi")
 *   POST /api/integrations/smartrmi/{uuid}/alerts                       angry-call alert, as SmartDCM's /api/angry-call-alert
 * Contract: docs/SMARTRMI-INTEGRATION.md.
 */
final class SmartRmiHookController extends Controller
{
    private function run(Request $r, string $uuid, \Closure $fn)
    {
        [$conn, $company] = SmartRmi::resolve($uuid, (string) $r->header('X-Api-Key', ''));
        return app(CurrentCompany::class)->run($company, function () use ($conn, $fn) {
            abort_unless(app(Licensing::class)->has('smartrmi'), 403, 'SmartRMI is not in this workspace\'s licence.');
            $conn->forceFill(['last_ok_at' => now(), 'last_status' => 'ok'])->save();
            return $fn();
        });
    }

    public function calls(Request $r, string $uuid)
    {
        return $this->run($r, $uuid, function () use ($r, $uuid) {
            $after = max(0, (int) $r->query('after_id', 0));
            $limit = min(500, max(1, (int) $r->query('limit', 100)));
            $calls = Call::query()->where('id', '>', $after)->where('is_test', false)->where('recording_status', RecordingState::Available->value)
                ->with(['user:id,name,employee_code', 'customer:id,customer_ref,name', 'disposition:id,call_id,code', 'recording:id,call_id,format,duration_seconds'])->withExists('rmi')
                ->orderBy('id')->limit($limit)->get();
            return response()->json([
                'data' => $calls->map(fn (Call $c) => [
                    'id' => $c->id, 'call_uuid' => $c->uuid, 'provider' => $c->provider, 'started_at' => $c->initiated_at, 'talk_seconds' => (int) $c->talk_seconds,
                    'agent' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'employee_code' => $c->user->employee_code] : null,
                    'customer_ref' => $c->customer?->customer_ref, 'b_party_masked' => PhoneNormaliser::mask($c->destination), 'outcome' => $c->disposition?->code,
                    'recording_url' => url("/api/integrations/smartrmi/{$uuid}/recordings/{$c->uuid}"), 'recording_format' => $c->recording?->format,
                    'analysed' => (bool) $c->rmi_exists,
                ]),
                'next_after_id' => $calls->last()?->id ?? $after,
            ]);
        });
    }

    public function recording(Request $r, string $uuid, string $call)
    {
        return $this->run($r, $uuid, function () use ($call) {
            $c = Call::query()->where('uuid', $call)->firstOrFail();
            $rec = Recording::query()->where('call_id', $c->id)->first();
            if (!$rec || $rec->status !== RecordingState::Available->value) {
                return response()->json(['status' => $rec?->status ?? 'none', 'message' => 'Recording not available yet.'], $rec && $rec->status !== RecordingState::Failed->value ? 404 : 410);
            }
            \App\Support\Audit::record('recording.downloaded', $rec, ['call_id' => $c->id, 'by' => 'smartrmi']);
            return Storage::disk(config('ammp.recordings.disk'))->download($rec->object_key, $c->uuid . '.' . ($rec->format ?: 'mp3'),
                ['Content-Type' => $rec->mime, 'Cache-Control' => 'private, no-store']);
        });
    }

    public function results(Request $r, string $uuid)
    {
        return $this->run($r, $uuid, function () use ($r) {
            $call = $this->callFor($r);
            abort_if($call === null, 404, 'Call not found: send call_uuid (from /calls) or the Click2Call ref_id.');
            $a = app(SmartRmi::class)->ingest($call, $r->all());
            return response()->json(['status' => 'stored', 'call_uuid' => $call->uuid, 'score' => $a->score, 'angry' => $a->angry]);
        });
    }

    public function alerts(Request $r, string $uuid)
    {
        return $this->run($r, $uuid, function () use ($r) {
            $call = $this->callFor($r);
            $alert = app(SmartRmi::class)->alert($call, $r->all());
            return response()->json(['status' => 'alert_queued', 'alert_id' => $alert->id, 'emailed_to' => $alert->emailed_to], 201);
        });
    }

    private function callFor(Request $r): ?Call
    {
        $uuid = (string) ($r->input('call_uuid') ?: Click2CallAdapter::uuidFromRef((string) $r->input('ref_id', '')));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) ? Call::query()->where('uuid', strtolower($uuid))->first() : null;
    }
}
