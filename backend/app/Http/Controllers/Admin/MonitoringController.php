<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Customers\PhoneNormaliser;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallMonitorSession;
use App\Services\CallMonitor;
use Illuminate\Http\Request;

/** Call monitoring → Live calls: Click2Call calls in progress and "Listen in" (SmartDCM's Live calls / Join me). */
final class MonitoringController extends Controller
{
    public function live(Request $r, CallMonitor $m)
    {
        $mine = CallMonitor::listenNumber($r->user());
        return response()->json(['calls' => $m->live($r->user()), 'configured' => CallMonitor::configured(),
            'listen_number' => $mine, 'server_time' => now()]);
    }

    public function join(Request $r, CallMonitor $m, int $id)
    {
        $users = $r->user()->usersInView();
        $call = Call::query()->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))->findOrFail($id);
        try {
            $s = $m->join($r->user(), $call);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['session' => $s, 'ok' => $s->status === 'joined',
            'message' => $s->status === 'joined' ? 'Your phone (' . PhoneNormaliser::mask($s->listen_number) . ') will ring now. Answer it to listen in.' : $s->error], $s->status === 'joined' ? 200 : 502);
    }

    /** Who listened in to which call (newest first). */
    public function sessions(Request $r)
    {
        $users = $r->user()->usersInView();
        return response()->json(CallMonitorSession::query()
            ->when($users !== null, fn ($q) => $q->whereHas('call', fn ($c) => $c->whereIn('user_id', $users)))
            ->with(['user:id,name', 'call:id,user_id,customer_id,initiated_at,talk_seconds,state', 'call.user:id,name', 'call.customer:id,customer_ref,name'])
            ->latest()->paginate(50)->through(fn ($s) => $s->toArray() + ['listen_masked' => PhoneNormaliser::mask($s->listen_number)]));
    }
}
