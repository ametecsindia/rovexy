<?php

declare(strict_types=1);

namespace App\Domain\Security;

/** Neutralises CSV/XLSX formula injection (=, +, -, @, tab, CR) on export. */
final class SpreadsheetSanitiser
{
    public static function cell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        // Plain signed numbers (e.g. -12.5, +919876543210) are data, not formulas.
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            return $value;
        }
        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /** @param array<int|string,mixed> $row */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
