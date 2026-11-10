<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Security\SpreadsheetSanitiser;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every report download as a real Excel workbook that opens readable: columns as wide as their content (long text such
 * as addresses wraps instead of being cut off), a bold header row that stays on screen with filters, dates as real dates
 * (no "#####"), amounts as numbers, and phone numbers / account IDs kept as text (no "9.18E+11", no lost leading zeros).
 * Text a spreadsheet would run as a formula is defused (SpreadsheetSanitiser).
 */
final class Excel
{
    private const MAX_WIDTH = 50;   // wider text wraps
    private const SAMPLE = 1000;    // rows looked at for the column widths

    /**
     * @param list<string> $head
     * @param iterable<array<int|string, mixed>> $rows
     * @param list<array<int, mixed>> $intro lines above the table (title, period…)
     * @param array<int, mixed>|null $totals last row, bold
     */
    public static function download(string $fileName, array $head, iterable $rows, array $intro = [], ?array $totals = null, ?string $sheetName = null): Response
    {
        $fileName = (string) preg_replace('/\.(csv|xlsx)$/i', '', $fileName);
        if (request()->query('format') === 'csv') {   // plain CSV for systems that import it
            return response()->streamDownload(function () use ($head, $rows, $totals) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM: Excel shows ₹ and names correctly
                fputcsv($out, $head);
                foreach ($rows as $r) {
                    fputcsv($out, SpreadsheetSanitiser::row(array_values((array) $r)));
                }
                $totals && fputcsv($out, SpreadsheetSanitiser::row($totals));
                fclose($out);
            }, "{$fileName}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $path = tempnam(sys_get_temp_dir(), 'rx-xlsx') . '.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        self::sheet($w, $sheetName ?? pathinfo($fileName, PATHINFO_FILENAME), $head, $rows, $intro, $totals);
        $w->close();
        return response()->download($path, "{$fileName}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** The same workbook as bytes (for ZIP files such as the audit pack). */
    public static function bytes(string $sheetName, array $head, iterable $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rx-xlsx') . '.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        self::sheet($w, $sheetName, $head, $rows);
        $w->close();
        $data = (string) file_get_contents($path);
        @unlink($path);
        return $data;
    }

    /** Writes one formatted sheet into an open workbook (call addNewSheetAndMakeItCurrent() between sheets). */
    public static function sheet(Writer $w, string $name, array $head, iterable $rows, array $intro = [], ?array $totals = null): void
    {
        $sheet = $w->getCurrentSheet();
        $sheet->setName(mb_substr(trim((string) preg_replace('/[\\\\\/?*\[\]:]+/', ' ', $name)) ?: 'Sheet1', 0, 31));
        $it = is_array($rows) ? new \ArrayIterator($rows) : ($rows instanceof \Iterator ? $rows : new \IteratorIterator($rows));
        $it->rewind();
        $sample = [];
        while ($it->valid() && count($sample) < self::SAMPLE) {
            $sample[] = array_values((array) $it->current());
            $it->next();
        }

        // Column widths from the header and the first rows; long columns wrap.
        $n = max(count($head), ...array_map('count', $sample ?: [[]]), ...($totals ? [count($totals)] : []));
        $width = [];
        for ($i = 0; $i < $n; $i++) {
            $len = mb_strlen((string) ($head[$i] ?? ''));
            foreach ($sample as $r) {
                $len = max($len, self::shown($r[$i] ?? null));
            }
            $width[$i] = min(self::MAX_WIDTH, max(8, $len + 2));
            $sheet->setColumnWidth($width[$i], $i + 1);
        }
        $headStyle = (new Style())->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('072654')->setShouldWrapText();
        $bold = (new Style())->setFontBold();
        $wrap = (new Style())->setShouldWrapText();
        $date = (new Style())->setFormat('dd-mm-yyyy');
        $dateTime = (new Style())->setFormat('dd-mm-yyyy hh:mm');

        foreach ($intro as $line) {
            $w->addRow(Row::fromValues(array_map(fn ($v) => is_scalar($v) ? $v : null, array_values($line)), $bold));
        }
        if ($intro) {
            $w->addRow(Row::fromValues([]));
        }
        $headRow = count($intro) + ($intro ? 2 : 1);
        $w->addRow(Row::fromValues(array_map('strval', $head), $headStyle));
        $line = function (array $r, ?Style $rowStyle = null) use ($w, $width, $wrap, $date, $dateTime) {
            $cells = [];
            foreach (array_values($r) as $i => $v) {
                [$v, $kind] = self::value($v);
                $style = match ($kind) { 'date' => $date, 'datetime' => $dateTime, default => ($width[$i] ?? 0) >= self::MAX_WIDTH ? $wrap : null };
                $cells[] = Cell::fromValue($v, $style);
            }
            $w->addRow(new Row($cells, $rowStyle));
        };
        $count = 0;
        foreach ($sample as $r) {
            $line($r);
            $count++;
        }
        while ($it->valid()) {
            $line(array_values((array) $it->current()));
            $it->next();
            $count++;
        }
        if ($totals) {
            $line($totals, $bold);
        }
        $sheet->setSheetView((new SheetView())->setFreezeRow($headRow + 1));
        if ($n > 0 && $count > 0) {
            $sheet->setAutoFilter(new AutoFilter(0, $headRow, $n - 1, $headRow + $count));
        }
    }

    /** Spreadsheet value and kind: 'date' / 'datetime' for "2026-11-09" / "2026-11-09 10:16[:00]", numbers as numbers, IDs as text. */
    public static function value(mixed $v): array
    {
        if ($v instanceof \DateTimeInterface) {
            return [\DateTimeImmutable::createFromInterface($v)->setTimezone(new \DateTimeZone(CurrentCompany::tz())), 'datetime'];
        }
        if (!is_string($v)) {
            return [is_bool($v) ? ($v ? 'Yes' : 'No') : $v, null];
        }
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v))) {
            return [$d, 'date'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $v)
            && ($d = \DateTimeImmutable::createFromFormat(strlen($v) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s', str_replace('T', ' ', $v)))) {
            return [$d, 'datetime'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $v)) {   // API time → company time
            try {
                return [(new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(CurrentCompany::tz())), 'datetime'];
            } catch (\Throwable) {
            }
        }
        // Amounts and counts as numbers; phones, account numbers and anything with a leading zero stay text.
        if (preg_match('/^-?(0|[1-9]\d{0,8})(\.\d+)?$/', $v)) {
            return [str_contains($v, '.') ? (float) $v : (int) $v, null];
        }
        return [SpreadsheetSanitiser::cell($v), null];
    }

    private static function shown(mixed $v): int
    {
        [$v, $kind] = self::value($v);
        return match ($kind) { 'date' => 10, 'datetime' => 16, default => $v === null ? 0 : max(array_map('mb_strlen', explode("\n", (string) $v))) };
    }
}
