<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Events\MediaSignal;
use App\Http\Controllers\Controller;
use App\Models\MediaSession;
use App\Services\MediaSessionService;
use Illuminate\Http\Request;

final class MediaController extends Controller
{
    /** Pending/active requests for this device. Expired requests are never returned (stale requests are not executed). */
    public function pending(Request $r, MediaSessionService $svc)
    {
        $svc->expireStale();
        return response()->json(MediaSession::query()->where('device_id', $this->device($r)->id)->where('user_id', $r->user()->id)
            ->whereIn('state', ['requested', 'accepted', 'active'])->get(['uuid', 'kind', 'purpose', 'state', 'requested_at', 'timeout_minutes', 'options'])
            ->map(fn ($s) => $s->toArray() + ['requested_by_name' => \App\Models\User::query()->find($s->requested_by)?->name]));
    }

    public function signals(Request $r, string $uuid)
    {
        $s = MediaSession::query()->where('uuid', $uuid)->where('device_id', $this->device($r)->id)->where('user_id', $r->user()->id)->firstOrFail();
        return response()->json(\App\Events\MediaSignal::inbox($s, 'supervisor', (int) $r->query('after', 0)));
    }

    public function act(Request $r, string $uuid, string $action, MediaSessionService $svc)
    {
        $s = MediaSession::query()->where('uuid', $uuid)->where('device_id', $this->device($r)->id)->where('user_id', $r->user()->id)->firstOrFail();
        if ($action === 'signal') {
            $data = $r->validate(['type' => 'required|in:offer,answer,candidate,bye', 'payload' => 'required|array']);
            abort_unless(in_array($s->state, ['accepted', 'active'], true), 409);
            MediaSignal::dispatchFor($s, 'device', $data['type'], $data['payload']);
            return response()->json(['ok' => true]);
        }
        if ($action === 'protected-content') {
            $s->forceFill(['protected_content_reported' => true])->save();
            return response()->json(['ok' => true]);
        }
        $reason = $action === 'end' ? $r->validate(['reason' => 'required|in:employee_stopped,connection_failed,os_consent_denied,timeout'])['reason'] : null;
        $svc->act($s, $action, $reason, $r->user());
        return response()->json(['state' => $s->state]);
    }

    public function ice(Request $r, string $uuid, MediaSessionService $svc)
    {
        $s = MediaSession::query()->where('uuid', $uuid)->where('device_id', $this->device($r)->id)->whereIn('state', ['accepted', 'active'])->firstOrFail();
        return response()->json(['ice_servers' => $svc->iceServers($s)]);
    }
}
