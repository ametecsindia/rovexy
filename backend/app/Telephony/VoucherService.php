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
 *     (numbers the admin added by hand stay).
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
        return ($s ?: config('ammp.telephony.click2call.licence_secret')) ?: null;
    }

    /** @return array{ok: bool, message: string, voucher?: Voucher} */
    public function add(string $code, ?int $userId, string $ip): array
    {
        $code = trim($code);
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $code)) {
            return ['ok' => false, 'message' => 'Enter a valid voucher code.'];
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
        [$c2c, $obd, $webrtc, $verified] = [$list('click2call_did'), $list('obd_did'), $list('webrtc_did'), $list('tc_verified')];
        $all = array_values(array_unique(array_merge($c2c, $obd, $webrtc)));
        $expiry = !empty($data['expiry']) ? date('Y-m-d', strtotime((string) $data['expiry'])) : null;
        $now = now();
        $cid = app(CurrentCompany::class)->id();

        Vmn::query()->orderBy('id')->get()->each(fn (Vmn $v) => DB::table('c2c_vmn_history')->insert([
            'company_id' => $cid, 'virtual_number' => $v->virtual_number, 'click2call' => $v->click2call, 'webrtc' => $v->webrtc, 'obd' => $v->obd,
            'tc_verified' => $v->tc_verified, 'team_ids' => json_encode($v->team_ids ?? []), 'expires_at' => $v->expires_at,
            'voucher_id' => $v->voucher_id, 'replaced_at' => $now, 'created_at' => $v->created_at, 'updated_at' => $now,
        ]));
        Vmn::query()->delete();
        Smart31Number::query()->where('source', 'voucher')->delete();

        $today = $now->toDateString();
        $smart = collect((array) ($data['smart_groups'] ?? []))->map(fn ($g) => (array) $g)
            ->filter(fn ($g) => ($g['usage_date'] ?? null) === $today && !empty($g['vmn']) && !empty($g['c2c']));   // today's, Click2Call-capable
        $voucher = Voucher::query()->create(['voucher_code' => $code, 'numbers' => count($all), 'smart31_numbers' => $smart->count(), 'expires_at' => $expiry, 'created_by' => $userId]);
        foreach ($all as $n) {
            Vmn::query()->create(['virtual_number' => $n, 'click2call' => in_array($n, $c2c, true), 'obd' => in_array($n, $obd, true),
                'webrtc' => in_array($n, $webrtc, true), 'tc_verified' => in_array($n, $verified, true), 'team_ids' => [], 'expires_at' => $expiry, 'voucher_id' => $voucher->id]);
        }
        foreach ($smart as $g) {
            $n = Click2CallAdapter::normalize($g['vmn']);
            if (Smart31Number::query()->where('number', $n)->exists()) {
                continue;   // the admin already has it in the pool
            }
            Smart31Number::query()->create(['number' => $n, 'source' => 'voucher', 'type' => $g['type'] ?? null, 'group_name' => $g['group_name'] ?? null,
                'usage_date' => $today, 'label' => $g['group_name'] ?? null, 'team_ids' => [], 'active' => true]);
        }
        return $voucher;
    }
}
