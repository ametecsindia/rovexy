<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\FieldCircle;
use App\Models\ManagedDevice;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use App\Services\GoogleMaps;
use App\Services\PushService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * G7 circles + G8 route plans (admin console → Field planning).
 *
 * Circle: each field employee's own working area (centre + radius). Customers inside it can be given to that employee
 * in one click, and route plans pick from them first.
 * Plan: the admin ticks the customers an employee should visit on a day. Saving puts them in straight-line order (free).
 * "Optimise" asks Google ONCE for the best two-wheeler order (the only two-wheeler call in ROVEXY); the answer is kept
 * while the stops don't change. "Publish" sends it to the employee's phone (Today's route, G9) — no Google call there.
 */
final class FieldPlanController extends Controller
{
    /** Field employees the admin may plan for, with their circle and how many of their cases have a pin / sit inside it. */
    public function employees(Request $r)
    {
        $circles = FieldCircle::query()->get()->keyBy('user_id');
        $date = $this->date($r);
        $plans = RoutePlan::query()->where('plan_date', $date)->get()->keyBy('user_id');
        $rows = $this->fieldStaff($r)->map(function (User $u) use ($circles, $plans) {
            $cases = Customer::query()->visibleTo($u)->get(['id', 'lat', 'lng']);
            $pinned = $cases->filter(fn ($c) => Customer::validCoords($c->lat, $c->lng));
            $circle = $circles->get($u->id);
            $plan = $plans->get($u->id);
            return ['id' => $u->id, 'name' => $u->name, 'employee_code' => $u->employee_code, 'cases' => $cases->count(), 'pinned' => $pinned->count(),
                'inside' => $circle ? $pinned->filter(fn ($c) => $circle->contains($c->lat, $c->lng))->count() : null,
                'circle' => $circle?->only(['name', 'lat', 'lng', 'radius_m']),
                'plan' => $plan ? ['status' => $plan->status, 'stops' => count($plan->stops), 'distance_m' => $plan->distance_m, 'duration_s' => $plan->duration_s, 'optimised' => $plan->optimised] : null];
        });
        return response()->json(['date' => $date, 'employees' => $rows->values()]);
    }

    public function saveCircle(Request $r, int $userId)
    {
        $u = $this->employee($r, $userId);
        $d = $r->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'radius_m' => 'required|integer|min:200|max:100000',
            'name' => 'nullable|string|max:100']);
        abort_unless(Customer::validCoords($d['lat'], $d['lng']), 422, 'Pick the centre of the circle on the map.');
        $c = FieldCircle::query()->updateOrCreate(['user_id' => $u->id], $d + ['updated_by' => $r->user()->id]);
        Audit::record('field.circle_saved', $u, ['radius_m' => $c->radius_m]);
        return response()->json($c->only(['name', 'lat', 'lng', 'radius_m']));
    }

    public function deleteCircle(Request $r, int $userId)
    {
        $u = $this->employee($r, $userId);
        FieldCircle::query()->where('user_id', $u->id)->delete();
        Audit::record('field.circle_removed', $u, []);
        return response()->json(['ok' => true]);
    }

    /**
     * Give this employee the customers with a pin inside the circle. Default: only customers no employee has yet;
     * include_assigned also moves cases from other employees (logged as reassigned, like any transfer).
     */
    public function assignInCircle(Request $r, int $userId, CustomerAssignmentService $svc)
    {
        $u = $this->employee($r, $userId);
        $d = $r->validate(['include_assigned' => 'boolean']);
        $circle = FieldCircle::query()->where('user_id', $u->id)->first() ?? throw new DomainException('no_circle', 'Draw this employee\'s circle first.');
        // ponytail: bounding box in SQL, exact distance in PHP; fine for tens of thousands of cases per company.
        $dLat = $circle->radius_m / 111320;
        $dLng = $circle->radius_m / (111320 * max(0.01, cos(deg2rad($circle->lat))));
        $teams = $r->user()->supervisedTeamIds();
        $ids = Customer::query()->whereNull('withdrawn_at')
            ->whereBetween('lat', [$circle->lat - $dLat, $circle->lat + $dLat])->whereBetween('lng', [$circle->lng - $dLng, $circle->lng + $dLng])
            ->when(empty($d['include_assigned']), fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($teams !== null, fn ($q) => $q->whereIn('assigned_team_id', $teams))
            ->where(fn ($q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', '!=', $u->id))
            ->get(['id', 'lat', 'lng'])->filter(fn ($c) => $circle->contains($c->lat, $c->lng))->pluck('id')->all();
        $n = $ids ? $svc->assign($ids, $u->id, null, $r->user()) : 0;
        return response()->json(['assigned' => $n]);
    }

    /** One employee's plan for a day, plus the customers to choose from (inside the circle first, then priority). */
    public function show(Request $r, int $userId)
    {
        $u = $this->employee($r, $userId);
        $date = $this->date($r);
        $circle = FieldCircle::query()->where('user_id', $u->id)->first();
        $plan = RoutePlan::query()->where('user_id', $u->id)->where('plan_date', $date)->first();
        $cases = Customer::query()->visibleTo($u)->get(['id', 'customer_ref', 'name', 'lat', 'lng', 'address', 'area', 'city', 'pincode', 'status', 'follow_up_on', 'overdue_amount', 'outstanding'])
            ->filter(fn ($c) => Customer::validCoords($c->lat, $c->lng));
        $lastVisit = CustomerEvent::query()->where('type', 'visit')->whereIn('customer_id', $cases->pluck('id'))
            ->selectRaw('customer_id, max(created_at) as at')->groupBy('customer_id')->pluck('at', 'customer_id');
        $start = $this->start($circle, $plan);
        $rows = $cases->map(fn ($c) => [
            'id' => $c->id, 'customer_ref' => $c->customer_ref, 'name' => $c->name, 'lat' => $c->lat, 'lng' => $c->lng, 'status' => $c->status,
            'address' => implode(', ', array_filter([$c->address, $c->area, $c->city, $c->pincode])),
            'follow_up_due' => $c->follow_up_on !== null && $c->follow_up_on->toDateString() <= $date,
            'overdue_amount' => $c->overdue_amount, 'outstanding' => $c->outstanding,
            'last_visit' => isset($lastVisit[$c->id]) ? \Illuminate\Support\Carbon::parse($lastVisit[$c->id], 'UTC')->setTimezone(\App\Support\CurrentCompany::tz())->toDateString() : null,
            'inside' => $circle ? $circle->contains($c->lat, $c->lng) : null,
            'from_start_m' => $start ? (int) round(GoogleMaps::straightMeters($start, [$c->lat, $c->lng])) : null,
        ])->sortBy(fn ($x) => [$x['inside'] === false ? 1 : 0, $x['follow_up_due'] ? 0 : 1, $x['last_visit'] ?? '0000', -1 * (float) ($x['overdue_amount'] ?? 0)])->values();
        return response()->json(['date' => $date, 'employee' => $u->only(['id', 'name', 'employee_code']), 'circle' => $circle?->only(['name', 'lat', 'lng', 'radius_m']),
            'plan' => $plan ? $this->planJson($plan) : null, 'candidates' => $rows, 'max_stops' => GoogleMaps::MAX_PLAN_STOPS]);
    }

    /** Save the chosen stops (draft), in straight-line nearest-next order from the start. No Google call. */
    public function save(Request $r, int $userId)
    {
        $u = $this->employee($r, $userId);
        $date = $this->date($r);
        $d = $r->validate(['stops' => 'required|array|min:1|max:' . GoogleMaps::MAX_PLAN_STOPS, 'stops.*' => 'integer',
            'start_lat' => 'nullable|numeric|between:-90,90', 'start_lng' => 'nullable|numeric|between:-180,180']);
        $cases = Customer::query()->visibleTo($u)->whereIn('id', $d['stops'])->get(['id', 'lat', 'lng'])->filter(fn ($c) => Customer::validCoords($c->lat, $c->lng))->keyBy('id');
        abort_if($cases->isEmpty(), 422, 'None of these customers belongs to this employee with a map location.');
        $circle = FieldCircle::query()->where('user_id', $u->id)->first();
        $start = isset($d['start_lat'], $d['start_lng']) ? [(float) $d['start_lat'], (float) $d['start_lng']]
            : ($circle ? [$circle->lat, $circle->lng] : [$cases->first()->lat, $cases->first()->lng]);
        // Nearest-next by straight line: a sensible free order until (unless) the admin optimises.
        $left = $cases->all();
        $order = [];
        $at = $start;
        while ($left) {
            $next = collect($left)->sortBy(fn ($c) => GoogleMaps::straightMeters($at, [$c->lat, $c->lng]))->first();
            $order[] = $next->id;
            $at = [$next->lat, $next->lng];
            unset($left[$next->id]);
        }
        $plan = RoutePlan::query()->firstOrNew(['user_id' => $u->id, 'plan_date' => $date]);
        $same = $plan->exists && $plan->optimised && $plan->stops_hash === self::hash($start, $order);
        $plan->fill(['start_lat' => $start[0], 'start_lng' => $start[1], 'created_by' => $plan->created_by ?? $r->user()->id] + ($same ? [] : [
            'stops' => $order, 'optimised' => false, 'stops_hash' => null, 'points' => null, 'legs' => null, 'distance_m' => null, 'duration_s' => null]));
        $plan->status = $plan->status === 'published' && !$same ? 'draft' : ($plan->status ?? 'draft');   // a changed plan must be published again
        $plan->save();
        return response()->json($this->planJson($plan));
    }

    /** One optimised two-wheeler route (Routes Enterprise) for the saved stops; reused while the stops don't change. */
    public function optimise(Request $r, int $userId, GoogleMaps $maps)
    {
        $u = $this->employee($r, $userId);
        $plan = RoutePlan::query()->where('user_id', $u->id)->where('plan_date', $this->date($r))->firstOrFail();
        $start = [$plan->start_lat, $plan->start_lng];
        if ($plan->optimised && $plan->stops_hash === self::hash($start, self::sorted($plan->stops))) {
            return response()->json($this->planJson($plan) + ['reused' => true]);
        }
        $cases = Customer::query()->whereIn('id', $plan->stops)->get(['id', 'lat', 'lng'])->keyBy('id');
        $ids = array_values(array_filter($plan->stops, fn ($id) => isset($cases[$id]) && Customer::validCoords($cases[$id]->lat, $cases[$id]->lng)));
        $route = $maps->planRoute($start, array_map(fn ($id) => [$cases[$id]->lat, $cases[$id]->lng], $ids));
        $order = array_map(fn ($i) => $ids[$i], $route['order']);
        $plan->fill(['stops' => $order, 'points' => $route['points'], 'legs' => $route['legs'], 'distance_m' => $route['distance_m'], 'duration_s' => $route['duration_s'],
            'optimised' => true, 'stops_hash' => self::hash($start, self::sorted($order))]);
        if ($plan->status === 'published') $plan->status = 'draft';
        $plan->save();
        Audit::record('field.plan_optimised', $u, ['date' => $plan->plan_date->toDateString(), 'stops' => count($order)]);
        return response()->json($this->planJson($plan));
    }

    public function publish(Request $r, int $userId, PushService $push)
    {
        $u = $this->employee($r, $userId);
        $plan = RoutePlan::query()->where('user_id', $u->id)->where('plan_date', $this->date($r))->firstOrFail();
        $plan->forceFill(['status' => 'published', 'published_at' => now()])->save();
        ManagedDevice::query()->where('assigned_user_id', $u->id)->where('approval_state', 'approved')->each(fn ($d) => $push->ping($d, 'customers'));
        Audit::record('field.plan_published', $u, ['date' => $plan->plan_date->toDateString(), 'stops' => count($plan->stops), 'optimised' => $plan->optimised]);
        return response()->json($this->planJson($plan));
    }

    public function destroy(Request $r, int $userId)
    {
        $u = $this->employee($r, $userId);
        RoutePlan::query()->where('user_id', $u->id)->where('plan_date', $this->date($r))->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * G10: plan board — for every published plan of the day: stops done / left, the next stop, visits made off the plan,
     * when the phone last sent a location, and alerts (no location for 30+ min, fake GPS, behind the planned time).
     */
    public function board(Request $r)
    {
        $date = $this->date($r);
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata';
        $from = \Illuminate\Support\Carbon::parse($date, $tz)->startOfDay()->utc();
        $to = $from->copy()->addDay();
        $isToday = $date === now($tz)->toDateString();
        $staff = $this->fieldStaff($r)->keyBy('id');
        $plans = RoutePlan::query()->where('plan_date', $date)->where('status', 'published')->whereIn('user_id', $staff->keys())->get();
        $ids = $plans->pluck('user_id')->all();
        $visits = CustomerEvent::query()->where('type', 'visit')->whereIn('user_id', $ids)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderBy('created_at')->get(['user_id', 'customer_id', 'created_at'])->groupBy('user_id');
        $last = \App\Models\LocationEvent::query()->whereIn('user_id', $ids)->where('captured_at', '>=', $from)->where('captured_at', '<', $to)
            ->selectRaw('user_id, MAX(captured_at) as at, MAX(CASE WHEN mock THEN 1 ELSE 0 END) as mock')->groupBy('user_id')->get()->keyBy('user_id');
        $names = Customer::query()->whereIn('id', $plans->pluck('stops')->flatten()->merge($visits->flatten()->pluck('customer_id'))->unique())->pluck('name', 'id');
        $rows = $plans->map(function (RoutePlan $p) use ($visits, $last, $names, $staff, $isToday, $tz) {
            $v = $visits->get($p->user_id, collect());
            $done = $v->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values();
            $left = array_values(array_filter($p->stops, fn ($id) => !$done->contains($id)));
            $off = $done->reject(fn ($id) => in_array($id, $p->stops, true))->values();
            $seen = isset($last[$p->user_id]) ? \Illuminate\Support\Carbon::parse($last[$p->user_id]->at) : null;
            $alerts = [];
            if ($isToday && $left && (!$seen || $seen->lt(now()->subMinutes(30)))) $alerts[] = $seen ? 'No location for ' . $seen->diffForHumans(null, true) : 'No location today';
            if (($last[$p->user_id]->mock ?? 0) == 1) $alerts[] = 'Fake GPS used';
            // behind: past the time the planned route should have taken from the first check-in (plus 50%), stops still left
            $first = $v->first()?->created_at;
            if ($isToday && $left && $first && $p->duration_s && now()->gt($first->copy()->addSeconds((int) ($p->duration_s * 1.5) + 20 * 60 * count($p->stops)))) $alerts[] = 'Behind plan';
            return ['user_id' => $p->user_id, 'name' => $staff[$p->user_id]->name, 'employee_code' => $staff[$p->user_id]->employee_code,
                'planned' => count($p->stops), 'done' => count($p->stops) - count($left), 'off_plan' => $off->count(),
                'next' => $left ? ['id' => $left[0], 'name' => $names[$left[0]] ?? null] : null,
                'stops' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? null, 'done' => $done->contains($id),
                    'at' => optional($v->firstWhere('customer_id', $id))->created_at?->setTimezone($tz)->format('H:i')], $p->stops),
                'off_plan_visits' => $off->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? null])->all(),
                'last_location_at' => $seen?->toIso8601String(), 'alerts' => $alerts, 'optimised' => $p->optimised, 'distance_m' => $p->distance_m];
        })->sortByDesc(fn ($x) => count($x['alerts']))->values();
        $unplanned = $staff->except($ids)->values()->map(fn ($u) => $u->only(['id', 'name', 'employee_code']));
        return response()->json(['date' => $date, 'today' => $isToday, 'plans' => $rows, 'without_plan' => $unplanned,
            'totals' => ['planned' => $rows->sum('planned'), 'done' => $rows->sum('done'), 'off_plan' => $rows->sum('off_plan'), 'alerts' => $rows->sum(fn ($x) => count($x['alerts']))]]);
    }

    public static function planJson(RoutePlan $p): array
    {
        return ['date' => $p->plan_date->toDateString(), 'status' => $p->status, 'stops' => $p->stops, 'start' => ['lat' => $p->start_lat, 'lng' => $p->start_lng],
            'distance_m' => $p->distance_m, 'duration_s' => $p->duration_s, 'points' => $p->points ?? [], 'legs' => $p->legs ?? [], 'optimised' => $p->optimised,
            'published_at' => $p->published_at?->toIso8601String()];
    }

    private static function hash(array $start, array $stops): string
    {
        return sha1(json_encode([round($start[0], 5), round($start[1], 5), self::sorted($stops)]));
    }

    private static function sorted(array $ids): array
    {
        $ids = array_map('intval', $ids);
        sort($ids);
        return $ids;
    }

    private function start(?FieldCircle $circle, ?RoutePlan $plan): ?array
    {
        return $plan ? [$plan->start_lat, $plan->start_lng] : ($circle ? [$circle->lat, $circle->lng] : null);
    }

    private function date(Request $r): string
    {
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? null;
        return $d ?? now(app(\App\Support\CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata')->toDateString();
    }

    /** Field employees (role "employee") the signed-in admin may see. */
    private function fieldStaff(Request $r)
    {
        $teams = $r->user()->teamsInView();
        return User::query()->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('key', 'employee'))
            ->when($teams !== null, fn ($q) => $q->whereHas('teams', fn ($t) => $t->whereIn('teams.id', $teams)))
            ->orderBy('name')->get(['id', 'name', 'employee_code', 'company_id']);
    }

    private function employee(Request $r, int $id): User
    {
        $u = $this->fieldStaff($r)->firstWhere('id', $id);
        abort_if($u === null, 404, 'No such field employee in your groups.');
        return $u;
    }
}
