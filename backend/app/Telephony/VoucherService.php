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
 *     (click2call_did / obd_did / tc_verified, expiry; webrtc_did is not used). Groups start empty; the admin assigns them.
 *     Today's smart_groups numbers replace the previous voucher's Smart31 numbers in the existing Smart31 pool
 *     (numbers the admin added by hand stay); a Smart31 group keeps the teams the company gave it (inheritAssignments).
 * Every 5 minutes, before each Click2Call call (LcGuard) and on "Sync with Ametecs CRM" — LeadGER/SmartDCM dailyValidations:
 *  4. GET {licence_url}/api/did_voucher_validate/{code}/{crm_id} → status ≠ 200: voucher revoked, all its numbers removed;
 *     status 200: numbers in deleted[] are removed (kept in history)
 *  5. GET {licence_url}/api/smart31-details/{crm_id} → today's Smart31 numbers replace the voucher numbers in the pool
 */
final class VoucherService
{
    /** Today's Click2Call Smart31 numbers from the LC Manager (last replaceSmart31). */
    private array $lcSmart31 = [];

    /** CRM id of the chosen licence: SmartDCM's (from the link) or the client's own (Calling options). */
    public static function crmId(): ?string
    {
        $c = app(CurrentCompany::class)->get();
        $own = $c->setting('click2call.crm_id');
        $dcm = LcGuard::source() === 'smartdcm' ? $c->setting('click2call.smartdcm.crm_id') : null;
        return ($dcm ?: $own ?: config('ammp.telephony.click2call.crm_id')) ?: null;
    }

    /** CRM Secret-Key of the chosen licence: SmartDCM's if it sends one, else the client's own (Calling options). */
    public static function licenceSecret(): ?string
    {
        $c = app(CurrentCompany::class)->get();
        $dec = function (?string $enc): ?string {
            try { return $enc ? \Illuminate\Support\Facades\Crypt::decryptString($enc) : null; } catch (\Throwable) { return null; }
        };
        $dcm = LcGuard::source() === 'smartdcm' ? $dec($c->setting('click2call.smartdcm.lc_secret')) : null;
        return ($dcm ?: $dec($c->setting('click2call.lc_secret')) ?: config('ammp.telephony.click2call.licence_secret')) ?: null;
    }

    /** "SMART-31 Web RTC - 1" is WebRTC Smart31. The LC Manager marks C2C groups webrtc=1 too, so the group name decides (27 Nov 2026). */
    public static function webrtcGroup(?string $name): bool
    {
        return (bool) preg_match('/web\s*-?\s*rtc/i', (string) $name);
    }

    /** "Secret Key not matched": ROVEXY's CRM key is wrong. Says nothing about the voucher, so it is never revoked for it. */
    private static function keyRejected(?array $json): bool
    {
        return stripos((string) ($json['message'] ?? $json['msg'] ?? $json['error'] ?? ''), 'secret key') !== false;
    }

    /** @return array{ok: bool, message: string, voucher?: Voucher} */
    public function add(string $code, ?int $userId, string $ip): array
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $code)) {
            return ['ok' => false, 'message' => 'Enter a valid voucher code.'];
        }
        if (LcGuard::source() === 'smartdcm') {
            return ['ok' => false, 'message' => 'This workspace uses the SmartDCM licence: its voucher comes from SmartDCM automatically. Choose "ROVEXY licence" to add your own voucher.'];
        }
        if (!self::crmId() || !self::licenceSecret()) {
            return ['ok' => false, 'message' => !self::crmId()
                ? 'Enter your CRM id first (Calling configuration → Calling options → Click2Call connection).'
                : 'Enter the CRM secret key first (Calling configuration → Calling options → Click2Call connection).'];
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
        // ROVEXY offers Click2Call and Smart31 only (27 Nov 2026): webrtc_did numbers are not taken.
        [$c2c, $obd, $verified] = [$list('click2call_did'), $list('obd_did'), $list('tc_verified')];
        $all = array_values(array_unique(array_merge($c2c, $obd)));
        $expiry = !empty($data['expiry']) ? date('Y-m-d', strtotime((string) $data['expiry'])) : null;
        $now = now();

        $this->archiveVmns(Vmn::query()->orderBy('id')->get(), $now);
        Vmn::query()->delete();

        Voucher::query()->where('status', 'active')->where('source', 'rovexy')->update(['status' => 'replaced']);   // only the newest own voucher's numbers exist
        $voucher = Voucher::query()->create(['voucher_code' => $code, 'source' => 'rovexy', 'numbers' => count($all), 'smart31_numbers' => 0, 'expires_at' => $expiry, 'created_by' => $userId,
            'status' => 'active', 'last_ok_at' => now(), 'checked_at' => now()]);
        foreach ($all as $n) {
            Vmn::query()->create(['virtual_number' => $n, 'click2call' => in_array($n, $c2c, true), 'obd' => in_array($n, $obd, true),
                'webrtc' => false, 'tc_verified' => in_array($n, $verified, true), 'team_ids' => [], 'expires_at' => $expiry, 'voucher_id' => $voucher->id]);
        }
        $voucher->forceFill(['smart31_numbers' => $this->replaceSmart31((array) ($data['smart_groups'] ?? []))])->save();
        $this->markSmart31Confirmed();
        return $voucher;
    }

    /**
     * Check the voucher and today's Smart31 numbers with the LC Manager now (LcGuard calls this before calls and every
     * 5 minutes; "Sync with Ametecs CRM" in the console too). As SmartDCM's dailyValidations:
     *  - voucher past its expiry day, or did_voucher_validate answering status ≠ 200 (revoked / expired / not found in
     *    the LC Manager) → the voucher is marked revoked/expired and ALL its numbers are removed (kept in history);
     *  - status 200 → numbers listed in deleted[] are removed; a new expiry date is taken;
     *  - smart31-details → today's Smart31 numbers replace the voucher ones; an answer refusing the CRM id removes them.
     * A server that can't be reached changes nothing (LcGuard's grace period then decides). The verdict is kept in
     * click2call.voucher_sync for the console.
     * @return array{ok: bool, message: string, removed: int, smart31: ?int, voucher: ?string}
     */
    public function verify(bool $record = false): array
    {
        if (!self::crmId() || !self::licenceSecret()) {
            return ['ok' => false, 'message' => LcGuard::source() === 'smartdcm' ? 'SmartDCM has not sent its CRM id yet.' : 'Enter the CRM id and CRM secret key in Calling options first.', 'removed' => 0, 'smart31' => null, 'voucher' => null];
        }
        $removed = 0;
        $problems = [];
        $state = null;
        // Every active voucher: ROVEXY's own, and the ones behind SmartDCM's numbers (27 Nov 2026).
        $source = LcGuard::source();
        $key = self::licenceSecret();
        foreach (Voucher::query()->where('status', 'active')->where('source', $source)->latest('id')->get() as $voucher) {   // the chosen licence's vouchers
            $was = $removed;
            $voucher->checked_at = now();
            if ($voucher->expires_at && $voucher->expires_at->copy()->endOfDay()->lt(now())) {
                $removed += $this->retire($voucher, 'expired', 'The voucher expired on ' . $voucher->expires_at->toDateString() . '.');
            } elseif (!$key) {   // no CRM key: a SmartDCM voucher goes by the status SmartDCM sends (SmartDcmSync)
                $source === 'rovexy' && $problems[] = 'no CRM secret key';
            } else {
                [$json, $reachable] = $this->ask('/api/did_voucher_validate/' . rawurlencode($voucher->voucher_code) . '/' . rawurlencode((string) self::crmId()));
                if (!$reachable) {
                    $problems[] = 'the LC Manager did not answer the voucher check';
                } elseif (self::keyRejected($json)) {
                    $problems[] = 'the LC Manager rejected the CRM secret key (Super Admin → Settings → Click2Call)';
                } elseif (self::refused($json)) {
                    $msg = (string) ($json['message'] ?? $json['msg'] ?? $json['error'] ?? 'The LC Manager no longer accepts this voucher.');
                    $removed += $this->retire($voucher, self::looksExpired($json) ? 'expired' : 'revoked', mb_substr($msg, 0, 250));
                } else {
                    $voucher->last_ok_at = now();
                    $voucher->status_message = null;
                    $exp = $json['expiry'] ?? $json['data']['expiry'] ?? null;
                    if ($exp && strtotime((string) $exp)) {
                        $voucher->expires_at = date('Y-m-d', strtotime((string) $exp));
                        Vmn::query()->where('voucher_id', $voucher->id)->update(['expires_at' => $voucher->expires_at]);
                    }
                    $gone = collect((array) ($json['deleted'] ?? $json['data']['deleted'] ?? []))
                        ->map(fn ($n) => Click2CallAdapter::normalize(is_array($n) ? ($n['virtual_number'] ?? $n['vmn'] ?? '') : (string) $n))->filter()->unique()->values()->all();
                    $removed += $gone ? DB::transaction(function () use ($gone) {
                        $rows = Vmn::query()->whereIn('virtual_number', $gone)->get();
                        $this->archiveVmns($rows, now());
                        Vmn::query()->whereIn('id', $rows->pluck('id'))->delete();
                        return $rows->count();
                    }) : 0;
                }
            }
            $voucher->save();
            $state = $state === null || $state === 'active' ? $voucher->status : $state;   // a revoked / expired one is what the console must say
            if ($voucher->status !== 'active') {
                \App\Support\Audit::record('c2c.voucher_' . $voucher->status, $voucher, ['removed' => $removed - $was, 'message' => $voucher->status_message]);
            }
        }
        $smart = null;
        [$json, $reachable] = $this->ask('/api/smart31-details/' . rawurlencode((string) self::crmId()));
        $list = is_array($json) ? ($json['vmn_details'] ?? $json['data']['vmn_details'] ?? null) : null;
        if (is_array($list) || ($reachable && self::refused($json) && !self::keyRejected($json))) {
            $smart = DB::transaction(fn () => $this->replaceSmart31(is_array($list) ? $list : []));   // refused = no Smart31 numbers for this CRM id
            $this->markSmart31Confirmed();
        } else {
            $problems[] = 'Smart31 numbers not received';
        }
        $ok = !$problems;
        $message = ($state === 'revoked' ? 'The LC Manager has revoked the voucher: its numbers were removed. '
                : ($state === 'expired' ? 'The voucher has expired: its numbers were removed. ' : ''))
            . ($ok ? 'Checked with Ametecs CRM' : 'Partly checked (' . implode(', ', $problems) . ')')
            . ": {$removed} number(s) removed" . ($smart !== null ? ", {$smart} Smart31 number(s) for today" : '') . '.';
        $c = app(CurrentCompany::class)->get();
        $s = $c->settings ?? [];
        $prev = data_get($s, 'click2call.voucher_sync');
        data_set($s, 'click2call.voucher_sync', ['at' => now()->toIso8601String(), 'ok' => $ok, 'message' => $message, 'voucher' => $state]);
        // The 5-minute check would otherwise save the company row every time; save only when something changed.
        if ($record || $removed || $prev === null || ($prev['ok'] ?? null) !== $ok || ($prev['voucher'] ?? null) !== $state
            || \Illuminate\Support\Carbon::parse($prev['at'] ?? '2000-01-01')->lt(now()->subHour())) {
            $c->forceFill(['settings' => $s])->save();
        }
        return ['ok' => $ok, 'message' => $message, 'removed' => $removed, 'smart31' => $smart, 'voucher' => $state];
    }

    /** Kept for callers of the old name ("Sync with Ametecs CRM"). */
    public function syncDaily(): array
    {
        return $this->verify(true);   // pressed in the console: always show this check's result
    }

    /** As SmartDCM's helper: anything but status 200 is a refusal (revoked, expired, not found, wrong CRM id). */
    private static function refused(?array $json): bool
    {
        if ($json === null) {
            return false;
        }
        foreach (['revoked', 'is_revoked', 'expired', 'is_expired', 'blocked'] as $k) {
            if (!empty($json[$k]) || !empty($json['data'][$k])) {
                return true;
            }
        }
        return (int) ($json['status'] ?? 0) !== 200;
    }

    private static function looksExpired(array $json): bool
    {
        return !empty($json['expired']) || !empty($json['is_expired']) || str_contains(strtolower((string) ($json['message'] ?? '')), 'expir');
    }

    /** The voucher is no longer valid: every number it gave goes to history and is removed (SmartDCM: delete from obd_did). */
    public function retire(Voucher $voucher, string $status, string $message): int
    {
        $voucher->forceFill(['status' => $status, 'revoked_at' => now(), 'status_message' => $message]);
        return DB::transaction(function () use ($voucher) {
            $rows = Vmn::query()->where('voucher_id', $voucher->id)->get();
            $this->archiveVmns($rows, now());
            Vmn::query()->whereIn('id', $rows->pluck('id'))->delete();
            return $rows->count();
        });
    }

    private function markSmart31Confirmed(): void
    {
        $c = app(CurrentCompany::class)->get();
        $s = $c->settings ?? [];
        $prev = data_get($s, 'click2call.smart31_ok_at');
        $list = ['date' => now(CurrentCompany::tz())->toDateString(), 'numbers' => $this->lcSmart31];
        // grace is 24 h: no need to save every check — unless today's LC list changed
        if (!$prev || \Illuminate\Support\Carbon::parse($prev)->lt(now()->subMinutes(30)) || data_get($s, 'click2call.smart31_lc') != $list) {
            data_set($s, 'click2call.smart31_ok_at', now()->toIso8601String());
            data_set($s, 'click2call.smart31_lc', $list);
            $c->forceFill(['settings' => $s])->save();
        }
    }

    /** @return array{0: ?array, 1: bool} the JSON answer, and whether the LC Manager answered at all (no network error, no 5xx). */
    private function ask(string $path): array
    {
        try {
            $r = $this->licence()->get($path);
            $json = $r->json();
            return [is_array($json) ? $json : null, $r->status() < 500 && is_array($json)];
        } catch (\Throwable $e) {
            Log::warning('[Click2Call] LC Manager unreachable: ' . $e->getMessage());
            return [null, false];
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
            // C2C Smart31 only: "SMART-31 Web RTC" groups are WebRTC Smart31 → not used (27 Nov 2026)
            ->filter(fn ($g) => substr((string) ($g['usage_date'] ?? ''), 0, 10) === $today && !empty($g['vmn']) && !empty($g['c2c']) && !self::webrtcGroup($g['group_name'] ?? null))
            ->keyBy(fn ($g) => Click2CallAdapter::normalize($g['vmn']));
        $this->lcSmart31 = $want->keys()->map(fn ($k) => (string) $k)->values()->all();   // SmartDCM's Smart31 numbers are checked against this
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
