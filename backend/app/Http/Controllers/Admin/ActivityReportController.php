<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Jobs\BuildActivityDays;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailyActivity;
use App\Models\User;
use App\Services\Activity\DayBuilder;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Reports → Employee activity: one employee's day (timeline), one employee over a period, the team over a period,
 * Excel / PDF (print page), and the work hours + office locations the report measures against.
 * Scoping: the existing team rules (canSeeUser / usersInView); distance needs location.view as on the Day route.
 */
final class ActivityReportController extends Controller
{
    private const MAX_DAYS = 92;

    /** Daily-row columns summed into period totals. */
    private const SUM = ['signed_in_s', 'worked_s', 'productive_s', 'active_s', 'customer_s', 'travel_s', 'break_s', 'idle_s', 'offline_s', 'office_s',
        'talk_s', 'idle_sessions', 'distance_m', 'calls', 'connected', 'visits', 'case_updates', 'planned_stops', 'planned_visited', 'late_min', 'early_min'];

    public function __construct(private readonly DayBuilder $builder)
    {
    }

    /** GET activity/day?user_id=&date= — the full day: timeline, logins, breaks, idle sessions, visits. */
    public function day(Request $r)
    {
        $d = $r->validate(['user_id' => 'required|integer', 'date' => 'nullable|date_format:Y-m-d']);
        $u = $this->person($r, (int) $d['user_id']);
        $date = $d['date'] ?? $this->today();
        $date <= $this->today() || throw new DomainException('future_date', 'Pick today or an earlier day.');
        $built = $this->builder->build($u, $date);
        $this->builder->save($u, $date, $built);
        Audit::record('activity.viewed', $u, ['date' => $date]);
        $names = Customer::query()->whereIn('id', array_column($built['visits'], 'customer_id'))->get(['id', 'name', 'customer_ref'])->keyBy('id');
        $built['visits'] = array_map(fn ($v) => $v + ['name' => $names[$v['customer_id']]->name ?? null, 'customer_ref' => $names[$v['customer_id']]->customer_ref ?? null], $built['visits']);
        return response()->json(['employee' => $this->who($u), 'date' => $date, 'hours' => DayBuilder::hours()] + $this->redact($r, $built));
    }

    /** GET activity/employee?user_id=&from=&to= — one row per day + totals. Missing days are built on the spot. */
    public function employee(Request $r)
    {
        $d = $r->validate(['user_id' => 'required|integer', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $u = $this->person($r, (int) $d['user_id']);
        [$from, $to] = $this->range($d['from'], $d['to']);
        $rows = $this->ensure($u, $from, $to);
        Audit::record('activity.viewed', $u, ['from' => $from, 'to' => $to]);
        return response()->json(['employee' => $this->who($u), 'from' => $from, 'to' => $to, 'hours' => DayBuilder::hours(),
            'days' => $rows->map(fn (DailyActivity $a) => $this->row($r, $a))->values(), 'totals' => $this->totals($r, $rows, $from, $to)]);
    }

    /** GET activity/team?from=&to= — every employee in the viewer's groups (and the top-bar filter), with period totals. */
    public function team(Request $r)
    {
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        [$from, $to] = $this->range($d['from'], $d['to']);
        $users = $this->staff($r);
        $building = $this->backfill($from, $to);   // before reading: with a sync queue the rows are ready now
        $rows = DailyActivity::query()->whereIn('user_id', $users->pluck('id'))->whereBetween('work_date', [$from, $to])->get()->groupBy('user_id');
        $list = $users->map(fn (User $u) => ['employee' => $this->who($u)] + $this->totals($r, $rows[$u->id] ?? collect(), $from, $to))->values();
        return response()->json(['from' => $from, 'to' => $to, 'hours' => DayBuilder::hours(), 'building' => $building, 'employees' => $list,
            'totals' => $this->totals($r, $rows->flatten(1), $from, $to, max(1, $users->count()))]);
    }

    /** GET activity/export?kind=employee|team&format=xlsx|pdf&user_id=&from=&to= */
    public function export(Request $r)
    {
        $d = $r->validate(['kind' => 'required|in:employee,team', 'format' => 'required|in:xlsx,pdf', 'user_id' => 'required_if:kind,employee|integer',
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        [$from, $to] = $this->range($d['from'], $d['to']);
        $tz = CurrentCompany::tz();
        $hms = fn (?int $s) => $s === null ? '' : sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
        $time = fn ($t) => $t ? Carbon::parse($t)->setTimezone($tz)->format('H:i') : '';
        $sheets = [];
        if ($d['kind'] === 'employee') {
            $u = $this->person($r, (int) $d['user_id']);
            $rows = $this->ensure($u, $from, $to);
            $t = $this->totals($r, $rows, $from, $to);
            $title = "Employee activity — {$u->name} ({$u->employee_code})";
            $sheets['Summary'] = [['Measure', 'Value'], ['Employee ID', $u->employee_code], ['Employee name', $u->name], ...$this->summaryLines($t, $hms)];
            $sheets['Daily'] = [self::DAILY_HEAD, ...$rows->map(fn ($a) => $this->dailyLine($r, $a, $hms, $time))->all()];
            if ($from === $to) {
                $day = $this->builder->build($u, $from);
                $sheets['Timeline'] = [['From', 'To', 'State', 'Minutes'], ...array_map(fn ($s) => [$time($s['from']), $time($s['to']), self::LABEL[$s['state']], $s['minutes']], $day['segments'])];
                $sheets['Logins'] = [['Time', 'Event'], ...array_map(fn ($l) => [$time($l['at']), $l['type'] === 'sign_in' ? 'Signed in' : 'Signed out'], $day['logins'])];
                $sheets['Breaks'] = [['From', 'To', 'Minutes', 'Reason'], ...array_map(fn ($b) => [$time($b['from']), $time($b['to']), $b['minutes'], $b['reason']], $day['breaks'])];
                $sheets['Idle'] = [['From', 'To', 'Minutes'], ...array_map(fn ($b) => [$time($b['from']), $time($b['to']), $b['minutes']], $day['idle'])];
            }
            Audit::record('activity.exported', $u, ['from' => $from, 'to' => $to, 'format' => $d['format']]);
        } else {
            $users = $this->staff($r);
            $rows = DailyActivity::query()->whereIn('user_id', $users->pluck('id'))->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get()->groupBy('user_id');
            $title = 'Team activity';
            $avg = $from === $to ? '' : 'Avg ';
            $sheets['Team'] = [['Code', 'Employee', "{$avg}Login", "{$avg}Logout", 'Days worked', 'Attendance %', 'Late days', 'Worked', 'Productive', 'Productivity %', 'Idle', 'Break', 'Travel', 'Offline', 'Distance km', 'Calls', 'Connected', 'Visits'],
                ...$users->map(function (User $u) use ($r, $rows, $from, $to, $hms) {
                    $t = $this->totals($r, $rows[$u->id] ?? collect(), $from, $to);
                    return [$u->employee_code, $u->name, $t['login'] ?? '', $t['logout'] ?? '', $t['days_worked'], $t['attendance_pct'], $t['late_days'], $hms($t['worked_s']), $hms($t['productive_s']),
                        $t['productivity_pct'], $hms($t['idle_s']), $hms($t['break_s']), $hms($t['travel_s']), $hms($t['offline_s']),
                        $t['distance_m'] === null ? '' : round($t['distance_m'] / 1000, 1), $t['calls'], $t['connected'], $t['visits']];
                })->all()];
            $names = $users->keyBy('id');
            $sheets['Daily'] = [['Code', 'Employee', ...self::DAILY_HEAD], ...$rows->flatten(1)->sortBy(fn ($a) => $a->work_date->format('Y-m-d') . $a->user_id)
                ->map(fn ($a) => [$names[$a->user_id]->employee_code ?? '', $names[$a->user_id]->name ?? '', ...$this->dailyLine($r, $a, $hms, $time)])->values()->all()];
            Audit::record('activity.exported', null, ['from' => $from, 'to' => $to, 'format' => $d['format'], 'kind' => 'team']);
        }
        $period = $from === $to ? Carbon::parse($from)->format('d M Y') : Carbon::parse($from)->format('d M Y') . ' to ' . Carbon::parse($to)->format('d M Y');
        $file = 'activity-' . ($d['kind'] === 'employee' ? ($u->employee_code ?: $u->id) : 'team') . "-{$from}" . ($from === $to ? '' : "-to-{$to}");
        if ($d['format'] === 'pdf') {
            return response()->view('reports.activity', ['title' => $title, 'period' => $period, 'sheets' => $sheets,
                'company' => app(CurrentCompany::class)->get()->name, 'printed' => now($tz)->format('d M Y, h:i A')]);
        }
        abort_unless(class_exists(\ZipArchive::class), 503, 'Excel files cannot be made on this server: the PHP "zip" extension is switched off. Ask your administrator to enable it (php.ini: extension=zip), or use PDF.');
        $path = tempnam(sys_get_temp_dir(), 'act') . '.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        $first = true;
        foreach ($sheets as $name => $lines) {   // first line of each sheet = its header
            $first ? $first = false : $w->addNewSheetAndMakeItCurrent();
            $intro = $name === 'Summary' || $name === 'Team' ? [[$title], ["Period: {$period}"]] : [];
            \App\Support\Excel::sheet($w, $name, array_map('strval', $lines[0] ?? []), array_slice($lines, 1), $intro);
        }
        $w->close();
        return response()->download($path, "{$file}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** GET activity/settings — work hours + branches with their office location. */
    public function settings()
    {
        return response()->json(['hours' => DayBuilder::hours(), 'defaults' => DayBuilder::DEFAULT_HOURS,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name', 'lat', 'lng', 'radius_m'])]);
    }

    /** PUT activity/settings — work hours; branch office locations. Rebuilds today so the change shows at once. */
    public function saveSettings(Request $r)
    {
        $d = $r->validate([
            'hours' => 'required|array', 'hours.days' => 'required|array|min:1', 'hours.days.*' => 'integer|between:1,7',
            'hours.start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'hours.end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hours.grace_minutes' => 'required|integer|between:0,120', 'hours.full_day_hours' => 'required|numeric|between:1,16',
            'hours.half_day_hours' => 'required|numeric|between:0.5,12', 'hours.idle_minutes' => 'required|integer|between:3,120',
            'hours.travel_productive' => 'required|boolean',
            'branches' => 'array', 'branches.*.id' => 'required|integer', 'branches.*.lat' => 'nullable|numeric|between:-90,90',
            'branches.*.lng' => 'nullable|numeric|between:-180,180', 'branches.*.radius_m' => 'nullable|integer|between:30,2000',
        ]);
        $d['hours']['start'] < $d['hours']['end'] || throw new DomainException('invalid_hours', 'The day must end after it starts.');
        $c = app(CurrentCompany::class)->get();
        $s = $c->settings ?? [];
        $h = $d['hours'];
        $h['days'] = array_values(array_unique(array_map('intval', $h['days'])));
        sort($h['days']);
        $h['travel_productive'] = (bool) $h['travel_productive'];
        data_set($s, 'work_hours', $h);
        $c->forceFill(['settings' => $s])->save();
        foreach ($d['branches'] ?? [] as $b) {
            $both = isset($b['lat'], $b['lng']);
            Branch::query()->whereKey($b['id'])->update(['lat' => $both ? $b['lat'] : null, 'lng' => $both ? $b['lng'] : null, 'radius_m' => $b['radius_m'] ?? 150]);
        }
        Audit::record('activity.settings_changed', null, ['hours' => $h]);
        BuildActivityDays::dispatch($c->id, $this->today());
        return response()->json(['ok' => true, 'hours' => DayBuilder::hours()]);
    }

    private const LABEL = ['active' => 'Active work', 'customer' => 'At customer', 'travel' => 'Travel', 'break' => 'Break', 'idle' => 'Idle', 'offline' => 'Offline'];

    private const ATTENDANCE = ['present' => 'Present', 'half_day' => 'Half day', 'short' => 'Short day', 'absent' => 'Absent', 'off' => 'Weekly off', 'worked_off' => 'Worked on day off'];

    private const DAILY_HEAD = ['Date', 'Attendance', 'First login', 'Last logout', 'Late min', 'Early min', 'Signed in', 'Worked', 'Productive',
        'Productivity %', 'Active', 'At customer', 'Travel', 'Break', 'Idle', 'Idle sessions', 'Offline', 'Distance km', 'Calls', 'Connected', 'Talk', 'Visits', 'Data coverage %'];

    private function dailyLine(Request $r, DailyActivity $a, callable $hms, callable $time): array
    {
        $x = $this->row($r, $a);
        return [$a->work_date->format('Y-m-d'), $a->final ? (self::ATTENDANCE[$a->attendance] ?? $a->attendance) : 'In progress', $time($a->first_login_at), $a->no_signout ? 'no sign-out' : $time($a->last_logout_at),
            $a->late_min, $a->early_min, $hms($a->signed_in_s), $hms($a->worked_s), $hms($a->productive_s), $a->productivity_pct, $hms($a->active_s),
            $hms($a->customer_s), $hms($a->travel_s), $hms($a->break_s), $hms($a->idle_s), $a->idle_sessions, $hms($a->offline_s),
            $x['distance_m'] === null ? '' : round($x['distance_m'] / 1000, 1), $a->calls, $a->connected, $hms($a->talk_s), $a->visits, $a->coverage_pct];
    }

    private function summaryLines(array $t, callable $hms): array
    {
        return [
            [$t['login_avg'] ? 'Average login time' : 'Login time', $t['login'] ?? ''], [$t['login_avg'] ? 'Average logout time' : 'Logout time', $t['logout'] ?? ''],
            ['Scheduled working days', $t['scheduled_days']], ['Days worked', $t['days_worked']], ['Attendance %', $t['attendance_pct']],
            ['Present / half day / short / absent', "{$t['present']} / {$t['half_day']} / {$t['short']} / {$t['absent']}"],
            ['Late arrivals (days)', $t['late_days']], ['Early departures (days)', $t['early_days']],
            ['Signed in', $hms($t['signed_in_s'])], ['Worked (signed in − break − offline)', $hms($t['worked_s'])], ['Productive', $hms($t['productive_s'])],
            ['Productivity %', $t['productivity_pct']], ['Active work', $hms($t['active_s'])], ['At customers', $hms($t['customer_s'])],
            ['Travel', $hms($t['travel_s'])], ['Break', $hms($t['break_s'])], ['Idle', $hms($t['idle_s'])], ['Idle sessions', $t['idle_sessions']],
            ['Offline (no data)', $hms($t['offline_s'])], ['At office', $hms($t['office_s'])],
            ['Distance (km)', $t['distance_m'] === null ? '' : round($t['distance_m'] / 1000, 1)], ['Calls / connected', "{$t['calls']} / {$t['connected']}"],
            ['Talk time', $hms($t['talk_s'])], ['Customer visits', $t['visits']], ['Planned stops visited', "{$t['planned_visited']} of {$t['planned_stops']}"],
        ];
    }

    private function person(Request $r, int $id): User
    {
        $u = User::query()->findOrFail($id);
        $r->user()->canSeeUser($u) || throw new DomainException('forbidden');
        return $u;
    }

    /** Active people who use the phone app, in the viewer's groups and the console's Branch / Team / Employee filter. */
    private function staff(Request $r): Collection
    {
        $ids = $r->user()->usersInView();
        return User::query()->where('status', 'active')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('mobile.work'))->values();
    }

    private function who(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'employee_code' => $u->employee_code];
    }

    private function today(): string
    {
        return now(CurrentCompany::tz())->toDateString();
    }

    /** @return array{string, string} the range clipped to today and to MAX_DAYS. */
    private function range(string $from, string $to): array
    {
        $to = min($to, $this->today());
        $from = min($from, $to);
        Carbon::parse($from)->diffInDays(Carbon::parse($to)) < self::MAX_DAYS || throw new DomainException('range_too_long', 'Pick at most ' . self::MAX_DAYS . ' days.');
        return [$from, $to];
    }

    /** One employee's rows for the range; days never built (or today's, if older than 2 minutes) are built now. */
    private function ensure(User $u, string $from, string $to): Collection
    {
        $have = DailyActivity::query()->where('user_id', $u->id)->whereBetween('work_date', [$from, $to])->get()->keyBy(fn ($a) => $a->work_date->format('Y-m-d'));
        $today = $this->today();
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $k = $d->toDateString();
            $a = $have[$k] ?? null;
            if ($a === null || (!$a->final && ($k < $today || $a->built_at->lt(now()->subMinutes(2))))) {
                $have[$k] = $this->builder->save($u, $k);
            }
        }
        return $have->sortKeys()->values();
    }

    /** Team view: queue a build for past days nobody has a row for yet (new install, or before this report existed). */
    private function backfill(string $from, string $to): bool
    {
        $built = DailyActivity::query()->whereBetween('work_date', [$from, $to])->distinct()->pluck('work_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $queued = false;
        $cid = app(CurrentCompany::class)->id();
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $k = $d->toDateString();
            if (!isset($built[$k]) && !Cache::has("activity-built:{$cid}:{$k}") && Cache::add("activity-backfill:{$cid}:{$k}", 1, 3600)) {
                BuildActivityDays::dispatch($cid, $k);
                $queued = true;
            }
        }
        return $queued && config('queue.default') !== 'sync';
    }

    private function row(Request $r, DailyActivity $a): array
    {
        $x = $a->toArray();
        $x['work_date'] = $a->work_date->format('Y-m-d');
        if (!$r->user()->hasPermission('location.view')) {
            $x['distance_m'] = null;
        }
        unset($x['company_id']);
        return $x;
    }

    private function redact(Request $r, array $built): array
    {
        if (!$r->user()->hasPermission('location.view')) {
            $built['summary']['distance_m'] = null;
            $built['offices'] = [];
        }
        return $built;
    }

    /** Period totals. Attendance % = (present + half of half days) ÷ scheduled working days up to today. */
    private function totals(Request $r, Collection $rows, string $from, string $to, int $people = 1): array
    {
        $hours = DayBuilder::hours();
        $t = [];
        foreach (self::SUM as $k) {
            $t[$k] = (int) $rows->sum($k);
        }
        $scheduled = 0;
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            in_array($d->dayOfWeekIso, array_map('intval', (array) $hours['days']), true) && $scheduled++;
        }
        $scheduled *= $people;
        $count = fn (string $v) => $rows->where('attendance', $v)->count();
        $t += [
            'scheduled_days' => $scheduled,
            'days_worked' => $rows->where('signed_in_s', '>', 0)->count(),
            'present' => $count('present'), 'half_day' => $count('half_day'), 'short' => $count('short'),
            'absent' => max(0, $scheduled - $rows->where('scheduled', true)->where('signed_in_s', '>', 0)->count()),
            'worked_off' => $count('worked_off'),
            'late_days' => $rows->where('late_min', '>', 0)->count(), 'early_days' => $rows->where('early_min', '>', 0)->count(),
            'no_signout_days' => $rows->where('no_signout', true)->count(),
            'productivity_pct' => $t['worked_s'] > 0 ? (int) round($t['productive_s'] * 100 / $t['worked_s']) : null,
            'coverage_pct' => $t['signed_in_s'] > 0 ? (int) round(($t['signed_in_s'] - $t['offline_s']) * 100 / $t['signed_in_s']) : null,
            'longest_idle_s' => (int) $rows->max('longest_idle_s'),
        ];
        // Login / logout: the actual times for one day, else the average over the days worked (company time zone, HH:MM).
        $tz = CurrentCompany::tz();
        $clock = function (string $col) use ($rows, $tz): ?string {
            $mins = $rows->pluck($col)->filter()->map(fn ($at) => ($c = Carbon::parse($at)->setTimezone($tz))->hour * 60 + $c->minute);
            if ($mins->isEmpty()) {
                return null;
            }
            $m = (int) round($mins->avg());
            return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        };
        $t['login'] = $clock('first_login_at');
        $t['logout'] = $clock('last_logout_at') ?? ($rows->count() === 1 && $rows->first()->no_signout ? 'no sign-out' : null);
        $t['login_avg'] = $rows->whereNotNull('first_login_at')->count() > 1;
        $t['attendance_pct'] = $scheduled > 0 ? (int) round(($t['present'] + $t['half_day'] / 2) * 100 / $scheduled) : null;
        $t['high_idle'] = $t['worked_s'] >= 3600 && $t['idle_s'] * 4 >= $t['worked_s'];   // idle ≥ 25% of worked time
        if (!$r->user()->hasPermission('location.view')) {
            $t['distance_m'] = null;
        }
        return $t;
    }
}
