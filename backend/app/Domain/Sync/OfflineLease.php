<?php

declare(strict_types=1);

namespace App\Domain\Sync;

/**
 * Offline authorisation lease. The server issues lease_expires_at on every successful validation;
 * the device may use protected work functions offline only until then. Device restrictions are unaffected.
 */
final class OfflineLease
{
    public static function expiresAt(\DateTimeImmutable $validatedAt, int $leaseMinutes): \DateTimeImmutable
    {
        return $validatedAt->modify('+' . max(0, $leaseMinutes) . ' minutes');
    }

    public static function isValid(\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): bool
    {
        return $now < $expiresAt;
    }
}
