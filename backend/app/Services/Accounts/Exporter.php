<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts exports: Excel (.xlsx, real workbook via OpenSpout), CSV, or a print-ready page the browser saves as PDF
 * ("Save as PDF" in the print dialog — no PDF library on the server). Text that a spreadsheet would run as a formula is
 * defused with a leading apostrophe.
 */
final class Exporter
{
    /**
     * @param list<string> $headers
     * @param iterable<array<int, scalar|null>> $rows
     * @param array<int, scalar|null>|null $totals last row, bold
     */
    public static function download(string $format, string $name, string $title, array $headers, iterable $rows, ?array $totals = null, array $meta = []): Response
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        $stamp = now(Ledger::TZ)->format('Y-m-d');
        if ($format === 'pdf') {
            return response()->view('accounts.print', ['title' => $title, 'headers' => $headers, 'rows' => $rows, 'totals' => $totals, 'meta' => $meta,
                'seller' => \App\Services\Platform\PlatformSettings::get('seller_name'), 'printed' => now(Ledger::TZ)->format('d M Y, h:i A')]);
        }
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers, $rows, $totals, $safe) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows ₹ and names correctly
                fputcsv($out, $headers);
                foreach ($rows as $r) {
                    fputcsv($out, array_map($safe, $r));
                }
                if ($totals) {
                    fputcsv($out, array_map($safe, $totals));
                }
                fclose($out);
            }, "{$name}-{$stamp}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $path = tempnam(sys_get_temp_dir(), 'acc') . '.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31));
        $bold = (new Style())->setFontBold();
        foreach ($meta as $k => $v) {
            $w->addRow(Row::fromValues([$k, (string) $v]));
        }
        if ($meta) {
            $w->addRow(Row::fromValues([]));
        }
        $w->addRow(Row::fromValues($headers, $bold));
        foreach ($rows as $r) {
            $w->addRow(Row::fromValues(array_map($safe, array_values($r))));
        }
        if ($totals) {
            $w->addRow(Row::fromValues(array_map($safe, $totals), $bold));
        }
        $w->close();
        return response()->download($path, "{$name}-{$stamp}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
