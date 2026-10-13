<?php

declare(strict_types=1);

namespace App\Domain\Security;

/** Redacts secrets and phone numbers from log context before it reaches log sinks. */
final class LogRedactor
{
    private const SECRET_KEYS = '/pass(word)?|secret|token|authorization|cookie|signature|credential|totp|otp|private|api[_-]?key/i';

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SECRET_KEYS, $key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : null);
            }
            return $out;
        }
        if (is_string($value)) {
            $value = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[REDACTED]', $value) ?? $value;
            // Mask phone numbers: keep last 4 digits.
            $value = preg_replace_callback('/\+?\d[\d\s-]{8,14}\d/', static function (array $m): string {
                $digits = preg_replace('/\D/', '', $m[0]) ?? '';
                return strlen($digits) >= 10 ? '***' . substr($digits, -4) : $m[0];
            }, $value) ?? $value;
        }
        return $value;
    }
}
