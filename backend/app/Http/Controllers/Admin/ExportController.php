<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExportService;
use App\Support\Audit;
use Illuminate\Http\Request;

/** G21: Reports & exports — one CSV per kind, and the audit pack ZIP (CSVs + recordings + slips + checksums). */
final class ExportController extends Controller
{
    public function csv(Request $r, string $kind)
    {
        $f = $this->filters($r);
        Audit::record('report.exported', null, ['kind' => $kind] + $f);
        $svc = new ExportService($r->user(), $f);
        [$head, $rows] = $svc->table($kind);
        return \App\Support\Excel::download("{$kind}-{$f['from']}-to-{$f['to']}", $head, $rows);
    }

    /** POST reports/table.xlsx — a table already on screen (employee performance, cases) as the same formatted Excel file. */
    public function table(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:80', 'head' => 'required|array|max:60', 'head.*' => 'nullable|string|max:120',
            'rows' => 'present|array|max:20000', 'rows.*' => 'array|max:60']);
        Audit::record('report.exported', null, ['kind' => 'table', 'name' => $d['name'], 'rows' => count($d['rows'])]);
        $rows = array_map(fn ($row) => array_map(fn ($v) => is_scalar($v) || $v === null ? $v : null, $row), $d['rows']);
        return \App\Support\Excel::download(preg_replace('/[^A-Za-z0-9._-]+/', '-', $d['name']), array_map('strval', $d['head']), $rows);
    }

    public function pack(Request $r)
    {
        $f = $this->filters($r);
        abort_unless(class_exists(\ZipArchive::class), 503, 'ZIP files cannot be made on this server: the PHP "zip" extension is switched off. Ask your administrator to enable it (php.ini: extension=zip).');
        @set_time_limit(600);
        // Recordings and slips only for people who may download them; the CSVs are always in the pack.
        $media = $r->user()->hasPermission('recordings.download') && $r->boolean('media', true);
        $res = (new ExportService($r->user(), $f))->pack($media);
        Audit::record('report.audit_pack', null, $f + ['media' => $media, 'files' => $res['files'], 'skipped' => $res['skipped']]);
        return response()->download($res['path'], 'ROVEXY-audit-pack-' . ($f['portfolio'] ?? 'all') . "-{$f['from']}-to-{$f['to']}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function filters(Request $r): array
    {
        return $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'portfolio' => 'nullable|string|max:20', 'employee' => 'nullable|integer']);
    }
}
