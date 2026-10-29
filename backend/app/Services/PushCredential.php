<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Amapi\ServiceAccountJwt;
use App\Domain\DomainException;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging credential (G3). Ametecs's own Firebase project is used for every install, because the one
 * Play Store app has Ametecs's google-services.json built in — a phone's push token only works with that project.
 *
 * Where it lives (nothing in .env):
 *  - Licence server (rovexy.app): pasted by staff in Super Admin → Settings → Push notifications (stored encrypted).
 *  - On-premises install: delivered by the licence server with each successful licence check-in, over HTTPS, and stored
 *    encrypted in this install's database (setting 'fcm_source' = 'licence'). An install without internet (licence file)
 *    gets no credential: the app then syncs by polling, which works, only a little slower.
 *  - Older servers that still have FCM_PROJECT_ID / FCM_SERVICE_ACCOUNT_JSON_PATH in .env keep working (read last).
 *
 * The service account should have only the "Firebase Cloud Messaging API Admin" role (send-only; no access to any
 * other Firebase or Google Cloud data). Pings never carry customer data (PushService).
 */
final class PushCredential
{
    public const SETTING = 'fcm_service_account_json';

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    public static function get(): ?array
    {
        $json = rescue(fn () => PlatformSettings::get(self::SETTING), null, false);
        if ($json) {
            return rescue(fn () => self::parse($json), null, false);
        }
        $file = (string) config('ammp.push.fcm_service_account_json');   // legacy .env setup
        if ($file !== '' && is_file($file) && config('ammp.push.fcm_project_id')) {
            $sa = rescue(fn () => self::parse((string) file_get_contents($file)), null, false);
            return $sa ? ['project_id' => (string) config('ammp.push.fcm_project_id')] + $sa : null;
        }
        return null;
    }

    /** 'settings' (pasted in Super Admin), 'licence' (sent by the licence server), 'env' (old .env setup) or null. */
    public static function source(): ?string
    {
        if (rescue(fn () => PlatformSettings::get(self::SETTING), null, false)) {
            return PlatformSettings::get('fcm_source') === 'licence' ? 'licence' : 'settings';
        }
        return self::get() ? 'env' : null;
    }

    /** True when pings can really be sent: a credential is present (or a non-log driver is configured the old way). */
    public static function ready(): bool
    {
        return self::get() !== null;
    }

    /** Checks a pasted service-account JSON file and returns its useful fields (DomainException with a plain reason). */
    public static function parse(string $json): array
    {
        $sa = json_decode(trim($json), true);
        if (!is_array($sa)) {
            throw new DomainException('fcm_bad_json', 'That is not a Firebase service-account file. Paste the whole .json file downloaded from Firebase → Project settings → Service accounts.');
        }
        if (($sa['type'] ?? null) !== 'service_account' || empty($sa['project_id']) || empty($sa['client_email']) || empty($sa['private_key'])
            || !str_contains((string) $sa['private_key'], 'PRIVATE KEY')) {
            throw new DomainException('fcm_bad_json', 'The file must be a service-account key (type "service_account" with project_id, client_email and private_key).');
        }
        return ['project_id' => (string) $sa['project_id'], 'client_email' => (string) $sa['client_email'], 'private_key' => (string) $sa['private_key']];
    }

    /** Stores a credential (encrypted). $source: 'settings' | 'licence'. Blank JSON removes it. */
    public static function store(?string $json, string $source): void
    {
        if ($json === null || trim($json) === '') {
            PlatformSettings::set(self::SETTING, '');
            PlatformSettings::set('fcm_source', '');
        } else {
            $sa = self::parse($json);
            PlatformSettings::set(self::SETTING, json_encode(['type' => 'service_account'] + $sa, JSON_UNESCAPED_SLASHES));
            PlatformSettings::set('fcm_source', $source);
        }
        Cache::forget(self::cacheKey());
    }

    /** What the console may see: never the key, only which project / account is set and where it came from. */
    public static function summary(): array
    {
        $sa = self::get();
        return ['set' => $sa !== null, 'source' => self::source(), 'project_id' => $sa['project_id'] ?? null, 'client_email' => $sa['client_email'] ?? null];
    }

    /**
     * Licence server side: what is sent to an on-prem install with a successful check-in (null = nothing to send).
     * Sent beside the signed licence, not inside it, so the licence token the install keeps holds no secret.
     */
    public static function forLicence(): ?array
    {
        $sa = self::get();
        return $sa ? ['fcm' => json_encode(['type' => 'service_account'] + $sa, JSON_UNESCAPED_SLASHES)] : null;
    }

    /** On-prem side: store what the licence server sent. A credential pasted locally (source 'settings') is never overwritten. */
    public static function receiveFromLicence(mixed $push): void
    {
        $json = is_array($push) ? ($push['fcm'] ?? null) : null;
        if (!is_string($json) || $json === '' || self::source() === 'settings') {
            return;
        }
        $current = rescue(fn () => PlatformSettings::get(self::SETTING), null, false);
        $new = rescue(fn () => json_encode(['type' => 'service_account'] + self::parse($json), JSON_UNESCAPED_SLASHES), null, false);
        if ($new && $new !== $current) {
            self::store($json, 'licence');
        }
    }

    /** OAuth access token for FCM (cached ~50 min). */
    public static function accessToken(): string
    {
        $sa = self::get() ?? throw new DomainException('fcm_not_set', 'Push notifications are not set up (no Firebase credential).');
        return Cache::remember(self::cacheKey(), 3000, function () use ($sa) {
            $jwt = ServiceAccountJwt::assertion($sa['client_email'], $sa['private_key'], time(), 'https://www.googleapis.com/auth/firebase.messaging');
            return Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }

    /** "Test" button: asks Google for an access token with the stored credential. Proves the key is valid and not disabled. */
    public static function test(): array
    {
        if (!self::get()) {
            return ['ok' => false, 'detail' => 'No Firebase credential is set.'];
        }
        Cache::forget(self::cacheKey());
        try {
            $token = self::accessToken();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return ['ok' => false, 'detail' => 'Google refused the credential: ' . mb_substr((string) ($e->response->json('error_description') ?? $e->response->json('error') ?? $e->response->status()), 0, 200)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Could not reach Google or read the key: ' . mb_substr($e->getMessage(), 0, 200)];
        }
        return ['ok' => (bool) $token, 'detail' => 'Google accepted the credential for project ' . self::get()['project_id'] . '.'];
    }

    public static function projectId(): string
    {
        return (string) (self::get()['project_id'] ?? '');
    }

    private static function cacheKey(): string
    {
        return 'fcm:access_token:' . substr(sha1((string) (self::get()['client_email'] ?? '')), 0, 12);
    }
}
