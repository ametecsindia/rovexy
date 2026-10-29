<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Security\SpreadsheetSanitiser;
use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\FollowUp;
use App\Models\ImportJob;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * G21: CSV exports for lender / RBI audits — customers, visits, payments, follow-ups, audit log (calls.csv already
 * existed) — and the "Audit pack": one ZIP per portfolio and period with every CSV, the call recordings and payment
 * slips, and a manifest with each file's SHA-256 so nobody can change a file unnoticed.
 * Filters: from / to (dates), portfolio (import id | 'manual'), employee (user id). Managers get their own groups only.
 */
final class ExportService
{
    public const KINDS = ['customers', 'calls', 'visits', 'payments', 'follow-ups', 'audit'];
    /** ponytail: a cap so one click can't fill the disk; split the period for bigger portfolios. */
    public const PACK_MAX_FILES = 5000;

    public function __construct(private readonly User $actor, private readonly array $f)
    {
    }

    /** @return array{0: list<string>, 1: iterable<list<mixed>>} */
    public function table(string $kind): array
    {
        return match ($kind) {
            'customers' => [['Customer ID', 'Name', 'Phone', 'Portfolio', 'Status', 'Employee code', 'Employee', 'Address', 'Area', 'City', 'Pincode', 'Latitude', 'Longitude',
                'Pin source', 'Outstanding', 'Overdue', 'Last paid on', 'Last paid amount'], $this->customers()],
            'calls' => [['Call ID', 'Employee code', 'Employee', 'Customer ID', 'Customer', 'Destination', 'Outcome', 'Initiated', 'Talk s', 'Recording', 'Disposition', 'Amount'], $this->calls()],
            'visits' => [['Date and time', 'Employee code', 'Employee', 'Customer ID', 'Customer', 'Distance from pin m', 'GPS accuracy m', 'Latitude', 'Longitude'], $this->visits()],
            'payments' => [['Submitted', 'Employee code', 'Employee', 'Customer ID', 'Customer', 'Amount', 'Mode', 'Reference', 'Paid on', 'Review', 'Reviewed by', 'Reviewed at', 'Slip file'], $this->payments()],
            'follow-ups' => [['Due', 'Status', 'Employee code', 'Employee', 'Customer ID', 'Customer', 'Notes', 'Created'], $this->followUps()],
            'audit' => [['Time', 'Action', 'Actor', 'Actor id', 'Subject', 'Subject id', 'IP', 'Details'], $this->audit()],
        };
    }

    /** Streams one CSV to php://output. */
    public function csv(string $kind, $out): void
    {
        [$head, $rows] = $this->table($kind);
        fputcsv($out, $head);
        foreach ($rows as $row) {
            fputcsv($out, SpreadsheetSanitiser::row($row));
        }
    }

    /**
     * Builds the audit pack ZIP in a temp file and returns its path. Recordings / slips only with $withMedia.
     * @return array{path: string, files: int, skipped: int}
     */
    public function pack(bool $withMedia): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rx-pack') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true || throw new \RuntimeException('Could not create the ZIP file.');
        $manifest = [];
        $add = function (string $name, string $data) use ($zip, &$manifest) {
            $zip->addFromString($name, $data);
            $manifest[] = [$name, strlen($data), hash('sha256', $data)];
        };
        foreach (self::KINDS as $kind) {
            $h = fopen('php://temp', 'w+');
            $this->csv($kind, $h);
            rewind($h);
            $add("{$kind}.csv", (string) stream_get_contents($h));
            fclose($h);
        }
        $files = 0;
        $skipped = 0;
        if ($withMedia) {
            $recDisk = Storage::disk(config('ammp.recordings.disk'));
            foreach ($this->callQuery()->whereHas('recording', fn ($q) => $q->where('status', 'available'))->with('recording')->lazyById(500) as $c) {
                if ($files >= self::PACK_MAX_FILES) { $skipped++; continue; }
                $r = $c->recording;
                $data = rescue(fn () => $recDisk->get($r->object_key), null, false);
                if ($data === null) { $skipped++; continue; }
                $add("recordings/call-{$c->uuid}.{$r->format}", $data);
                $files++;
            }
            $slips = Storage::disk('company_files');
            foreach ($this->paymentQuery()->whereNotNull('slip_key')->lazyById(500) as $d) {
                if ($files >= self::PACK_MAX_FILES) { $skipped++; continue; }
                $data = rescue(fn () => $slips->get($d->slip_key), null, false);
                if ($data === null) { $skipped++; continue; }
                $add('slips/payment-' . $d->id . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($d->slip_name ?: basename($d->slip_key))), $data);
                $files++;
            }
        }
        $readme = "ROVEXY audit pack\n" . 'Company: ' . app(CurrentCompany::class)->get()->name . "\nMade: " . now()->toDateTimeString() . ' by ' . $this->actor->name . "\n"
            . 'Filters: ' . json_encode(array_filter($this->f)) . "\nFiles: " . (count($manifest) + 2) . ($skipped ? " · {$skipped} recordings / slips not included (missing or over " . self::PACK_MAX_FILES . ')' : '') . "\n"
            . "MANIFEST.csv lists every file with its SHA-256 checksum. Times are in " . $this->tz() . ".\n";
        $add('README.txt', $readme);
        $m = "File,Bytes,SHA-256\n" . implode("\n", array_map(fn ($x) => implode(',', $x), $manifest)) . "\n";
        $zip->addFromString('MANIFEST.csv', $m);
        $zip->close();
        return ['path' => $path, 'files' => $files, 'skipped' => $skipped];
    }

    // ── queries ─────────────────────────────────────────────────────────────────────────────────────────────────

    private function tz(): string
    {
        return app(CurrentCompany::class)->get()->timezone ?: 'Asia/Kolkata';
    }

    private function from(): string
    {
        return \Illuminate\Support\Carbon::parse($this->f['from'], $this->tz())->startOfDay()->utc()->toDateTimeString();
    }

    private function to(): string
    {
        return \Illuminate\Support\Carbon::parse($this->f['to'], $this->tz())->endOfDay()->utc()->toDateTimeString();
    }

    private function t($dt): ?string
    {
        return $dt ? \Illuminate\Support\Carbon::parse($dt)->setTimezone($this->tz())->format('Y-m-d H:i:s') : null;
    }

    /** Users the actor may see (null = all). */
    private function userScope(): ?array
    {
        $teams = $this->actor->supervisedTeamIds();
        return $teams === null ? null : DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }

    /** Customers in the chosen portfolio and in the actor's groups. */
    private function customerQuery()
    {
        $pf = $this->f['portfolio'] ?? null;
        $teams = $this->actor->supervisedTeamIds();
        return Customer::query()
            ->when($pf === 'manual', fn ($q) => $q->whereNull('last_import_id'))
            ->when(ctype_digit((string) $pf), fn ($q) => $q->where('last_import_id', (int) $pf))
            ->when($teams !== null, fn ($q) => $q->whereIn('assigned_team_id', $teams))
            ->when(!empty($this->f['employee']), fn ($q) => $q->where('assigned_user_id', (int) $this->f['employee']));
    }

    private function byUser($q)
    {
        $users = $this->userScope();
        return $q->when($users !== null, fn ($w) => $w->whereIn('user_id', $users))
            ->when(!empty($this->f['employee']), fn ($w) => $w->where('user_id', (int) $this->f['employee']));
    }

    private function inPortfolio($q)
    {
        return empty($this->f['portfolio']) ? $q : $q->whereIn('customer_id', $this->customerQuery()->select('id'));
    }

    private function callQuery()
    {
        return $this->inPortfolio($this->byUser(Call::query()->where('is_test', false)->whereBetween('initiated_at', [$this->from(), $this->to()])));
    }

    private function paymentQuery()
    {
        return CallDisposition::query()->whereNotNull('amount')->whereBetween('submitted_at', [$this->from(), $this->to()])
            ->whereIn('call_id', $this->inPortfolio($this->byUser(Call::query()->where('is_test', false)))->select('id'))
            ->when(!empty($this->f['employee']), fn ($w) => $w->where('user_id', (int) $this->f['employee']));
    }

    private function customers(): \Generator
    {
        $names = ImportJob::query()->get(['id', 'portfolio', 'original_name'])->mapWithKeys(fn ($j) => [$j->id => $j->portfolio ?: pathinfo((string) $j->original_name, PATHINFO_FILENAME)]);
        foreach ($this->customerQuery()->with('assignee:id,employee_code,name')->lazyById(1000) as $c) {
            yield [$c->customer_ref, $c->name, $c->phone, $c->last_import_id ? ($names[$c->last_import_id] ?? "Import #{$c->last_import_id}") : 'Added by hand', $c->status,
                $c->assignee?->employee_code, $c->assignee?->name, $c->address, $c->area, $c->city, $c->pincode, $c->lat, $c->lng, $c->geo_status,
                $c->outstanding, $c->overdue_amount, $c->last_paid_on, $c->last_paid_amount];
        }
    }

    private function calls(): \Generator
    {
        foreach ($this->callQuery()->with(['user:id,employee_code,name', 'customer:id,customer_ref,name', 'disposition:id,call_id,code,amount'])->lazyById(1000) as $c) {
            yield [$c->uuid, $c->user?->employee_code, $c->user?->name, $c->customer?->customer_ref, $c->customer?->name, $c->destination, $c->state,
                $this->t($c->initiated_at), $c->talk_seconds, $c->recording_status, $c->disposition?->code, $c->disposition?->amount];
        }
    }

    private function visits(): \Generator
    {
        $q = $this->inPortfolio($this->byUser(CustomerEvent::query()->where('type', 'visit')->whereBetween('created_at', [$this->from(), $this->to()])));
        $customers = [];
        foreach ($q->with('user:id,employee_code,name')->lazyById(1000) as $e) {
            $c = $customers[$e->customer_id] ??= Customer::query()->find($e->customer_id, ['id', 'customer_ref', 'name']);
            $m = (array) $e->meta;
            yield [$this->t($e->created_at), $e->user?->employee_code, $e->user?->name, $c?->customer_ref, $c?->name, $m['distance_m'] ?? null, $m['accuracy_m'] ?? null, $m['lat'] ?? null, $m['lng'] ?? null];
        }
    }

    private function payments(): \Generator
    {
        foreach ($this->paymentQuery()->with(['user:id,employee_code,name', 'call:id,customer_id', 'call.customer:id,customer_ref,name', 'reviewer:id,name'])->lazyById(1000) as $d) {
            yield [$this->t($d->submitted_at), $d->user?->employee_code, $d->user?->name, $d->call?->customer?->customer_ref, $d->call?->customer?->name, $d->amount, $d->payment_mode,
                $d->payment_ref, $d->paid_on?->toDateString(), $d->review_status ?? 'pending', $d->reviewer?->name, $this->t($d->reviewed_at),
                $d->slip_key ? 'slips/payment-' . $d->id . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($d->slip_name ?: basename($d->slip_key))) : null];
        }
    }

    private function followUps(): \Generator
    {
        $q = $this->inPortfolio($this->byUser(FollowUp::query()->whereBetween('due_at', [$this->from(), $this->to()])));
        foreach ($q->with(['user:id,employee_code,name', 'customer:id,customer_ref,name'])->lazyById(1000) as $u) {
            yield [$this->t($u->due_at), $u->status, $u->user?->employee_code, $u->user?->name, $u->customer?->customer_ref, $u->customer?->name, $u->notes, $this->t($u->created_at)];
        }
    }

    private function audit(): \Generator
    {
        $users = $this->userScope();
        $q = DB::table('audit_events')->where('company_id', app(CurrentCompany::class)->id())->whereBetween('created_at', [$this->from(), $this->to()])
            ->when($users !== null, fn ($w) => $w->where('actor_type', 'user')->whereIn('actor_id', $users))
            ->when(!empty($this->f['employee']), fn ($w) => $w->where('actor_type', 'user')->where('actor_id', (int) $this->f['employee']))
            ->orderBy('id');
        foreach ($q->lazy(1000) as $a) {
            yield [$this->t($a->created_at), $a->action, $a->actor_type, $a->actor_id, $a->subject_type, $a->subject_id, $a->ip, $a->meta];
        }
    }
}
