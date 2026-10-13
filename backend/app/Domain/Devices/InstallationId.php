<?php

declare(strict_types=1);

namespace App\Domain\Devices;

use App\Domain\DomainException;

/** Installation IDs are app-generated random 128-bit values (UUIDv4 text). Not hardware identity. */
final class InstallationId
{
    public static function assertValid(string $id): string
    {
        $id = strtolower($id);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw new DomainException('invalid_installation_id');
        }
        return $id;
    }
}
