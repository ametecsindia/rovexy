<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Customers\ImportValidator;
use App\Domain\DomainException;
use App\Jobs\ProcessImport;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\ImportJob;
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

    public function upload(UploadedFile $file, User $actor, ?string $portfolio = null): ImportJob
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
        ]);
        try {
            $headers = array_map(fn ($h) => trim((string) $h), $this->readRows($job, 1)[0] ?? []);
        } catch (\Throwable $e) {
            $headers = null;
        }
        if (!$headers || implode('', $headers) === '') {
            // Never leave a half-made upload behind (it would show as "not imported — click to finish" and could not be opened).
            \Illuminate\Support\Facades\Storage::disk('company_files')->delete($key);
            $job->delete();
            throw new DomainException('empty_file', $headers === null ? 'We could not read this file. Save it again as .csv or .xlsx and retry.' : 'The file has no header row. The first row must hold the column names.');
        }
        $job->forceFill(['headers' => $headers, 'mapping' => ImportValidator::suggestMapping($headers)])->save();
        Audit::record('import.uploaded', $job, ['name' => $job->original_name, 'sha256' => $job->sha256]);
        return $job;
    }

    public function preview(ImportJob $job, array $mapping, array $options, ?User $actor = null): array
    {
        $this->saveMapping($job, $mapping, $options, $actor);
        $rows = array_slice($this->readRows($job, self::PREVIEW_ROWS + 1), 1);
        $callers = User::query()->whereIn('id', $job->options['user_ids'] ?? [])->orderBy('name')->get(['id', 'name', 'employee_code']);
        return ['mapping' => $job->mapping, 'result' => $this->validateRows($job, $this->mapRows($rows, $job->mapping)), 'callers' => $callers];
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
        foreach (ImportValidator::FIELDS as $f) {
            $i = $mapping[$f] ?? null;
            $clean[$f] = ($i === null || $i === '') ? null : (int) $i;
            if ($clean[$f] !== null && ($clean[$f] < 0 || $clean[$f] >= count($job->headers ?? []))) {
                throw new DomainException('invalid_mapping');
            }
        }
        foreach (['customer_ref', 'name', 'phone'] as $req) {
            if ($clean[$req] === null) {
                throw new DomainException('mapping_incomplete', "Map a column to {$req}.");
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
        $job->forceFill(['mapping' => $clean, 'options' => ['on_existing' => ($options['on_existing'] ?? 'update') === 'skip' ? 'skip' : 'update',
            'allocation' => $allocation, 'team_id' => $teamId, 'user_ids' => $userIds, 'scope' => $scope]])->save();
    }

    /** Background step 1: validate every row and store row errors. */
    public function validateAll(ImportJob $job): void
    {
        $job->forceFill(['status' => 'validating', 'started_at' => now()])->save();
        ImportRowError::query()->where('import_job_id', $job->id)->delete();
        $rows = array_slice($this->readRows($job), 1);
        $r = $this->validateRows($job, $this->mapRows($rows, $job->mapping));
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
        $rows = array_slice($this->readRows($job), 1);
        $r = $this->validateRows($job, $this->mapRows($rows, $job->mapping));
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
                        'city' => $row['city'], 'area' => $row['area'], 'pincode' => $row['pincode']] + self::accountAttrs($row['account'] ?? []);
                    $changedAssignment = !$c || $c->assigned_user_id !== $userId || $c->assigned_team_id !== $teamId || $c->withdrawn_at !== null;
                    if ($c) {
                        $c->fill($attrs + ['assigned_user_id' => $userId, 'assigned_team_id' => $teamId, 'withdrawn_at' => null])->save();
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
    }

    /**
     * Loan / dues columns present in this file (columns not in the file keep their old values). DPD without an oldest
     * due date gives the oldest due date, so days overdue keep counting after the upload.
     */
    public static function accountAttrs(array $a): array
    {
        if (isset($a['dpd']) && !isset($a['oldest_due_date'])) {
            $a['oldest_due_date'] = now()->subDays($a['dpd'])->toDateString();
        }
        unset($a['dpd']);
        if (array_intersect_key($a, array_flip(ImportValidator::MONEY)) !== []) {
            $a['balance_as_of'] = now()->toDateString();
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

    private function mapRows(array $rows, array $mapping): array
    {
        return array_map(function (array $row) use ($mapping) {
            $out = [];
            foreach ($mapping as $field => $idx) {
                $out[$field] = $idx === null ? null : (isset($row[$idx]) ? trim((string) $row[$idx]) : null);
            }
            return $out;
        }, $rows);
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
