<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Domain\DomainException;
use App\Models\Platform\Licence;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use Illuminate\Support\Facades\DB;

/**
 * Central side of on-premises licensing.
 *  check-in : validate(key, fingerprint, users) → 200 {ok:true, licence:<signed bundle>} or 403 {ok:false, reason}
 *             reasons (closed set — the install demotes only on these): unknown_key, licence_suspended, licence_revoked,
 *             licence_superseded, licence_expired, server_mismatch
 *  binding  : the first fingerprint that checks in is bound; a different machine gets server_mismatch (logged + one sales
 *             alert e-mail per licence per day). Staff release or move the binding.
 *  file     : a signed licence file for a server without internet (machine-locked when a server ID is given).
 */
final class LicenceAuthority
{
    public const REASONS = ['unknown_key', 'licence_suspended', 'licence_revoked', 'licence_superseded', 'licence_expired', 'server_mismatch'];

    /** @return array{0:int,1:array} HTTP status + JSON body */
    public static function validate(string $key, string $fingerprint, ?int $users): array
    {
        $l = Licence::query()->with('plan:id,name,code')->where('key', strtoupper(trim($key)))->first();
        if (!$l || $l->deployment !== 'onprem') {
            return self::refuse('unknown_key', 'This licence key is not known. Check it, or contact Ametecs.');
        }
        if ($l->status !== 'active') {
            self::note($l, 'licence.check_refused', ['reason' => $l->status, 'fingerprint' => $fingerprint]);
            return self::refuse('licence_' . $l->status, 'This licence is ' . $l->status . '. Contact Ametecs.');
        }
        if ($l->expired()) {
            return self::refuse('licence_expired', 'This licence has expired. Renew it to keep using ROVEXY.');
        }
        if ($l->server_fingerprint && !hash_equals($l->server_fingerprint, $fingerprint)) {
            if (self::once('server_mismatch', "licence:{$l->id}:" . now()->toDateString())) {
                PlatformAudit::record('licence.server_mismatch', $l, ['fingerprint' => $fingerprint], 'licence-server');
                $to = PlatformSettings::get('seller_email');
                if ($to) {
                    Sales::send($to, "ROVEXY licence {$l->key} used on another server", '<p>Licence <b>' . e($l->key) . '</b> (' . e($l->holder_name) . ') checked in from a server it is not bound to.</p>'
                        . '<p>Bound server: ' . e($l->server_fingerprint) . '<br>This server: ' . e($fingerprint) . '</p><p>If the customer replaced the server, move or release the binding in Super Admin → Licences.</p>');
                }
            }
            return self::refuse('server_mismatch', 'This licence is registered to another server. Contact Ametecs to move it.');
        }
        $first = $l->server_fingerprint === null;
        $l->forceFill(['server_fingerprint' => $fingerprint, 'activated_at' => $l->activated_at ?? now(), 'last_validated_at' => now(), 'reported_users' => $users ?? $l->reported_users])->save();
        if ($first) {
            $l->log('activated', ['fingerprint' => $fingerprint], 'licence-server');
        }
        if (self::once('licence_verified', "licence:{$l->id}:" . now()->toDateString())) {
            PlatformAudit::record('licence.verified', $l, ['users' => $users], 'licence-server');
        }
        // G3: Ametecs's Firebase credential travels beside (not inside) the signed licence, to active bound licences only.
        return [200, ['ok' => true, 'licence' => LicenceSigner::sign(self::payload($l, 'online', $fingerprint))]
            + array_filter(['push' => \App\Services\PushCredential::forLicence()])];
    }

    /** Signed licence file for an offline server. */
    public static function file(Licence $l, ?string $fingerprint, string $actor): string
    {
        if ($l->deployment !== 'onprem' && !$fingerprint) {
            throw new DomainException('bad_fingerprint', 'A cloud licence file needs the workspace fingerprint from its Licence page.');
        }
        if ($l->status !== 'active' || $l->expired()) {
            throw new DomainException('not_active', 'A licence file can only be made for an active licence.');
        }
        $fingerprint = $fingerprint ? strtolower(trim($fingerprint)) : null;
        if ($fingerprint !== null && !preg_match('/^[a-f0-9]{40,64}$/', $fingerprint)) {
            throw new DomainException('bad_fingerprint', 'The server ID is the 40-character code shown in the install\'s Plan & licence page.');
        }
        if ($fingerprint && $l->server_fingerprint !== $fingerprint) {
            $l->log('binding_moved', ['from' => $l->server_fingerprint, 'to' => $fingerprint, 'by' => 'licence file'], $actor);
            $l->forceFill(['server_fingerprint' => $fingerprint, 'activated_at' => $l->activated_at ?? now()])->save();
        }
        $l->log('file_issued', ['fingerprint' => $fingerprint ?? 'not locked'], $actor);
        PlatformAudit::record('licence.file_issued', $l, ['fingerprint' => $fingerprint]);
        return LicenceSigner::sign(self::payload($l, 'file', $fingerprint));
    }

    /** What the install is told (signed). */
    public static function payload(Licence $l, string $source, ?string $fingerprint): array
    {
        $open = SalesOrder::query()->where('licence_id', $l->id)->where('status', 'created')->latest('id')->first();
        return [
            'v' => 1, 'source' => $source, 'key' => $l->key, 'holder' => $l->holder_name, 'plan' => $l->plan?->name, 'plan_code' => $l->plan?->code,
            'kind' => $l->kind, 'deployment' => $l->deployment, 'billing' => $l->billing, 'max_users' => $l->max_users, 'features' => array_values($l->features ?? []),
            'status' => $l->status, 'expires_at' => $l->expires_at?->toAtomString(), 'grace_days' => $l->grace_days, 'amc_expires_at' => $l->amc_expires_at?->toAtomString(),
            'fingerprint' => $fingerprint, 'issued_at' => now()->toAtomString(),
            'open_order' => $open && !$open->expiredQuote() ? ['number' => $open->number, 'total' => $open->total, 'pay_url' => $open->payUrl()] : null,
        ];
    }

    private static function refuse(string $reason, string $message): array
    {
        return [403, ['ok' => false, 'reason' => $reason, 'message' => $message]];
    }

    private static function note(Licence $l, string $action, array $meta): void
    {
        if (self::once($action, "licence:{$l->id}:" . now()->toDateString())) {
            PlatformAudit::record($action, $l, $meta, 'licence-server');
        }
    }

    /** True the first time (kind, ref) is seen — the mail-log's unique index gives once-a-day logging for free. */
    private static function once(string $kind, string $ref): bool
    {
        return DB::table('platform_mail_log')->insertOrIgnore(['kind' => $kind, 'ref' => $ref, 'email' => '-', 'created_at' => now()]) === 1;
    }
}
