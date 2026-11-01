<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Demo\DemoCities;
use App\Services\Demo\DemoWorkspace;
use App\Services\Platform\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Super Admin → Demo Clients: who registered, what each one added or changed, their activity, one-click
 * "Clear Demo Data" (back to the original dataset) and permanent delete. Demo workspaces only.
 * "Added" / "changed" = created / updated after the dataset was built (companies.demo.seeded_at).
 */
final class DemoController extends Controller
{
    public function index(Request $r)
    {
        $q = trim((string) $r->query('q'));
        $list = Company::query()->whereNotNull('demo')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%")
                ->orWhere('demo->email', 'like', "%{$q}%")->orWhere('demo->mobile', 'like', "%{$q}%")->orWhere('demo->contact_name', 'like', "%{$q}%")))
            ->when($r->query('city'), fn ($w, $c) => $w->where('demo->city', $c))
            ->latest('id')->paginate(50);
        $ids = $list->pluck('id');
        $count = fn (string $t, ?callable $f = null) => DB::table($t)->whereIn('company_id', $ids)->when($f, $f)->groupBy('company_id')->selectRaw('company_id, COUNT(*) n')->pluck('n', 'company_id');
        $users = $count('users');
        $customers = $count('customers');
        $calls = $count('calls', fn ($w) => $w->where('provider', '!=', 'manual'));
        $admins = DB::table('users')->whereIn('company_id', $ids)->where('employee_code', 'admin')->get()->keyBy('company_id');
        $active = DB::table('audit_events')->whereIn('company_id', $ids)->where('actor_type', 'user')->groupBy('company_id')->selectRaw('company_id, MAX(created_at) t')->pluck('t', 'company_id');
        $list->getCollection()->transform(fn (Company $c) => $this->info($c) + [
            'users' => (int) ($users[$c->id] ?? 0), 'customers' => (int) ($customers[$c->id] ?? 0), 'calls' => (int) ($calls[$c->id] ?? 0),
            'changes' => collect($this->changes($c))->sum(fn ($k) => $k['added'] + $k['changed']),   // ponytail: ~14 counts per row; fine for 50 per page
            'last_login_at' => $admins[$c->id]->last_login_at ?? null, 'last_active_at' => $active[$c->id] ?? null,
        ]);
        return response()->json(['cities' => DemoCities::options()] + $list->toArray());
    }

    public function show(int $id)
    {
        $c = $this->demo($id);
        $since = $this->since($c);
        $state = fn ($row) => match (true) {
            ($row->created_at ?? null) !== null && $row->created_at > $since => 'added',
            ($row->updated_at ?? null) !== null && $row->updated_at > $since => 'changed',
            default => 'demo',
        };
        $names = DB::table('users')->where('company_id', $id)->pluck('name', 'id');
        $roles = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('role_user.company_id', $id)->get(['role_user.user_id', 'roles.name'])->groupBy('user_id');
        $teams = DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->where('team_user.company_id', $id)->get(['team_user.user_id', 'teams.name'])->groupBy('user_id');
        $people = DB::table('users')->where('company_id', $id)->orderBy('id')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'employee_code' => $u->employee_code,
            'email' => $u->email, 'phone' => $u->phone, 'status' => $u->status, 'roles' => ($roles[$u->id] ?? collect())->pluck('name')->implode(', '),
            'teams' => ($teams[$u->id] ?? collect())->pluck('name')->implode(', '), 'last_login_at' => $u->last_login_at, 'created_at' => $u->created_at, 'state' => $u->employee_code === 'admin' ? 'demo' : $state($u)]);
        $customers = DB::table('customers')->where('company_id', $id)->orderByDesc('updated_at')->limit(1000)->get()->map(fn ($x) => ['id' => $x->id, 'ref' => $x->customer_ref,
            'name' => $x->name, 'phone' => $x->phone, 'alt_phone' => $x->alt_phone, 'area' => trim(($x->area ?? '') . ', ' . ($x->city ?? ''), ', '), 'status' => $x->status,
            'assigned_to' => $names[$x->assigned_user_id] ?? null, 'portfolio' => $x->campaign, 'created_at' => $x->created_at, 'updated_at' => $x->updated_at, 'state' => $state($x)]);
        $numbers = collect()
            ->concat($customers->flatMap(fn ($x) => array_filter([['number' => $x['phone'], 'kind' => 'Customer', 'owner' => "{$x['name']} ({$x['ref']})", 'state' => $x['state']],
                $x['alt_phone'] ? ['number' => $x['alt_phone'], 'kind' => 'Customer (alternate)', 'owner' => "{$x['name']} ({$x['ref']})", 'state' => $x['state']] : null])))
            ->concat($people->filter(fn ($u) => $u['phone'])->map(fn ($u) => ['number' => $u['phone'], 'kind' => 'Employee', 'owner' => "{$u['name']} ({$u['employee_code']})", 'state' => $u['state']]))
            ->concat(DB::table('smart31_numbers')->where('company_id', $id)->get()->map(fn ($n) => ['number' => $n->number, 'kind' => 'Smart31 number', 'owner' => $n->label ?? 'Company', 'state' => $state($n)]))
            ->concat(DB::table('c2c_vmns')->where('company_id', $id)->get()->map(fn ($n) => ['number' => $n->virtual_number, 'kind' => 'Click2Call number', 'owner' => 'Company', 'state' => $state($n)]))
            ->sortBy(fn ($n) => ['added' => 0, 'changed' => 1, 'demo' => 2][$n['state']])->values();
        $payments = DB::table('call_dispositions as d')->join('calls as c', 'c.id', '=', 'd.call_id')->leftJoin('customers as x', 'x.id', '=', 'c.customer_id')
            ->where('d.company_id', $id)->whereNotNull('d.payment_mode')->orderByDesc('d.submitted_at')->limit(500)
            ->get(['d.id', 'd.amount', 'd.payment_mode', 'd.payment_ref', 'd.paid_on', 'd.review_status', 'd.slip_key', 'd.submitted_at', 'd.reviewed_at', 'd.user_id', 'x.name as customer', 'x.customer_ref'])
            ->map(fn ($p) => ['id' => $p->id, 'customer' => $p->customer ? "{$p->customer} ({$p->customer_ref})" : '—', 'amount' => (float) $p->amount, 'mode' => $p->payment_mode,
                'ref' => $p->payment_ref, 'paid_on' => $p->paid_on, 'status' => $p->review_status ?? 'pending', 'slip' => $p->slip_key !== null, 'by' => $names[$p->user_id] ?? '—',
                'state' => $p->submitted_at > $since ? 'added' : ($p->reviewed_at !== null && $p->reviewed_at > $since ? 'changed' : 'demo')]);
        $activity = DB::table('audit_events')->where('company_id', $id)->where('created_at', '>', $since)->whereIn('actor_type', ['user', 'device'])
            ->orderByDesc('id')->limit(400)->get()->map(fn ($e) => ['at' => $e->created_at, 'who' => $e->actor_type === 'user' ? ($names[$e->actor_id] ?? 'User #' . $e->actor_id) : 'Phone #' . $e->actor_id,
                'what' => ucfirst(str_replace(['.', '_'], [' — ', ' '], $e->action)), 'subject' => $e->subject_type ? "{$e->subject_type} #{$e->subject_id}" : null, 'ip' => $e->ip]);
        return response()->json(['info' => $this->info($c), 'changes' => $this->changes($c), 'people' => $people, 'customers' => $customers, 'numbers' => $numbers,
            'payments' => $payments, 'activity' => $activity]);
    }

    /** The slip image the client (or the dataset) attached to a payment. */
    public function slip(int $id, int $paymentId)
    {
        $this->demo($id);
        $key = DB::table('call_dispositions')->where('company_id', $id)->where('id', $paymentId)->value('slip_key') ?? abort(404);
        return Storage::disk('company_files')->response($key, basename($key), ['Cache-Control' => 'private, no-store']);
    }

    public function reset(int $id)
    {
        DemoWorkspace::reset($c = $this->demo($id));
        return response()->json(['ok' => true, 'info' => $this->info($c->refresh())]);
    }

    public function destroy(Request $r, int $id)
    {
        $c = $this->demo($id);
        $r->validate(['confirm' => 'required|string']);
        abort_unless($r->input('confirm') === $c->slug, 422, 'Type the workspace name to confirm.');
        DemoWorkspace::delete($c);
        return response()->json(['ok' => true]);
    }

    private function demo(int $id): Company
    {
        return Company::query()->whereNotNull('demo')->findOrFail($id);
    }

    private function since(Company $c): string
    {
        return Carbon::parse($c->demo['seeded_at'] ?? $c->created_at)->utc()->format('Y-m-d H:i:s');
    }

    private function info(Company $c): array
    {
        $d = $c->demo;
        $city = DemoCities::get($d['city'] ?? null);
        return ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'status' => $c->status, 'console_url' => Workspaces::consoleUrl($c),
            'city' => $d['city'] ?? null, 'city_name' => $city ? "{$city[1]}, {$city[2]}" : ($d['city'] ?? '—'), 'contact_name' => $d['contact_name'] ?? null,
            'business' => $d['business'] ?? $c->name, 'email' => $d['email'] ?? null, 'mobile' => $d['mobile'] ?? null, 'address' => $d['address'] ?? null,
            'registered_at' => $d['registered_at'] ?? $c->created_at, 'seeded_at' => $d['seeded_at'] ?? null, 'last_reset_at' => $d['last_reset_at'] ?? null, 'resets' => (int) ($d['resets'] ?? 0)];
    }

    /** Per kind of record: how many the client added and how many demo records they changed. */
    private function changes(Company $c): array
    {
        $since = $this->since($c);
        $kinds = [['users', 'Employees / users'], ['teams', 'Teams'], ['branches', 'Branches'], ['customers', 'Customers'], ['import_jobs', 'Uploads'], ['calls', 'Calls'],
            ['follow_ups', 'Callbacks'], ['managed_devices', 'Phones'], ['smart31_numbers', 'Smart31 numbers'], ['c2c_vmns', 'Click2Call numbers'], ['policy_versions', 'Phone policies']];
        $out = [];
        foreach ($kinds as [$t, $label]) {
            $q = fn () => DB::table($t)->where('company_id', $c->id);
            $out[] = ['kind' => $label, 'added' => $q()->where('created_at', '>', $since)->count(),
                'changed' => $q()->where('created_at', '<=', $since)->where('updated_at', '>', $since)->when($t === 'users', fn ($w) => $w->where('employee_code', '!=', 'admin'))->count()];
        }
        $pay = fn () => DB::table('call_dispositions')->where('company_id', $c->id);
        $out[] = ['kind' => 'Call outcomes & payments', 'added' => $pay()->where('submitted_at', '>', $since)->count(), 'changed' => $pay()->where('submitted_at', '<=', $since)->where('reviewed_at', '>', $since)->count()];
        $out[] = ['kind' => 'Notes & visits', 'added' => DB::table('customer_events')->where('company_id', $c->id)->where('created_at', '>', $since)->count(), 'changed' => 0];
        $out[] = ['kind' => 'Chat messages', 'added' => DB::table('messages')->where('company_id', $c->id)->where('created_at', '>', $since)->count(), 'changed' => 0];
        return $out;
    }
}
