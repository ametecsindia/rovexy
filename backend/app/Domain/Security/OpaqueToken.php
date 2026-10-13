<?php

declare(strict_types=1);

namespace App\Domain\Security;

/** Random bearer tokens. Only the SHA-256 hash is persisted; the plaintext is returned once. */
final class OpaqueToken
{
    public static function issue(string $prefix): array
    {
        $plain = $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return ['plain' => $plain, 'hash' => self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function looksValid(string $plain, string $prefix): bool
    {
        return (bool) preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9_-]{43}$/', $plain);
    }
}
