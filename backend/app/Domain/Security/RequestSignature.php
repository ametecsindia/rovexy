<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\DomainException;

/**
 * Proof-of-possession for device requests.
 * Canonical string: METHOD \n PATH(+query) \n TIMESTAMP(unix s) \n NONCE \n hex(SHA256(body))
 * Signature: ECDSA P-256 / SHA-256, DER-encoded, base64 — exactly what Android Keystore's
 * Signature("SHA256withECDSA") produces.
 */
final class RequestSignature
{
    public const MAX_SKEW_SECONDS = 300;

    public function __construct(private readonly NonceStore $nonces)
    {
    }

    public static function canonical(string $method, string $pathWithQuery, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public function verify(
        DevicePublicKey $key,
        string $method,
        string $pathWithQuery,
        string $timestamp,
        string $nonce,
        string $body,
        string $signatureB64,
        int $now,
    ): void {
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            throw new DomainException('signature_stale');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) {
            throw new DomainException('signature_invalid');
        }
        $sig = base64_decode(strtr($signatureB64, '-_', '+/'), true);
        if ($sig === false || $sig === '') {
            throw new DomainException('signature_invalid');
        }
        $ok = openssl_verify(self::canonical($method, $pathWithQuery, $timestamp, $nonce, $body), $sig, $key->pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new DomainException('signature_invalid');
        }
        // Replay check only after a valid signature so attackers cannot burn nonces.
        if (!$this->nonces->remember($key->fingerprint . ':' . $nonce, self::MAX_SKEW_SECONDS * 2)) {
            throw new DomainException('signature_replayed');
        }
    }
}
