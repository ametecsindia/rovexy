<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Call;
use App\Services\CallService;
use Illuminate\Support\Str;

/** DEVELOPMENT ONLY — drives the simulator adapter through the real ingestion path. */
final class SimulateCallProgress extends TenantJob
{
    public function __construct(int $companyId, public readonly string $callUuid, public readonly string $scenario)
    {
        parent::__construct($companyId);
    }

    public function run(CallService $calls): void
    {
        if (!app()->environment('local', 'testing')) {
            return;
        }
        $call = Call::query()->where('uuid', $this->callUuid)->firstOrFail();
        $t = now()->utc();
        $ev = fn (string $type, int $offset, array $extra = []) => $calls->ingest([
            'call_id' => $call->uuid, 'company_id' => $call->company_id, 'provider_event_id' => 'sim:' . Str::uuid(),
            'provider_call_id' => 'sim-' . substr($call->uuid, 0, 8), 'type' => $type, 'at' => $t->copy()->addSeconds($offset)->format('Y-m-d\TH:i:s\Z'),
        ] + $extra);
        if (str_starts_with($this->scenario, 'hangup:')) {
            $ev('hangup', 0, ['cause' => substr($this->scenario, 7)]);
            return;
        }
        match ($this->scenario) {
            'agent_unanswered' => [$ev('agent_ringing', 0), $ev('hangup', 30, ['cause' => 'NO_ANSWER'])],
            'busy' => [$ev('agent_answered', 2), $ev('customer_ringing', 3), $ev('hangup', 6, ['cause' => 'USER_BUSY'])],
            'no_answer' => [$ev('agent_answered', 2), $ev('customer_ringing', 3), $ev('hangup', 33, ['cause' => 'NO_ANSWER'])],
            // Answered: no recording_started event is emitted, so a required recording correctly ends up Failed.
            default => [$ev('agent_answered', 2), $ev('customer_ringing', 3), $ev('customer_answered', 9), $ev('hangup', 29, ['cause' => 'NORMAL_CLEARING'])],
        };
    }
}
