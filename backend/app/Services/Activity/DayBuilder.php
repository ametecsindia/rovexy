<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Branch;
use App\Models\Call;
use App\Models\CustomerEvent;
use App\Models\DailyActivity;
use App\Models\LocationEvent;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\GoogleMaps;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds one employee-day of the activity report from data ROVEXY already records (sign-in/out, heartbeats, GPS,
 * calls, outcomes, case updates, visits) plus the phone's break / app events when the app sends them.
 *
 * Every signed-in minute gets exactly one state, so the parts always add up to the signed-in total. When two signals
 * overlap the higher one wins: offline > break > call > at customer > travel > case work / ROVEXY app in use > nothing.
 * A run of "nothing" at least the idle threshold long is idle; shorter runs take the state before them.
 * ponytail: minute resolution (a 20-second call fills one minute); seconds-level segments if anyone ever needs them.
 */
final class DayBuilder
{
    public const STATES = ['active', 'customer', 'travel', 'break', 'idle', 'offline'];

    public const DEFAULT_HOURS = ['days' => [1, 2, 3, 4, 5, 6], 'start' => '09:00', 'end' => '18:00', 'grace_minutes' => 10,
        'full_day_hours' => 8, 'half_day_hours' => 4, 'idle_minutes' => 10, 'travel_productive' => true];

    private const OFFLINE_GAP = 600;     // no heartbeat, fix or event for 10 min while signed in = offline
    private const AFTER_CALL_MAX = 300;  // outcome typed after the call counts as work for at most 5 min
    private const CASE_WORK = 120;       // a note / status / visit = the 2 minutes before it were case work
    private const AT_CUSTOMER_M = 150;
    private const BAD_FIX_M = 100;
    private const PRIO = ['none' => 0, 'app' => 1, 'travel' => 2, 'customer' => 3, 'call' => 4, 'break' => 5, 'offline' => 6];

    /** The company's work hours (Reports → Employee activity → Work hours), with defaults for anything not set. */
    public static function hours(): array
    {
        $saved = (array) (app(CurrentCompany::class)->get()?->setting('work_hours') ?? []);
        return array_intersect_key($saved, self::DEFAULT_HOURS) + self::DEFAULT_HOURS;
    }

    /** Builds and stores the day; returns the stored row. */
    public function save(User $u, string $date, ?array $built = null): DailyActivity
    {
        $sum = ($built ?? $this->build($u, $date))['summary'];
        return DB::transaction(function () use ($u, $date, $sum) {
            $row = DailyActivity::query()->firstOrNew(['user_id' => $u->id, 'work_date' => $date]);
            $row->forceFill($sum + ['built_at' => now()])->save();
            return $row;
        });
    }

    /**
     * @return array{summary: array, segments: list<array>, logins: list<array>, breaks: list<array>, idle: list<array>, visits: list<array>, offices: array}
     */
    public function build(User $u, string $date): array
    {
        $tz = CurrentCompany::tz();
        $hours = self::hours();
        $start = Carbon::parse($date, $tz)->startOfDay()->utc();
        $dayEnd = $start->copy()->addDay();
        $now = now();
        $today = $dayEnd->gt($now);
        $end = $today ? $now->copy() : $dayEnd;
        $n = max(0, intdiv((int) $start->diffInSeconds($end, false), 60));
        $t0 = $start->getTimestamp();

        $events = ActivityEvent::query()->where('user_id', $u->id)->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->orderBy('occurred_at')->orderBy('id')->get(['type', 'occurred_at', 'meta']);
        $carry = ActivityEvent::query()->where('user_id', $u->id)->whereIn('type', ['signed_in', 'signed_out'])->where('occurred_at', '<', $start)
            ->orderByDesc('occurred_at')->orderByDesc('id')->value('type') === 'signed_in';
        $fixes = LocationEvent::query()->where('user_id', $u->id)->where('captured_at', '>=', $start)->where('captured_at', '<', $end)
            ->orderBy('captured_at')->get(['lat', 'lng', 'accuracy_m', 'captured_at']);
        $calls = Call::query()->where('user_id', $u->id)->where('is_test', false)->where('provider', '!=', 'manual')
            ->where('initiated_at', '>=', $start)->where('initiated_at', '<', $end)->orderBy('initiated_at')
            ->get(['id', 'initiated_at', 'answered_at', 'ended_at', 'talk_seconds', 'total_seconds']);
        $outcomes = DB::table('call_dispositions')->whereIn('call_id', $calls->pluck('id'))->pluck('submitted_at', 'call_id');
        $caseEvents = CustomerEvent::query()->where('user_id', $u->id)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->orderBy('created_at')->get(['customer_id', 'type', 'meta', 'created_at']);

        // Moments the phone or the person showed signs of life: heartbeats, any app event, GPS, calls, case work.
        $contacts = [];
        foreach ($events as $e) $contacts[] = $e->occurred_at->getTimestamp();
        foreach ($fixes as $f) $contacts[] = $f->captured_at->getTimestamp();
        foreach ($calls as $c) {
            $contacts[] = $c->initiated_at->getTimestamp();
            $c->ended_at && $contacts[] = $c->ended_at->getTimestamp();
        }
        foreach ($caseEvents as $e) $contacts[] = $e->created_at->getTimestamp();
        sort($contacts);
        $contacts = array_values(array_unique($contacts));

        // Signed-in spans. A session still open from yesterday starts at today's first sign of life; one never signed out
        // ends at the last sign of life (today: now, if the phone is still reporting).
        $spans = [];
        $logins = [];
        $open = $carry && $contacts ? $contacts[0] : null;
        $firstLogin = null;
        $lastLogout = null;
        foreach ($events as $e) {
            $ts = $e->occurred_at->getTimestamp();
            if ($e->type === 'signed_in') {
                $logins[] = ['type' => 'sign_in', 'at' => $e->occurred_at->toAtomString()];
                $firstLogin ??= $ts;
                $open ??= $ts;
            } elseif ($e->type === 'signed_out') {
                $logins[] = ['type' => 'sign_out', 'at' => $e->occurred_at->toAtomString()];
                $lastLogout = $ts;
                if ($open !== null) {
                    $spans[] = [$open, $ts];
                    $open = null;
                }
            }
        }
        $noSignout = false;
        if ($open !== null) {
            $last = $contacts ? max($open, end($contacts)) : $open;
            $close = $today && $end->getTimestamp() - $last <= self::OFFLINE_GAP ? $end->getTimestamp() : $last;
            $spans[] = [$open, $close];
            $noSignout = !$today;
            $firstLogin ??= $open;
        }

        // Minute grid: null = not signed in.
        $grid = array_fill(0, $n, null);
        $prio = array_fill(0, $n, -1);
        $mark = function (int $from, int $to, string $what) use (&$grid, &$prio, $t0, $n) {
            $a = max(0, intdiv($from - $t0, 60));
            $b = min($n, intdiv($to - $t0 + 59, 60));
            for ($i = $a; $i < $b; $i++) {
                if ($grid[$i] !== null && self::PRIO[$what] > $prio[$i]) {
                    $grid[$i] = $what;
                    $prio[$i] = self::PRIO[$what];
                }
            }
        };
        foreach ($spans as [$a, $b]) {
            for ($i = max(0, intdiv($a - $t0, 60)); $i < min($n, intdiv($b - $t0 + 59, 60)); $i++) {
                $grid[$i] = 'none';
                $prio[$i] = 0;
            }
        }

        // Offline: gaps in signs of life longer than the limit, inside signed-in spans.
        foreach ($spans as [$a, $b]) {
            $pts = array_merge([$a], array_filter($contacts, fn ($c) => $c > $a && $c < $b), [$b]);
            for ($i = 1; $i < count($pts); $i++) {
                if ($pts[$i] - $pts[$i - 1] > self::OFFLINE_GAP) {
                    $mark($pts[$i - 1] + 60, $pts[$i], 'offline');
                }
            }
        }

        // Breaks (app 1.0.16+): break_start → break_end, or to sign-out / now.
        $breaks = [];
        $bOpen = null;
        foreach ($events as $e) {
            $ts = $e->occurred_at->getTimestamp();
            if ($e->type === 'break_start' && $bOpen === null) {
                $bOpen = [$ts, (string) ($e->meta['reason'] ?? 'other')];
            } elseif ($bOpen !== null && in_array($e->type, ['break_end', 'signed_out'], true)) {
                $breaks[] = [$bOpen[0], $ts, $bOpen[1]];
                $bOpen = null;
            }
        }
        $bOpen !== null && $breaks[] = [$bOpen[0], $end->getTimestamp(), $bOpen[1]];
        foreach ($breaks as [$a, $b]) $mark($a, $b, 'break');

        // Calls and the outcome typed after them.
        $talk = 0;
        $connected = 0;
        foreach ($calls as $c) {
            $a = $c->initiated_at->getTimestamp();
            $b = $c->ended_at?->getTimestamp() ?? $a + (int) ($c->total_seconds ?? $c->talk_seconds ?? 0);
            $mark($a, max($b, $a + 1), 'call');
            if (isset($outcomes[$c->id]) && $c->ended_at) {
                $mark($b, min(Carbon::parse($outcomes[$c->id])->getTimestamp(), $b + self::AFTER_CALL_MAX), 'call');
            }
            $talk += (int) $c->talk_seconds;
            $c->answered_at && $connected++;
        }

        // Case work: notes, status changes, visits. ROVEXY app in the foreground (app 1.0.16+).
        foreach ($caseEvents as $e) {
            $ts = $e->created_at->getTimestamp();
            $mark($ts - self::CASE_WORK, $ts + 1, 'app');
        }
        $fg = null;
        foreach ($events as $e) {
            $ts = $e->occurred_at->getTimestamp();
            if (in_array($e->type, ['app_foreground', 'customer_opened'], true)) {
                $fg ??= $ts;
            } elseif ($fg !== null && in_array($e->type, ['app_background', 'screen_off', 'signed_out', 'idle_start'], true)) {
                $mark($fg, $ts, 'app');
                $fg = null;
            }
        }
        $fg !== null && $mark($fg, min($end->getTimestamp(), $fg + self::OFFLINE_GAP), 'app');

        // GPS: distance (same rule as the console's Day route), travel, office time.
        $good = $fixes->filter(fn ($f) => $f->accuracy_m === null || $f->accuracy_m <= self::BAD_FIX_M)->values();
        $branches = Branch::query()->whereNotNull('lat')->whereNotNull('lng')->get(['id', 'name', 'lat', 'lng', 'radius_m']);
        $meters = 0.0;
        $offices = [];
        $officeS = 0;
        for ($i = 1; $i < $good->count(); $i++) {
            $p = $good[$i - 1];
            $q = $good[$i];
            $d = GoogleMaps::straightMeters([(float) $p->lat, (float) $p->lng], [(float) $q->lat, (float) $q->lng]);
            $dt = max(1, $q->captured_at->getTimestamp() - $p->captured_at->getTimestamp());
            if ($d / $dt <= 42) {   // 42 m/s ≈ 150 km/h: faster = a GPS jump
                $meters += $d;
            }
            if ($dt <= self::OFFLINE_GAP && $d >= 100 && $d / $dt >= 1.0 && $d / $dt <= 42) {
                $mark($p->captured_at->getTimestamp(), $q->captured_at->getTimestamp(), 'travel');
            }
            if ($dt <= self::OFFLINE_GAP) {
                foreach ($branches as $br) {
                    $r = (int) $br->radius_m ?: 150;
                    if (GoogleMaps::straightMeters([(float) $p->lat, (float) $p->lng], [(float) $br->lat, (float) $br->lng]) <= $r
                        && GoogleMaps::straightMeters([(float) $q->lat, (float) $q->lng], [(float) $br->lat, (float) $br->lng]) <= $r) {
                        $officeS += $dt;
                        $offices[$br->name] = ($offices[$br->name] ?? 0) + $dt;
                        break;
                    }
                }
            }
        }

        // At customer: from the first to the last GPS fix within 150 m of each visit's check-in point.
        $visits = [];
        foreach ($caseEvents->where('type', 'visit') as $v) {
            $at = $v->created_at->getTimestamp();
            $lat = $v->meta['lat'] ?? null;
            $lng = $v->meta['lng'] ?? null;
            [$from, $to] = [$at, $at + 60];
            if ($lat !== null && $lng !== null && $good->isNotEmpty()) {
                $near = fn ($f) => GoogleMaps::straightMeters([(float) $f->lat, (float) $f->lng], [(float) $lat, (float) $lng]) <= self::AT_CUSTOMER_M;
                $k = 0;
                foreach ($good as $j => $f) {
                    if ($f->captured_at->getTimestamp() <= $at) $k = $j;
                }
                for ($j = $k; $j >= 0 && $near($good[$j]); $j--) $from = min($from, $good[$j]->captured_at->getTimestamp());
                for ($j = $k + 1; $j < $good->count() && $near($good[$j]); $j++) $to = max($to, $good[$j]->captured_at->getTimestamp());
            }
            $mark($from, $to, 'customer');
            $visits[] = ['customer_id' => $v->customer_id, 'at' => $v->created_at->toAtomString(), 'minutes' => (int) round(($to - $from) / 60),
                'distance_m' => $v->meta['distance_m'] ?? null];
        }

        // Nothing recorded: idle when long enough, otherwise part of what came before.
        $idleMin = max(1, (int) $hours['idle_minutes']);
        for ($i = 0; $i < $n;) {
            if ($grid[$i] !== 'none') {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && $grid[$j] === 'none') $j++;
            $work = fn ($s) => $s !== null && !in_array($s, ['none', 'offline', 'break'], true);
            $prev = $i > 0 ? $grid[$i - 1] : null;
            $next = $j < $n ? $grid[$j] : null;
            $fill = $j - $i >= $idleMin ? 'idle' : ($work($prev) ? $prev : ($work($next) ? $next : 'idle'));
            for ($k = $i; $k < $j; $k++) $grid[$k] = $fill;
            $i = $j;
        }

        // Minutes → segments.
        $segments = [];
        for ($i = 0; $i < $n;) {
            if ($grid[$i] === null) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && $grid[$j] === $grid[$i]) $j++;
            $state = match ($grid[$i]) { 'call', 'app' => 'active', default => $grid[$i] };
            $last = end($segments);
            if ($last && $last['state'] === $state && $last['to'] === $t0 + $i * 60) {
                $segments[array_key_last($segments)]['to'] = $t0 + $j * 60;
            } else {
                $segments[] = ['state' => $state, 'from' => $t0 + $i * 60, 'to' => $t0 + $j * 60];
            }
            $i = $j;
        }
        $secs = array_fill_keys(self::STATES, 0);
        foreach ($segments as $s) $secs[$s['state']] += $s['to'] - $s['from'];
        $idles = array_values(array_filter($segments, fn ($s) => $s['state'] === 'idle'));

        $signedIn = array_sum($secs);
        $worked = max(0, $signedIn - $secs['break'] - $secs['offline']);
        $productive = $secs['active'] + $secs['customer'] + ($hours['travel_productive'] ? $secs['travel'] : 0);

        // Planned stops (published route plan) vs visited.
        $plan = RoutePlan::query()->where('user_id', $u->id)->whereDate('plan_date', $date)->where('status', 'published')->first();
        $planned = array_map('intval', (array) ($plan?->stops ?? []));
        $visitedIds = $caseEvents->where('type', 'visit')->pluck('customer_id')->map(fn ($i) => (int) $i)->unique()->all();

        // Attendance against the work hours.
        $local = Carbon::parse($date, $tz);
        $scheduled = in_array($local->dayOfWeekIso, array_map('intval', (array) $hours['days']), true);
        $late = $early = 0;
        if ($scheduled && $firstLogin !== null) {
            $due = Carbon::parse("$date {$hours['start']}", $tz)->addMinutes((int) $hours['grace_minutes'])->getTimestamp();
            $late = max(0, intdiv($firstLogin - $due, 60));
            $leave = Carbon::parse("$date {$hours['end']}", $tz)->subMinutes((int) $hours['grace_minutes'])->getTimestamp();
            if ($lastLogout !== null && !$noSignout && $open === null) {
                $early = max(0, intdiv($leave - $lastLogout, 60));
            }
        }
        $attendance = match (true) {
            !$scheduled => $signedIn > 0 ? 'worked_off' : 'off',
            $worked >= $hours['full_day_hours'] * 3600 => 'present',
            $worked >= $hours['half_day_hours'] * 3600 => 'half_day',
            $signedIn > 0 => 'short',
            default => 'absent',
        };

        $summary = [
            'first_login_at' => $firstLogin ? Carbon::createFromTimestamp($firstLogin) : null,
            'last_logout_at' => $lastLogout && $open === null ? Carbon::createFromTimestamp($lastLogout) : null,
            'no_signout' => $noSignout,
            'signed_in_s' => $signedIn, 'worked_s' => $worked, 'productive_s' => $productive,
            'active_s' => $secs['active'], 'customer_s' => $secs['customer'], 'travel_s' => $secs['travel'], 'break_s' => $secs['break'],
            'idle_s' => $secs['idle'], 'offline_s' => $secs['offline'], 'office_s' => $officeS,
            'idle_sessions' => count($idles), 'longest_idle_s' => $idles ? max(array_map(fn ($s) => $s['to'] - $s['from'], $idles)) : 0,
            'productivity_pct' => $worked > 0 ? min(100, (int) round($productive * 100 / $worked)) : null,
            'coverage_pct' => $signedIn > 0 ? (int) round(($signedIn - $secs['offline']) * 100 / $signedIn) : null,
            'distance_m' => (int) round($meters), 'talk_s' => $talk, 'calls' => $calls->count(), 'connected' => $connected,
            'visits' => count($visits), 'case_updates' => $caseEvents->whereIn('type', ['note', 'status'])->count(),
            'planned_stops' => count($planned), 'planned_visited' => count(array_intersect($planned, $visitedIds)),
            'late_min' => min(65535, $late), 'early_min' => min(65535, $early), 'scheduled' => $scheduled, 'attendance' => $attendance,
            'final' => !$today,
        ];
        $iso = fn (int $ts) => Carbon::createFromTimestamp($ts)->toAtomString();
        return [
            'summary' => $summary,
            'segments' => array_map(fn ($s) => ['state' => $s['state'], 'from' => $iso($s['from']), 'to' => $iso($s['to']), 'minutes' => intdiv($s['to'] - $s['from'], 60)], $segments),
            'logins' => $logins,
            'breaks' => array_map(fn ($b) => ['from' => $iso($b[0]), 'to' => $iso($b[1]), 'minutes' => intdiv($b[1] - $b[0], 60), 'reason' => $b[2]], $breaks),
            'idle' => array_map(fn ($s) => ['from' => $iso($s['from']), 'to' => $iso($s['to']), 'minutes' => intdiv($s['to'] - $s['from'], 60)], $idles),
            'visits' => $visits,
            'offices' => array_map(fn ($s) => (int) round($s / 60), $offices),
        ];
    }
}
