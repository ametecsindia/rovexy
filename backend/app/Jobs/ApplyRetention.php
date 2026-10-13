<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Calling\RecordingState;
use App\Models\Recording;
use App\Models\RetentionJob;
use App\Support\Audit;
use Illuminate\Support\Facades\Storage;

/** Executes ONLY the reviewed, approved scope (IDs captured at planning), re-checking expiry and legal hold per item. */
final class ApplyRetention extends TenantJob
{
    public int $timeout = 3600;

    public function __construct(int $companyId, public readonly int $retentionJobId)
    {
        parent::__construct($companyId);
    }

    public function run(): void
    {
        $job = RetentionJob::query()->where('status', 'approved')->findOrFail($this->retentionJobId);
        $job->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $disk = Storage::disk(config('ammp.recordings.disk'));
        $n = 0;
        foreach (array_chunk($job->scope['ids'] ?? [], 200) as $ids) {
            Recording::query()->whereIn('id', $ids)->where('status', RecordingState::Available->value)
                ->where('legal_hold', false)->where('retention_expires_at', '<=', now())
                ->each(function (Recording $r) use ($disk, &$n) {
                    if ($r->object_key) {
                        $disk->delete($r->object_key);
                    }
                    $r->forceFill(['status' => RecordingState::DeletedByRetention->value, 'purged_at' => now(), 'object_key' => null])->save();
                    $r->call()->update(['recording_status' => RecordingState::DeletedByRetention->value]);
                    $n++;
                });
        }
        $job->forceFill(['status' => 'completed', 'executed_count' => $n, 'finished_at' => now()])->save();
        Audit::record('retention.executed', $job, ['deleted' => $n]);
    }
}
