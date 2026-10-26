<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\ImportJob;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Portfolio reports. One case set (customers in scope + filters) drives every number, so totals never double count:
 *  cases = customers; calls = calls on those customers in the date range; collections = payment feedback (not rejected)
 *  on those calls, by paid-on date; callbacks = open follow-ups; "not made" = assigned case with no call in the range;
 *  "pending call" = called in the range but never connected; pending case = status new / in progress / follow-up.
 * Portfolio = the import file a customer came from (`last_import_id`); `manual` = customers added by hand.
 */
final class PortfolioReportController extends Controller
{
    private const OPEN = ['new', 'in_progress', 'follow_up'];

    public function show(Request $r)
    {
        [$cases, $range, $userId] = $this->caseSet($r);
        $ids = (clone $cases)->select('customers.id');
        $calls = $this->calls($ids, $range, $userId);
        $pay = $this->payments($ids, $range, $userId);
        $fu = FollowUp::query()->where('status', 'open')->whereIn('customer_id', clone $ids)->when($userId, fn ($q) => $q->where('user_id', $userId));

        // Per employee (assigned owner of the cases).
        $owned = (clone $cases)->whereNotNull('assigned_user_id')->selectRaw('assigned_user_id AS uid, COUNT(*) AS cases, SUM(CASE WHEN status IN (\'new\',\'in_progress\',\'follow_up\') THEN 1 ELSE 0 END) AS pending')
            ->groupBy('assigned_user_id')->get()->keyBy('uid');
        $notMade = $this->withoutCall((clone $cases)->whereNotNull('assigned_user_id'), $range)->selectRaw('assigned_user_id AS uid, COUNT(*) AS n')->groupBy('assigned_user_id')->pluck('n', 'uid');
        $notConnected = $this->calledNotConnected((clone $cases)->whereNotNull('assigned_user_id'), $range)->selectRaw('assigned_user_id AS uid, COUNT(*) AS n')->groupBy('assigned_user_id')->pluck('n', 'uid');
        $byUserCalls = (clone $calls)->selectRaw('user_id AS uid, COUNT(*) AS calls, SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) AS connected, MAX(initiated_at) AS last_at')
            ->groupBy('user_id')->get()->keyBy('uid');
        $byUserPay = (clone $pay)->selectRaw('call_dispositions.user_id AS uid, SUM(amount) AS amount, SUM(CASE WHEN review_status = \'verified\' THEN amount ELSE 0 END) AS verified, COUNT(*) AS n')
            ->groupBy('call_dispositions.user_id')->get()->keyBy('uid');
        $byUserFu = (clone $fu)->selectRaw('user_id AS uid, COUNT(*) AS n, SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END) AS overdue', [now()])->groupBy('user_id')->get()->keyBy('uid');
        $uids = collect([$owned->keys(), $byUserCalls->keys(), $byUserPay->keys()])->flatten()->filter()->unique();
        $users = User::query()->whereIn('id', $uids)->with('teams:id,name')->get(['id', 'name', 'employee_code'])->keyBy('id');
        $pfNames = $this->portfolioNames();
        $pfByUser = (clone $cases)->whereNotNull('assigned_user_id')->selectRaw('assigned_user_id AS uid, last_import_id AS pid')->distinct()->get()->groupBy('uid');
        $employees = $uids->map(function ($uid) use ($users, $owned, $notMade, $notConnected, $byUserCalls, $byUserPay, $byUserFu, $pfByUser, $pfNames) {
            $u = $users[$uid] ?? null;
            $c = $byUserCalls[$uid] ?? null;
            return ['user_id' => (int) $uid, 'name' => $u?->name ?? "#{$uid}", 'employee_code' => $u?->employee_code,
                'groups' => $u?->teams->pluck('name')->values() ?? [], 'group_ids' => $u?->teams->pluck('id')->map(fn ($i) => (int) $i)->values() ?? [], 'portfolios' => ($pfByUser[$uid] ?? collect())->map(fn ($x) => $pfNames[$x->pid ?? 0] ?? 'Added by hand')->unique()->values(),
                'cases' => (int) ($owned[$uid]->cases ?? 0), 'pending_cases' => (int) ($owned[$uid]->pending ?? 0),
                'calls' => (int) ($c->calls ?? 0), 'connected' => (int) ($c->connected ?? 0), 'not_connected_cases' => (int) ($notConnected[$uid] ?? 0),
                'not_made' => (int) ($notMade[$uid] ?? 0), 'callbacks' => (int) ($byUserFu[$uid]->n ?? 0), 'callbacks_overdue' => (int) ($byUserFu[$uid]->overdue ?? 0),
                'collected' => round((float) ($byUserPay[$uid]->amount ?? 0), 2), 'verified' => round((float) ($byUserPay[$uid]->verified ?? 0), 2),
                'last_activity' => $c?->last_at ? \Illuminate\Support\Carbon::parse($c->last_at)->toAtomString() : null];
        })->sortByDesc('cases')->values();

        // Per group: cases by assigned group, plus the group's employees' numbers.
        $teamCases = (clone $cases)->selectRaw('assigned_team_id AS tid, COUNT(*) AS cases, SUM(CASE WHEN assigned_user_id IS NULL THEN 1 ELSE 0 END) AS unassigned')->groupBy('assigned_team_id')->get();
        $teamNames = Team::query()->pluck('name', 'id');
        $groups = $teamCases->map(function ($t) use ($employees, $teamNames) {
            $name = $t->tid ? ($teamNames[$t->tid] ?? '?') : null;
            $emps = $employees->filter(fn ($e) => $t->tid !== null && collect($e['group_ids'])->contains((int) $t->tid));   // by id: two groups with one name never double-count
            return ['team_id' => $t->tid, 'name' => $name ?? 'No group', 'cases' => (int) $t->cases, 'unassigned' => (int) $t->unassigned, 'employees' => $emps->count(),
                'calls' => $emps->sum('calls'), 'connected' => $emps->sum('connected'), 'collected' => round($emps->sum('collected'), 2), 'callbacks' => $emps->sum('callbacks')];
        })->sortByDesc('cases')->values();

        $summary = [
            'cases' => (clone $cases)->count(),
            'unassigned' => (clone $cases)->whereNull('assigned_user_id')->count(),
            'pending_cases' => (clone $cases)->whereIn('status', self::OPEN)->count(),
            'calls' => (clone $calls)->count(),
            'connected' => (clone $calls)->whereNotNull('answered_at')->count(),
            'not_connected_cases' => $this->calledNotConnected((clone $cases)->whereNotNull('assigned_user_id'), $range)->count(),
            'not_made' => $this->withoutCall((clone $cases)->whereNotNull('assigned_user_id'), $range)->count(),
            'callbacks' => (clone $fu)->count(),
            'callbacks_overdue' => (clone $fu)->where('due_at', '<', now())->count(),
            'collected' => round((float) (clone $pay)->sum('amount'), 2),
            'verified' => round((float) (clone $pay)->where('review_status', 'verified')->sum('amount'), 2),
            'payments' => (clone $pay)->count(),
            'employees' => (clone $cases)->whereNotNull('assigned_user_id')->distinct()->count('assigned_user_id'),
            'groups' => (clone $cases)->whereNotNull('assigned_team_id')->distinct()->count('assigned_team_id'),
        ];

        // Trend by day / week / month (grouped in PHP: same on MySQL and SQLite). ponytail: fine up to ~100k calls in a range; move to SQL GROUP BY if bigger.
        $period = in_array($r->query('period'), ['week', 'month'], true) ? $r->query('period') : 'day';
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
        $key = function ($dt) use ($period, $tz) {
            $d = \Illuminate\Support\Carbon::parse($dt)->setTimezone($tz);
            return match ($period) { 'week' => $d->startOfWeek()->toDateString(), 'month' => $d->format('Y-m-01'), default => $d->toDateString() };
        };
        $trend = [];
        foreach ((clone $calls)->get(['initiated_at', 'answered_at']) as $c) {
            $k = $key($c->initiated_at);
            $trend[$k] ??= ['period' => $k, 'calls' => 0, 'connected' => 0, 'collected' => 0.0];
            $trend[$k]['calls']++;
            $c->answered_at && $trend[$k]['connected']++;
        }
        foreach ((clone $pay)->get(['paid_on', 'amount']) as $p) {
            $k = $key($p->paid_on);
            $trend[$k] ??= ['period' => $k, 'calls' => 0, 'connected' => 0, 'collected' => 0.0];
            $trend[$k]['collected'] += (float) $p->amount;
        }
        ksort($trend);

        $outcomes = CallDisposition::query()->whereIn('call_id', (clone $calls)->select('calls.id'))->selectRaw('code, COUNT(*) AS n')->groupBy('code')->orderByDesc('n')->get();
        $pf = $r->query('portfolio');
        $job = is_numeric($pf) ? ImportJob::query()->find((int) $pf) : null;

        return response()->json(['summary' => $summary, 'employees' => $employees, 'groups' => $groups, 'trend' => array_values($trend), 'period' => $period,
            'outcomes' => $outcomes, 'portfolio' => $job ? ['id' => $job->id, 'name' => $job->portfolio ?: $job->original_name, 'file' => $job->original_name,
                'uploaded_at' => $job->created_at, 'rows' => $job->total_rows] : ($pf === 'manual' ? ['id' => 'manual', 'name' => 'Added by hand'] : null),
            'portfolios' => $this->portfolioList($r)]);
    }

    /**
     * GET reports/portfolio.csv?by=portfolio|employee + the same filters as the report page → CSV (audited, formula-safe).
     * Portfolio-wise = the report summary once per portfolio, plus an "All portfolios" total (a case has one portfolio, so no double count).
     * ponytail: one full report per portfolio; fine for dozens of portfolios, move to one grouped query if there are hundreds.
     */
    public function export(Request $r)
    {
        $by = $r->query('by') === 'employee' ? 'employee' : 'portfolio';
        \App\Support\Audit::record('report.portfolio_exported', null, ['by' => $by] + $r->query());
        $pct = fn ($a, $b) => $b ? round($a * 100 / $b) . '%' : '';
        if ($by === 'employee') {
            $head = ['Employee', 'Employee code', 'Groups', 'Portfolios', 'Cases', 'Pending cases', 'Calls', 'Connected', 'Connect rate', 'Cases not called', 'Called, not connected',
                'Callbacks pending', 'Callbacks overdue', 'Collected', 'Verified', 'Last call'];
            $rows = array_map(fn ($e) => [$e['name'], $e['employee_code'], implode(', ', $e['groups']), implode(', ', $e['portfolios']), $e['cases'], $e['pending_cases'],
                $e['calls'], $e['connected'], $pct($e['connected'], $e['calls']), $e['not_made'], $e['not_connected_cases'], $e['callbacks'], $e['callbacks_overdue'],
                $e['collected'], $e['verified'], $e['last_activity']], $this->show($r)->getData(true)['employees']);
        } else {
            $head = ['Portfolio', 'Cases', 'Unassigned', 'Pending cases', 'Calls', 'Connected', 'Connect rate', 'Cases not called', 'Called, not connected',
                'Callbacks pending', 'Callbacks overdue', 'Payments', 'Collected', 'Verified', 'Employees', 'Groups'];
            $line = fn (string $name, array $s) => [$name, $s['cases'], $s['unassigned'], $s['pending_cases'], $s['calls'], $s['connected'], $pct($s['connected'], $s['calls']),
                $s['not_made'], $s['not_connected_cases'], $s['callbacks'], $s['callbacks_overdue'], $s['payments'], $s['collected'], $s['verified'], $s['employees'], $s['groups']];
            $only = (string) $r->query('portfolio', '');
            $rows = [];
            foreach ($this->portfolioList($r) as $p) {
                if ($only !== '' && (string) $p['id'] !== $only) continue;
                $sub = $r->duplicate(['portfolio' => (string) $p['id']] + $r->query());
                $sub->setUserResolver($r->getUserResolver());
                $rows[] = $line($p['name'], $this->show($sub)->getData(true)['summary']);
            }
            if ($only === '' && count($rows) > 1) $rows[] = $line('All portfolios', $this->show($r)->getData(true)['summary']);
        }
        $name = $by === 'employee' ? 'employee-wise.csv' : 'portfolio-wise.csv';
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $head);
            foreach ($rows as $row) fputcsv($out, \App\Domain\Security\SpreadsheetSanitiser::row($row));
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** Case-level drill-down (paginated): one row per customer with its calls, last outcome, callback and collection. */
    public function cases(Request $r)
    {
        [$cases, $range, $userId] = $this->caseSet($r);
        $inRange = fn ($q) => $q->where('provider', '!=', 'manual')->when($range[0], fn ($w) => $w->where('initiated_at', '>=', $range[0]))->when($range[1], fn ($w) => $w->where('initiated_at', '<', $range[1]));
        $sort = in_array($r->query('sort'), ['name', 'calls_count', 'last_call_at', 'collected'], true) ? $r->query('sort') : 'id';
        $page = $cases->with(['assignee:id,name,employee_code'])
            ->withCount(['calls as calls_count' => $inRange, 'calls as connected_count' => fn ($q) => $inRange($q)->whereNotNull('answered_at')])
            ->withMax(['calls as last_call_at' => $inRange], 'initiated_at')
            ->addSelect(['collected' => CallDisposition::query()->selectRaw('COALESCE(SUM(amount),0)')->whereNotNull('payment_mode')->where(fn ($w) => $w->whereNull('review_status')->orWhere('review_status', '!=', 'rejected'))
                ->whereIn('call_id', Call::query()->select('id')->whereColumn('calls.customer_id', 'customers.id'))
                ->when($range[2], fn ($q) => $q->where('paid_on', '>=', $range[2]))->when($range[3], fn ($q) => $q->where('paid_on', '<', $range[3]))])   // same dates as the Collections tile
            ->addSelect(['last_outcome' => CallDisposition::query()->select('code')->whereIn('call_id', Call::query()->select('id')->whereColumn('calls.customer_id', 'customers.id'))->orderByDesc('submitted_at')->limit(1)])
            ->addSelect(['callback_due' => FollowUp::query()->selectRaw('MIN(due_at)')->where('status', 'open')->whereColumn('follow_ups.customer_id', 'customers.id')])
            ->orderBy($sort, in_array($sort, ['name', 'id'], true) ? 'asc' : 'desc')->paginate(50);
        $teams = Team::query()->pluck('name', 'id');
        $names = $this->portfolioNames();
        return response()->json($page->through(fn (Customer $c) => ['id' => $c->id, 'customer_ref' => $c->customer_ref, 'name' => $c->name, 'status' => $c->status,
            'city' => $c->city, 'portfolio' => $names[$c->last_import_id ?? 0] ?? 'Added by hand', 'group' => $teams[$c->assigned_team_id] ?? null,
            'employee' => $c->assignee?->name, 'calls' => (int) $c->calls_count, 'connected' => (int) $c->connected_count, 'last_call_at' => $c->last_call_at,
            'last_outcome' => $c->last_outcome, 'callback_due' => $c->callback_due, 'collected' => round((float) $c->collected, 2)]));
    }

    /** @return array{0: Builder, 1: array{0:?string,1:?string,2:?string,3:?string}, 2:?int} */
    private function caseSet(Request $r): array
    {
        $q = fn (string $k) => ($v = $r->query($k)) !== null && $v !== '' ? (string) $v : null;
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
        $range = [$q('from') ? \Illuminate\Support\Carbon::parse($q('from'), $tz)->startOfDay()->utc()->toDateTimeString() : null,
            $q('to') ? \Illuminate\Support\Carbon::parse($q('to'), $tz)->addDay()->startOfDay()->utc()->toDateTimeString() : null,
            // local calendar dates for paid_on (a DATE column): [from, to + 1 day)
            $q('from') ? \Illuminate\Support\Carbon::parse($q('from'))->toDateString() : null, $q('to') ? \Illuminate\Support\Carbon::parse($q('to'))->addDay()->toDateString() : null];
        $userId = ctype_digit((string) $q('employee')) ? (int) $q('employee') : null;
        $f = ['team_id' => $q('group'), 'status' => $q('case_status'), 'assigned' => match ($q('assigned')) { 'assigned' => 'any', 'unassigned' => 'none', default => $userId ? (string) $userId : null }];
        $cases = CustomerController::filtered(array_filter($f, fn ($v) => $v !== null), $r->user());
        $pf = $q('portfolio');
        $cases->when($pf === 'manual', fn ($w) => $w->whereNull('last_import_id'))->when(ctype_digit((string) $pf), fn ($w) => $w->where('last_import_id', (int) $pf));
        $inRange = fn ($w) => $w->when($range[0], fn ($x) => $x->where('initiated_at', '>=', $range[0]))->when($range[1], fn ($x) => $x->where('initiated_at', '<', $range[1]));
        match ($q('call_status')) {
            'connected' => $cases->whereHas('calls', fn ($w) => $inRange($w)->whereNotNull('answered_at')),
            'not_connected' => $this->calledNotConnected($cases, $range),
            'not_called' => $this->withoutCall($cases, $range),
            default => null,
        };
        $paid = fn ($w) => $w->whereHas('disposition', fn ($d) => $d->whereNotNull('payment_mode')->where(fn ($x) => $x->whereNull('review_status')->orWhere('review_status', '!=', 'rejected')));
        match ($q('collection')) {
            'paid' => $cases->whereHas('calls', $paid),
            'verified' => $cases->whereHas('calls', fn ($w) => $w->whereHas('disposition', fn ($d) => $d->where('review_status', 'verified'))),
            'to_check' => $cases->whereHas('calls', fn ($w) => $w->whereHas('disposition', fn ($d) => $d->whereNotNull('payment_mode')->where('review_status', 'pending'))),
            'none' => $cases->whereDoesntHave('calls', $paid),
            default => null,
        };
        match ($q('callback')) {
            'pending' => $cases->whereHas('followUps', fn ($w) => $w->where('status', 'open')),
            'overdue' => $cases->whereHas('followUps', fn ($w) => $w->where('status', 'open')->where('due_at', '<', now())),
            'none' => $cases->whereDoesntHave('followUps', fn ($w) => $w->where('status', 'open')),
            default => null,
        };
        return [$cases, $range, $userId];
    }

    private function calls(Builder $ids, array $range, ?int $userId): Builder
    {
        return Call::query()->where('provider', '!=', 'manual')->whereIn('customer_id', $ids)->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($range[0], fn ($q) => $q->where('initiated_at', '>=', $range[0]))->when($range[1], fn ($q) => $q->where('initiated_at', '<', $range[1]));
    }

    private function payments(Builder $ids, array $range, ?int $userId): Builder
    {
        return CallDisposition::query()->whereNotNull('payment_mode')->where(fn ($w) => $w->whereNull('review_status')->orWhere('review_status', '!=', 'rejected'))
            ->whereIn('call_id', Call::query()->select('id')->whereIn('customer_id', $ids))->when($userId, fn ($q) => $q->where('call_dispositions.user_id', $userId))
            ->when($range[2], fn ($q) => $q->where('paid_on', '>=', $range[2]))->when($range[3], fn ($q) => $q->where('paid_on', '<', $range[3]));
    }

    private function withoutCall(Builder $cases, array $range): Builder
    {
        return $cases->whereDoesntHave('calls', fn ($w) => $w->where('provider', '!=', 'manual')   // a field entry without a call is not a call (same as Total calls)
            ->when($range[0], fn ($x) => $x->where('initiated_at', '>=', $range[0]))->when($range[1], fn ($x) => $x->where('initiated_at', '<', $range[1])));
    }

    private function calledNotConnected(Builder $cases, array $range): Builder
    {
        $in = fn ($w) => $w->where('provider', '!=', 'manual')->when($range[0], fn ($x) => $x->where('initiated_at', '>=', $range[0]))->when($range[1], fn ($x) => $x->where('initiated_at', '<', $range[1]));
        return $cases->whereHas('calls', $in)->whereDoesntHave('calls', fn ($w) => $in($w)->whereNotNull('answered_at'));
    }

    private function portfolioNames(): array
    {
        return ImportJob::query()->get(['id', 'portfolio', 'original_name'])->mapWithKeys(fn ($j) => [$j->id => $j->portfolio ?: pathinfo($j->original_name, PATHINFO_FILENAME)])->all();
    }

    /** Portfolios the actor can see (with case counts) for the picker; `manual` when there are hand-added customers. */
    private function portfolioList(Request $r): array
    {
        $names = $this->portfolioNames();
        return CustomerController::filtered([], $r->user())->selectRaw('last_import_id AS pid, COUNT(*) AS n')->groupBy('last_import_id')->get()
            ->map(fn ($x) => ['id' => $x->pid ?? 'manual', 'name' => $x->pid ? ($names[$x->pid] ?? "Import #{$x->pid}") : 'Added by hand', 'cases' => (int) $x->n])
            ->sortByDesc(fn ($x) => is_int($x['id']) ? $x['id'] : -1)->values()->all();
    }
}
