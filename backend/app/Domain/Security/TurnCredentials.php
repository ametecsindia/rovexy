<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * coturn "use-auth-secret" (TURN REST API) ephemeral credentials.
 * username = "<expiry>:<company>:<session>", credential = base64(HMAC-SHA1(secret, username)).
 */
final class TurnCredentials
{
    public static function issue(string $sharedSecret, int $companyId, string $sessionId, int $now, int $ttl = 600): array
    {
        $username = ($now + $ttl) . ':c' . $companyId . ':' . $sessionId;
        return [
            'username' => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, $sharedSecret, true)),
            'ttl' => $ttl,
        ];
    }
}
