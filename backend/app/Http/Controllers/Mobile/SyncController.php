<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Domain\DomainException;
use App\Domain\Sync\QueuedEvent;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Call;
use App\Models\Customer;
use App\Models\LocationEvent;
use App\Models\ManagedDevice;
use App\Models\MessageReceipt;
use App\Services\CallService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Offline queue upload. Each event has a client UUID; the (device_id, client_event_id) ledger makes replays no-ops.
 * Returns a per-event result so the app can drop accepted/duplicate events and keep only retryable ones.
 */
final class SyncController extends Controller
{
    private const WORK_STATES = ['idle', 'active', 'updating_customer'];

    public function store(Request $r, CallService $calls)
    {
        $data = $r->validate(['events' => 'required|array|max:200', 'events.*' => 'array']);
        $device = $this->device($r);
        $ws = $this->workSession($r);
        $user = $r->user();
        $now = new \DateTimeImmutable();
        $leaseEnd = \DateTimeImmutable::createFromInterface($r->attributes->get('lease_before') ?? $ws->lease_expires_at);
        $results = [];
        foreach ($data['events'] as $raw) {
            $id = (string) ($raw['id'] ?? '');
            try {
                $e = QueuedEvent::validate($raw, $now, $leaseEnd);
            } catch (DomainException $ex) {
                $results[] = ['id' => $id, 'result' => 'rejected', 'reason' => $ex->reason];
                continue;
            }
            try {
                $result = DB::transaction(function () use ($e, $device, $user, $calls, $ws) {
                    DB::table('client_events')->insert(['company_id' => $device->company_id, 'device_id' => $device->id,
                        'client_event_id' => $e['id'], 'type' => $e['type'], 'result' => 'accepted', 'received_at' => now()]);
                    $this->apply($e, $device, $user, $calls, $ws->id);
                    return 'accepted';
                });
            } catch (UniqueConstraintViolationException) {
                $result = 'duplicate';
            } catch (DomainException $ex) {
                $results[] = ['id' => $e['id'], 'result' => 'rejected', 'reason' => $ex->reason];
                continue;
            } catch (\Exception) {
                // Malformed payload (e.g. unparsable date): reject this event only, so the queue can drain.
                $results[] = ['id' => $e['id'], 'result' => 'rejected', 'reason' => 'invalid_payload'];
                continue;
            }
            $results[] = ['id' => $e['id'], 'result' => $result];
        }
        return response()->json(['results' => $results, 'server_time' => now()->toAtomString()]);
    }

    private function apply(array $e, ManagedDevice $device, $user, CallService $calls, int $wsId): void
    {
        $p = $e['payload'];
        switch ($e['type']) {
            case 'disposition':
                $call = Call::query()->where('uuid', (string) ($p['call_id'] ?? ''))->where('user_id', $user->id)->first()
                    ?? throw new DomainException('unknown_call');
                $calls->dispose($call, $user, (string) ($p['code'] ?? ''), $p['notes'] ?? null, $p['follow_up_at'] ?? null,
                    array_intersect_key($p, array_flip(['amount', 'payment_mode', 'payment_ref', 'paid_on'])));
                return;
            case 'note':
                $c = Customer::query()->visibleTo($user)->whereKey((int) ($p['customer_id'] ?? 0))->first() ?? throw new DomainException('customer_not_assigned');
                app(\App\Services\CaseService::class)->addNote($c, $user, (string) ($p['text'] ?? ''), $e['occurred_at']);
                return;
            case 'status':
                $c = Customer::query()->visibleTo($user)->whereKey((int) ($p['customer_id'] ?? 0))->first() ?? throw new DomainException('customer_not_assigned');
                app(\App\Services\CaseService::class)->setStatus($c, $user, (string) ($p['status'] ?? ''), isset($p['notes']) ? (string) $p['notes'] : null);
                return;
            case 'follow_up':
                $c = Customer::query()->visibleTo($user)->whereKey((int) ($p['customer_id'] ?? 0))->first() ?? throw new DomainException('customer_not_assigned');
                $due = new \DateTimeImmutable((string) ($p['due_at'] ?? 'invalid'));
                \App\Models\FollowUp::query()->where('customer_id', $c->id)->where('status', 'open')->update(['status' => 'done', 'updated_at' => now()]);
                \App\Models\FollowUp::query()->create(['customer_id' => $c->id, 'user_id' => $user->id, 'due_at' => $due, 'notes' => mb_substr((string) ($p['notes'] ?? ''), 0, 2000)]);
                $c->forceFill(['status' => 'follow_up', 'follow_up_on' => $due->format('Y-m-d')])->save();
                return;
            case 'activity':
                $type = (string) ($p['type'] ?? '');
                in_array($type, ['app_foreground', 'app_background', 'idle_start', 'idle_end', 'customer_opened', 'heartbeat'], true) || throw new DomainException('invalid_activity');
                ActivityEvent::query()->create(['device_id' => $device->id, 'user_id' => $user->id, 'type' => $type, 'occurred_at' => $e['occurred_at'],
                    'meta' => array_intersect_key($p, array_flip(['customer_id', 'battery_pct', 'network_type']))]);
                $state = match ($type) { 'idle_start' => 'idle', 'customer_opened' => 'updating_customer', 'idle_end', 'app_foreground' => 'active', default => null };
                $upd = array_filter(['battery_pct' => isset($p['battery_pct']) ? max(0, min(100, (int) $p['battery_pct'])) : null,
                    'network_type' => isset($p['network_type']) ? mb_substr((string) $p['network_type'], 0, 16) : null]);
                if ($state !== null && in_array($device->work_state, self::WORK_STATES, true)) {
                    $upd['work_state'] = $state;   // never overrides on_call (server-owned)
                }
                $upd !== [] && $device->forceFill($upd)->saveQuietly();
                return;
            case 'location':
                $lat = (float) ($p['lat'] ?? 999);
                $lng = (float) ($p['lng'] ?? 999);
                ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) || throw new DomainException('invalid_location');
                $loc = LocationEvent::query()->create(['device_id' => $device->id, 'user_id' => $user->id, 'work_session_id' => $wsId, 'lat' => $lat, 'lng' => $lng,
                    'accuracy_m' => isset($p['accuracy_m']) ? (float) $p['accuracy_m'] : null, 'provider' => mb_substr((string) ($p['provider'] ?? ''), 0, 16) ?: null,
                    'battery_pct' => isset($p['battery_pct']) ? (int) $p['battery_pct'] : null, 'captured_at' => $e['occurred_at']]);
                app(\App\Services\FieldForceIntegration::class)->recordLocation($user, $loc);   // → SmartEPT trail (outbox)
                return;
            case 'chat_read':
                MessageReceipt::query()->where('user_id', $user->id)->whereIn('message_id', array_map('intval', (array) ($p['message_ids'] ?? [])))
                    ->whereNull('read_at')->update(['read_at' => $e['occurred_at'], 'delivered_at' => DB::raw('COALESCE(delivered_at, NOW())')]);
                return;
        }
    }
}
