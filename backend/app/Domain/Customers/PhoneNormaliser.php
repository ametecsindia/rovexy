<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\DomainException;

/**
 * Normalises phone numbers to E.164. Deliberately small: defaults are tuned for India (+91), with a few
 * other calling codes. For wider coverage swap in giggsey/libphonenumber-for-php behind this same API.
 */
final class PhoneNormaliser
{
    private const CALLING_CODES = ['IN' => '91', 'AE' => '971', 'US' => '1', 'GB' => '44', 'SG' => '65', 'SA' => '966'];

    public static function normalise(string $raw, string $defaultCountry = 'IN'): string
    {
        $s = trim($raw);
        if ($s === '' || preg_match('/[a-z]/i', preg_replace('/\s*(ext\.?|x)\s*\d+$/i', '', $s) ?? $s)) {
            throw new DomainException('invalid_phone', 'Phone number contains letters or is empty.');
        }
        $plus = str_starts_with($s, '+');
        $digits = preg_replace('/\D/', '', $s) ?? '';
        if (!$plus && str_starts_with($digits, '00')) {
            $plus = true;
            $digits = substr($digits, 2);
        }
        $cc = self::CALLING_CODES[strtoupper($defaultCountry)] ?? throw new DomainException('invalid_phone', 'Unsupported default country.');

        if (!$plus) {
            if ($cc === '91') {
                if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
                    $digits = substr($digits, 2);
                } elseif (strlen($digits) === 11 && $digits[0] === '0') {
                    $digits = substr($digits, 1);
                }
            } elseif ($digits !== '' && $digits[0] === '0') {
                $digits = substr($digits, 1);
            }
            $digits = $cc . $digits;
        }
        $e164 = '+' . $digits;
        if (str_starts_with($digits, '91')) {
            if (!preg_match('/^\+91[1-9]\d{9}$/', $e164)) {
                throw new DomainException('invalid_phone', 'Indian numbers need 10 digits after +91.');
            }
        } elseif (!preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
            throw new DomainException('invalid_phone', 'Not a valid international number.');
        }
        return $e164;
    }

    public static function mask(string $e164): string
    {
        return strlen($e164) > 6 ? substr($e164, 0, 3) . str_repeat('•', strlen($e164) - 7) . substr($e164, -4) : '••••';
    }
}
