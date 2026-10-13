<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Platform\CustomPrice;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin → Custom pricing. Negotiated prices for one company (or a buyer e-mail before the company exists).
 * Reading: `platform:plans` view (super, sales, support). Changing: manage = super admins only (PlatformAccess matrix).
 * The global plan price is never touched; every change is in the audit log with before/after values.
 */
final class PricingController extends Controller
{
    private const FIELDS = ['company_id', 'email', 'plan_id', 'applies_to', 'mode', 'value', 'starts_on', 'ends_on', 'active', 'note'];

    public function index(Request $r)
    {
        $plans = Plan::query()->with('cloudTiers', 'perpetualBands')->orderBy('sort')->get();
        $rows = CustomPrice::query()->with('company:id,name,slug', 'plan:id,name')
            ->when($r->integer('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest('id')->get()
            ->map(function (CustomPrice $c) use ($plans) {
                $l = $c->company_id ? Licence::query()->where('company_id', $c->company_id)->whereIn('kind', ['subscription', 'trial'])->where('status', 'active')->latest('id')->first() : null;
                $users = $l && $l->kind === 'subscription' ? $l->max_users : null;
                return $c->toArray() + ['state' => $c->state(), 'orders' => SalesOrder::query()->where('custom_price_id', $c->id)->count(),
                    // standard vs negotiated (cloud, before GST) for every package the rule covers, at this company's size
                    'prices' => $plans->filter(fn ($p) => $c->plan_id === null ? $p->active : $p->id === $c->plan_id)->values()
                        ->map(fn ($p) => self::example($p, $c, $users ?? $p->min_users, $l?->billing ?? 'annual', $c->company_id ? 'renewal' : 'new'))];
            });
        $state = (string) $r->query('state');
        return response()->json([
            'rules' => $state !== '' ? $rows->where('state', $state)->values() : $rows,
            'plans' => $plans->map(fn ($p) => $p->only(['id', 'name', 'active', 'min_users'])),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    /** One plan's standard and negotiated cloud price for $users (null where it is a custom quote or the rule doesn't apply). */
    private static function example(Plan $p, ?CustomPrice $c, int $users, string $billing, string $kind): array
    {
        $spec = ['plan' => $p, 'kind' => $kind, 'licence_kind' => 'subscription', 'deployment' => 'cloud', 'billing' => $billing, 'users' => max($users, $p->min_users), 'first' => false];
        $try = function (array $x) { try { return \App\Services\Platform\Pricing::build($x); } catch (DomainException) { return null; } };
        $std = $try($spec);
        $cus = $c ? $try($spec + ['custom' => $c]) : null;
        return ['plan' => $p->name, 'users' => $spec['users'], 'billing' => $billing, 'standard' => $std['list_price'] ?? null,
            'custom' => $cus && $cus['pricing'] === 'custom' ? $cus['amount'] : null];
    }

    public function save(Request $r, ?int $id = null)
    {
        $r->merge(['email' => $r->filled('email') ? strtolower(trim((string) $r->input('email'))) : null]);
        $d = $r->validate([
            'company_id' => 'nullable|integer|exists:companies,id|required_without:email', 'email' => 'nullable|email|max:190|required_without:company_id',
            'plan_id' => 'nullable|integer|exists:plans,id', 'applies_to' => 'required|in:any,new,renewal', 'mode' => 'required|in:fixed,percent,rate',
            'value' => ['required', 'numeric', 'min:0.01', match ($r->input('mode')) { 'percent' => 'max:99', 'rate' => 'max:100000', default => 'max:99999999' }],
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'active' => 'boolean', 'note' => 'nullable|string|max:190',
        ], ['value.max' => $r->input('mode') === 'percent' ? 'A discount can be at most 99%. For a free workspace issue an internal licence instead.' : 'That price is too large.']);
        if (!empty($d['company_id'])) {
            $d['email'] = null;   // a company rule is keyed by the company only
        }
        if (empty($d['company_id']) && ($d['applies_to'] ?? 'any') === 'renewal') {
            throw new DomainException('bad_rule', 'A renewal price needs a company. An e-mail rule is for a new workspace bought on the website.');
        }
        $admin = auth('platform')->user();
        $c = $id ? CustomPrice::query()->findOrFail($id) : new CustomPrice(['created_by' => $admin?->id]);
        $before = $id ? $c->only(self::FIELDS) : null;
        $c->fill($d + ['active' => $d['active'] ?? true, 'updated_by' => $admin?->id])->save();
        PlatformAudit::record($id ? 'custom_price.updated' : 'custom_price.created', $c, ['before' => $before, 'after' => $c->fresh()->only(self::FIELDS)]);
        return response()->json($c->fresh()->load('company:id,name,slug', 'plan:id,name'), $id ? 200 : 201);
    }

    /** Remove a rule. One already used on an order is kept for the record — switch it off instead. */
    public function destroy(int $id)
    {
        $c = CustomPrice::query()->findOrFail($id);
        if (SalesOrder::query()->where('custom_price_id', $id)->exists()) {
            throw new DomainException('custom_price_used', 'This price is on orders already, so it is kept for the record. Switch it off instead.');
        }
        $before = $c->only(self::FIELDS);
        $c->delete();
        PlatformAudit::record('custom_price.deleted', null, ['id' => $id, 'before' => $before]);
        return response()->json(['ok' => true]);
    }

    /** Who created / changed this price, when, and the values before and after. */
    public function history(int $id)
    {
        return response()->json(DB::table('platform_audit as a')->leftJoin('platform_admins as p', 'p.id', '=', 'a.admin_id')
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('a.subject_type', 'CustomPrice')->where('a.subject_id', $id))
                ->orWhere(fn ($x) => $x->where('a.action', 'custom_price.deleted')->where('a.meta', 'like', '%"id":' . $id . ',%')))
            ->orderByDesc('a.id')->get(['a.action', 'a.meta', 'a.created_at', 'p.name as admin_name'])
            ->map(fn ($e) => ['action' => $e->action, 'at' => $e->created_at, 'by' => $e->admin_name] + (array) json_decode((string) $e->meta, true)));
    }

    /** What one company would pay today for each package (standard vs negotiated). Used on the company panel. */
    public function preview(Request $r)
    {
        $d = $r->validate(['company_id' => 'required|integer|exists:companies,id', 'state_code' => 'nullable|digits:2']);
        $l = Licence::query()->where('company_id', $d['company_id'])->whereIn('kind', ['subscription', 'trial'])->where('status', 'active')->latest('id')->first();
        $state = $d['state_code'] ?? Company::query()->whereKey($d['company_id'])->value('state_code');
        return response()->json(Plan::query()->with('cloudTiers', 'perpetualBands')->where('active', true)->orderBy('sort')->get()->map(function (Plan $p) use ($d, $l, $state) {
            $users = max($p->min_users, $l && $l->kind === 'subscription' ? $l->max_users : $p->min_users);
            $billing = $l?->billing ?? 'annual';
            $spec = ['plan' => $p, 'kind' => 'renewal', 'licence_kind' => 'subscription', 'deployment' => 'cloud', 'billing' => $billing, 'users' => $users, 'first' => false, 'state' => $state,
                'custom' => CustomPrice::for($p, 'renewal', (int) $d['company_id'], null)];
            try {
                $pr = \App\Services\Platform\Pricing::build($spec);
            } catch (DomainException) {
                return ['plan' => $p->name, 'plan_id' => $p->id, 'users' => $users, 'billing' => $billing, 'standard' => null, 'pricing' => 'quotation'];
            }
            return ['plan' => $p->name, 'plan_id' => $p->id, 'users' => $users, 'billing' => $billing, 'standard' => $pr['list_price'], 'rule_id' => $pr['custom_price_id']] + $pr;
        }));
    }
}
