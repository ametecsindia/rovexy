<?php

declare(strict_types=1);

use App\Domain\Devices\ApprovalCode;
use App\Domain\Devices\ApprovalState;
use App\Domain\DomainException;
use App\Domain\Security\DevicePublicKey;
use App\Domain\Security\NonceStore;
use App\Domain\Security\OpaqueToken;
use App\Domain\Security\RequestSignature;
use PHPUnit\Framework\TestCase;

final class DeviceSecurityTest extends TestCase
{
    private static function keyPair(): array
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pem = openssl_pkey_get_details($k)['key'];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        return [$k, base64_encode($der)];
    }

    private static function nonces(): NonceStore
    {
        return new class implements NonceStore {
            private array $seen = [];

            public function remember(string $n, int $ttl): bool
            {
                if (isset($this->seen[$n])) {
                    return false;
                }
                return $this->seen[$n] = true;
            }
        };
    }

    private static function sign($priv, string $canonical): string
    {
        openssl_sign($canonical, $sig, $priv, OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    public function test_valid_signature_accepted_once_then_replay_rejected(): void
    {
        [$priv, $pub] = self::keyPair();
        $key = DevicePublicKey::fromBase64Der($pub);
        $v = new RequestSignature(self::nonces());
        $now = 1_800_000_000;
        $body = '{"x":1}';
        $sig = self::sign($priv, RequestSignature::canonical('POST', '/api/mobile/v1/device/session', (string) $now, 'nonce-abcdefghijklmnop', $body));
        $v->verify($key, 'POST', '/api/mobile/v1/device/session', (string) $now, 'nonce-abcdefghijklmnop', $body, $sig, $now);
        $this->expectExceptionObject(new DomainException('signature_replayed'));
        $v->verify($key, 'POST', '/api/mobile/v1/device/session', (string) $now, 'nonce-abcdefghijklmnop', $body, $sig, $now);
    }

    public function test_tampered_body_wrong_key_and_stale_timestamp_rejected(): void
    {
        [$priv, $pub] = self::keyPair();
        [, $otherPub] = self::keyPair();
        $v = new RequestSignature(self::nonces());
        $now = 1_800_000_000;
        $canon = RequestSignature::canonical('GET', '/p', (string) $now, 'nonce-0000000000000001', '');
        $sig = self::sign($priv, $canon);
        foreach ([
            fn () => $v->verify(DevicePublicKey::fromBase64Der($pub), 'GET', '/p', (string) $now, 'nonce-0000000000000001', 'tampered', $sig, $now),
            fn () => $v->verify(DevicePublicKey::fromBase64Der($otherPub), 'GET', '/p', (string) $now, 'nonce-0000000000000001', '', $sig, $now),
            fn () => $v->verify(DevicePublicKey::fromBase64Der($pub), 'GET', '/p', (string) $now, 'nonce-0000000000000001', '', $sig, $now + 301),
            fn () => $v->verify(DevicePublicKey::fromBase64Der($pub), 'GET', '/other', (string) $now, 'nonce-0000000000000001', '', $sig, $now),
        ] as $i => $fn) {
            try {
                $fn();
                self::fail("case {$i} accepted");
            } catch (DomainException $e) {
                self::assertContains($e->reason, ['signature_invalid', 'signature_stale'], "case {$i}");
            }
        }
    }

    public function test_only_p256_public_keys_accepted(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $pem = openssl_pkey_get_details($rsa)['key'];
        $der = base64_encode(base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem)));
        $this->expectException(DomainException::class);
        DevicePublicKey::fromBase64Der($der);
    }

    public function test_approval_state_machine(): void
    {
        self::assertSame(ApprovalState::Approved, ApprovalState::Pending->transitionTo(ApprovalState::Approved));
        self::assertSame(ApprovalState::Revoked, ApprovalState::Approved->transitionTo(ApprovalState::Revoked));
        self::assertFalse(ApprovalState::Revoked->permitsWork());
        self::assertFalse(ApprovalState::Pending->permitsWork());
        foreach ([[ApprovalState::Revoked, ApprovalState::Approved], [ApprovalState::Rejected, ApprovalState::Approved], [ApprovalState::Pending, ApprovalState::Revoked]] as [$a, $b]) {
            try {
                $a->transitionTo($b);
                self::fail("{$a->value}->{$b->value} allowed");
            } catch (DomainException $e) {
                self::assertSame('invalid_transition', $e->reason);
            }
        }
    }

    public function test_approval_code_and_tokens(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $c = ApprovalCode::generate();
            self::assertTrue(ApprovalCode::isWellFormed($c), $c);
            $codes[$c] = true;
        }
        self::assertGreaterThan(195, count($codes));
        $t = OpaqueToken::issue('dst');
        self::assertTrue(OpaqueToken::looksValid($t['plain'], 'dst'));
        self::assertSame(hash('sha256', $t['plain']), $t['hash']);
        self::assertStringNotContainsString($t['plain'], $t['hash']);
    }
}
