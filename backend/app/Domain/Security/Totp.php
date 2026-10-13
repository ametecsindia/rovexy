<?php

declare(strict_types=1);

namespace App\Domain\Security;

/** RFC 6238 TOTP (SHA-1, 30 s, 6 digits) for administrator MFA, with replay protection by step. */
final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secretB32, int $timestamp, int $digits = 6, int $period = 30): string
    {
        return self::hotp(self::base32Decode($secretB32), intdiv($timestamp, $period), $digits);
    }

    /**
     * Returns the matched time step (to be stored as last-used so the same code cannot be replayed),
     * or null. Accepts ±1 step drift. Steps <= $lastUsedStep are rejected.
     */
    public static function verify(string $secretB32, string $code, int $timestamp, ?int $lastUsedStep = null, int $window = 1): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secretB32);
        $step = intdiv($timestamp, 30);
        for ($i = -$window; $i <= $window; $i++) {
            $s = $step + $i;
            if ($lastUsedStep !== null && $s <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::hotp($key, $s, 6), $code)) {
                return $s;
            }
        }
        return null;
    }

    public static function provisioningUri(string $secretB32, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function hotp(string $key, int $counter, int $digits): string
    {
        $bin = pack('N*', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $h = hash_hmac('sha1', $bin, $key, true);
        $o = ord($h[19]) & 0x0F;
        $v = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($v % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $p = strpos(self::B32, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Invalid base32');
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
