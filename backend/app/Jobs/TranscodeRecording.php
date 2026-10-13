<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Call;
use App\Models\Recording;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/** WAV → MP3 via ffmpeg. Only a verified output replaces the original; otherwise the WAV stays as the available format. */
final class TranscodeRecording extends TenantJob
{
    public int $tries = 3;

    public function __construct(int $companyId, public readonly int $recordingId)
    {
        parent::__construct($companyId);
    }

    public function run(): void
    {
        $rec = Recording::query()->findOrFail($this->recordingId);
        $call = Call::query()->findOrFail($rec->call_id);
        if ($rec->format !== 'wav' || $rec->object_key === null) {
            return;
        }
        $disk = Storage::disk(config('ammp.recordings.disk'));
        $tmpIn = tempnam(sys_get_temp_dir(), 'rec') . '.wav';
        $tmpOut = substr($tmpIn, 0, -4) . '.mp3';
        try {
            file_put_contents($tmpIn, $disk->get($rec->object_key));
            $r = Process::timeout(300)->run(['ffmpeg', '-nostdin', '-y', '-loglevel', 'error', '-i', $tmpIn, '-ac', '1', '-b:a', '32k', $tmpOut]);
            $probe = $r->successful() ? Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=format_name', '-of', 'csv=p=0', $tmpOut]) : null;
            if (!$r->successful() || $probe === null || !str_contains($probe->output(), 'mp3') || filesize($tmpOut) < 100) {
                FetchRecording::markAvailable($rec, $call); // keep verified WAV
                return;
            }
            $newKey = substr($rec->object_key, 0, -4) . '.mp3';
            $bytes = (string) file_get_contents($tmpOut);
            $disk->put($newKey, $bytes, ['visibility' => 'private']);
            $oldKey = $rec->object_key;
            $rec->forceFill(['object_key' => $newKey, 'format' => 'mp3', 'mime' => 'audio/mpeg', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])->save();
            $disk->delete($oldKey);
            FetchRecording::markAvailable($rec, $call);
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }
}
