<?php

declare(strict_types=1);

namespace App\Domain\Amapi;

/** Google OAuth 2.0 JWT-bearer assertion (RS256) for a service account. Exchanged at oauth2.googleapis.com/token. */
final class ServiceAccountJwt
{
    public const SCOPE = 'https://www.googleapis.com/auth/androidmanagement';

    public static function assertion(string $clientEmail, string $privateKeyPem, int $now, string $scope = self::SCOPE): string
    {
        $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $b64(json_encode([
            'iss' => $clientEmail, 'scope' => $scope, 'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now, 'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $sig = '';
        if (!openssl_sign("{$header}.{$claims}", $sig, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign service-account assertion.');
        }
        return "{$header}.{$claims}." . $b64($sig);
    }
}
