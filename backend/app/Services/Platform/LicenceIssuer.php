<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use Illuminate\Support\Carbon;

/**
 * Issues licences (cloud workspaces and on-prem installs).
 *  trial        : trial_days, grace 0
 *  subscription : billing cycle (12 / 6 / 3 months), grace_days
 *  perpetual    : never expires, grace_days, AMC 12 months from issue
 *  internal     : never expires, no user limit (super admins only)
 * Keys: RX-XXXX-XXXX-XXXX-CCCC — 12 random characters + a 4-character check, so an install can reject a typo before
 * calling the licence server. Older SC- keys stay valid.
 */
final class LicenceIssuer
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I

    public static function newKey(): string
    {
        $body = '';
        for ($i = 0; $i < 12; $i++) {
            $body .= self::ALPHABET[random_int(0, 31)];
        }
        return 'RX-' . implode('-', str_split($body, 4)) . '-' . self::check($body);
    }

    /** Format check only (offline): RX- keys must carry the right check characters. */
    public static function validKey(string $key): bool
    {
        $key = strtoupper(trim($key));
        if (preg_match('/^RX-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})$/', $key, $m)) {
            return hash_equals(self::check($m[1] . $m[2] . $m[3]), $m[4]);
        }
        return (bool) preg_match('/^(SC|INT)-[A-Z0-9-]{8,30}$/', $key);   // keys issued before 13 Oct 2026
    }

    private static function check(string $body): string
    {
        $h = hash('sha256', 'ROVEXY-KEY|' . $body, true);
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::ALPHABET[ord($h[$i]) % 32];
        }
        return $out;
    }

    public static function issue(Plan $plan, string $holder, ?string $email, ?int $companyId, string $kind, int $users, string $deployment = 'cloud',
        ?string $billing = 'annual', ?string $actor = null): Licence
    {
        $now = Carbon::now();
        $grace = (int) PlatformSettings::get('grace_days');
        [$expires, $graceDays, $amc, $billing] = match ($kind) {
            'trial' => [$now->copy()->addDays((int) PlatformSettings::get('trial_days')), 0, null, null],
            'subscription' => [$now->copy()->addMonthsNoOverflow(Pricing::CYCLES[$billing ?? 'annual'] ?? 12), $grace, null, $billing ?? 'annual'],
            'perpetual' => [null, $grace, $now->copy()->addYear(), null],
            'internal' => [null, 0, null, null],
        };
        $l = Licence::query()->create(['key' => self::newKey(), 'company_id' => $companyId, 'plan_id' => $plan->id, 'holder_name' => $holder, 'holder_email' => $email ? strtolower($email) : null,
            'kind' => $kind, 'deployment' => $deployment, 'billing' => $billing, 'max_users' => $kind === 'internal' ? 0 : $users,
            // A trial (website or staff-made) and an internal licence carry every feature, so a client can try everything.
            'features' => in_array($kind, ['trial', 'internal'], true) ? array_keys(Licensing::FEATURES) : $plan->features, 'status' => 'active',
            'starts_at' => $now, 'expires_at' => $expires, 'grace_days' => $graceDays, 'amc_expires_at' => $amc]);
        $l->log('issued', array_filter(['plan' => $plan->code, 'kind' => $kind, 'deployment' => $deployment, 'users' => $l->max_users, 'billing' => $billing,
            'expires_at' => $expires?->toDateString(), 'amc_until' => $amc?->toDateString()], fn ($v) => $v !== null), $actor);
        return $l;
    }
}
