<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\ImportJob;
use App\Services\RecordingName;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Customers → Recording file names: named formats (one per bank, say), the default, and which portfolios use each. */
final class RecordingFormatController extends Controller
{
    /** ponytail: formats live in companies.settings; a few dozen is plenty. */
    private const MAX = 50;

    public function index()
    {
        $sample = Call::query()->where('is_test', false)->whereHas('recording', fn ($q) => $q->where('status', 'available'))->latest('initiated_at')->first();
        $used = ImportJob::query()->whereNotNull('recording_format')->get(['id', 'portfolio', 'original_name', 'recording_format'])->groupBy('recording_format');
        $default = RecordingName::defaultId();
        return response()->json([
            'formats' => collect(RecordingName::formats())->map(fn ($f) => $f + [
                'is_default' => $f['id'] === $default,
                'portfolios' => ($used[$f['id']] ?? collect())->map(fn ($j) => $j->portfolio ?: pathinfo((string) $j->original_name, PATHINFO_FILENAME))->values(),
                'example' => $sample ? RecordingName::for($sample, $sample->recording?->format ?? 'mp3', $f) : null,
            ])->values(),
            'default_id' => $default,
            'fields' => collect(RecordingName::FIELDS)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'formats' => $f[1]])->values(),
            'separators' => RecordingName::SEPARATORS, 'blank' => RecordingName::DEFAULT, 'sample_call' => $sample?->id,
        ]);
    }

    /** Preview a format (saved or not) on one real recording. */
    public function preview(Request $r)
    {
        $t = RecordingName::clean((array) $r->input('template', []));
        $call = $r->filled('call') ? Call::query()->find((int) $r->input('call')) : null;
        return response()->json(['name' => $call ? RecordingName::for($call, $call->recording?->format ?? 'mp3', $t) : null]);
    }

    public function store(Request $r)
    {
        $list = RecordingName::formats();
        count($list) < self::MAX || throw new DomainException('too_many', 'At most ' . self::MAX . ' formats.');
        $f = ['id' => strtolower(Str::random(8)), 'name' => $this->name($r, $list)] + RecordingName::clean((array) $r->input('template', []));
        $list[] = $f;
        RecordingName::store($list, $r->boolean('make_default') ? $f['id'] : RecordingName::defaultId());
        Audit::record('recordings.format_created', null, ['id' => $f['id'], 'name' => $f['name']]);
        return response()->json($f, 201);
    }

    public function update(Request $r, string $id)
    {
        $list = RecordingName::formats();
        $i = array_search($id, array_column($list, 'id'), true);
        $i !== false || abort(404);
        $list[$i] = ['id' => $id, 'name' => $this->name($r, $list, $id)] + RecordingName::clean((array) $r->input('template', []));
        RecordingName::store($list, RecordingName::defaultId());
        Audit::record('recordings.format_updated', null, ['id' => $id, 'name' => $list[$i]['name']]);
        return response()->json($list[$i]);
    }

    public function makeDefault(string $id)
    {
        RecordingName::find($id) ?? abort(404);
        RecordingName::store(RecordingName::formats(), $id);
        Audit::record('recordings.format_default', null, ['id' => $id]);
        return response()->json(['ok' => true]);
    }

    /** Portfolios that used it fall back to the default. The last format can't go. */
    public function destroy(string $id)
    {
        $list = RecordingName::formats();
        RecordingName::find($id) ?? abort(404);
        count($list) > 1 || throw new DomainException('last_format', 'Keep at least one format.');
        $list = array_values(array_filter($list, fn ($f) => $f['id'] !== $id));
        $default = RecordingName::defaultId() === $id ? $list[0]['id'] : RecordingName::defaultId();
        RecordingName::store($list, $default);
        ImportJob::query()->where('recording_format', $id)->update(['recording_format' => null]);
        Audit::record('recordings.format_deleted', null, ['id' => $id]);
        return response()->json(['ok' => true, 'default_id' => $default]);
    }

    private function name(Request $r, array $list, ?string $self = null): string
    {
        $n = trim((string) $r->input('name'));
        ($n !== '' && mb_strlen($n) <= 60) || throw new DomainException('name_required', 'Give the format a name (up to 60 characters), e.g. HDFC Bank.');
        foreach ($list as $f) {
            if ($f['id'] !== $self && mb_strtolower($f['name']) === mb_strtolower($n)) {
                throw new DomainException('name_taken', "A format called \"{$n}\" already exists.");
            }
        }
        return $n;
    }
}
