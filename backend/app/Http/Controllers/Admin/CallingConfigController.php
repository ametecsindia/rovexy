<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallingAccess;
use App\Models\Smart31Number;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Support\CurrentCompany;
use App\Telephony\Click2CallSettings as C;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Calling Configuration → Access & permissions, Smart31 numbers (Click2Call settings: IntegrationController). */
final class CallingConfigController extends Controller
{
    private function setSetting(string $key, mixed $value): void
    {
        $c = app(CurrentCompany::class)->get();
        $s = $c->settings ?? [];
        data_set($s, $key, $value);
        $c->forceFill(['settings' => $s])->save();
    }

    /** Both features with plan, company switch and who may use them. */
    public function access()
    {
        $grants = CallingAccess::query()->get();
        $teams = Team::query()->whereIn('id', $grants->pluck('team_id')->filter())->pluck('name', 'id');
        $users = User::query()->whereIn('id', $grants->pluck('user_id')->filter())->get(['id', 'name', 'employee_code'])->keyBy('id');
        return response()->json(collect(C::FEATURES)->mapWithKeys(fn ($f) => [$f => [
            'in_plan' => C::inPlan($f),
            'on' => match ($f) { 'click2call' => C::active(), 'smart31' => C::smart31On(), default => C::simOn() },
            'mode' => C::accessMode($f),
            'grants' => $grants->where('feature', $f)->values()->map(fn ($g) => $g->team_id
                ? ['id' => $g->id, 'type' => 'group', 'team_id' => $g->team_id, 'name' => $teams[$g->team_id] ?? "#{$g->team_id}"]
                : ['id' => $g->id, 'type' => 'person', 'name' => $users[$g->user_id]?->name ?? "#{$g->user_id}", 'code' => $users[$g->user_id]?->employee_code]),
        ]]));
    }

    /** Licensed Click2Call users (C2C_MAX_USERS): a change that would allow more people is undone. */
    private function withinLimit(string $feature, callable $change): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($feature, $change) {
            $change();
            app(\App\Services\Platform\Licensing::class)->forget();
            $u = C::userLimit();
            abort_if($feature === 'click2call' && $u['limit'] > 0 && $u['in_use'] > $u['limit'], 422,
                "Click2Call user limit reached ({$u['limit']} licensed); this change would allow {$u['in_use']}. Remove someone first.");
        });
    }

    public function setAccessMode(Request $r, string $feature)
    {
        $d = $r->validate(['mode' => 'required|in:everyone,selected']);
        $this->withinLimit($feature, fn () => $this->setSetting("calling_access.$feature", $d['mode']));
        Audit::record('calling.access_mode', null, ['feature' => $feature, 'mode' => $d['mode']]);
        return $this->access();
    }

    /** Give a group or a person access (one call can add several of each). */
    public function grant(Request $r, string $feature)
    {
        $d = $r->validate(['team_ids' => 'array', 'team_ids.*' => 'integer', 'user_ids' => 'array', 'user_ids.*' => 'integer']);
        $teams = Team::query()->whereIn('id', $d['team_ids'] ?? [])->pluck('id');     // company-scoped: other companies' ids drop out
        $users = User::query()->whereIn('id', $d['user_ids'] ?? [])->pluck('id');
        $this->withinLimit($feature, function () use ($feature, $teams, $users) {
            foreach ($teams as $t) {
                CallingAccess::query()->firstOrCreate(['feature' => $feature, 'team_id' => $t, 'user_id' => null]);
            }
            foreach ($users as $u) {
                CallingAccess::query()->firstOrCreate(['feature' => $feature, 'team_id' => null, 'user_id' => $u]);
            }
        });
        Audit::record('calling.access_granted', null, ['feature' => $feature, 'team_ids' => $teams, 'user_ids' => $users]);
        return $this->access();
    }

    public function revoke(string $feature, int $id)
    {
        $g = CallingAccess::query()->where('feature', $feature)->findOrFail($id);
        $g->delete();
        Audit::record('calling.access_removed', null, ['feature' => $feature, 'team_id' => $g->team_id, 'user_id' => $g->user_id]);
        return $this->access();
    }

    /** Calling configuration → SIM calling: on/off for the company (who: Access & permissions). */
    public function setSim(Request $r)
    {
        $d = $r->validate(['enabled' => 'required|boolean']);
        $this->setSetting('sim.enabled', $d['enabled']);
        Audit::record('sim.switched', null, ['enabled' => $d['enabled']]);
        return $this->access();
    }

    // ───────── Numbers & vouchers (kit: Add Voucher) ─────────

    public function numbers()
    {
        $teams = Team::query()->orderBy('name')->pluck('name', 'id');
        return response()->json([
            'crm_id_set' => (bool) \App\Telephony\VoucherService::crmId(),
            'licence_secret_set' => (bool) \App\Telephony\VoucherService::licenceSecret(),
            'numbers' => \App\Models\C2c\Vmn::query()->orderBy('virtual_number')->get()->map(fn ($v) => $v->toArray() + [
                'groups' => collect($v->team_ids ?? [])->map(fn ($t) => $teams[$t] ?? null)->filter()->values(), 'available' => $v->isAvailable(),
            ]),
            'vouchers' => \App\Models\C2c\Voucher::query()->latest('id')->limit(10)->get(),
            'smart31_from_voucher' => Smart31Number::query()->where('source', 'voucher')->count(),
        ]);
    }

    public function addVoucher(Request $r, \App\Telephony\VoucherService $vouchers)
    {
        $d = $r->validate(['voucher_code' => 'required|string|max:100']);
        $res = $vouchers->add($d['voucher_code'], $r->user()->id, (string) $r->ip());
        Audit::record('c2c.voucher_added', null, ['ok' => $res['ok'], 'message' => $res['message']]);
        abort_unless($res['ok'], 422, $res['message']);
        return response()->json(['message' => $res['message']] + $this->numbers()->getData(true));
    }

    /** kit usage values → flags. */
    private function usage(?string $usage): array
    {
        return match ($usage) {
            'click2call' => ['click2call' => true, 'obd' => false],
            'obd' => ['click2call' => false, 'obd' => true],
            'both' => ['click2call' => true, 'obd' => true],
            'webrtc' => ['webrtc' => true],
            default => [],
        };
    }

    private function validTeams(array $ids): array
    {
        return Team::query()->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    public function updateNumberVmn(Request $r, int $id)
    {
        $v = \App\Models\C2c\Vmn::query()->findOrFail($id);
        $d = $r->validate(['team_ids' => 'nullable|array', 'team_ids.*' => 'integer', 'usage' => 'nullable|in:click2call,obd,both,webrtc', 'expires_at' => 'nullable|date']);
        $v->fill((array_key_exists('team_ids', $d) ? ['team_ids' => $this->validTeams($d['team_ids'] ?? [])] : [])
            + (array_key_exists('expires_at', $d) ? ['expires_at' => $d['expires_at']] : []) + $this->usage($d['usage'] ?? null))->save();
        Audit::record('c2c.number_updated', $v, ['number' => $v->virtual_number]);
        return $this->numbers();
    }

    public function bulkNumbers(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer', 'action' => 'required|in:update,delete',
            'team_ids' => 'nullable|array', 'team_ids.*' => 'integer', 'usage' => 'nullable|in:click2call,obd,both,webrtc']);
        $q = \App\Models\C2c\Vmn::query()->whereIn('id', $d['ids']);
        if ($d['action'] === 'delete') {
            $q->delete();
        } else {
            $changes = $this->usage($d['usage'] ?? null) + (!empty($d['team_ids']) ? ['team_ids' => $this->validTeams($d['team_ids'])] : []);
            $q->get()->each(fn ($v) => $v->fill($changes)->save());
        }
        Audit::record('c2c.numbers_bulk', null, ['action' => $d['action'], 'count' => count($d['ids'])]);
        return $this->numbers();
    }

    public function deleteVmn(int $id)
    {
        $v = \App\Models\C2c\Vmn::query()->findOrFail($id);
        $v->delete();
        Audit::record('c2c.number_removed', null, ['number' => $v->virtual_number]);
        return $this->numbers();
    }

    // ───────── Click2Call users (kit: agents page) ─────────

    /** Everyone who can place calls, with each option: allowed or not, and why (everyone / group / person). */
    public function users()
    {
        $people = User::query()->where('status', 'active')->with('teams:id,name')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('calls.make'))->values();   // everyone who can place calls (same as the licence count)
        $grants = CallingAccess::query()->get();
        $access = fn (User $u, string $f) => (function () use ($u, $f, $grants) {
            $personal = $grants->first(fn ($g) => $g->feature === $f && (int) $g->user_id === $u->id);
            $teams = $u->teams->pluck('id')->all();
            $viaGroup = $grants->first(fn ($g) => $g->feature === $f && $g->team_id && in_array($g->team_id, $teams));
            return ['allowed' => C::allows($u, $f), 'via' => C::accessMode($f) === 'everyone' ? 'everyone' : ($personal ? 'person' : ($viaGroup ? 'group' : null)),
                'grant_id' => $personal?->id];
        })();
        return response()->json([
            'limit' => C::userLimit(), 'modes' => collect(C::FEATURES)->mapWithKeys(fn ($f) => [$f => C::accessMode($f)]),
            'users' => $people->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'employee_code' => $u->employee_code, 'groups' => $u->teams->pluck('name'),
                'phone' => $u->phone, 'click_agent_number' => $u->click_agent_number, 'c2c_default_number' => $u->c2c_default_number,
                'agent_number' => C::agentNumber($u), 'numbers' => collect(C::numbersFor($u))->pluck('number'),
                'access' => collect(C::FEATURES)->mapWithKeys(fn ($f) => [$f => $access($u, $f)]),
                'options' => C::routes($u),
            ]),
        ]);
    }

    /** Phone rung first, default company number, and per-person switches ({feature: bool}) in "selected" mode. */
    public function updateUser(Request $r, int $id)
    {
        $u = User::query()->findOrFail($id);
        $d = $r->validate(['click_agent_number' => ['nullable', 'regex:/^\+?[0-9 ]{10,15}$/'], 'c2c_default_number' => 'nullable|string|max:20',
            'click2call' => 'boolean', 'sim' => 'boolean', 'smart31' => 'boolean'],
            ['click_agent_number.regex' => "Enter the employee's 10-digit mobile number."]);
        if (array_key_exists('click_agent_number', $d)) {
            $u->click_agent_number = $d['click_agent_number'] ? \App\Telephony\Click2CallAdapter::normalize($d['click_agent_number']) : null;
        }
        if (array_key_exists('c2c_default_number', $d)) {
            $n = \App\Telephony\Click2CallAdapter::normalize($d['c2c_default_number']);
            abort_if($n !== '' && !collect(C::numbersFor($u))->contains('number', $n), 422, "+91{$n} is not one of this employee's Click2Call numbers.");
            $u->c2c_default_number = $n ?: null;
        }
        $u->save();
        foreach (C::FEATURES as $f) {
            if (!array_key_exists($f, $d)) {
                continue;
            }
            abort_if(C::accessMode($f) !== 'selected', 422, 'This option is open to everyone. Choose "Selected groups and people" for it first.');
            $this->withinLimit($f, fn () => $d[$f]
                ? CallingAccess::query()->firstOrCreate(['feature' => $f, 'team_id' => null, 'user_id' => $u->id])
                : CallingAccess::query()->where(['feature' => $f, 'user_id' => $u->id])->delete());
        }
        Audit::record('c2c.user_updated', $u, array_keys($d));
        return $this->users();
    }

    public function smart31()
    {
        $teams = Team::query()->pluck('name', 'id');
        return response()->json([
            'in_plan' => C::inPlan('smart31'),
            'enabled' => (bool) app(CurrentCompany::class)->get()->setting('smart31.enabled'),
            'click2call_on' => C::active(),
            'numbers' => Smart31Number::query()->orderBy('number')->get()->map(fn ($n) => $n->toArray() + [
                'groups' => collect($n->team_ids ?? [])->map(fn ($t) => $teams[$t] ?? null)->filter()->values(),
                'expired' => $n->expires_on?->isPast() && !$n->expires_on->isToday(),
            ]),
        ]);
    }

    public function setSmart31(Request $r)
    {
        $d = $r->validate(['enabled' => 'required|boolean']);
        $this->setSetting('smart31.enabled', $d['enabled']);
        Audit::record('smart31.switched', null, ['enabled' => $d['enabled']]);
        return $this->smart31();
    }

    private function numberRules(?int $id = null): array
    {
        return [
            'number' => [$id ? 'sometimes' : 'required', 'regex:/^\+?[0-9 ]{6,15}$/'],
            'label' => 'nullable|string|max:60', 'team_ids' => 'nullable|array', 'team_ids.*' => 'integer',
            'active' => 'boolean', 'expires_on' => 'nullable|date',
        ];
    }

    private function clean(array $d): array
    {
        if (isset($d['number'])) {
            $d['number'] = \App\Telephony\Click2CallAdapter::normalize($d['number']);
        }
        if (array_key_exists('team_ids', $d)) {
            $d['team_ids'] = Team::query()->whereIn('id', $d['team_ids'] ?? [])->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        }
        return $d;
    }

    public function addNumber(Request $r)
    {
        $d = $this->clean($r->validate($this->numberRules()));
        abort_if(Smart31Number::query()->where('number', $d['number'])->exists(), 422, 'This number is already in the pool.');
        $n = Smart31Number::query()->create($d);
        Audit::record('smart31.number_added', $n, ['number' => $n->number]);
        return $this->smart31();
    }

    public function updateNumber(Request $r, int $id)
    {
        $n = Smart31Number::query()->findOrFail($id);
        $d = $this->clean($r->validate($this->numberRules($id)));
        abort_if(isset($d['number']) && Smart31Number::query()->where('number', $d['number'])->whereKeyNot($id)->exists(), 422, 'This number is already in the pool.');
        $n->fill($d)->save();
        Audit::record('smart31.number_updated', $n, array_keys($d));
        return $this->smart31();
    }

    public function deleteNumber(int $id)
    {
        $n = Smart31Number::query()->findOrFail($id);
        $n->delete();
        Audit::record('smart31.number_removed', null, ['number' => $n->number]);
        return $this->smart31();
    }
}
