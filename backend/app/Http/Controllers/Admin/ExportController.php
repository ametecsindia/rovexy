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
        return response()->streamDownload(function () use ($svc, $kind) {
            $out = fopen('php://output', 'w');
            $svc->csv($kind, $out);
            fclose($out);
        }, "{$kind}-{$f['from']}-to-{$f['to']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function pack(Request $r)
    {
        $f = $this->filters($r);
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
