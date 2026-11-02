<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Models\Call;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Company-defined file names for downloaded call recordings (Customers → Recording file names), e.g.
 * HDFC_AcmeAgency_PL123_20261031_143205.mp3. A company keeps several named formats (one per bank, say):
 * companies.settings.recordings = {formats: [{id, name, sep, parts: [{type: 'text', value} | {type: <field>, format?}]}], default: id}.
 * Each portfolio (import_jobs.recording_format) picks one; customers added by hand and portfolios without one use the default.
 * Used by every recording download: one call, the bulk ZIP and the audit pack.
 */
final class RecordingName
{
    /** field => [label, formats (first = default) or null] */
    public const FIELDS = [
        'company' => ['Agency (company name)', null],
        'portfolio' => ['Portfolio', null],
        'customer_ref' => ['Customer ID / loan account', null],
        'customer_name' => ['Customer name', null],
        'customer_phone' => ['Customer mobile', ['full' => '10 digits', 'last4' => 'Last 4 digits']],
        'employee_code' => ['Employee code', null],
        'employee_name' => ['Employee name', null],
        'team' => ['Team', null],
        'branch' => ['Branch', null],
        'date' => ['Call date', ['Ymd' => 'YYYYMMDD', 'dmY' => 'DDMMYYYY', 'Y-m-d' => 'YYYY-MM-DD', 'd-m-Y' => 'DD-MM-YYYY', 'd-M-Y' => 'DD-Mon-YYYY']],
        'time' => ['Call time', ['His' => 'HHMMSS', 'Hi' => 'HHMM', 'H-i-s' => 'HH-MM-SS', 'h-iA' => 'hh-mmAM/PM']],
        'duration' => ['Talk time', ['s' => 'Seconds (75)', 'ms' => 'Min-sec (01m15s)']],
        'outcome' => ['Call outcome', ['label' => 'Name (Promise to pay)', 'code' => 'Code (CONNECTED_PTP)']],
        'call_id' => ['Call number', null],
    ];

    public const SEPARATORS = ['_' => 'Underscore _', '-' => 'Dash -', '.' => 'Dot .', ' ' => 'Space', '' => 'None'];

    public const DEFAULT = ['sep' => '_', 'parts' => [['type' => 'customer_ref'], ['type' => 'employee_code'], ['type' => 'date', 'format' => 'Ymd'], ['type' => 'time', 'format' => 'His']]];

    /** Built-in format until the company saves its own. */
    public const STANDARD_ID = 'standard';

    /** @return list<array{id: string, name: string, sep: string, parts: list<array>}> (never empty) */
    public static function formats(): array
    {
        $c = app(CurrentCompany::class)->get();
        $list = (array) ($c?->setting('recordings.formats') ?? []);
        if (!$list && ($old = $c?->setting('recordings.filename'))) {   // the single format saved before 2 Nov 2026
            $list = [['id' => self::STANDARD_ID, 'name' => 'Standard'] + $old];
        }
        return $list ?: [['id' => self::STANDARD_ID, 'name' => 'Standard'] + self::DEFAULT];
    }

    public static function defaultId(): string
    {
        $ids = array_column(self::formats(), 'id');
        $d = app(CurrentCompany::class)->get()?->setting('recordings.default');
        return in_array($d, $ids, true) ? $d : $ids[0];
    }

    public static function find(?string $id): ?array
    {
        foreach (self::formats() as $f) {
            if ($f['id'] === $id) {
                return $f;
            }
        }
        return null;
    }

    /** The default format's template (customers added by hand, portfolios without a format). */
    public static function template(): array
    {
        return self::find(self::defaultId());
    }

    /** The format of the call's portfolio, else the company default. */
    public static function templateFor(Call $call): array
    {
        $cu = $call->relationLoaded('customer') ? $call->customer : $call->customer()->first();
        $id = $cu?->last_import_id ? DB::table('import_jobs')->where('id', $cu->last_import_id)->value('recording_format') : null;
        return ($id ? self::find($id) : null) ?? self::template();
    }

    /** Saves the whole list (the console sends one format at a time; the controller merges). */
    public static function store(array $formats, string $default): void
    {
        $c = app(CurrentCompany::class)->get();
        $s = $c->settings ?? [];
        data_set($s, 'recordings.formats', array_values($formats));
        data_set($s, 'recordings.default', $default);
        unset($s['recordings']['filename']);
        $c->forceFill(['settings' => $s])->save();
    }

    /** Validates and normalises a template from the console. */
    public static function clean(array $t): array
    {
        $sep = array_key_exists($t['sep'] ?? '_', self::SEPARATORS) ? (string) ($t['sep'] ?? '_') : '_';
        $parts = [];
        foreach (array_slice($t['parts'] ?? [], 0, 12) as $p) {
            $type = (string) ($p['type'] ?? '');
            if ($type === 'text') {
                $v = self::safe((string) ($p['value'] ?? ''));
                $v !== '' && $parts[] = ['type' => 'text', 'value' => mb_substr($v, 0, 40)];
            } elseif (isset(self::FIELDS[$type])) {
                $formats = self::FIELDS[$type][1];
                $parts[] = $formats ? ['type' => $type, 'format' => array_key_exists($p['format'] ?? '', $formats) ? $p['format'] : array_key_first($formats)] : ['type' => $type];
            }
        }
        $parts || throw new DomainException('empty_template', 'Add at least one part to the file name.');
        in_array(true, array_map(fn ($p) => in_array($p['type'], ['call_id', 'time', 'customer_ref', 'customer_phone'], true), $parts), true)
            || throw new DomainException('not_unique', 'Add Call number, Call time, Customer ID or Customer mobile, so two recordings do not get the same name.');
        return ['sep' => $sep, 'parts' => $parts];
    }

    /** "HDFC_AcmeAgency_PL123_20261031_143205.mp3" for this call (without the extension when $ext is null). */
    public static function for(Call $call, ?string $ext = null, ?array $template = null): string
    {
        $t = $template ?? self::templateFor($call);
        $sep = $t['sep'];
        $values = array_map(fn ($p) => self::value($call, $p), $t['parts']);
        $values = array_filter(array_map(fn ($v) => self::safe((string) $v, $sep), $values), fn ($v) => $v !== '');
        $name = mb_substr(implode($sep, $values), 0, 180) ?: 'call-' . $call->id;
        return $ext ? "{$name}.{$ext}" : $name;
    }

    private static function value(Call $c, array $p): ?string
    {
        $tz = app(CurrentCompany::class)->get()?->timezone ?: 'Asia/Kolkata';
        $at = Carbon::parse($c->initiated_at)->setTimezone($tz);
        $cu = $c->relationLoaded('customer') ? $c->customer : $c->customer()->first();
        $u = $c->relationLoaded('user') ? $c->user : $c->user()->first();
        return match ($p['type']) {
            'text' => $p['value'],
            'company' => app(CurrentCompany::class)->get()?->name,
            'portfolio' => $cu?->campaign ?: ($cu?->last_import_id ? self::portfolio($cu->last_import_id) : null),
            'customer_ref' => $cu?->customer_ref,
            'customer_name' => $cu?->name,
            'customer_phone' => ($d = substr(preg_replace('/\D/', '', (string) ($cu?->phone ?? $c->destination)), -10)) !== '' ? (($p['format'] ?? '') === 'last4' ? substr($d, -4) : $d) : null,
            'employee_code' => $u?->employee_code,
            'employee_name' => $u?->name,
            'team' => $u ? DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->where('team_user.user_id', $u->id)->value('teams.name') : null,
            'branch' => $u ? DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->join('branches', 'branches.id', '=', 'teams.branch_id')->where('team_user.user_id', $u->id)->value('branches.name') : null,
            'date' => $at->format($p['format'] ?? 'Ymd'),
            'time' => $at->format($p['format'] ?? 'His'),
            'duration' => ($p['format'] ?? 's') === 'ms' ? sprintf('%02dm%02ds', intdiv((int) $c->talk_seconds, 60), (int) $c->talk_seconds % 60) : (string) (int) $c->talk_seconds,
            'outcome' => ($code = $c->relationLoaded('disposition') ? $c->disposition?->code : DB::table('call_dispositions')->where('call_id', $c->id)->value('code'))
                ? (($p['format'] ?? 'label') === 'code' ? $code : (DB::table('disposition_codes')->where('company_id', $c->company_id)->where('code', $code)->value('label') ?? $code)) : null,
            'call_id' => (string) $c->id,
            default => null,
        };
    }

    private static function portfolio(int $importId): ?string
    {
        static $names = [];
        return $names[$importId] ??= DB::table('import_jobs')->where('id', $importId)->value('portfolio') ?: pathinfo((string) DB::table('import_jobs')->where('id', $importId)->value('original_name'), PATHINFO_FILENAME);
    }

    /** Letters, digits, dot, dash, underscore; spaces become the separator (or stay when the separator is a space / none). */
    private static function safe(string $v, string $sep = '_'): string
    {
        $v = trim((string) preg_replace('/[^\pL\pN ._-]+/u', '-', $v), " .-_");
        $v = (string) preg_replace('/\s+/u', in_array($sep, ['_', '-', '.'], true) ? $sep : ' ', $v);
        return $v;
    }
}
