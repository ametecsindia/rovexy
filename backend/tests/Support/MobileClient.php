<?php

namespace Tests\Support;

use App\Domain\Security\RequestSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Emulates the Android app: Keystore-like EC key, signed bootstrap requests, bearer tokens. */
final class MobileClient
{
    public string $installationId;
    public $privateKey;
    public string $publicKeyB64;
    public ?string $deviceToken = null;
    public ?string $accessToken = null;
    public ?string $refreshToken = null;

    public function __construct(private TestCase $t, public string $workspace)
    {
        $this->installationId = (string) Str::uuid();
        $this->privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($this->privateKey)['key'];
        $this->publicKeyB64 = base64_encode(base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem)));
    }

    public function register(): TestResponse
    {
        return $this->t->withHeaders(['X-Workspace' => $this->workspace])->postJson('/api/mobile/v1/devices/register', [
            'installation_id' => $this->installationId, 'public_key' => $this->publicKeyB64, 'manufacturer' => 'TestCo', 'model' => 'T1', 'sdk_int' => 34,
        ]);
    }

    public function signed(string $method, string $uri, array $body = []): TestResponse
    {
        $json = $body === [] ? '' : json_encode($body);
        $ts = (string) time();
        $nonce = Str::random(24);
        openssl_sign(RequestSignature::canonical($method, $uri, $ts, $nonce, $json), $sig, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $this->t->call($method, $uri, [], [], [], $this->server([
            'HTTP_X_INSTALLATION_ID' => $this->installationId, 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => base64_encode($sig),
        ]), $json);
    }

    public function deviceCall(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->t->call($method, $uri, [], [], [], $this->server(['HTTP_AUTHORIZATION' => 'Bearer ' . $this->deviceToken]), $body ? json_encode($body) : '');
    }

    public function work(string $method, string $uri, array $body = [], ?string $token = null): TestResponse
    {
        return $this->t->call($method, $uri, [], [], [], $this->server(['HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->accessToken)]), $body ? json_encode($body) : '');
    }

    public function upload(string $uri, array $fields, array $files): TestResponse
    {
        return $this->t->call('POST', $uri, $fields, [], $files, ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessToken, 'HTTP_X_WORKSPACE' => $this->workspace, 'HTTP_ACCEPT' => 'application/json']);
    }

    private function server(array $extra): array
    {
        return $extra + ['HTTP_X_WORKSPACE' => $this->workspace, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }
}
