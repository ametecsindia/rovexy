<?php

declare(strict_types=1);

namespace App\Domain\Customers;

/**
 * Column names of a bank file as it is: finds the row that holds them (title lines above are skipped), makes every name
 * unique (two "Reference" columns → "Reference", "Reference (2)"; a blank one → "Column 5"), and fits a saved mapping
 * to a file by column NAME, so a bank file with its columns in another order still matches. Pure.
 */
final class ImportHeaders
{
    public const SCAN_ROWS = 30;

    public static function norm(string $h): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $h) ?? ''));
    }

    /**
     * The header row among the first rows: the first that is about as wide as the widest row and mostly text
     * (title lines like "HDFC Bank — Allocation Oct 2026" have one or two cells; data rows are mostly numbers or dates).
     * @param list<list<string>> $rows
     */
    public static function detect(array $rows): int
    {
        $rows = array_slice($rows, 0, self::SCAN_ROWS);
        $filled = array_map(fn ($r) => array_values(array_filter(array_map(fn ($v) => trim((string) $v), $r), fn ($v) => $v !== '')), $rows);
        $widest = max(array_map('count', $filled) ?: [0]);
        foreach ($filled as $i => $cells) {
            $text = count(array_filter($cells, fn ($v) => !is_numeric(str_replace([',', ' '], '', $v)) && ImportValidator::parseDate($v) === null));
            if (count($cells) >= max(2, (int) ceil($widest * 0.6)) && $text * 2 >= count($cells)) {
                return $i;
            }
        }
        return 0;
    }

    /** @param list<string> $headers @return list<string> */
    public static function unique(array $headers): array
    {
        $seen = [];
        $out = [];
        foreach (array_values($headers) as $i => $h) {
            $h = trim(preg_replace('/\s+/u', ' ', (string) $h) ?? '');
            $h = $h === '' ? 'Column ' . ($i + 1) : mb_substr($h, 0, 100);
            $base = $h;
            for ($n = 2; isset($seen[self::norm($h)]); $n++) {
                $h = "{$base} ({$n})";
            }
            $seen[self::norm($h)] = true;
            $out[] = $h;
        }
        return $out;
    }

    /**
     * A saved mapping (field => column name, ignored column names) on this file's headers.
     * @param array<string,?string> $fields
     * @param list<string> $headers
     * @return array{mapping: array<string,?int>, ignore: list<int>, score: float}|null null = a mapped column is missing
     */
    public static function fit(array $fields, array $ignored, array $savedHeaders, array $headers): ?array
    {
        $idx = [];
        foreach ($headers as $i => $h) {
            $idx[self::norm($h)] ??= $i;
        }
        $mapping = [];
        foreach ($fields as $field => $name) {
            if ($name === null || $name === '') {
                $mapping[$field] = null;
                continue;
            }
            if (!isset($idx[self::norm($name)])) {
                return null;
            }
            $mapping[$field] = $idx[self::norm($name)];
        }
        $ignore = array_values(array_filter(array_map(fn ($n) => $idx[self::norm((string) $n)] ?? null, $ignored), fn ($i) => $i !== null));
        $mine = array_keys($idx);
        $theirs = array_map(fn ($h) => self::norm((string) $h), $savedHeaders);
        $score = count(array_intersect($mine, $theirs)) / max(1, count(array_unique([...$mine, ...$theirs])));
        return ['mapping' => $mapping, 'ignore' => $ignore, 'score' => $score];
    }
}
