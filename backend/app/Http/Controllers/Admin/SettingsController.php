<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\DomainException;
use App\Domain\Retention\RetentionPlanner;
use App\Domain\Security\SpreadsheetSanitiser;
use App\Http\Controllers\Controller;
use App\Jobs\ApplyRetention;
use App\Models\Call;
use App\Models\CompanyBranding;
use App\Models\Recording;
use App\Models\RetentionJob;
use App\Support\Audit;
use App\Support\CurrentCompany;
use App\Telephony\TelephonyManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

final class SettingsController extends Controller
{
    public function branding(CurrentCompany $c)
    {
        $b = CompanyBranding::query()->first();
        return response()->json([
            'display_name' => $b?->display_name ?? $c->get()->name, 'primary_color' => $b?->primary_color ?? '#1F3A5F',
            'accent_color' => $b?->accent_color ?? '#B8893B', 'version' => $b?->version ?? 0,
            'logo_url' => $b?->logoUrl(),
        ]);
    }

    /** One branding configuration feeds both the desktop console and the mobile app. No logo => neutral placeholder. */
    public function saveBranding(Request $r, CurrentCompany $c)
    {
        $data = $r->validate([
            'display_name' => 'required|string|max:150',
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:' . config('ammp.uploads.branding_logo_max_kb'),
            'remove_logo' => 'boolean',
        ]);
        $b = CompanyBranding::query()->firstOrNew([]);
        $fill = array_intersect_key($data, array_flip(['display_name', 'primary_color', 'accent_color']));
        if ($r->hasFile('logo')) {
            $info = @getimagesize($r->file('logo')->getRealPath());
            if ($info === false || $info[0] > 2048 || $info[1] > 2048 || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new DomainException('invalid_logo', 'Logo must be a PNG, JPEG or WebP up to 2048×2048.');
            }
            $fill['logo_key'] = $r->file('logo')->store("companies/{$c->id()}/branding", 'company_files');
            $fill['logo_sha256'] = hash_file('sha256', $r->file('logo')->getRealPath());
            $fill['logo_mime'] = $info['mime'];
        } elseif ($r->boolean('remove_logo')) {
            $fill += ['logo_key' => null, 'logo_sha256' => null, 'logo_mime' => null];
        }
        $b->fill($fill + ['updated_by' => $r->user()->id]);
        $b->version = ($b->version ?? 0) + 1;
        $b->save();
        Audit::record('branding.updated', $b, ['version' => $b->version, 'logo_changed' => $r->hasFile('logo') || $r->boolean('remove_logo')]);
        return $this->branding($c);
    }

    public function estimate(Request $r)
    {
        $d = $r->validate(['agents' => 'required|integer|min:1|max:100000', 'calls_per_day' => 'required|integer|min:1|max:1000',
            'avg_talk_minutes' => 'required|numeric|min:0.1|max:120', 'retention_days' => 'required|integer|min:1|max:3650', 'kbps' => 'integer|min:8|max:256']);
        $bytes = RetentionPlanner::estimateBytes((int) $d['agents'], (int) $d['calls_per_day'], (float) $d['avg_talk_minutes'], (int) $d['retention_days'], (int) ($d['kbps'] ?? 32));   // query values arrive as strings
        $actual = (int) Recording::query()->where('status', 'available')->sum('size_bytes');
        return response()->json(['estimated_bytes' => $bytes, 'estimated_gb' => round($bytes / 1e9, 1), 'current_bytes' => $actual]);
    }

    public function retentionJobs()
    {
        return response()->json(RetentionJob::query()->latest('id')->paginate(50));
    }

    /** Plans a deletion scope for review. Nothing is deleted until a (different) authorised user approves it. */
    public function planRetention(Request $r)
    {
        $data = $r->validate(['kind' => 'required|in:recordings']);
        $rows = Recording::query()->where('status', 'available')->where('retention_expires_at', '<=', now())->limit(5000)
            ->get(['id', 'retention_expires_at', 'legal_hold', 'size_bytes'])
            ->map(fn ($x) => ['id' => $x->id, 'expires_at' => $x->retention_expires_at->toAtomString(), 'legal_hold' => $x->legal_hold, 'size' => (int) $x->size_bytes])->all();
        $plan = RetentionPlanner::scope($rows, new \DateTimeImmutable());
        $job = RetentionJob::query()->create(['kind' => $data['kind'], 'status' => 'planned', 'planned_by' => $r->user()->id,
            'scope' => ['cutoff' => now()->toAtomString(), 'count' => count($plan['delete_ids']), 'bytes' => $plan['bytes'], 'held' => $plan['held'], 'ids' => $plan['delete_ids']]]);
        Audit::record('retention.planned', $job, ['count' => count($plan['delete_ids']), 'bytes' => $plan['bytes']]);
        return response()->json($job, 201);
    }

    public function approveRetention(Request $r, int $id)
    {
        $job = RetentionJob::query()->where('status', 'planned')->findOrFail($id);
        if ($job->planned_by === $r->user()->id) {
            throw new DomainException('four_eyes_required', 'A different administrator must approve this deletion.');
        }
        $job->forceFill(['status' => 'approved', 'approved_by' => $r->user()->id])->save();
        Audit::record('retention.approved', $job);
        ApplyRetention::dispatch($job->company_id, $job->id);
        return response()->json(['ok' => true]);
    }

    public function audit(Request $r)
    {
        return response()->json(DB::table('audit_events')->where('company_id', app(CurrentCompany::class)->id())
            ->when($r->query('action'), fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_') . '%'))
            ->orderByDesc('id')->paginate(100));
    }

    /** Reports each dependency honestly; simulated/dev drivers are flagged, never shown as healthy production. */
    public function health(TelephonyManager $tm)
    {
        $check = function (callable $fn) {
            try {
                return ['ok' => true, 'detail' => $fn()];
            } catch (\Throwable $e) {
                return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
            }
        };
        $tel = $check(fn () => $tm->adapter()->health());
        return response()->json([
            'environment' => app()->environment(),
            'database' => $check(fn () => DB::select('select 1') ? 'reachable' : 'no'),
            'redis' => $check(fn () => (string) Redis::connection()->ping()),
            'queue_size' => $check(fn () => Queue::size()),
            'telephony' => ['route' => \App\Services\CallService::routes()[0] ?? 'none', 'routes' => \App\Services\CallService::routes(),
                'driver' => rescue(fn () => $tm->adapter()->name(), config('ammp.telephony.driver'), false), 'simulated' => config('ammp.telephony.driver') === 'simulator'] + ($tel['ok'] ? $tel['detail'] : $tel),
            'management' => ['driver' => config('ammp.management.driver'), 'configured' => config('ammp.management.driver') === 'amapi' && (bool) config('ammp.management.amapi.service_account_json')],
            'push' => ['driver' => \App\Services\PushService::driver(), 'simulated' => \App\Services\PushService::driver() === 'log',
                'configured' => \App\Services\PushCredential::ready(), 'source' => \App\Services\PushCredential::source()],
            'websocket' => ['driver' => config('broadcasting.default'), 'host' => config('broadcasting.connections.reverb.options.host')],
            'turn' => ['configured' => (bool) config('ammp.media.turn_secret') && config('ammp.media.turn_urls') !== []],
            'ffmpeg' => $check(fn () => Process::run(['ffmpeg', '-version'])->successful() ? 'available' : throw new \RuntimeException('not found')),
            'recordings_storage' => $check(fn () => Storage::disk(config('ammp.recordings.disk'))->put('.health', (string) time()) ? 'writable' : 'not writable'),
            'nightly_backup' => \App\Services\NightlyBackup::status(),
        ]);
    }

    /** Call-by-call CSV. Optional: portfolio (import id | manual), employee (user id). Managers get only their groups' calls. */
    public function exportCalls(Request $r)
    {
        $data = $r->validate(['from' => 'required|date', 'to' => 'required|date|after:from', 'portfolio' => 'nullable|string|max:20', 'employee' => 'nullable|integer']);
        $users = $r->user()->usersInView();
        Audit::record('report.calls_exported', null, $data);
        $tz = app(CurrentCompany::class)->get()->timezone;
        $pf = $data['portfolio'] ?? null;
        $names = \App\Models\ImportJob::query()->get(['id', 'portfolio', 'original_name'])->mapWithKeys(fn ($j) => [$j->id => $j->portfolio ?: pathinfo($j->original_name, PATHINFO_FILENAME)]);
        return response()->streamDownload(function () use ($data, $users, $tz, $pf, $names) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Call ID', 'Employee code', 'Employee', 'Customer ID', 'Customer', 'Portfolio', 'Destination', 'Outcome', 'Initiated (' . $tz . ')', 'Ring s', 'Talk s', 'Total s', 'Recording', 'Disposition', 'Amount', 'Payment mode']);
            Call::query()->with(['user:id,employee_code,name', 'customer:id,customer_ref,name,last_import_id', 'disposition:id,call_id,code,amount,payment_mode'])
                ->where('is_test', false)->whereBetween('initiated_at', [$data['from'], $data['to']])
                ->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))
                ->when(!empty($data['employee']), fn ($q) => $q->where('user_id', (int) $data['employee']))
                ->when($pf === 'manual', fn ($q) => $q->whereHas('customer', fn ($c) => $c->whereNull('last_import_id')))
                ->when(ctype_digit((string) $pf), fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('last_import_id', (int) $pf)))
                ->orderBy('id')->chunk(1000, function ($calls) use ($out, $tz, $names) {
                    foreach ($calls as $c) {
                        $pid = $c->customer?->last_import_id;
                        fputcsv($out, SpreadsheetSanitiser::row([$c->uuid, $c->user?->employee_code, $c->user?->name, $c->customer?->customer_ref, $c->customer?->name,
                            $c->customer ? ($pid ? ($names[$pid] ?? "Import #{$pid}") : 'Added by hand') : null, $c->destination, $c->state,
                            $c->initiated_at->setTimezone($tz)->format('Y-m-d H:i:s'), $c->ring_seconds, $c->talk_seconds, $c->total_seconds, $c->recording_status,
                            $c->disposition?->code, $c->disposition?->amount, $c->disposition?->payment_mode]));
                    }
                });
            fclose($out);
        }, 'calls.csv', ['Content-Type' => 'text/csv']);
    }
}
