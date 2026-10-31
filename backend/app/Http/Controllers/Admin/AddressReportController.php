<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddressChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Address report (console): every address / pin change with before and after, and per-case reachability
 * (pin usable? visited at the door? reached by phone?). Same team scoping as the case list: a manager sees only
 * their groups' cases. "Verified" = an employee checked in at the customer (within 100 m) after the change.
 */
final class AddressReportController extends Controller
{
    /** GET address-changes?q=&from=&to=&source=&what=&status=verified|pending|replaced&customer_id=&employee= */
    public function changes(Request $r)
    {
        $q = $this->changeQuery($r);
        $count = fn (?callable $f = null) => tap(clone $q, fn ($x) => $f && $f($x))->count();
        $page = (clone $q)->with(['user:id,name', 'customer:id,customer_ref,name,address,area,city,state,pincode,lat,lng,assigned_user_id', 'customer.assignee:id,name'])
            ->addSelect([
                'customer_address_changes.*',
                'verified_at' => DB::table('customer_events as v')->selectRaw('MIN(v.created_at)')->whereColumn('v.customer_id', 'customer_address_changes.customer_id')
                    ->where('v.type', 'visit')->whereColumn('v.created_at', '>=', 'customer_address_changes.created_at'),
                'replaced' => DB::table('customer_address_changes as n')->selectRaw('COUNT(*)')->whereColumn('n.customer_id', 'customer_address_changes.customer_id')
                    ->whereColumn('n.id', '>', 'customer_address_changes.id'),
            ])
            ->orderByDesc('customer_address_changes.id')->paginate(50);
        return response()->json([
            'summary' => ['changes' => $count(), 'pins_added' => $count(fn ($x) => $x->whereNull('old_lat')->whereNotNull('new_lat')),
                'pins_moved' => $count(fn ($x) => $x->whereNotNull('moved_m')), 'big_moves' => $count(fn ($x) => $x->where('moved_m', '>', 500)),
                'address_only' => $count(fn ($x) => $x->where('what', 'address')), 'verified' => $count(fn ($x) => $this->verified($x)),
                'customers' => (clone $q)->distinct()->count('customer_address_changes.customer_id')],
            'sources' => CustomerAddressChange::SOURCES,
            'page' => $page->through(fn (CustomerAddressChange $ch) => $ch->toRow() + [
                'status' => $ch->replaced > 0 ? 'replaced' : ($ch->verified_at ? 'verified' : 'pending'),
                'verified_at' => $ch->verified_at ? Carbon::parse($ch->verified_at)->toAtomString() : null,
                'customer' => $this->customer($ch->customer),
            ]),
        ]);
    }

    /** GET reachability?q=&pin=ok|missing|invalid&reach=visited|phone|not_reached&changed=yes|no&city= */
    public function reachability(Request $r)
    {
        $base = CustomerController::filtered(['city' => $r->query('city'), 'status' => $r->query('case_status')], $r->user());
        if (($s = trim((string) $r->query('q', ''))) !== '') {   // name, case ID or any part of the address
            $like = '%' . addcslashes($s, '%_') . '%';
            $base->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('customer_ref', $s)
                ->orWhere(fn ($x) => array_map(fn ($f) => $x->orWhere($f, 'like', $like), CustomerAddressChange::ADDRESS_FIELDS)));
        }
        $pin = (string) $r->query('pin', '');
        $reach = (string) $r->query('reach', '');
        $base->when($pin === 'ok', fn ($w) => $this->pinOk($w))
            ->when($pin === 'missing', fn ($w) => $w->whereNull('lat')->whereNull('lng'))
            ->when($pin === 'invalid', fn ($w) => $w->where(fn ($x) => $x->whereNotNull('lat')->orWhereNotNull('lng'))->whereNot(fn ($x) => $this->pinOk($x)))
            ->when($r->query('changed') === 'yes', fn ($w) => $w->whereExists($this->changesOf()))
            ->when($r->query('changed') === 'no', fn ($w) => $w->whereNotExists($this->changesOf()));
        $summary = ['cases' => (clone $base)->count(), 'pin_ok' => $this->pinOk(clone $base)->count(), 'pin_missing' => (clone $base)->whereNull('lat')->whereNull('lng')->count(),
            'visited' => (clone $base)->whereExists($this->visitsOf())->count(),
            'phone_only' => (clone $base)->whereNotExists($this->visitsOf())->whereExists($this->connectedOf())->count()];
        $summary['pin_invalid'] = $summary['cases'] - $summary['pin_ok'] - $summary['pin_missing'];
        $summary['not_reached'] = $summary['cases'] - $summary['visited'] - $summary['phone_only'];
        $base->when($reach === 'visited', fn ($w) => $w->whereExists($this->visitsOf()))
            ->when($reach === 'phone', fn ($w) => $w->whereNotExists($this->visitsOf())->whereExists($this->connectedOf()))
            ->when($reach === 'not_reached', fn ($w) => $w->whereNotExists($this->visitsOf())->whereNotExists($this->connectedOf()));
        $page = $base->with('assignee:id,name')->select('customers.*')->addSelect([
            'last_visit_at' => $this->visitsOf()->selectRaw('MAX(e.created_at)'),
            'visits' => $this->visitsOf()->selectRaw('COUNT(*)'),
            'last_change_at' => $this->changesOf()->selectRaw('MAX(ac.created_at)'),
            'changes' => $this->changesOf()->selectRaw('COUNT(*)'),
            'last_call_at' => DB::table('calls as k')->selectRaw('MAX(k.initiated_at)')->whereColumn('k.customer_id', 'customers.id')->where('k.provider', '!=', 'manual')->where('k.is_test', false),
            'last_connected_at' => $this->connectedOf()->selectRaw('MAX(k.answered_at)'),
        ])->orderBy('name')->paginate(50);
        $ts = fn ($v) => $v ? Carbon::parse($v)->toAtomString() : null;
        return response()->json(['summary' => $summary, 'page' => $page->through(fn (Customer $c) => $this->customer($c) + [
            'visits' => (int) $c->visits, 'last_visit_at' => $ts($c->last_visit_at), 'changes' => (int) $c->changes, 'last_change_at' => $ts($c->last_change_at),
            'last_call_at' => $ts($c->last_call_at), 'last_connected_at' => $ts($c->last_connected_at), 'status' => $c->status,
            'reach' => $c->visits > 0 ? 'visited' : ($c->last_connected_at ? 'phone' : 'not_reached'),
        ])]);
    }

    private function changeQuery(Request $r): Builder
    {
        $v = fn (string $k) => ($x = $r->query($k)) !== null && $x !== '' ? (string) $x : null;
        $tz = app(\App\Support\CurrentCompany::class)->get()->timezone ?: config('app.timezone');
        $scope = CustomerController::filtered(['include_withdrawn' => 1], $r->user())->select('customers.id');
        return CustomerAddressChange::query()->whereIn('customer_address_changes.customer_id', $scope)
            ->when($v('q'), function ($q, $s) {
                $like = '%' . addcslashes($s, '%_') . '%';
                $q->where(fn ($w) => $w->where('old_address', 'like', $like)->orWhere('new_address', 'like', $like)
                    ->orWhereIn('customer_id', Customer::query()->where('name', 'like', $like)->orWhere('customer_ref', $s)->select('id')));
            })
            ->when($v('from'), fn ($q, $d) => $q->where('customer_address_changes.created_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($v('to'), fn ($q, $d) => $q->where('customer_address_changes.created_at', '<', Carbon::parse($d, $tz)->addDay()->startOfDay()->utc()))
            ->when($v('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($v('what'), fn ($q, $s) => $q->where('what', $s))
            ->when(ctype_digit((string) $v('customer_id')), fn ($q) => $q->where('customer_address_changes.customer_id', (int) $v('customer_id')))
            ->when(ctype_digit((string) $v('employee')), fn ($q) => $q->where('user_id', (int) $v('employee')))
            ->when($v('status') === 'verified', fn ($q) => $this->verified($q))
            ->when($v('status') === 'replaced', fn ($q) => $q->whereExists($this->newer()))
            ->when($v('status') === 'pending', fn ($q) => $q->whereNotExists($this->newer())->whereNot(fn ($w) => $this->verified($w)));
    }

    private function verified(Builder $q): Builder
    {
        return $q->whereExists(DB::table('customer_events as v')->selectRaw('1')->whereColumn('v.customer_id', 'customer_address_changes.customer_id')
            ->where('v.type', 'visit')->whereColumn('v.created_at', '>=', 'customer_address_changes.created_at'));
    }

    private function newer()
    {
        return DB::table('customer_address_changes as n')->selectRaw('1')->whereColumn('n.customer_id', 'customer_address_changes.customer_id')->whereColumn('n.id', '>', 'customer_address_changes.id');
    }

    private function visitsOf()
    {
        return DB::table('customer_events as e')->whereColumn('e.customer_id', 'customers.id')->where('e.type', 'visit');
    }

    private function changesOf()
    {
        return DB::table('customer_address_changes as ac')->whereColumn('ac.customer_id', 'customers.id');
    }

    private function connectedOf()
    {
        return DB::table('calls as k')->whereColumn('k.customer_id', 'customers.id')->whereNotNull('k.answered_at')->where('k.provider', '!=', 'manual')->where('k.is_test', false);
    }

    /** Usable pin, as Customer::validCoords, in SQL. */
    private function pinOk(Builder $q): Builder
    {
        return $q->whereBetween('lat', [-90, 90])->whereBetween('lng', [-180, 180])
            ->whereNot(fn ($w) => $w->whereBetween('lat', [-0.0001, 0.0001])->whereBetween('lng', [-0.0001, 0.0001]));
    }

    private function customer(?Customer $c): ?array
    {
        if (!$c) return null;
        $ok = Customer::validCoords($c->lat, $c->lng);
        return ['id' => $c->id, 'customer_ref' => $c->customer_ref, 'name' => $c->name, 'assigned_to' => $c->assignee?->name,
            'address' => implode(', ', array_filter(array_map(fn ($k) => trim((string) $c->{$k}), CustomerAddressChange::ADDRESS_FIELDS))) ?: null,
            'city' => $c->city, 'pin' => $ok ? ['lat' => $c->lat, 'lng' => $c->lng] : null,
            'pin_status' => $ok ? 'ok' : ($c->lat === null && $c->lng === null ? 'missing' : 'invalid')];
    }
}
