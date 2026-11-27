<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Customer;
use App\Services\CallService;
use App\Telephony\Click2CallSettings;
use Illuminate\Http\Request;

/**
 * G14: a telecaller at a desk calls their own customers from the console (My calls). Click2Call only: the gateway
 * rings the telecaller's work phone first, then the customer; the gateway records the call and the recording is fetched
 * by the server as for calls from the app. Same rules as the app (assignment, calling hours, daily attempts, DNC).
 */
final class ConsoleCallController extends Controller
{
    /** My customers (assigned to me, or to my group with no owner), searchable. */
    public function customers(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        $page = Customer::query()->visibleTo($r->user())
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('customer_ref', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->orderByRaw('follow_up_on IS NULL')->orderBy('follow_up_on')->orderByDesc('overdue_amount')
            ->paginate(50, ['id', 'customer_ref', 'name', 'phone', 'alt_phone', 'status', 'follow_up_on', 'overdue_amount', 'outstanding', 'city']);
        $page->getCollection()->transform(fn ($c) => $c->only(['id', 'customer_ref', 'name', 'status', 'follow_up_on', 'overdue_amount', 'outstanding', 'city'])
            + ['phone' => self::mask($c->phone), 'has_alternate' => filled($c->alt_phone)]);
        return response()->json(['can_call' => in_array('server', CallService::routes($r->user()), true) && Click2CallSettings::allows($r->user(), 'click2call'),
            'work_number' => self::mask(Click2CallSettings::agentNumber($r->user()))] + $page->toArray());
    }

    public function call(Request $r, int $id, CallService $svc)
    {
        $d = $r->validate(['which' => 'required|in:primary,alternate', 'vmn' => 'nullable|string|max:20']);
        abort_unless(Click2CallSettings::allows($r->user(), 'click2call'), 403, 'Click2Call is not enabled for you.');
        $call = $svc->start($r->user(), null, $id, $d['which'], null, 'server', $d['vmn'] ?? null);
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

    private function own(Request $r, string $uuid): Call
    {
        return Call::query()->where('uuid', $uuid)->where('user_id', $r->user()->id)->whereNull('device_id')->firstOrFail();
    }

    private function body(Call $c): array
    {
        return ['call_id' => $c->uuid, 'state' => $c->state, 'terminal' => $c->isTerminal(), 'customer_id' => $c->customer_id, 'talk_seconds' => $c->talk_seconds,
            'failure_reason' => $c->failure_reason, 'recording_status' => $c->recording_status];
    }

    /** Last 4 digits only: the telecaller never needs the full number (the gateway dials it). */
    private static function mask(?string $n): ?string
    {
        return $n ? '•••••• ' . substr(preg_replace('/\D/', '', $n), -4) : null;
    }
}
