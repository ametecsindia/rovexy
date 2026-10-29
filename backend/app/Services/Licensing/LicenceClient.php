<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Domain\DomainException;
use App\Models\InstallationLicence;
use App\Models\User;
use App\Services\Platform\LicenceIssuer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The on-prem install's side of licensing (LICENCE_MODE=client).
 *
 * Verdict rule: only a known rejection reason from the licence server demotes the licence. Anything else — HTTP 5xx, a
 * proxy's HTML 403, a timeout, an unknown reason — is an OUTAGE: the last verdict is kept and unreachable_since is stamped.
 * The cached licence is a token signed by the licence server and is re-verified (public key) on every read, so editing
 * the database cannot license an install. One server ID (fingerprint) is used for both check-ins and licence files.
 *
 * Without a key, a fresh install runs a 14-day evaluation. A key entered while the server can't be reached works for 7
 * days counted from when the key was saved (not from the last attempt). A licence file is never overruled by a check-in.
 */
final class LicenceClient
{
    private ?array $memo = null;

    public static function isClient(): bool
    {
        return config('ammp.licence.mode') === 'client';
    }

    /** This server's ID: sha256 of the machine id, 40 hex characters. */
    public static function fingerprint(): string
    {
        return Cache::remember('rovexy.server_fingerprint', 86400, fn () => substr(hash('sha256', 'ROVEXY|' . self::machineId()), 0, 40));
    }

    /** A cloud workspace's own fingerprint: this server + the workspace, so a licence file locked to it activates that workspace only. */
    public static function workspaceFingerprint(\App\Models\Company $c): string
    {
        return substr(hash('sha256', 'ROVEXY|' . self::machineId() . '|workspace|' . $c->id . '|' . $c->slug), 0, 40);
    }

    private static function machineId(): string
    {
        if ($id = config('ammp.licence.machine_id')) {
            return (string) $id;
        }
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
            if (is_readable($f) && ($v = trim((string) @file_get_contents($f))) !== '') {
                return $v;
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $out = (string) @shell_exec('reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid 2>NUL');
            if (preg_match('/MachineGuid\s+REG_SZ\s+([0-9a-fA-F-]{36})/', $out, $m)) {
                return strtolower($m[1]);
            }
        }
        return 'weak|' . php_uname('n');   // no machine id: the host name — a rename changes the fingerprint (see weakFingerprint)
    }

    /** True when no hardware/OS machine id could be read and the fingerprint rests on the host name (shown as a warning). */
    public static function weakFingerprint(): bool
    {
        return !config('ammp.licence.machine_id') && str_starts_with(self::machineId(), 'weak|');
    }

    public function row(): InstallationLicence
    {
        return InstallationLicence::query()->firstOrCreate(['id' => 1], ['installed_at' => now(), 'status' => 'none']);
    }

    public function saveKey(string $key): InstallationLicence
    {
        $key = strtoupper(trim($key));
        if (!LicenceIssuer::validKey($key)) {
            throw new DomainException('bad_key', 'That is not a valid ROVEXY licence key. Check for typing mistakes (keys look like RX-XXXX-XXXX-XXXX-XXXX).');
        }
        $row = $this->row();
        $row->fill(['licence_key' => $key, 'key_saved_at' => now(), 'token' => null, 'source' => null, 'status' => 'none', 'last_error' => null, 'unreachable_since' => null])->save();
        $this->memo = null;
        $this->check();
        return $row->fresh();
    }

    /** Check in with the licence server. Never throws: an outage is recorded and the last verdict stays. */
    public function check(): InstallationLicence
    {
        $row = $this->row();
        $this->memo = null;
        if (!$row->licence_key) {
            return $row;
        }
        if ($row->source === 'file') {
            return $this->revocationCheck($row);
        }
        $url = (string) config('ammp.licence.server_url');
        if ($url === '') {
            $row->fill(['last_error' => 'LICENCE_SERVER_URL is not set in .env.', 'last_checked_at' => now()])->save();
            return $row;
        }
        try {
            $res = Http::timeout(10)->acceptJson()->post($url . '/api/v1/license/validate', ['key' => $row->licence_key, 'fingerprint' => self::fingerprint(),
                // unscoped: the nightly check runs with no workspace bound (an install has one workspace anyway)
                'users' => app(\App\Support\CurrentCompany::class)->withoutScope(fn () => User::query()->where('status', 'active')->count())]);
            $j = $res->json();
        } catch (\Throwable $e) {
            return $this->outage($row, 'Licence server unreachable: ' . $e->getMessage());
        }
        $j = is_array($j) ? $j : [];
        if (($j['ok'] ?? false) === true) {
            $p = LicenceSigner::verify($j['licence'] ?? null);
            if ($p && ($p['key'] ?? null) === $row->licence_key && ($p['fingerprint'] ?? null) === self::fingerprint()) {
                $row->fill(['token' => $j['licence'], 'source' => 'online', 'status' => 'active', 'last_checked_at' => now(), 'unreachable_since' => null, 'last_error' => null])->save();
                \App\Services\PushCredential::receiveFromLicence($j['push'] ?? null);   // G3: Firebase credential, stored encrypted
                return $row;
            }
            return $this->outage($row, 'The licence server reply could not be verified (wrong public key or tampered reply).');
        }
        if (in_array($j['reason'] ?? null, LicenceAuthority::REASONS, true)) {   // a real verdict — ignore the HTTP status, read the reason
            $row->fill(['status' => $j['reason'], 'last_checked_at' => now(), 'unreachable_since' => null, 'last_error' => (string) ($j['message'] ?? $j['reason'])])->save();
            return $row;
        }
        return $this->outage($row, 'Licence server answered HTTP ' . $res->status() . ' without a licence verdict.');
    }

    /**
     * Self-serve purchase calls to the licence server (offer / quote / order / cancel), authenticated by this install's key and
     * server ID. A refusal comes back as a DomainException with the server's own message; an outage as 'shop_unavailable'.
     */
    public function shop(string $action, array $body = []): array
    {
        $row = $this->row();
        $url = (string) config('ammp.licence.server_url');
        if (!$row->licence_key || $url === '') {
            throw new DomainException('shop_unavailable', 'Online purchase needs a licence key and LICENCE_SERVER_URL. Contact Ametecs for a quotation.');
        }
        try {
            $res = Http::timeout(20)->acceptJson()->post("{$url}/api/v1/license/{$action}", ['key' => $row->licence_key, 'fingerprint' => self::fingerprint()] + $body);
        } catch (\Throwable $e) {
            throw new DomainException('shop_unavailable', 'The ROVEXY licence server could not be reached. Check this server\'s internet connection and try again.');
        }
        $j = $res->json();
        if ($res->successful() && is_array($j)) {
            return $j;
        }
        if ($res->status() === 422 && is_array($j) && isset($j['message'])) {
            throw new DomainException((string) ($j['error'] ?? $j['code'] ?? 'refused'), (string) $j['message']);
        }
        throw new DomainException('shop_unavailable', 'The ROVEXY licence server did not answer (HTTP ' . $res->status() . '). Try again in a few minutes.');
    }

    /** Check again when the last check is older than recheck_hours (called from the console's licence page and nightly). */
    public function ensureFresh(): void
    {
        $row = $this->row();
        if ($row->licence_key && $row->source !== 'file' && (!$row->last_checked_at || $row->last_checked_at->lt(now()->subHours((int) config('ammp.licence.recheck_hours'))))) {
            Cache::lock('rovexy.licence_check', 600)->get(fn () => $this->check());
        }
    }

    /** Installs a signed licence file (server without internet). */
    public function importFile(string $contents): InstallationLicence
    {
        $p = LicenceSigner::verify(trim($contents));
        if (!$p || ($p['source'] ?? null) !== 'file') {
            throw new DomainException('bad_licence_file', 'This is not a valid ROVEXY licence file.');
        }
        if (($p['fingerprint'] ?? null) !== null && $p['fingerprint'] !== self::fingerprint()) {
            throw new DomainException('bad_licence_file', 'This licence file was made for another server. This server\'s ID is ' . self::fingerprint() . '.');
        }
        if (($p['status'] ?? null) !== 'active') {
            throw new DomainException('bad_licence_file', 'This licence file is not for an active licence.');
        }
        $row = $this->row();
        $row->fill(['licence_key' => $p['key'], 'key_saved_at' => now(), 'token' => trim($contents), 'source' => 'file', 'status' => 'active',
            'last_checked_at' => now(), 'unreachable_since' => null, 'last_error' => null])->save();
        $this->memo = null;
        return $row;
    }

    /** The verified licence payload, or null. */
    public function payload(): ?array
    {
        $row = $this->row();
        $p = LicenceSigner::verify($row->token);
        if (!$p || ($p['key'] ?? null) !== $row->licence_key) {
            return null;
        }
        if (($p['fingerprint'] ?? null) !== null && $p['fingerprint'] !== self::fingerprint()) {
            return null;   // a token copied from another server
        }
        return $p;
    }

    /**
     * What Licensing enforces: {key, kind, plan, max_users, features, status, expires_at, grace_days}.
     * status is 'active', a rejection reason (blocked), or 'unlicensed'.
     */
    public function effective(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $row = $this->row();
        $p = $this->payload();
        $all = array_keys(\App\Services\Platform\Licensing::FEATURES);
        $evaluation = fn (\Illuminate\Support\Carbon $until, string $kind) => ['key' => $row->licence_key, 'kind' => $kind, 'plan' => $kind === 'evaluation' ? 'Evaluation' : 'Key not verified yet',
            'max_users' => 10, 'features' => $all, 'status' => 'active', 'expires_at' => $until->toAtomString(), 'grace_days' => 0];
        return $this->memo = match (true) {
            in_array($row->status, LicenceAuthority::REASONS, true) && ($row->source !== 'file' || in_array($row->status, ['licence_revoked', 'licence_suspended'], true)) => ['key' => $row->licence_key, 'kind' => $p['kind'] ?? null, 'plan' => $p['plan'] ?? null,
                'max_users' => (int) ($p['max_users'] ?? 0), 'features' => $p['features'] ?? [], 'status' => $row->status, 'expires_at' => $p['expires_at'] ?? null, 'grace_days' => (int) ($p['grace_days'] ?? 0)],
            $p !== null && $this->stale($p) => ['key' => $p['key'], 'kind' => $p['kind'], 'plan' => $p['plan'], 'max_users' => (int) $p['max_users'], 'features' => $p['features'] ?? [],
                'status' => 'stale', 'expires_at' => $p['expires_at'], 'grace_days' => (int) $p['grace_days']],
            $p !== null => ['key' => $p['key'], 'kind' => $p['kind'], 'plan' => $p['plan'], 'max_users' => (int) $p['max_users'], 'features' => $p['features'] ?? [],
                'status' => $p['status'] === 'active' ? 'active' : 'licence_' . $p['status'], 'expires_at' => $p['expires_at'], 'grace_days' => (int) $p['grace_days']],
            // ponytail: this window lives in the customer's own database, so it can be stretched by editing key_saved_at — capped at 10 users
            // and every feature of an evaluation, never the paid licence (that needs a signature). Move the anchor into a signed file if it matters.
            $row->licence_key && $row->key_saved_at && $row->key_saved_at->gt(now()->subDays((int) config('ammp.licence.unverified_days')))
                => $evaluation($row->key_saved_at->copy()->addDays((int) config('ammp.licence.unverified_days')), 'unverified'),
            !$row->licence_key && $row->installed_at->gt(now()->subDays((int) config('ammp.licence.evaluation_days')))
                => $evaluation($row->installed_at->copy()->addDays((int) config('ammp.licence.evaluation_days')), 'evaluation'),
            default => ['key' => $row->licence_key, 'kind' => null, 'plan' => null, 'max_users' => 0, 'features' => [], 'status' => 'unlicensed', 'expires_at' => null, 'grace_days' => 0],
        };
    }

    /**
     * A licence file is never overruled by a check-in — except that a revoked or suspended licence is honoured when the
     * server happens to be online (otherwise a revoked perpetual file would run forever). Offline / outages change nothing.
     */
    private function revocationCheck(InstallationLicence $row): InstallationLicence
    {
        $url = (string) config('ammp.licence.server_url');
        if ($url === '') {
            return $row;
        }
        try {
            $j = Http::timeout(10)->acceptJson()->post($url . '/api/v1/license/validate', ['key' => $row->licence_key, 'fingerprint' => self::fingerprint()])->json();
        } catch (\Throwable) {
            return $row;
        }
        $reason = is_array($j) ? ($j['reason'] ?? null) : null;
        $row->fill(['last_checked_at' => now()]);
        if (in_array($reason, ['licence_revoked', 'licence_suspended'], true)) {
            $row->fill(['status' => $reason, 'last_error' => (string) ($j['message'] ?? $reason)]);
        } elseif (is_array($j) && ($j['ok'] ?? false) === true && in_array($row->status, ['licence_revoked', 'licence_suspended'], true)) {
            $row->fill(['status' => 'active', 'last_error' => null]);   // resumed on the licence server
        }
        $row->save();
        return $row;
    }

    /** An online-verified licence not confirmed for offline_days (from its signed issue time, which the customer can't edit). */
    private function stale(array $p): bool
    {
        $days = (int) config('ammp.licence.offline_days');
        return $days > 0 && ($p['source'] ?? null) === 'online' && isset($p['issued_at'])
            && \Illuminate\Support\Carbon::parse($p['issued_at'])->lt(now()->subDays($days));
    }

    private function outage(InstallationLicence $row, string $error): InstallationLicence
    {
        $row->fill(['unreachable_since' => $row->unreachable_since ?? now(), 'last_error' => mb_substr($error, 0, 255), 'last_checked_at' => now()])->save();
        return $row;
    }
}
