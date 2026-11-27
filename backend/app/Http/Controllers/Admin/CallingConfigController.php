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
            'mode' => $f === 'smart31' ? 'click2call' : C::accessMode($f),
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
                'groups' => collect($v->team_ids ?? [])->map(fn ($t) => $teams[$t] ?? null)->filter()->values(), 'available' => \App\Telephony\LcGuard::vmnLicensed($v),
            ]),
            'lc_managed' => \App\Telephony\LcGuard::managed(),
            'licence_source' => $source = \App\Telephony\LcGuard::source(),
            'smartdcm_linked' => \App\Telephony\LcGuard::smartDcmLinked(),
            'smartdcm_licence' => ['crm_id' => app(CurrentCompany::class)->get()->setting('click2call.smartdcm.crm_id'),
                'key_from_smartdcm' => (bool) app(CurrentCompany::class)->get()->setting('click2call.smartdcm.lc_secret'),
                'at' => app(CurrentCompany::class)->get()->setting('click2call.smartdcm.at')],
            'vouchers' => \App\Models\C2c\Voucher::query()->where('source', $source)->latest('id')->limit(10)->get(),   // the chosen licence's
            'smart31_from_voucher' => Smart31Number::query()->where('source', 'voucher')->count(),
            'last_sync' => app(CurrentCompany::class)->get()->setting('click2call.voucher_sync'),
        ]);
    }

    /** "Sync with Ametecs CRM": the daily voucher check + today's Smart31 numbers, now (LeadGER users/license/sync-did). */
    public function syncVouchers(\App\Telephony\VoucherService $vouchers)
    {
        abort_if(!\App\Telephony\VoucherService::crmId(), 422, \App\Telephony\LcGuard::source() === 'smartdcm'
            ? 'SmartDCM has not sent its CRM id yet.' : 'Enter the CRM id and CRM secret key in Calling options first.');
        \App\Telephony\LcGuard::source() === 'smartdcm' && $this->pullSmartDcmLicence();
        $res = $vouchers->syncDaily();
        Audit::record('c2c.voucher_synced', null, ['ok' => $res['ok'], 'message' => $res['message']]);
        return response()->json(['message' => $res['message']] + $this->numbers()->getData(true));
    }

    /**
     * Licence for C2C and C2C Smart31 (27 Nov 2026): "smartdcm" = the voucher and CRM id already set up in the linked
     * SmartDCM (nothing typed again here); "rovexy" = the client's own CRM id, key and voucher. Saved as the SmartDCM
     * connection's use_calling option — the same switch as on the SmartDCM integration page.
     */
    public function setLicenceSource(Request $r)
    {
        $d = $r->validate(['source' => 'required|in:smartdcm,rovexy']);
        $conn = \App\Models\IntegrationConnection::query()->where('system', 'smartdcm')->first();
        abort_if($d['source'] === 'smartdcm' && !\App\Telephony\LcGuard::smartDcmLinked(), 422, 'Link SmartDCM first (Administration → Integrations → SmartDCM).');
        if ($conn) {
            $conn->forceFill(['options' => array_replace((array) $conn->options, ['use_calling' => $d['source'] === 'smartdcm'])])->save();
        }
        Audit::record('c2c.licence_source', null, ['source' => $d['source']]);
        $d['source'] === 'smartdcm' && $this->pullSmartDcmLicence();
        \Illuminate\Support\Facades\Cache::forget('lc-check:' . app(CurrentCompany::class)->id());
        \App\Telephony\LcGuard::refresh(true);   // today's Smart31 list for the chosen CRM id, and its vouchers checked
        return $this->numbers();
    }

    /** SmartDCM's calling account (CRM id, voucher and its status, numbers) now, not at the next 5-minute sync. */
    private function pullSmartDcmLicence(): void
    {
        $conn = \App\Models\IntegrationConnection::query()->where('system', 'smartdcm')->where('enabled', true)->first();
        $conn && rescue(fn () => \App\Services\SmartDcm\SmartDcmSync::for($conn)->telephony(), null);
    }

    public function addVoucher(Request $r, \App\Telephony\VoucherService $vouchers)
    {
        $d = $r->validate(['voucher_code' => 'required|string|max:100']);
        $res = $vouchers->add($d['voucher_code'], $r->user()->id, (string) $r->ip());
        Audit::record('c2c.voucher_added', null, ['ok' => $res['ok'], 'message' => $res['message']]);
        abort_unless($res['ok'], 422, $res['message']);
        return response()->json(['message' => $res['message']] + $this->numbers()->getData(true));
    }

    private function validTeams(array $ids): array
    {
        return Team::query()->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    public function updateNumberVmn(Request $r, int $id)
    {
        $v = \App\Models\C2c\Vmn::query()->findOrFail($id);
        // 27 Nov 2026: what a number may do and until when comes from the LC Manager (voucher); here only its groups change.
        $d = $r->validate(['team_ids' => 'nullable|array', 'team_ids.*' => 'integer']);
        $v->fill(array_key_exists('team_ids', $d) ? ['team_ids' => $this->validTeams($d['team_ids'] ?? [])] : [])->save();
        Audit::record('c2c.number_updated', $v, ['number' => $v->virtual_number]);
        return $this->numbers();
    }

    public function bulkNumbers(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer', 'action' => 'required|in:update,delete',
            'team_ids' => 'nullable|array', 'team_ids.*' => 'integer']);
        $q = \App\Models\C2c\Vmn::query()->whereIn('id', $d['ids']);
        if ($d['action'] === 'delete') {
            $q->delete();
        } else {
            $changes = !empty($d['team_ids']) ? ['team_ids' => $this->validTeams($d['team_ids'])] : [];
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
            if ($f === 'smart31') {
                return ['allowed' => C::allows($u, $f), 'via' => 'click2call', 'grant_id' => null];
            }
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
            'click2call' => 'boolean', 'sim' => 'boolean'],   // Smart31 follows Click2Call (company switch), no per-person setting
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
                'licensed' => \App\Telephony\LcGuard::smart31Licensed($n),
            ]),
            'lc_managed' => \App\Telephony\LcGuard::managed(),
        ]);
    }

    public function setSmart31(Request $r)
    {
        $d = $r->validate(['enabled' => 'required|boolean']);
        $this->setSetting('smart31.enabled', $d['enabled']);
        Audit::record('smart31.switched', null, ['enabled' => $d['enabled']]);
        return $this->smart31();
    }

    /** Give a whole Smart31 group (all its numbers, today's and future days') to teams (LeadGER smart31-group/update). */
    public function setSmart31Group(Request $r)
    {
        $d = $r->validate(['group_name' => 'required|string|max:100', 'team_ids' => 'present|array', 'team_ids.*' => 'integer']);
        $ids = $this->clean(['team_ids' => $d['team_ids']])['team_ids'];
        $n = Smart31Number::query()->where('group_name', $d['group_name'])->get();
        abort_if($n->isEmpty(), 404, 'No Smart31 numbers in this group.');
        $n->each(fn ($x) => $x->forceFill(['team_ids' => $ids])->save());
        Audit::record('smart31.group_assigned', null, ['group' => $d['group_name'], 'team_ids' => $ids]);
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
        abort_if(\App\Telephony\LcGuard::managed(), 422, 'Smart31 numbers come from the Ametecs LC Manager for each day; they cannot be added by hand.');
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
        if (\App\Telephony\LcGuard::managed() || $n->source !== 'admin') {
            unset($d['number'], $d['expires_on']);   // the number and its validity come from the LC Manager / SmartDCM
        }
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
