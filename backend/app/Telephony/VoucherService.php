<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\C2c\Vmn;
use App\Models\C2c\Voucher;
use App\Models\Smart31Number;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Add Voucher (Click2Call kit / LeadGER ObdController::did_add) for the current company:
 *  1. GET {licence_url}/api/did_voucher/{code}/{crm_id}  (Secret-Key) → must answer status 200
 *  2. GET {licence_url}/api/did_voucher_used/{code}/{ip} → marks it used
 *  3. One transaction: the company's numbers go to c2c_vmn_history and are REPLACED by the voucher's
 *     (click2call_did / obd_did / webrtc_did / tc_verified, expiry). Groups start empty; the admin assigns them.
 *     Today's smart_groups numbers replace the previous voucher's Smart31 numbers in the existing Smart31 pool
 *     (numbers the admin added by hand stay); a Smart31 group keeps the teams the company gave it (inheritAssignments).
 * Daily (scheduler, and "Sync with Ametecs CRM"), LeadGER dailyValidations:
 *  4. GET {licence_url}/api/did_voucher_validate/{code}/{crm_id} → numbers in deleted[] are removed (kept in history)
 *  5. GET {licence_url}/api/smart31-details/{crm_id} → today's Smart31 numbers replace the voucher numbers in the pool
 */
final class VoucherService
{
    public static function crmId(): ?string
    {
        $c = app(CurrentCompany::class)->get();
        return ($c->setting('click2call.crm_id') ?: config('ammp.telephony.click2call.crm_id')) ?: null;
    }

    /** CRM Secret-Key: entered in Calling options (stored encrypted); C2C_LICENSE_SECRET in .env only as an optional fallback. */
    public static function licenceSecret(): ?string
    {
        $enc = app(CurrentCompany::class)->get()->setting('click2call.lc_secret');
        try { $s = $enc ? \Illuminate\Support\Facades\Crypt::decryptString($enc) : null; } catch (\Throwable) { $s = null; }
        return ($s ?: Click2CallSettings::platformSecret('c2c_crm_secret') ?: config('ammp.telephony.click2call.licence_secret')) ?: null;
    }

    /** @return array{ok: bool, message: string, voucher?: Voucher} */
    public function add(string $code, ?int $userId, string $ip): array
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $code)) {
            return ['ok' => false, 'message' => 'Enter a valid voucher code.'];
        }
        if (!self::crmId() || !self::licenceSecret()) {
            return ['ok' => false, 'message' => Click2CallSettings::managed() ? 'Ametecs has not finished your Click2Call set-up yet (CRM id). Please contact Ametecs support.' : (!self::crmId()
                ? 'Enter your CRM id first (Calling configuration → Calling options → Click2Call connection).'
                : 'Enter the CRM secret key first (Calling configuration → Calling options → Click2Call connection).')];
        }
        $result = $this->validate($code);
        if ($result === null) {
            return ['ok' => false, 'message' => 'The licence server could not be reached. Try again in a minute.'];
        }
        if ((int) ($result['status'] ?? 0) !== 200) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'This voucher is not valid.')];
        }
        $this->markUsed($code, $ip);
        try {
            $voucher = DB::transaction(fn () => $this->import($code, (array) ($result['data'] ?? []), $userId));
        } catch (\Throwable $e) {
            Log::error('[Click2Call] voucher not saved: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The voucher was accepted but its numbers could not be saved. Try again.'];
        }
        return ['ok' => true, 'message' => "Voucher added: {$voucher->numbers} number(s)" . ($voucher->smart31_numbers ? " and {$voucher->smart31_numbers} Smart31 number(s)" : '') . '. Assign them to groups.', 'voucher' => $voucher];
    }

    private function licence()
    {
        return Http::baseUrl(rtrim((string) config('ammp.telephony.click2call.licence_url'), '/'))
            ->withHeaders(['Secret-Key' => (string) self::licenceSecret()])->acceptJson()->timeout(30);
    }

    public function validate(string $code): ?array
    {
        try {
            $json = $this->licence()->get('/api/did_voucher/' . rawurlencode($code) . '/' . rawurlencode((string) self::crmId()))->json();
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('[Click2Call] licence server unreachable: ' . $e->getMessage());
            return null;
        }
    }

    public function markUsed(string $code, string $ip): void
    {
        try {
            $this->licence()->get('/api/did_voucher_used/' . rawurlencode($code) . '/' . rawurlencode($ip));
        } catch (\Throwable) {
            // ponytail: LeadGER ignores this answer too; the voucher's numbers are already ours.
        }
    }

    private function import(string $code, array $data, ?int $userId): Voucher
    {
        $list = fn (string $key) => array_values(array_filter(array_map(fn ($n) => Click2CallAdapter::normalize($n), explode(',', (string) ($data[$key] ?? '')))));
        [$c2c, $obd, $webrtc, $verified] = [$list('click2call_did'), $list('obd_did'), $list('webrtc_did'), $list('tc_verified')];
        $all = array_values(array_unique(array_merge($c2c, $obd, $webrtc)));
        $expiry = !empty($data['expiry']) ? date('Y-m-d', strtotime((string) $data['expiry'])) : null;
        $now = now();

        $this->archiveVmns(Vmn::query()->orderBy('id')->get(), $now);
        Vmn::query()->delete();

        $voucher = Voucher::query()->create(['voucher_code' => $code, 'numbers' => count($all), 'smart31_numbers' => 0, 'expires_at' => $expiry, 'created_by' => $userId]);
        foreach ($all as $n) {
            Vmn::query()->create(['virtual_number' => $n, 'click2call' => in_array($n, $c2c, true), 'obd' => in_array($n, $obd, true),
                'webrtc' => in_array($n, $webrtc, true), 'tc_verified' => in_array($n, $verified, true), 'team_ids' => [], 'expires_at' => $expiry, 'voucher_id' => $voucher->id]);
        }
        $voucher->forceFill(['smart31_numbers' => $this->replaceSmart31((array) ($data['smart_groups'] ?? []))])->save();
        return $voucher;
    }

    /**
     * Daily check with the licence server (LeadGER dailyValidations, steps 2–3): numbers the server lists as deleted
     * leave the company's numbers, and today's Smart31 numbers replace the voucher ones in the pool. A server that
     * can't be reached changes nothing. The verdict is kept in click2call.voucher_sync for the console.
     * @return array{ok: bool, message: string, removed: int, smart31: ?int}
     */
    public function syncDaily(): array
    {
        if (!self::crmId() || !self::licenceSecret()) {
            return ['ok' => false, 'message' => Click2CallSettings::managed() ? 'Ametecs has not finished your Click2Call set-up yet (CRM id).' : 'Enter the CRM id and CRM secret key in Calling options first.', 'removed' => 0, 'smart31' => null];
        }
        $removed = 0;
        $problems = [];
        if ($voucher = Voucher::query()->latest('id')->first()) {
            $json = $this->get('/api/did_voucher_validate/' . rawurlencode($voucher->voucher_code) . '/' . rawurlencode((string) self::crmId()));
            if ($json === null) {
                $problems[] = 'voucher check failed';
            } else {
                $gone = collect((array) ($json['deleted'] ?? $json['data']['deleted'] ?? []))
                    ->map(fn ($n) => Click2CallAdapter::normalize(is_array($n) ? ($n['virtual_number'] ?? $n['vmn'] ?? '') : (string) $n))->filter()->unique()->values()->all();
                $removed = $gone ? DB::transaction(function () use ($gone) {
                    $rows = Vmn::query()->whereIn('virtual_number', $gone)->get();
                    $this->archiveVmns($rows, now());
                    Vmn::query()->whereIn('id', $rows->pluck('id'))->delete();
                    return $rows->count();
                }) : 0;
            }
        }
        $smart = null;
        $json = $this->get('/api/smart31-details/' . rawurlencode((string) self::crmId()));
        $list = is_array($json) ? ($json['vmn_details'] ?? $json['data']['vmn_details'] ?? null) : null;
        if (is_array($list)) {
            $smart = DB::transaction(fn () => $this->replaceSmart31($list));
        } else {
            $problems[] = 'Smart31 numbers not received';
        }
        $ok = !$problems;
        $message = ($ok ? 'Checked with Ametecs CRM' : 'Partly checked (' . implode(', ', $problems) . ')')
            . ": {$removed} number(s) removed" . ($smart !== null ? ", {$smart} Smart31 number(s) for today" : '') . '.';
        $c = app(CurrentCompany::class)->get();
        $s = $c->settings ?? [];
        data_set($s, 'click2call.voucher_sync', ['at' => now()->toIso8601String(), 'ok' => $ok, 'message' => $message]);
        $c->forceFill(['settings' => $s])->save();
        return ['ok' => $ok, 'message' => $message, 'removed' => $removed, 'smart31' => $smart];
    }

    private function get(string $path): ?array
    {
        try {
            $json = $this->licence()->get($path)->json();
            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('[Click2Call] licence server unreachable: ' . $e->getMessage());
            return null;
        }
    }

    private function archiveVmns($rows, $now): void
    {
        $cid = app(CurrentCompany::class)->id();
        $rows->each(fn (Vmn $v) => DB::table('c2c_vmn_history')->insert([
            'company_id' => $cid, 'virtual_number' => $v->virtual_number, 'click2call' => $v->click2call, 'webrtc' => $v->webrtc, 'obd' => $v->obd,
            'tc_verified' => $v->tc_verified, 'team_ids' => json_encode($v->team_ids ?? []), 'expires_at' => $v->expires_at,
            'voucher_id' => $v->voucher_id, 'replaced_at' => $now, 'created_at' => $v->created_at, 'updated_at' => $now,
        ]));
    }

    /**
     * Today's Click2Call-capable Smart31 numbers (smart_groups / vmn_details rows) become the voucher numbers in the
     * existing Smart31 pool. A number staying in the list keeps its groups, switch and rotation count; a new number gets
     * the teams its Smart31 group had before (current rows, else smart31_history — LeadGER inheritAssignments).
     * Numbers the admin added, or that came from SmartDCM, are never touched. Returns how many voucher numbers are in the pool.
     */
    private function replaceSmart31(array $rows): int
    {
        $today = now(CurrentCompany::tz())->toDateString();
        $want = collect($rows)->map(fn ($g) => (array) $g)
            ->filter(fn ($g) => substr((string) ($g['usage_date'] ?? ''), 0, 10) === $today && !empty($g['vmn']) && !empty($g['c2c']))
            ->keyBy(fn ($g) => Click2CallAdapter::normalize($g['vmn']));
        $cid = app(CurrentCompany::class)->id();
        $old = Smart31Number::query()->where('source', 'voucher')->get()->keyBy('number');
        $groupTeams = DB::table('smart31_history')->where('company_id', $cid)->whereNotNull('group_name')->orderBy('id')->get(['group_name', 'team_ids'])
            ->merge($old->map(fn ($n) => (object) ['group_name' => $n->group_name, 'team_ids' => json_encode($n->team_ids ?? [])])->values())
            ->reduce(function (array $m, $r) {
                $ids = json_decode((string) $r->team_ids, true) ?: [];
                return $ids && $r->group_name ? [$r->group_name => $ids] + $m : $m;   // latest non-empty wins
            }, []);
        foreach ($old->except($want->keys()->all()) as $n) {
            DB::table('smart31_history')->insert(['company_id' => $cid, 'number' => $n->number, 'group_name' => $n->group_name, 'type' => $n->type,
                'team_ids' => json_encode($n->team_ids ?? []), 'usage_date' => $n->usage_date, 'calls' => $n->calls, 'replaced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $n->delete();
        }
        $count = 0;
        foreach ($want as $number => $g) {
            $attrs = ['type' => $g['type'] ?? null, 'group_name' => $g['group_name'] ?? null, 'label' => $g['group_name'] ?? null, 'usage_date' => $today];
            if ($row = $old->get($number)) {
                $row->fill($attrs + (empty($row->team_ids) && isset($groupTeams[$attrs['group_name']]) ? ['team_ids' => $groupTeams[$attrs['group_name']]] : []))->save();
            } elseif (Smart31Number::query()->where('number', $number)->exists()) {
                continue;   // the admin (or SmartDCM) already has it in the pool
            } else {
                Smart31Number::query()->create($attrs + ['number' => $number, 'source' => 'voucher', 'active' => true,
                    'team_ids' => $groupTeams[$attrs['group_name']] ?? []]);
            }
            $count++;
        }
        return $count;
    }
}
