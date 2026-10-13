<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use RuntimeException;

/**
 * Signs and verifies licence bundles: base64url(json) . "." . base64url(RSA-SHA256 signature).
 * The licence server holds the private key (storage/app/keys, never shipped); every install ships only the public key
 * (resources/licence/public.pem), so an install can check a licence but cannot make one.
 */
final class LicenceSigner
{
    public static function generate(bool $force = false): string
    {
        $priv = (string) config('ammp.licence.private_key');
        $pub = (string) config('ammp.licence.public_key');
        if (is_file($priv) && !$force) {
            throw new RuntimeException("A signing key already exists at {$priv}. Use --force only if you mean to invalidate every licence file and install already issued.");
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            ?: throw new RuntimeException('OpenSSL could not create a key: ' . openssl_error_string() . ' (on Windows set OPENSSL_CONF to your openssl.cnf).');
        openssl_pkey_export($key, $pem);
        @mkdir(dirname($priv), 0700, true);
        @mkdir(dirname($pub), 0755, true);
        file_put_contents($priv, $pem);
        @chmod($priv, 0600);
        $public = openssl_pkey_get_details($key)['key'];
        file_put_contents($pub, $public);
        return $public;
    }

    public static function canSign(): bool
    {
        return is_file((string) config('ammp.licence.private_key'));
    }

    public static function sign(array $payload): string
    {
        $priv = (string) config('ammp.licence.private_key');
        $key = is_file($priv) ? openssl_pkey_get_private((string) file_get_contents($priv)) : false;
        if (!$key) {
            throw new RuntimeException('The licence signing key is missing. Run: php artisan rovexy:licence-keys');
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        openssl_sign($json, $sig, $key, OPENSSL_ALGO_SHA256);
        return self::b64($json) . '.' . self::b64($sig);
    }

    /** The payload when the signature is genuine, else null. */
    public static function verify(?string $token): ?array
    {
        $pub = (string) config('ammp.licence.public_key');
        if (!$token || !str_contains($token, '.') || !is_file($pub)) {
            return null;
        }
        [$a, $b] = explode('.', trim($token), 2);
        $json = self::unb64($a);
        $sig = self::unb64($b);
        $key = openssl_pkey_get_public((string) file_get_contents($pub));
        if ($json === false || $sig === false || !$key || openssl_verify($json, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
