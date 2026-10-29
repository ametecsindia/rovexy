<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Customers\PhoneNormaliser;
use App\Domain\Security\SpreadsheetSanitiser;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ImportJob;
use App\Models\ImportRowError;
use App\Services\CustomerAssignmentService;
use App\Services\ImportService;
use Illuminate\Http\Request;

final class CustomerController extends Controller
{
    public function template()
    {
        // Sample lead file: imports cleanly as downloaded (no Caller ID / group, so nothing depends on this company's people).
        // Dates are relative to today so they never look stale. Amounts add up: total = principal + interest + charges; overdue = EMI × overdue EMIs.
        $d = fn (int $days) => now()->addDays($days)->format('Y-m-d');
        $rows = [
            ['Customer ID', 'Customer Name', 'Phone Number', 'Alternate Number', 'Email', 'Address', 'Area', 'City', 'State', 'Pincode', 'Latitude', 'Longitude',
                'Preferred Time', 'Campaign', 'Caller ID', 'Assigned Team', 'Loan Amount', 'Total Outstanding', 'Principal Outstanding', 'Interest Outstanding',
                'Charges', 'Overdue Amount', 'EMI Amount', 'Due Date', 'DPD', 'Overdue EMIs', 'Last Payment Date', 'Last Payment Amount', 'Notes', 'Status', 'Follow-up Date'],
            ['LN-100001', 'Ravi Kumar', '9876543210', '9876501234', 'ravi.kumar@example.com', 'Flat 304, Sai Residency, Road No 2', 'Kondapur', 'Hyderabad', 'Telangana', '500084', '17.4600', '78.3570',
                'After 6 pm', 'Personal Loan', '', '', '300000', '224500', '210000', '12000', '2500', '19000', '9500', $d(9), '38', '2', $d(-38), '9500',
                'Promised to pay by month end', 'new', $d(1)],
            ['LN-100002', 'Lakshmi Devi', '9876543211', '', 'lakshmi.devi@example.com', 'Plot 21, Ayyappa Society', 'Madhapur', 'Hyderabad', 'Telangana', '500081', '17.4483', '78.3915',
                '10 am - 1 pm', 'Personal Loan', '', '', '150000', '98300', '92000', '4800', '1500', '12400', '6200', $d(14), '22', '2', $d(-22), '6200',
                'Asked for EMI restructuring', 'new', $d(2)],
            ['LN-100003', 'Mohammed Imran', '9876543212', '', '', 'H.No 8-2-120, Lane 4', 'Gachibowli', 'Hyderabad', 'Telangana', '500032', '17.4401', '78.3489',
                '2 pm - 5 pm', 'Two-wheeler Loan', '', '', '85000', '61500', '58000', '2500', '1000', '9000', '3000', $d(5), '65', '3', $d(-65), '3000',
                'Not reachable on first attempt', 'follow_up', $d(1)],
            ['LN-100004', 'Sunita Reddy', '9876543213', '', 'sunita.reddy@example.com', '7-1-55, Lal Bungalow Road', 'Ameerpet', 'Hyderabad', 'Telangana', '500016', '17.4375', '78.4483',
                'Anytime', 'Consumer Durable', '', '', '40000', '18600', '17800', '600', '200', '4700', '4700', $d(20), '12', '1', $d(-12), '4700',
                'Partial payment made', 'in_progress', $d(3)],
            ['LN-100005', 'Venkatesh Rao', '9876543214', '', '', '2-3-410, Main Road', 'Secunderabad', 'Hyderabad', 'Telangana', '500003', '', '',
                'After 7 pm', 'Credit Card', '', '', '120000', '54000', '50000', '3000', '1000', '0', '5000', $d(25), '0', '0', $d(-5), '5000',
                'Paying on time — reminder call only', 'new', ''],
        ];
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'customer-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /** Managers see and work only on their own uploads; admins see all. */
    private function job(Request $r, int $id): ImportJob
    {
        return ImportJob::query()->when($r->user()->supervisedTeamIds() !== null, fn ($q) => $q->where('uploaded_by', $r->user()->id))->findOrFail($id);
    }

    public function imports(Request $r)
    {
        return response()->json(ImportJob::query()->when($r->user()->supervisedTeamIds() !== null, fn ($q) => $q->where('uploaded_by', $r->user()->id))->latest('id')->paginate(50));
    }

    public function upload(Request $r, ImportService $svc)
    {
        $r->validate(['file' => 'required|file|max:' . (config('ammp.uploads.import_max_mb') * 1024), 'portfolio' => 'nullable|string|max:150']);
        $job = $svc->upload($r->file('file'), $r->user(), $r->input('portfolio'));
        return response()->json($job, 201);
    }

    public function preview(Request $r, int $id, ImportService $svc)
    {
        $data = $r->validate(['mapping' => 'required|array', 'on_existing' => 'in:update,skip', 'allocation' => 'in:column,random',
            'team_id' => 'nullable|integer', 'user_ids' => 'array|max:500', 'user_ids.*' => 'integer']);
        return response()->json($svc->preview($this->job($r, $id), $data['mapping'], $data, $r->user()));
    }

    public function validateImport(Request $r, int $id, ImportService $svc)
    {
        $job = $this->job($r, $id);
        abort_unless(in_array($job->status, ['uploaded', 'ready'], true), 409);
        // ponytail: set before queueing, so the page sees it at once and polls (else it shows a stale "ready")
        $job->forceFill(['status' => 'validating'])->save();
        $svc->start($job, 'validate', $r->user());
        return response()->json(['status' => 'queued'], 202);
    }

    public function commit(Request $r, int $id, ImportService $svc)
    {
        $job = $this->job($r, $id);
        abort_unless($job->status === 'ready', 409, in_array($job->status, ['committing', 'completed'], true)
            ? 'This file is already imported (or importing). Refresh the page.' : 'Validate the import first.');
        $job->forceFill(['status' => 'committing'])->save();   // set before queueing: a second click is refused, the page shows progress
        $svc->start($job, 'commit', $r->user());
        return response()->json(['status' => 'queued'], 202);
    }

    public function renameImport(Request $r, int $id)
    {
        $data = $r->validate(['portfolio' => 'required|string|max:150']);
        $this->job($r, $id)->forceFill(['portfolio' => trim($data['portfolio'])])->save();
        return response()->json(['ok' => true]);
    }

    /**
     * Portfolios = imported lead files, with how many of their cases the actor can see, how many are still not given to an
     * employee, and the split per group. Managers see only their groups' cases.
     */
    public function portfolios(Request $r)
    {
        $rows = self::filtered([], $r->user())->whereNotNull('last_import_id')
            ->selectRaw('last_import_id, assigned_team_id, COUNT(*) AS total, SUM(CASE WHEN assigned_user_id IS NULL THEN 1 ELSE 0 END) AS unassigned')
            ->groupBy('last_import_id', 'assigned_team_id')->get();
        $teams = \App\Models\Team::query()->pluck('name', 'id');
        $jobs = ImportJob::query()->whereIn('id', $rows->pluck('last_import_id')->unique())->get()->keyBy('id');
        return response()->json($rows->groupBy('last_import_id')->map(function ($g, $id) use ($jobs, $teams) {
            $j = $jobs[$id] ?? null;
            return ['id' => (int) $id, 'name' => $j?->portfolio ?: ($j ? pathinfo($j->original_name, PATHINFO_FILENAME) : "Import #{$id}"), 'file' => $j?->original_name,
                'uploaded_at' => $j?->created_at, 'total' => (int) $g->sum('total'), 'unassigned' => (int) $g->sum('unassigned'),
                'groups' => $g->map(fn ($x) => ['team_id' => $x->assigned_team_id, 'name' => $x->assigned_team_id ? ($teams[$x->assigned_team_id] ?? '?') : null,
                    'total' => (int) $x->total, 'unassigned' => (int) $x->unassigned])->values()];
        })->sortByDesc('id')->values());
    }

    public function importStatus(Request $r, int $id)
    {
        $job = $this->job($r, $id);
        return response()->json($job->toArray() + ['sample_errors' => ImportRowError::query()->where('import_job_id', $id)->orderBy('row_number')->limit(50)->get(),
            'geo' => $job->status === 'completed' ? \App\Services\AutoGeocode::progress($job->id) : null]);
    }

    public function errorReport(Request $r, int $id)
    {
        $job = $this->job($r, $id);
        return response()->streamDownload(function () use ($job) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Row', 'Field', 'Error', 'Message']);
            ImportRowError::query()->where('import_job_id', $job->id)->orderBy('row_number')->chunk(1000, function ($chunk) use ($out) {
                foreach ($chunk as $e) {
                    fputcsv($out, SpreadsheetSanitiser::row([$e->row_number, $e->field, $e->code, $e->message]));
                }
            });
            fclose($out);
        }, "import-{$job->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Customers the actor may see (managers: their groups), narrowed by the list / allocation filters.
     * Filters: q, status, assigned (none | any | user id), team_id (none | id), import_id, campaign, city, area, pincode.
     */
    public static function filtered(array $f, \App\Models\User $actor): \Illuminate\Database\Eloquent\Builder
    {
        $teams = $actor->supervisedTeamIds();
        $v = fn (string $k) => isset($f[$k]) && $f[$k] !== '' ? (string) $f[$k] : null;
        return Customer::query()
            ->when($teams !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('assigned_team_id', $teams)
                ->orWhereIn('assigned_user_id', \Illuminate\Support\Facades\DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id'))))
            ->when($v('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', '%' . addcslashes($s, '%_') . '%')->orWhere('customer_ref', $s)))
            ->when($v('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($v('assigned') === 'none', fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($v('assigned') === 'any', fn ($q) => $q->whereNotNull('assigned_user_id'))
            ->when(ctype_digit((string) $v('assigned')), fn ($q) => $q->where('assigned_user_id', (int) $v('assigned')))
            ->when($v('team_id') === 'none', fn ($q) => $q->whereNull('assigned_team_id'))
            ->when(ctype_digit((string) $v('team_id')), fn ($q) => $q->where('assigned_team_id', (int) $v('team_id')))
            ->when($v('import_id'), fn ($q, $i) => $q->where('last_import_id', $i))
            ->when($v('campaign'), fn ($q, $c) => $q->where('campaign', $c))
            ->when($v('city'), fn ($q, $c) => $q->where('city', $c))
            ->when($v('area'), fn ($q, $c) => $q->where('area', $c))
            ->when($v('pincode'), fn ($q, $c) => $q->where('pincode', 'like', addcslashes($c, '%_') . '%'))
            ->when(empty($f['include_withdrawn']), fn ($q) => $q->whereNull('withdrawn_at'));
    }

    public function index(Request $r)
    {
        return response()->json(self::filtered($r->query(), $r->user())
            ->orderByDesc('id')->paginate(100)
            ->through(fn (Customer $c) => $c->toArray() + ['phone_masked' => PhoneNormaliser::mask($c->phone)]));
    }

    /** Same team scoping as the list: managers reach only their groups' cases. */
    private function scopedCustomer(Request $r, int $id): Customer
    {
        return self::filtered(['include_withdrawn' => 1], $r->user())->whereKey($id)->firstOrFail();
    }

    public function note(Request $r, int $id, \App\Services\CaseService $cases)
    {
        $cases->addNote($this->scopedCustomer($r, $id), $r->user(), (string) $r->validate(['text' => 'required|string|max:2000'])['text']);
        return response()->json(['ok' => true]);
    }

    public function status(Request $r, int $id, \App\Services\CaseService $cases)
    {
        $d = $r->validate(['status' => 'required|in:' . implode(',', \App\Services\CaseService::STATUSES), 'note' => 'nullable|string|max:2000']);
        $cases->setStatus($this->scopedCustomer($r, $id), $r->user(), $d['status'], $d['note'] ?? null);
        return response()->json(['ok' => true]);
    }

    /** Customer page: record, assignee, full call history (with recording state) and follow-ups. Same team scoping as the list. */
    public function show(Request $r, int $id)
    {
        $teams = $r->user()->supervisedTeamIds();
        $c = Customer::query()->whereKey($id)
            ->when($teams !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('assigned_team_id', $teams)
                ->orWhereIn('assigned_user_id', \Illuminate\Support\Facades\DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id'))))
            ->firstOrFail();
        $calls = \App\Models\Call::query()->where('customer_id', $c->id)->where('provider', '!=', 'manual')->with(['user:id,name', 'disposition', 'recording:id,call_id,format,duration_seconds,last_error'])
            ->orderByDesc('initiated_at')->limit(200)->get()
            ->map(fn ($call) => $call->toArray() + ['destination_masked' => PhoneNormaliser::mask($call->destination)]);
        \App\Support\Audit::record('customer.profile_viewed', $c, ['via' => 'console']);
        return response()->json(app(\App\Services\CaseService::class)->profile($c, $r->user(), false) + [
            'calls' => $calls, 'assigned_user_id' => $c->assigned_user_id, 'assigned_team_id' => $c->assigned_team_id,
            'follow_up_on' => $c->follow_up_on?->format('Y-m-d'),
        ]);
    }

    /** Values for the location / campaign filters (within the actor's scope). */
    public function facets(Request $r)
    {
        $base = fn () => self::filtered([], $r->user());
        $vals = fn (string $col) => $base()->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->limit(500)->pluck($col);
        return response()->json(['city' => $vals('city'), 'area' => $vals('area'), 'pincode' => $vals('pincode'), 'campaign' => $vals('campaign')]);
    }

    /**
     * Allocation by filter: all matching cases or only N of them (oldest / newest / random), to
     *  - one caller, - random & even between callers of a group, - a group's pool (its manager shares them out later).
     * `dry_run` returns the count only. Managers: only their groups' cases and callers (checked again in the service).
     */
    public function allocate(Request $r, CustomerAssignmentService $svc)
    {
        $d = $r->validate(['filter' => 'array', 'limit' => 'nullable|integer|min:1|max:50000', 'pick' => 'in:oldest,newest,random',
            'target' => 'nullable|in:caller,random,group', 'user_id' => 'nullable|integer', 'team_id' => 'nullable|integer',
            'user_ids' => 'array|max:500', 'user_ids.*' => 'integer', 'dry_run' => 'boolean']);
        $q = self::filtered($d['filter'] ?? [], $r->user());
        $matching = (clone $q)->count();
        if ($r->boolean('dry_run')) {
            return response()->json(['matching' => $matching]);
        }
        $q = match ($d['pick'] ?? 'oldest') { 'newest' => $q->orderByDesc('id'), 'random' => $q->inRandomOrder(), default => $q->orderBy('id') };
        $ids = $q->limit(min($d['limit'] ?? 50000, 50000))->pluck('id')->all();
        if (!$ids) {
            throw new \App\Domain\DomainException('nothing_to_allocate', 'No cases match these filters.');
        }
        $res = match ($d['target'] ?? 'caller') {
            'random' => $svc->assignRandom($ids, (int) ($d['team_id'] ?? 0), $d['user_ids'] ?? [], $r->user()),
            'group' => ['updated' => $svc->assign($ids, null, (int) ($d['team_id'] ?? 0) ?: null, $r->user())],
            default => ['updated' => $svc->assign($ids, (int) ($d['user_id'] ?? 0) ?: null, null, $r->user())],
        };
        return response()->json($res + ['matching' => $matching, 'selected' => count($ids), 'unchanged' => count($ids) - $res['updated']]);
    }

    public function assign(Request $r, CustomerAssignmentService $svc)
    {
        $data = $r->validate(['customer_ids' => 'required|array|max:5000', 'customer_ids.*' => 'integer', 'user_id' => 'nullable|integer', 'team_id' => 'nullable|integer',
            'mode' => 'in:single,random', 'user_ids' => 'array|max:500', 'user_ids.*' => 'integer']);
        if (($data['mode'] ?? 'single') === 'random') {
            return response()->json($svc->assignRandom($data['customer_ids'], (int) ($data['team_id'] ?? 0), $data['user_ids'] ?? [], $r->user()));
        }
        return response()->json(['updated' => $svc->assign($data['customer_ids'], $data['user_id'] ?? null, $data['team_id'] ?? null, $r->user())]);
    }

    public function transfer(Request $r, CustomerAssignmentService $svc)
    {
        $data = $r->validate(['from_user_id' => 'required|integer', 'mode' => 'in:single,random', 'user_id' => 'nullable|integer',
            'team_id' => 'nullable|integer', 'user_ids' => 'array|max:500', 'user_ids.*' => 'integer']);
        return response()->json($svc->transferAll((int) $data['from_user_id'], $data, $r->user()));
    }

    public function withdraw(Request $r, CustomerAssignmentService $svc)
    {
        $data = $r->validate(['customer_ids' => 'required|array|max:5000', 'customer_ids.*' => 'integer']);
        return response()->json(['withdrawn' => $svc->withdraw($data['customer_ids'], $r->user())]);
    }
}
