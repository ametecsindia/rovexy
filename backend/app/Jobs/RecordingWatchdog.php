<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Call;
use App\Services\CallService;

final class RecordingWatchdog extends TenantJob
{
    public function __construct(int $companyId, public readonly int $callId)
    {
        parent::__construct($companyId);
    }

    public function run(CallService $calls): void
    {
        $calls->handleRecordingNotStarted(Call::query()->findOrFail($this->callId));
    }
}
