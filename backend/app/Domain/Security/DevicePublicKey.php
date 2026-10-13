<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\DomainException;

/**
 * Validates and normalises a device public key supplied at registration.
 * Accepts base64 (std or url-safe) DER SubjectPublicKeyInfo for EC P-256 only.
 */
final class DevicePublicKey
{
    private function __construct(public readonly string $pem, public readonly string $fingerprint)
    {
    }

    public static function fromBase64Der(string $b64): self
    {
        $der = base64_decode(strtr(trim($b64), '-_', '+/'), true);
        if ($der === false || strlen($der) < 80 || strlen($der) > 200) {
            throw new DomainException('invalid_public_key');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new DomainException('invalid_public_key');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new DomainException('invalid_public_key', 'Only EC P-256 keys are accepted.');
        }
        return new self($pem, hash('sha256', $der));
    }

    public static function fromPem(string $pem): self
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new DomainException('invalid_public_key');
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '', true);
        return new self($pem, hash('sha256', (string) $der));
    }
}
