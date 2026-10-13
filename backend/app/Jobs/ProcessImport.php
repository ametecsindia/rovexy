<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\User;
use App\Services\ImportService;

final class ProcessImport extends TenantJob
{
    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(int $companyId, public readonly int $importId, public readonly string $step, public readonly int $actorId)
    {
        parent::__construct($companyId);
    }

    public function run(ImportService $imports): void
    {
        $job = ImportJob::query()->findOrFail($this->importId);
        try {
            $this->step === 'commit'
                ? $imports->commit($job, User::query()->findOrFail($this->actorId))
                : $imports->validateAll($job);
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'failure' => mb_substr($e->getMessage(), 0, 255), 'finished_at' => now()])->save();
            throw $e;
        }
    }
}
