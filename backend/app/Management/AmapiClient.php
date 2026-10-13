<?php

declare(strict_types=1);

namespace App\Management;

use App\Domain\Amapi\ServiceAccountJwt;
use App\Domain\DomainException;
use App\Domain\Policy\AmapiPolicyTranslator;
use App\Models\Company;
use App\Models\ManagedDevice;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Android Management API REST client (no Google SDK dependency).
 * Eligibility: AMAPI is for commercial EMM offerings; verify Ametecs' eligibility and device quota before production.
 * https://developers.google.com/android/management/permissible-usage
 */
final class AmapiClient
{
    private const BASE = 'https://androidmanagement.googleapis.com/v1/';

    public function __construct(private readonly CurrentCompany $current)
    {
    }

    public function enabled(): bool
    {
        return config('ammp.management.driver') === 'amapi';
    }

    private function enterprise(): string
    {
        $e = $this->current->get()->amapi_enterprise ?? config('ammp.management.amapi.enterprise');
        if (!$this->enabled() || !$e) {
            throw new DomainException('management_not_configured', 'Android Management is not configured for this company.');
        }
        return $e;
    }

    /** QR enrolment token (fully managed, factory-reset device). additionalData carries the company slug, not secrets. */
    public function createEnrollmentToken(string $policyName, int $ttlSeconds = 3600): array
    {
        $company = $this->current->get();
        $resp = $this->http()->post(self::BASE . $this->enterprise() . '/enrollmentTokens', [
            'policyName' => $this->enterprise() . '/policies/' . $policyName,
            'duration' => $ttlSeconds . 's',
            'allowPersonalUsage' => 'PERSONAL_USAGE_DISALLOWED',
            'oneTimeOnly' => false,
            'additionalData' => json_encode(['company' => $company->slug]),
        ])->throw()->json();
        Audit::record('amapi.enrollment_token_created', null, ['policy' => $policyName, 'expires' => $resp['expirationTimestamp'] ?? null]);
        return ['qr' => $resp['qrCode'] ?? null, 'value' => $resp['value'] ?? null, 'expires_at' => $resp['expirationTimestamp'] ?? null];
    }

    public function applyDevicePolicy(ManagedDevice $device, array $effective): void
    {
        $company = $this->current->get();
        $name = $this->enterprise() . '/policies/dev-' . $device->id;
        $policy = AmapiPolicyTranslator::translate($effective, [
            'package' => (string) config('ammp.employee_app_package'),
            'server_url' => rtrim((string) config('app.url'), '/'),
            'company_slug' => $company->slug,
        ]);
        $this->http()->patch(self::BASE . $name, $policy)->throw();
        $this->http()->patch(self::BASE . $device->amapi_device_name . '?updateMask=policyName', ['policyName' => $name])->throw();
    }

    /** Device report used to verify device-level controls (appliedPolicyVersion, nonComplianceDetails, policyCompliant). */
    public function deviceReport(ManagedDevice $device): array
    {
        return $this->http()->get(self::BASE . $device->amapi_device_name)->throw()->json();
    }

    /** Factory reset — a separate, explicitly authorised and audited action. Revocation never calls this. */
    public function wipe(ManagedDevice $device, string $reason): void
    {
        $this->http()->delete(self::BASE . $device->amapi_device_name . '?' . http_build_query(['wipeDataFlags' => 'WIPE_EXTERNAL_STORAGE', 'wipeReasonMessage' => mb_substr($reason, 0, 200)]))->throw();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $token = Cache::remember('amapi:token', 3000, function () {
            $path = (string) config('ammp.management.amapi.service_account_json');
            $sa = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => ServiceAccountJwt::assertion($sa['client_email'], $sa['private_key'], time()),
            ])->throw()->json('access_token');
        });
        return Http::withToken($token)->timeout(15)->acceptJson();
    }

    /** Pub/Sub push: verify Google-signed OIDC token (audience = configured endpoint). */
    public static function verifyPubSubJwt(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        [$h64, $c64, $s64] = $parts;
        $d = static fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $header = json_decode((string) $d($h64), true);
        $claims = json_decode((string) $d($c64), true);
        if (($header['alg'] ?? '') !== 'RS256' || !isset($header['kid'])) {
            return false;
        }
        $certs = Cache::remember('google:oauth2:certs', 3600, fn () => Http::get('https://www.googleapis.com/oauth2/v1/certs')->throw()->json());
        $pem = $certs[$header['kid']] ?? null;
        if ($pem === null || openssl_verify("{$h64}.{$c64}", (string) $d($s64), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }
        return in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
            && ($claims['aud'] ?? '') === config('ammp.management.amapi.pubsub_audience')
            && ($claims['exp'] ?? 0) > time();
    }
}
