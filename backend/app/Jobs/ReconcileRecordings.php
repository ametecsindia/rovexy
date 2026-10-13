<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Calling\RecordingState;
use App\Models\Recording;
use App\Support\Audit;

/** Scheduled: retries stuck recordings and marks long-missing ones Failed (alerting the admin console). */
final class ReconcileRecordings extends TenantJob
{
    public function run(): void
    {
        $threshold = now()->subMinutes((int) config('ammp.recordings.missing_after_minutes'));
        Recording::query()->whereIn('status', [RecordingState::Pending->value, RecordingState::Recording->value, RecordingState::Processing->value])
            ->whereHas('call', fn ($q) => $q->whereNotNull('ended_at')->where('ended_at', '<', $threshold))
            ->each(function (Recording $r) {
                if ($r->attempts < 5) {
                    if ($r->status !== RecordingState::Processing->value) {
                        $r->forceFill(['status' => RecordingState::Processing->value])->save();
                    }
                    FetchRecording::dispatch($r->company_id, $r->id);
                    return;
                }
                $r->forceFill(['status' => RecordingState::Failed->value, 'last_error' => 'missing after reconciliation'])->save();
                $r->call()->update(['recording_status' => RecordingState::Failed->value]);
                Audit::record('recording.missing', $r, ['call_id' => $r->call_id]);
            });
        // Failed recordings can recover if the file later appears (e.g. telephony storage came back).
        Recording::query()->where('status', RecordingState::Failed->value)->where('attempts', '<', 5)->whereNotNull('source_path')
            ->where('updated_at', '<', now()->subHour())->each(fn (Recording $r) => FetchRecording::dispatch($r->company_id, $r->id));
    }
}
