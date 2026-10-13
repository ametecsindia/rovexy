<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Calling\RecordingState;
use App\Domain\Policy\PolicyResolver;
use App\Events\AdminFeed;
use App\Models\Call;
use App\Models\ManagedDevice;
use App\Models\Recording;
use App\Services\PolicyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the telephony recording into private storage, verifying size and SHA-256. Idempotent: if the object already
 * exists with the recorded checksum, it only finalises state. Retries with backoff; final failure is visible as Failed.
 */
final class FetchRecording extends TenantJob
{
    public int $tries = 5;

    public function __construct(int $companyId, public readonly int $recordingId)
    {
        parent::__construct($companyId);
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function run(PolicyService $policies): void
    {
        $rec = Recording::query()->findOrFail($this->recordingId);
        if (in_array($rec->status, [RecordingState::Available->value, RecordingState::DeletedByRetention->value, RecordingState::NotRequired->value], true)) {
            return;
        }
        $call = Call::query()->findOrFail($rec->call_id);
        $disk = Storage::disk(config('ammp.recordings.disk'));
        $rec->increment('attempts');

        $bytes = $this->readSource((string) $rec->source_path);
        if ($bytes === null || strlen($bytes) < 44) { // smaller than a WAV header: nothing recorded
            $rec->forceFill(['last_error' => 'source file missing or empty'])->save();
            if ($this->attempts() >= $this->tries) {
                $this->fail(new \RuntimeException('Recording source missing.'));
            }
            $this->release($this->backoff()[min($this->attempts() - 1, 3)]);
            return;
        }
        $isMp3 = str_starts_with($bytes, 'ID3') || (strlen($bytes) > 1 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0);
        $key = sprintf('companies/%d/recordings/%s/%s.%s', $rec->company_id, $call->initiated_at->utc()->format('Y/m'), $call->uuid, $isMp3 ? 'mp3' : 'wav');
        $sha = hash('sha256', $bytes);
        $disk->put($key, $bytes, ['visibility' => 'private']);
        if ($disk->size($key) !== strlen($bytes)) {
            throw new \RuntimeException('Stored size mismatch.');
        }
        $device = ManagedDevice::query()->find($call->device_id);
        $days = $device ? $policies->currentSnapshot($device)->values['retention.recording_days'] : PolicyResolver::effective([])['values']['retention.recording_days'];
        $rec->forceFill([
            'object_key' => $key, 'format' => $isMp3 ? 'mp3' : 'wav', 'mime' => $isMp3 ? 'audio/mpeg' : 'audio/wav', 'size_bytes' => strlen($bytes), 'sha256' => $sha,
            'duration_seconds' => $isMp3 ? ($call->talk_seconds ?: null) : $this->wavDuration($bytes), 'last_error' => null, 'retention_expires_at' => now()->addDays($days),
        ])->save();

        if (!$isMp3 && config('ammp.recordings.transcode') === 'mp3') {
            TranscodeRecording::dispatch($rec->company_id, $rec->id);
            return;
        }
        $this->markAvailable($rec, $call);
    }

    public static function markAvailable(Recording $rec, Call $call): void
    {
        $rec->forceFill(['status' => RecordingState::from($rec->status)->to(RecordingState::Available)->value, 'available_at' => now()])->save();
        $call->forceFill(['recording_status' => RecordingState::Available->value])->save();
        AdminFeed::dispatchFor($call->company_id, 'recording.available', ['call_id' => $call->id]);
    }

    public function failed(\Throwable $e): void
    {
        app(\App\Support\CurrentCompany::class)->withoutScope(function () use ($e) {
            $rec = Recording::query()->find($this->recordingId);
            if ($rec && $rec->status !== RecordingState::Available->value) {
                $rec->forceFill(['status' => RecordingState::Failed->value, 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                Call::query()->whereKey($rec->call_id)->update(['recording_status' => RecordingState::Failed->value]);
                AdminFeed::dispatchFor($rec->company_id, 'recording.failed', ['call_id' => $rec->call_id]);
            }
        });
    }

    private function readSource(string $relative): ?string
    {
        if (str_starts_with($relative, 'https://')) {   // provider-hosted recording (Exotel)
            $adapter = app(\App\Telephony\TelephonyManager::class)->adapter();
            return $adapter instanceof \App\Telephony\ExotelAdapter ? $adapter->fetchRecording($relative) : null;
        }
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $cfg = config('ammp.telephony.freeswitch');
        if ($cfg['recording_source'] === 'http') {
            $exp = time() + 120;
            $sig = hash_hmac('sha256', $relative . '|' . $exp, (string) $cfg['recording_http_secret']);
            $resp = Http::timeout(60)->get(rtrim((string) $cfg['recording_http_base'], '/') . '/' . $relative, ['exp' => $exp, 'sig' => $sig]);
            return $resp->successful() ? $resp->body() : null;
        }
        $path = rtrim((string) $cfg['recordings_base'], '/') . '/' . $relative;
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    private function wavDuration(string $bytes): ?int
    {
        if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            return null;
        }
        $byteRate = unpack('V', substr($bytes, 28, 4))[1] ?? 0;
        return $byteRate > 0 ? (int) round((strlen($bytes) - 44) / $byteRate) : null;
    }
}
