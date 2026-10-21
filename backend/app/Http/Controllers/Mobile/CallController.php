<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\CallService;
use Illuminate\Http\Request;

final class CallController extends Controller
{
    public function store(Request $r, CallService $svc)
    {
        $data = $r->validate([
            'customer_id' => 'nullable|integer',
            'which' => 'required|in:primary,alternate',
            'number' => 'nullable|string|max:20',   // only meaningful for manual dialling; otherwise must match the record
            'route' => 'nullable|in:sim,server',   // employee's choice: SIM call or Click2Call (server checks it is offered)
            'vmn' => 'nullable|string|max:20',     // Click2Call: company number picked by the employee (checked server-side)
        ]);
        $call = $svc->start($r->user(), $this->device($r), $data['customer_id'] ?? null, $data['which'], $data['number'] ?? null, $data['route'] ?? null, $data['vmn'] ?? null);
        return response()->json($this->body($call), 201);
    }

    public function show(Request $r, string $uuid, CallService $svc)
    {
        return response()->json($this->body($svc->syncProvider($this->own($r, $uuid))));
    }

    public function hangup(Request $r, string $uuid, CallService $svc)
    {
        $svc->hangup($this->own($r, $uuid), $r->user());
        return response()->json(['ok' => true]);
    }

    /** SIM route: duration from the phone's call log (and whether its recorder produced a file). */
    public function simResult(Request $r, string $uuid, CallService $svc)
    {
        $d = $r->validate(['talk_seconds' => 'required|integer|min:0|max:14400', 'ring_seconds' => 'nullable|integer|min:0|max:600',
            'started_at' => 'nullable|date', 'recording_found' => 'nullable|boolean', 'diagnostics' => 'nullable|array|max:10', 'diagnostics.*' => 'nullable|max:500']);
        $call = $svc->simResult($this->own($r, $uuid), $r->user(), (int) $d['talk_seconds'], isset($d['ring_seconds']) ? (int) $d['ring_seconds'] : null,
            $d['started_at'] ?? null, isset($d['recording_found']) ? (bool) $d['recording_found'] : null,
            array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 500) : null, $d['diagnostics'] ?? []));
        return response()->json($this->body($call));
    }

    /** SIM route: upload of the phone's call-recorder file (max 50 MB). */
    public function recording(Request $r, string $uuid, CallService $svc)
    {
        $d = $r->validate(['file' => 'required|file|max:51200', 'duration_seconds' => 'nullable|integer|min:0|max:14400']);
        $call = $svc->storeSimRecording($this->own($r, $uuid), $r->user(), $d['file'], isset($d['duration_seconds']) ? (int) $d['duration_seconds'] : null);
        return response()->json($this->body($call));
    }

    public function disposition(Request $r, string $uuid, CallService $svc)
    {
        // JSON (offline queue) or multipart when a payment slip is attached (photo or PDF, max 10 MB).
        $data = $r->validate(['code' => 'required|string|max:32', 'notes' => 'nullable|string|max:2000', 'follow_up_at' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0|max:99999999', 'payment_mode' => 'nullable|string|max:20', 'payment_ref' => 'nullable|string|max:64',
            'paid_on' => 'nullable|date', 'slip' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf']);
        $d = $svc->dispose($this->own($r, $uuid), $r->user(), $data['code'], $data['notes'] ?? null, $data['follow_up_at'] ?? null,
            array_intersect_key($data, array_flip(['amount', 'payment_mode', 'payment_ref', 'paid_on'])), $r->file('slip'));
        return response()->json(['ok' => true, 'disposition_id' => $d->id]);
    }

    private function own(Request $r, string $uuid): Call
    {
        return Call::query()->where('uuid', $uuid)->where('user_id', $r->user()->id)->firstOrFail();
    }

    private function body(Call $c): array
    {
        return ['call_id' => $c->uuid, 'state' => $c->state, 'customer_id' => $c->customer_id, 'recording_status' => $c->recording_status,
            'ring_seconds' => $c->ring_seconds, 'talk_seconds' => $c->talk_seconds, 'total_seconds' => $c->total_seconds,
            'failure_reason' => $c->failure_reason, 'disposition_status' => $c->disposition_status, 'agent_leg' => $c->agent_leg,
            'route' => $c->calling_mode === 'sim' ? 'sim' : 'server', 'dial' => $c->calling_mode === 'sim' && !$c->isTerminal() ? $c->destination : null,
            // Click-to-call rings the employee from this number first; the app auto-answers it (see CallSession.expectCallback).
            'callback_from' => in_array($c->provider, \App\Telephony\PolledTelephony::PROVIDERS, true) && !$c->isTerminal() ? $this->callbackNumber($c) : null];
    }

    private function callbackNumber(Call $c): ?string
    {
        $a = app(\App\Telephony\TelephonyManager::class)->adapter();
        return $a instanceof \App\Telephony\Click2CallAdapter ? $a->callbackNumberFor($c) : ($a instanceof \App\Telephony\PolledTelephony ? $a->callbackNumber() : null);
    }
}
