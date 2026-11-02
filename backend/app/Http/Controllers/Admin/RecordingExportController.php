<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\RecordingName;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Reports → Recording export: download many call recordings in one ZIP (date range, portfolio, employee, outcome, minimum talk
 * time + the top-bar branch / team / employee), named by the portfolio's file-name format or one picked on the page, with an index.csv.
 */
final class RecordingExportController extends Controller
{
    /** ponytail: one ZIP is built on the server; past this, choose a shorter period. */
    public const MAX_FILES = 3000;

    /** How many recordings match, their size, and the first few names. */
    public function count(Request $r)
    {
        $f = $this->filters($r);
        $q = $this->query($r->user(), $f);
        $n = (clone $q)->count();
        $bytes = (int) \App\Models\Recording::query()->whereIn('call_id', (clone $q)->select('calls.id'))->sum('size_bytes');
        $first = (clone $q)->with(['customer', 'user', 'disposition', 'recording'])->orderBy('initiated_at')->limit(5)->get();
        $t = $this->chosen($f);
        $pf = ctype_digit((string) ($f['portfolio'] ?? '')) ? \App\Models\ImportJob::query()->whereKey((int) $f['portfolio'])->value('recording_format') : null;
        return response()->json(['count' => $n, 'bytes' => $bytes, 'max' => self::MAX_FILES,
            'names' => $first->map(fn (Call $c) => RecordingName::for($c, $c->recording->format, $t))->values(),
            'formats' => collect(RecordingName::formats())->map(fn ($x) => ['id' => $x['id'], 'name' => $x['name']])->values(), 'default_id' => RecordingName::defaultId(),
            'portfolio_format' => $pf && RecordingName::find($pf) ? $pf : (($f['portfolio'] ?? '') !== '' ? RecordingName::defaultId() : null)]);
    }

    public function zip(Request $r)
    {
        $f = $this->filters($r);
        abort_unless(class_exists(\ZipArchive::class), 503, 'ZIP files cannot be made on this server: the PHP "zip" extension is switched off. Ask your administrator to enable it (php.ini: extension=zip).');
        @set_time_limit(900);
        $disk = Storage::disk(config('ammp.recordings.disk'));
        $tz = app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata';
        $path = tempnam(sys_get_temp_dir(), 'rx-rec') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true || throw new \RuntimeException('Could not create the ZIP file.');
        $index = fopen('php://temp', 'w+');
        fputcsv($index, ['File', 'Call number', 'Date and time', 'Employee code', 'Employee', 'Customer ID', 'Customer', 'Mobile', 'Talk seconds', 'Outcome', 'SHA-256']);
        $template = $this->chosen($f);   // null = each recording's portfolio format
        $used = [];
        $files = $skipped = 0;
        foreach ($this->query($r->user(), $f)->with(['customer', 'user', 'disposition', 'recording'])->orderBy('initiated_at')->lazy(500) as $c) {
            if ($files >= self::MAX_FILES) {
                $skipped++;
                continue;
            }
            $data = rescue(fn () => $disk->get($c->recording->object_key), null, false);
            if ($data === null) {
                $skipped++;
                continue;
            }
            $base = RecordingName::for($c, null, $template);
            $name = $base;
            for ($i = 2; isset($used[strtolower($name)]); $i++) {
                $name = "{$base}_{$i}";
            }
            $used[strtolower($name)] = true;
            $file = "{$name}.{$c->recording->format}";
            $zip->addFromString($file, $data);
            $zip->setCompressionName($file, \ZipArchive::CM_STORE);   // audio is already compressed
            fputcsv($index, \App\Domain\Security\SpreadsheetSanitiser::row([$file, $c->id, Carbon::parse($c->initiated_at)->setTimezone($tz)->format('Y-m-d H:i:s'),
                $c->user?->employee_code, $c->user?->name, $c->customer?->customer_ref, $c->customer?->name, $c->destination, $c->talk_seconds, $c->disposition?->code, hash('sha256', $data)]));
            $files++;
        }
        rewind($index);
        $zip->addFromString('index.csv', (string) stream_get_contents($index));
        $zip->close();
        Audit::record('recordings.bulk_exported', null, $f + ['files' => $files, 'skipped' => $skipped]);
        return response()->download($path, "recordings-{$f['from']}-to-{$f['to']}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function filters(Request $r): array
    {
        return $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'portfolio' => 'nullable|string|max:20',
            'employee' => 'nullable|integer', 'outcome' => 'nullable|string|max:32', 'min_talk' => 'nullable|integer|min:0|max:36000', 'format' => 'nullable|string|max:16']);
    }

    /** The format picked on the page; blank = each recording's own portfolio format. */
    private function chosen(array $f): ?array
    {
        return empty($f['format']) ? null : (RecordingName::find($f['format']) ?? abort(422, 'That recording file-name format no longer exists. Choose another.'));
    }

    /** Calls with a playable recording, inside the signed-in person's teams and the filters. Test calls are never exported. */
    private function query($user, array $f)
    {
        $tz = app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata';
        $users = $user->usersInView();
        $pf = $f['portfolio'] ?? null;
        return Call::query()->where('is_test', false)
            ->whereBetween('initiated_at', [Carbon::parse($f['from'], $tz)->startOfDay()->utc(), Carbon::parse($f['to'], $tz)->endOfDay()->utc()])
            ->whereHas('recording', fn ($q) => $q->where('status', 'available')->whereNotNull('object_key'))
            ->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))
            ->when(!empty($f['employee']), fn ($q) => $q->where('user_id', (int) $f['employee']))
            ->when(!empty($f['outcome']), fn ($q) => $q->whereHas('disposition', fn ($d) => $d->where('code', $f['outcome'])))
            ->when(!empty($f['min_talk']), fn ($q) => $q->where('talk_seconds', '>=', (int) $f['min_talk']))
            ->when(!empty($pf), fn ($q) => $q->whereIn('customer_id', \App\Models\Customer::query()->select('id')
                ->when($pf === 'manual', fn ($w) => $w->whereNull('last_import_id'))->when(ctype_digit((string) $pf), fn ($w) => $w->where('last_import_id', (int) $pf))));
    }
}
