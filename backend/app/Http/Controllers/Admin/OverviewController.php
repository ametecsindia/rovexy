<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Call;
use App\Models\ManagedDevice;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard. One request returns everything the page draws, scoped to the admin's teams (and an optional team
 * filter) for a range of 1, 7 or 30 days. ponytail: aggregates in PHP over a cursor (portable MySQL/SQLite); move the
 * per-day grouping to SQL if a company reaches ~100k calls per range.
 */
final class OverviewController extends Controller
{
    /** A phone not heard from for this long is Offline (a signed-in phone reports at least every 5 min). */
    private const ONLINE_WINDOW_MIN = 10;

    public function show(Request $r)
    {
        $days = in_array((int) $r->query('days', 7), [1, 7, 30], true) ? (int) $r->query('days', 7) : 7;
        $teams = $r->user()->supervisedTeamIds();
        if ($r->filled('team_id')) {
            $tid = (int) $r->query('team_id');
            $teams = $teams === null || in_array($tid, $teams, true) ? [$tid] : [];   // never widens a supervisor's scope
        }
        $users = $teams === null ? null : DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id')->all();
        $scope = fn ($q, string $col = 'user_id') => $q->when($users !== null, fn ($q) => $q->whereIn($col, $users));

        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: 'UTC';
        $start = now($tz)->startOfDay()->subDays($days - 1);
        $prevStart = $start->copy()->subDays($days);
        $devices = ManagedDevice::query()->when($teams !== null, fn ($q) => $q->whereIn('team_id', $teams));
        $today = $scope(Call::query()->where('provider', '!=', 'manual')->where('initiated_at', '>=', now($tz)->startOfDay()->utc()));
        $range = $scope(Call::query()->where('provider', '!=', 'manual')->where('initiated_at', '>=', $start->copy()->utc()));
        $prev = $scope(Call::query()->where('provider', '!=', 'manual')->whereBetween('initiated_at', [$prevStart->copy()->utc(), $start->copy()->utc()]));
        $people = $this->people($devices, $today);

        return response()->json([
            'range' => ['days' => $days, 'from' => $start->format('Y-m-d'), 'timezone' => $tz],
            'status' => $this->statusCounts($people),
            'people' => $people,
            'kpis' => $this->kpis($range) + ['prev' => $this->kpis($prev)],
            'trend' => $this->trend($range, $start, max($days, 7), $tz, $scope),
            'hourly' => $this->hourly($today, $tz),
            'outcomes' => (clone $range)->selectRaw('state, count(*) as n')->groupBy('state')->orderByDesc('n')->pluck('n', 'state'),
            'dispositions' => DB::table('call_dispositions')->join('calls', 'calls.id', '=', 'call_dispositions.call_id')
                ->where('calls.initiated_at', '>=', $start->copy()->utc())->when($users !== null, fn ($q) => $q->whereIn('calls.user_id', $users))
                ->when(app(\App\Support\CurrentCompany::class)->id(), fn ($q, $cid) => $q->where('calls.company_id', $cid))
                ->selectRaw('call_dispositions.code, count(*) as n')->groupBy('call_dispositions.code')->orderByDesc('n')->pluck('n', 'code'),
            'leaderboard' => $this->employees($range, $people),
            'devices' => [
                'pending' => (clone $devices)->where('approval_state', 'pending')->count(),
                'approved' => (clone $devices)->where('approval_state', 'approved')->count(),
                'online' => collect($people)->where('state', '!=', 'offline')->count(),
                'non_compliant' => (clone $devices)->where('approval_state', 'approved')->where('compliant', false)->count(),
                'on_call' => collect($people)->where('state', 'on_call')->count(),
                'low_battery' => collect($people)->filter(fn ($p) => $p['state'] !== 'offline' && $p['battery_pct'] !== null && $p['battery_pct'] < 20)->count(),
                'silent_24h' => (clone $devices)->where('approval_state', 'approved')->where(fn ($q) => $q->whereNull('last_contact_at')->orWhere('last_contact_at', '<', now()->subDay()))->count(),
            ],
            'recordings' => [
                'available' => (clone $range)->where('recording_status', 'available')->count(),
                'failed' => (clone $range)->where('recording_status', 'failed')->count(),
                'pending' => (clone $range)->whereIn('recording_status', ['pending', 'processing'])->count(),
            ],
            // Kept for older clients / tests.
            'calls_today' => ['total' => (clone $today)->count(), 'answered' => (clone $today)->whereNotNull('answered_at')->count(), 'talk_seconds' => (int) (clone $today)->sum('talk_seconds')],
            'recordings_failed_7d' => Recording::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count(),
            'recordings_pending' => Recording::query()->whereIn('status', ['pending', 'processing'])->where('created_at', '>=', now()->subDays(2))->count(),
            'follow_ups' => [
                'due_today' => $this->followUps($users)->whereBetween('due_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()])->count(),
                'overdue' => $this->followUps($users)->where('due_at', '<', now($tz)->startOfDay()->utc())->count(),
            ],
            'trend_7d' => $days === 7 ? $this->trend($range, $start, 7, $tz, $scope) : $this->trend($scope(Call::query()->where('provider', '!=', 'manual')->where('initiated_at', '>=', now($tz)->startOfDay()->subDays(6)->utc())), now($tz)->startOfDay()->subDays(6), 7, $tz, $scope),
            'employees_today' => $this->employees($today, $people),
        ]);
    }

    /** One row per employee (their most recently heard-from phone) with a live state. */
    private function people($devices, $today): array
    {
        $calls = (clone $today)->selectRaw('user_id, count(*) as calls, sum(talk_seconds) as talk')->groupBy('user_id')->get()->keyBy('user_id');
        return (clone $devices)->where('approval_state', 'approved')->whereNotNull('assigned_user_id')->with('assignedUser:id,name,employee_code')
            ->orderByDesc('last_contact_at')->get()->unique('assigned_user_id')
            ->map(function (ManagedDevice $d) use ($calls) {
                $fresh = $d->last_contact_at?->gt(now()->subMinutes(self::ONLINE_WINDOW_MIN));
                $state = !$fresh ? 'offline' : match ($d->work_state) {
                    'on_call' => 'on_call', 'active', 'updating_customer' => 'active', 'signed_out' => 'signed_out', default => 'idle',
                };
                return ['user_id' => $d->assigned_user_id, 'name' => $d->assignedUser?->name, 'employee_code' => $d->assignedUser?->employee_code,
                    'device_id' => $d->id, 'device' => $d->asset_tag ?? "Phone #{$d->id}", 'state' => $state, 'work_state' => $d->work_state,
                    'last_contact_at' => $d->last_contact_at?->toAtomString(), 'battery_pct' => $d->battery_pct, 'network_type' => $d->network_type,
                    'compliant' => (bool) $d->compliant, 'calls_today' => (int) ($calls[$d->assigned_user_id]->calls ?? 0), 'talk_today' => (int) ($calls[$d->assigned_user_id]->talk ?? 0)];
            })->values()->all();
    }

    private function statusCounts(array $people): array
    {
        $c = array_count_values(array_column($people, 'state'));
        $n = fn ($k) => (int) ($c[$k] ?? 0);
        return ['total' => count($people), 'online' => count($people) - $n('offline'), 'active' => $n('active'), 'on_call' => $n('on_call'),
            'idle' => $n('idle'), 'signed_out' => $n('signed_out'), 'offline' => $n('offline')];
    }

    private function kpis($q): array
    {
        $row = (clone $q)->selectRaw('count(*) as calls, sum(case when answered_at is not null then 1 else 0 end) as connected, coalesce(sum(talk_seconds),0) as talk, '
            . 'avg(case when answered_at is not null then talk_seconds end) as avg_talk, avg(ring_seconds) as avg_ring, count(distinct customer_id) as customers, count(distinct user_id) as callers')->first();
        return ['calls' => (int) $row->calls, 'connected' => (int) $row->connected, 'talk_seconds' => (int) $row->talk, 'avg_talk' => (int) round((float) $row->avg_talk),
            'avg_ring' => (int) round((float) $row->avg_ring), 'customers' => (int) $row->customers, 'callers' => (int) $row->callers];
    }

    /** Per-day calls/connected/talk plus active employees (anyone who called or used the app that day). */
    private function trend($q, $start, int $n, string $tz, callable $scope): array
    {
        $start = now($tz)->startOfDay()->subDays($n - 1);   // at least 7 days so "today" still shows a week of context
        $days = [];
        $users = [];
        for ($i = 0; $i < $n; $i++) {
            $k = $start->copy()->addDays($i)->format('Y-m-d');
            $days[$k] = ['date' => $k, 'calls' => 0, 'connected' => 0, 'talk_seconds' => 0, 'active_users' => 0];
            $users[$k] = [];
        }
        $scope(Call::query()->where('provider', '!=', 'manual')->where('initiated_at', '>=', $start->copy()->utc()))->select(['initiated_at', 'answered_at', 'talk_seconds', 'user_id'])->cursor()
            ->each(function (Call $c) use (&$days, &$users, $tz) {
                $d = $c->initiated_at->copy()->setTimezone($tz)->format('Y-m-d');
                if (isset($days[$d])) {
                    $days[$d]['calls']++;
                    $days[$d]['connected'] += $c->answered_at ? 1 : 0;
                    $days[$d]['talk_seconds'] += (int) $c->talk_seconds;
                    $users[$d][$c->user_id] = true;
                }
            });
        $scope(ActivityEvent::query()->where('occurred_at', '>=', $start->copy()->utc()))->select(['occurred_at', 'user_id'])->cursor()
            ->each(function ($e) use (&$users, $tz) {
                $d = $e->occurred_at->copy()->setTimezone($tz)->format('Y-m-d');
                if (isset($users[$d])) {
                    $users[$d][$e->user_id] = true;
                }
            });
        foreach ($users as $d => $u) {
            $days[$d]['active_users'] = count($u);
        }
        return array_values($days);
    }

    private function hourly($today, string $tz): array
    {
        $h = array_fill(0, 24, ['calls' => 0, 'connected' => 0]);
        (clone $today)->select(['initiated_at', 'answered_at'])->cursor()->each(function (Call $c) use (&$h, $tz) {
            $i = (int) $c->initiated_at->copy()->setTimezone($tz)->format('G');
            $h[$i]['calls']++;
            $h[$i]['connected'] += $c->answered_at ? 1 : 0;
        });
        return array_map(fn ($v, $i) => ['hour' => $i] + $v, $h, array_keys($h));
    }

    private function followUps(?array $users)
    {
        return \App\Models\FollowUp::query()->where('status', 'open')->when($users !== null, fn ($q) => $q->whereIn('user_id', $users));
    }

    private function employees($calls, array $people): array
    {
        $state = collect($people)->keyBy('user_id');
        return (clone $calls)->selectRaw('user_id, count(*) as calls, sum(case when answered_at is not null then 1 else 0 end) as connected, sum(talk_seconds) as talk_seconds, '
                . "sum(case when recording_status = 'available' then 1 else 0 end) as recorded, sum(case when recording_status = 'failed' then 1 else 0 end) as recording_failed, "
                . "sum(case when disposition_status = 'pending' and ended_at is not null then 1 else 0 end) as undisposed")
            ->groupBy('user_id')->orderByDesc('talk_seconds')->limit(50)->get()
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => User::query()->whereKey($r->user_id)->value('name'), 'calls' => (int) $r->calls,
                'connected' => (int) $r->connected, 'talk_seconds' => (int) $r->talk_seconds, 'recorded' => (int) $r->recorded, 'recording_failed' => (int) $r->recording_failed,
                'undisposed' => (int) $r->undisposed, 'state' => $state[(int) $r->user_id]['state'] ?? 'offline'])->all();
    }
}
