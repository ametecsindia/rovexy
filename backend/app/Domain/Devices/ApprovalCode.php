<?php

declare(strict_types=1);

namespace App\Domain\Devices;

/**
 * Short human-matchable code shown on the phone and in the admin queue.
 * It is NOT a secret and never authenticates anything.
 * Crockford-style alphabet without 0/O/1/I/L/U to avoid misreads.
 */
final class ApprovalCode
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $chars = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, $max)];
        }
        return substr($chars, 0, 4) . '-' . substr($chars, 4);
    }

    public static function isWellFormed(string $code): bool
    {
        return (bool) preg_match('/^[' . self::ALPHABET . ']{4}-[' . self::ALPHABET . ']{4}$/', $code);
    }
}
