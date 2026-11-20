<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Customers\ImportValidator;
use App\Domain\DomainException;
use App\Domain\Portfolios\PortfolioTypes;
use App\Jobs\ProcessImport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Domain\Customers\ImportHeaders;
use App\Models\ImportJob;
use App\Models\ImportMapping;
use App\Models\ImportRowError;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Upload → (auto) mapping suggestion → preview (first rows, validated) → background validate → commit.
 * Nothing touches customers until commit. Customer numbers are never written to the phone's contacts.
 */
final class ImportService
{
    public const PREVIEW_ROWS = 20;

    public function __construct(private readonly CurrentCompany $current)
    {
    }

    /** Group in a lead file: its code or its name, any letter case → team id. */
    private static function teamLookup(): array
    {
        $map = [];
        foreach (Team::query()->get(['id', 'code', 'name']) as $t) {
            $map[mb_strtolower(trim((string) $t->name))] = $t->id;
            if ($t->code) {
                $map[mb_strtolower(trim((string) $t->code))] = $t->id;
            }
        }
        return $map;
    }

    public function upload(UploadedFile $file, User $actor, ?string $portfolio = null, ?string $type = null): ImportJob
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        $ok = ($ext === 'csv' && in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true))
            || ($ext === 'xlsx' && in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true));
        if (!$ok) {
            throw new DomainException('invalid_upload', 'Upload a .csv or .xlsx file.');
        }
        if ($file->getSize() > config('ammp.uploads.import_max_mb') * 1024 * 1024) {
            throw new DomainException('upload_too_large', 'The file is larger than ' . config('ammp.uploads.import_max_mb') . ' MB. Split it into smaller files.');
        }
        if ($file->getSize() === 0) {
            throw new DomainException('empty_file', 'The file is empty.');
        }
        $cid = $this->current->id();
        $key = $file->storeAs("companies/{$cid}/imports", bin2hex(random_bytes(16)) . '.' . $ext, 'company_files');
        $job = ImportJob::query()->create([
            'uploaded_by' => $actor->id, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'stored_key' => $key,
            'portfolio' => mb_substr(trim((string) $portfolio) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 150),
            'format' => $ext, 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'status' => 'uploaded',
            'portfolio_type' => PortfolioTypes::exists($type) ? $type : PortfolioTypes::DEFAULT,
        ]);
        try {
            $top = $this->readRows($job, ImportHeaders::SCAN_ROWS);
        } catch (\Throwable $e) {
            $top = null;
        }
        if (!$top || implode('', array_merge(...$top)) === '') {
            // Never leave a half-made upload behind (it would show as "not imported — click to finish" and could not be opened).
            \Illuminate\Support\Facades\Storage::disk('company_files')->delete($key);
            $job->delete();
            throw new DomainException('empty_file', $top === null ? 'We could not read this file. Save it again as .csv or .xlsx and retry.' : 'The file has no header row. The first row must hold the column names.');
        }
        // A saved mapping of this bank / file format (same column names) wins; else find the header row and guess.
        if (!$this->applySaved($job, $top)) {
            $this->useHeaderRow($job, $top, ImportHeaders::detect($top));
        }
        $job->save();
        Audit::record('import.uploaded', $job, ['name' => $job->original_name, 'sha256' => $job->sha256, 'mapping' => $job->import_mapping_id]);
        return $job;
    }

    /** Column names from row $row of the file, a guessed mapping, every other column kept. */
    private function useHeaderRow(ImportJob $job, array $top, int $row): void
    {
        $headers = ImportHeaders::unique($top[$row] ?? []);
        $job->forceFill(['header_row' => $row, 'headers' => $headers, 'import_mapping_id' => null,
            'mapping' => ImportValidator::suggestMapping($headers, $job->portfolio_type),
            'options' => ['keep_other_columns' => true, 'ignore_columns' => []]]);
    }

    /** The best saved mapping of this company for this type whose every column is in the file. */
    private function applySaved(ImportJob $job, array $top, ?ImportMapping $only = null): bool
    {
        $best = null;
        $list = $only ? [$only] : ImportMapping::query()->where('portfolio_type', $job->portfolio_type)->latest('last_used_at')->get();
        $detected = ImportHeaders::detect($top);
        foreach ($list as $m) {
            // The saved header row, or this file's own (the bank may add or drop a title line next month).
            foreach (array_unique([$m->header_row, $detected]) as $row) {
                $headers = ImportHeaders::unique($top[$row] ?? []);
                $fit = ImportHeaders::fit((array) $m->fields, (array) $m->ignored, (array) $m->headers, $headers);
                // ponytail: 60% of column names alike = same format; a stricter or looser bar is one number.
                if ($fit && ($only || $fit['score'] >= 0.6) && (!$best || $fit['score'] > $best[1]['score'])) {
                    $best = [$m, $fit, $headers, $row];
                }
            }
        }
        if (!$best) {
            return false;
        }
        [$m, $fit, $headers, $row] = $best;
        $job->forceFill(['header_row' => $row, 'headers' => $headers, 'import_mapping_id' => $m->id,
            'mapping' => array_replace(array_fill_keys(ImportValidator::fieldsFor($job->portfolio_type), null), array_intersect_key($fit['mapping'], array_flip(ImportValidator::fieldsFor($job->portfolio_type)))),
            'options' => ['keep_other_columns' => $m->keep_other_columns, 'ignore_columns' => $fit['ignore']]]);
        $m->forceFill(['last_used_at' => now()])->save();
        return true;
    }

    /** "Column names are on row N" (Portfolios page): re-reads the names and guesses the mapping again. */
    public function setHeaderRow(ImportJob $job, int $row): ImportJob
    {
        $this->editable($job);
        $top = $this->readRows($job, ImportHeaders::SCAN_ROWS);
        if (!isset($top[$row]) || implode('', $top[$row]) === '') {
            throw new DomainException('invalid_header_row', 'That row is empty. Choose the row that holds the column names.');
        }
        $this->useHeaderRow($job, $top, $row);
        $job->forceFill(['status' => 'uploaded'])->save();
        return $job;
    }

    /** Use a saved mapping on this upload (chosen by hand). */
    public function applyMapping(ImportJob $job, ImportMapping $m): ImportJob
    {
        $this->editable($job);
        if ($m->portfolio_type !== $job->portfolio_type) {
            throw new DomainException('mapping_other_type', 'This saved mapping is for another type of portfolio.');
        }
        if (!$this->applySaved($job, $this->readRows($job, ImportHeaders::SCAN_ROWS), $m)) {
            throw new DomainException('mapping_not_fit', "This file does not have every column of “{$m->name}”. Match the columns by hand, then save a new mapping.");
        }
        $job->forceFill(['status' => 'uploaded'])->save();
        return $job;
    }

    /** Save the upload's current mapping under a name (same name = replaced), by column name. */
    public function saveAs(ImportJob $job, string $name, User $actor): ImportMapping
    {
        $h = (array) $job->headers;
        $m = ImportMapping::query()->firstOrNew(['name' => mb_substr(trim($name), 0, 100)]);
        $m->fill(['portfolio_type' => $job->portfolio_type, 'header_row' => $job->header_row, 'headers' => array_map([ImportHeaders::class, 'norm'], $h),
            'fields' => array_map(fn ($i) => $i === null ? null : ($h[$i] ?? null), (array) $job->mapping),
            'ignored' => array_values(array_filter(array_map(fn ($i) => $h[$i] ?? null, (array) ($job->options['ignore_columns'] ?? [])))),
            'keep_other_columns' => (bool) ($job->options['keep_other_columns'] ?? true), 'created_by' => $m->created_by ?? $actor->id, 'last_used_at' => now()])->save();
        $job->forceFill(['import_mapping_id' => $m->id])->save();
        Audit::record('import.mapping_saved', $m, ['name' => $m->name]);
        return $m;
    }

    private function editable(ImportJob $job): void
    {
        if (!in_array($job->status, ['uploaded', 'ready'], true)) {
            throw new DomainException('import_locked');
        }
    }

    public function preview(ImportJob $job, array $mapping, array $options, ?User $actor = null): array
    {
        $this->saveMapping($job, $mapping, $options, $actor);
        $rows = $this->dataRows($job, self::PREVIEW_ROWS);
        $callers = User::query()->whereIn('id', $job->options['user_ids'] ?? [])->orderBy('name')->get(['id', 'name', 'employee_code']);
        return ['mapping' => $job->mapping, 'result' => $this->validateRows($job, $this->mapRows($rows, $job->mapping, $job)), 'callers' => $callers,
            'sample' => array_map(fn ($r) => array_map(fn ($i) => $r[$i] ?? '', array_keys((array) $job->headers)), array_slice($rows, 0, 5))];
    }

    /**
     * Lead allocation (SmartDCM): `column` = Caller ID-wise (each row names its caller in the Assigned Employee column);
     * `random` = rows are shared at random, evenly, between the selected callers of one group (all its telecallers if none selected).
     * A manager (supervisor) may only allocate within the groups they manage, and cannot take over customers of other groups.
     */
    public function saveMapping(ImportJob $job, array $mapping, array $options, ?User $actor = null): void
    {
        if (!in_array($job->status, ['uploaded', 'ready'], true)) {
            throw new DomainException('import_locked');
        }
        $clean = [];
        foreach (ImportValidator::fieldsFor($job->portfolio_type) as $f) {
            $i = $mapping[$f] ?? null;
            $clean[$f] = ($i === null || $i === '') ? null : (int) $i;
            if ($clean[$f] !== null && ($clean[$f] < 0 || $clean[$f] >= count($job->headers ?? []))) {
                throw new DomainException('invalid_mapping');
            }
        }
        foreach (['customer_ref' => 'Customer ID', 'name' => 'Customer name', 'phone' => 'Phone number'] as $req => $label) {
            if ($clean[$req] === null) {
                throw new DomainException('mapping_incomplete', "Choose which column of your file is the {$label} (required).");
            }
        }
        $scope = $actor?->supervisedTeamIds();
        // `file` (default): Caller ID column optional — rows without one stay unassigned (or in the manager's group) for Customers → Allocation.
        $allocation = in_array($options['allocation'] ?? 'file', ['random', 'column'], true) ? $options['allocation'] : 'file';
        $teamId = null;
        $userIds = [];
        if ($allocation === 'random') {
            $teamId = (int) ($options['team_id'] ?? 0);
            if (!Team::query()->whereKey($teamId)->exists()) {
                throw new DomainException('group_required', 'Choose the group for random allocation.');
            }
            if ($scope !== null && !in_array($teamId, $scope, true)) {
                throw new DomainException('forbidden', 'You can allocate only within your own group.');
            }
            $members = User::query()->where('status', 'active')
                ->whereIn('id', DB::table('team_user')->where('team_id', $teamId)->where('membership', 'member')->pluck('user_id'))->pluck('id')->all();
            $picked = array_values(array_unique(array_map('intval', $options['user_ids'] ?? [])));
            if ($picked && array_diff($picked, $members)) {
                throw new DomainException('caller_not_in_group', 'Some selected callers are not telecallers of this group.');
            }
            $userIds = $picked ?: $members;
            if (!$userIds) {
                throw new DomainException('no_callers', 'This group has no active telecallers.');
            }
        } elseif ($allocation === 'column' && $clean['assigned_employee'] === null) {
            throw new DomainException('mapping_incomplete', 'Caller ID-wise allocation: map the Caller ID column (Assigned employee), or choose Random allocation.');
        }
        $ignore = array_values(array_unique(array_filter(array_map('intval', (array) ($options['ignore_columns'] ?? $job->options['ignore_columns'] ?? [])),
            fn ($i) => $i >= 0 && $i < count($job->headers ?? []))));
        $job->forceFill(['mapping' => $clean, 'options' => ['on_existing' => ($options['on_existing'] ?? 'update') === 'skip' ? 'skip' : 'update',
            'allocation' => $allocation, 'team_id' => $teamId, 'user_ids' => $userIds, 'scope' => $scope,
            'keep_other_columns' => (bool) ($options['keep_other_columns'] ?? true), 'ignore_columns' => $ignore]])->save();
    }

    /** Background step 1: validate every row and store row errors. */
    public function validateAll(ImportJob $job): void
    {
        $job->forceFill(['status' => 'validating', 'started_at' => now()])->save();
        ImportRowError::query()->where('import_job_id', $job->id)->delete();
        $rows = $this->dataRows($job);
        $r = $this->validateRows($job, $this->mapRows($rows, $job->mapping, $job));
        foreach (array_chunk($r['errors'], 500) as $chunk) {
            ImportRowError::query()->insert(array_map(fn ($e) => [
                'company_id' => $job->company_id, 'import_job_id' => $job->id, 'row_number' => $e['row'], 'field' => $e['field'],
                'code' => $e['code'], 'message' => mb_substr($e['message'], 0, 255),
            ], $chunk));
        }
        $job->forceFill(['status' => 'ready', 'total_rows' => count($rows), 'valid_rows' => count($r['valid']),
            'error_rows' => count(array_unique(array_column($r['errors'], 'row')))])->save();
    }

    /** Background step 2: write valid rows. Re-validates (data may have changed since preview). */
    public function commit(ImportJob $job, User $actor): void
    {
        $job->forceFill(['status' => 'committing'])->save();
        $rows = $this->dataRows($job);
        $r = $this->validateRows($job, $this->mapRows($rows, $job->mapping, $job));
        $users = User::query()->pluck('id', 'employee_code');
        $teams = self::teamLookup();
        $created = $updated = $skipped = 0;
        $random = ($job->options['allocation'] ?? 'column') === 'random';
        if ($random) {
            // ponytail: shuffle + round-robin = random and even (counts differ by at most 1).
            $callers = $job->options['user_ids'];
            shuffle($callers);
            $valid = $r['valid'];
            shuffle($valid);
            foreach ($valid as $i => &$v) {
                $v['user_id'] = $callers[$i % count($callers)];
            }
            unset($v);
            $r['valid'] = $valid;
        }
        // Address / pin changes from the file are logged as "upload" (CustomerAddressChange).
        foreach (array_chunk($r['valid'], 500) as $chunk) Customer::changedBy('upload', $actor->id, function () use ($chunk, $job, $actor, $users, $teams, $random, &$created, &$updated, &$skipped) {
            DB::transaction(function () use ($chunk, $job, $actor, $users, $teams, $random, &$created, &$updated, &$skipped) {
                foreach ($chunk as $row) {
                    $userId = $random ? $row['user_id'] : ($row['assigned_employee'] ? $users[$row['assigned_employee']] ?? null : null);
                    $teamId = $random ? $job->options['team_id'] : ($row['assigned_team'] ? $teams[mb_strtolower($row['assigned_team'])] ?? null : null);
                    if ($teamId === null && !empty($job->options['scope'])) {
                        // A manager's upload stays inside the manager's group (so they can see and allocate it).
                        $teamId = $userId ? (int) (DB::table('team_user')->where('user_id', $userId)->whereIn('team_id', $job->options['scope'])->value('team_id') ?? $job->options['scope'][0]) : $job->options['scope'][0];
                    }
                    $c = Customer::query()->where('customer_ref', $row['customer_ref'])->lockForUpdate()->first();
                    if ($c && ($job->options['on_existing'] ?? 'update') === 'skip') {
                        $skipped++;
                        continue;
                    }
                    $attrs = ['name' => $row['name'], 'phone' => $row['phone'], 'alt_phone' => $row['alt_phone'], 'campaign' => $row['campaign'],
                        'status' => $row['status'], 'notes' => $row['notes'], 'follow_up_on' => $row['follow_up_date'], 'last_import_id' => $job->id,
                        'city' => $row['city'], 'area' => $row['area'], 'pincode' => $row['pincode'], 'portfolio_type' => $job->portfolio_type] + self::accountAttrs($row['account'] ?? []);
                    // The type's own fields: values in this file replace the old ones; fields not in the file keep theirs.
                    $attrs['details'] = array_filter(array_merge($c && $c->portfolio_type === $job->portfolio_type ? (array) $c->details : [], $row['details'] ?? []),
                        fn ($v) => $v !== null && $v !== '') ?: null;
                    $changedAssignment = !$c || $c->assigned_user_id !== $userId || $c->assigned_team_id !== $teamId || $c->withdrawn_at !== null;
                    // G5: pins come only from the address (looked up after the upload), never from the file. A pin
                    // verified at the door stays while the address is the same; a changed address gets a fresh lookup.
                    if ($c) {
                        $c->fill($attrs + ['assigned_user_id' => $userId, 'assigned_team_id' => $teamId, 'withdrawn_at' => null]);
                        if ($c->isDirty(\App\Models\CustomerAddressChange::ADDRESS_FIELDS)) {
                            $c->fill(['lat' => null, 'lng' => null, 'geo_status' => null, 'geo_precision' => null]);   // old pin belongs to the old address
                        }
                        $c->save();
                        $updated++;
                    } else {
                        $c = Customer::query()->create($attrs + ['customer_ref' => $row['customer_ref'], 'assigned_user_id' => $userId, 'assigned_team_id' => $teamId]);
                        $created++;
                    }
                    if ($changedAssignment) {
                        CustomerAssignment::query()->create(['customer_id' => $c->id, 'user_id' => $userId, 'team_id' => $teamId,
                            'action' => 'assigned', 'actor_user_id' => $actor->id, 'import_job_id' => $job->id]);
                    }
                }
            });
        }, null, $job->id);
        $notify = $random ? $job->options['user_ids'] : User::query()->whereIn('employee_code', array_filter(array_column($r['valid'], 'assigned_employee')))->pluck('id')->all();
        \App\Models\ManagedDevice::query()->whereIn('assigned_user_id', $notify)->where('approval_state', 'approved')
            ->each(fn ($d) => app(PushService::class)->ping($d, 'customers'));   // phones fetch their new leads now
        $job->forceFill(['status' => 'completed', 'created_count' => $created, 'updated_count' => $updated, 'skipped_count' => $skipped, 'finished_at' => now()])->save();
        Audit::record('import.committed', $job, compact('created', 'updated', 'skipped'));
        if (app(\App\Services\GoogleMaps::class)->configured()) {
            \App\Jobs\GeocodePending::dispatch($job->company_id);   // G5: pins for the new addresses, in the background
        }
    }

    /**
     * Loan / dues columns present in this file (columns not in the file keep their old values). DPD without an oldest
     * due date gives the oldest due date, so days overdue keep counting after the upload.
     */
    public static function accountAttrs(array $a): array
    {
        if (isset($a['dpd']) && !isset($a['oldest_due_date'])) {
            $a['oldest_due_date'] = now(\App\Support\CurrentCompany::tz())->subDays($a['dpd'])->toDateString();
        }
        unset($a['dpd']);
        if (array_intersect_key($a, array_flip(ImportValidator::MONEY)) !== []) {
            $a['balance_as_of'] = now(\App\Support\CurrentCompany::tz())->toDateString();
        }
        return $a;
    }

    public function start(ImportJob $job, string $step, User $actor): void
    {
        ProcessImport::dispatch($job->company_id, $job->id, $step, $actor->id);
    }

    private function validateRows(ImportJob $job, array $mapped): array
    {
        $country = $this->current->get()->default_country;
        $employees = User::query()->where('status', 'active')->pluck('employee_code')->flip();
        $teams = self::teamLookup();
        $r = ImportValidator::validate(
            $mapped, $country,
            fn (string $phone) => Customer::query()->where('phone', $phone)->value('customer_ref'),
            fn (string $code) => isset($employees[$code]),
            fn (string $code) => isset($teams[mb_strtolower($code)]),
            2, $job->portfolio_type,
        );
        $opt = $job->options ?? [];
        $column = ($opt['allocation'] ?? 'file') === 'column';
        $scope = $opt['scope'] ?? null;
        $inScopeCodes = $scope === null ? null : User::query()->whereIn('id', DB::table('team_user')->whereIn('team_id', $scope)->pluck('user_id'))->pluck('employee_code')->flip();
        $scopeMembers = $scope === null ? null : DB::table('team_user')->whereIn('team_id', $scope)->pluck('user_id')->all();
        $otherGroup = $scope === null ? [] : Customer::query()->whereIn('customer_ref', array_column($r['valid'], 'customer_ref'))
            ->where(fn ($w) => $w->whereNotNull('assigned_user_id')->orWhereNotNull('assigned_team_id'))
            ->where(fn ($w) => $w->whereNotIn('assigned_team_id', $scope)->orWhereNull('assigned_team_id'))
            ->where(fn ($w) => $w->whereNotIn('assigned_user_id', $scopeMembers)->orWhereNull('assigned_user_id'))
            ->pluck('customer_ref')->flip();
        $valid = [];
        foreach ($r['valid'] as $row) {
            $e = null;
            if ($column && $row['assigned_employee'] === null) {
                $e = ['assigned_employee', 'caller_required', 'Caller ID is empty (Caller ID-wise allocation).'];
            } elseif ($row['assigned_employee'] !== null && $inScopeCodes !== null && !isset($inScopeCodes[$row['assigned_employee']])) {
                $e = ['assigned_employee', 'caller_outside_group', "Caller {$row['assigned_employee']} is not in your group."];
            } elseif (isset($otherGroup[$row['customer_ref']])) {
                $e = ['customer_ref', 'customer_other_group', 'This customer belongs to another group.'];
            }
            $e ? $r['errors'][] = ['row' => $row['row'], 'field' => $e[0], 'code' => $e[1], 'message' => $e[2]] : $valid[] = $row;
        }
        $r['valid'] = $valid;
        return $r;
    }

    /** Mapped fields, plus `__extra` = the file's other columns (header => value), kept under their own names. */
    private function mapRows(array $rows, array $mapping, ?ImportJob $job = null): array
    {
        $used = array_flip(array_filter($mapping, fn ($i) => $i !== null)) + array_flip((array) ($job?->options['ignore_columns'] ?? []));   // ignored = never kept
        $extra = [];
        if ($job && ($job->options['keep_other_columns'] ?? true)) {
            foreach ((array) $job->headers as $i => $h) {
                // ponytail: a Latitude / Longitude column is not kept either — pins always come from the address.
                if (!isset($used[$i]) && trim((string) $h) !== '' && !preg_match('/^(lat|latitude|lng|lon|long|longitude)$/i', trim((string) $h))) {
                    $extra[$i] = trim((string) $h);
                }
            }
        }
        return array_map(function (array $row) use ($mapping, $extra) {
            $out = isset($row['__row']) ? ['__row' => $row['__row']] : [];
            foreach ($mapping as $field => $idx) {
                $out[$field] = $idx === null ? null : (isset($row[$idx]) ? trim((string) $row[$idx]) : null);
            }
            foreach ($extra as $i => $h) {
                $out['__extra'][$h] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }
            return $out;
        }, $rows);
    }

    /**
     * The rows under the header row, without fully blank lines, each with its row number in the file (`__row`, as Excel shows it).
     * @return list<array>
     */
    private function dataRows(ImportJob $job, ?int $limit = null): array
    {
        $start = (int) $job->header_row + 1;
        $out = [];
        foreach (array_slice($this->readRows($job, $limit === null ? null : $start + $limit * 3), $start) as $i => $r) {   // *3: room for blank lines
            if (implode('', array_map(fn ($v) => trim((string) $v), $r)) === '') {
                continue;
            }
            $out[] = $r + ['__row' => $start + $i + 1];
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @return list<list<string>> */
    private function readRows(ImportJob $job, ?int $limit = null): array
    {
        $path = Storage::disk('company_files')->path($job->stored_key);
        $reader = $job->format === 'xlsx' ? new XlsxReader() : new CsvReader();
        $reader->open($path);
        $out = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $out[] = array_map(static fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $row->toArray());
                    if ($limit !== null && count($out) >= $limit) {
                        break 2;
                    }
                    if (count($out) > 200_000) {
                        throw new DomainException('import_too_many_rows', 'Split files larger than 200,000 rows.');
                    }
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
        return $out;
    }
}
