<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Jobs\SimulateCallProgress;
use App\Models\Call;

/**
 * DEVELOPMENT-ONLY simulator. Emits a scripted sequence of lifecycle events through the same ingestion path as real
 * telephony. It produces NO audio and NO recording file (the recording ends up Failed), so it cannot be mistaken for
 * working calling. TelephonyManager refuses to construct it outside APP_ENV=local/testing.
 */
final class SimulatorAdapter implements TelephonyAdapter
{
    public function name(): string
    {
        return 'simulator';
    }

    public function originate(Call $call, array $p): void
    {
        // Destination suffix selects the scenario, e.g. ...0001 busy, ...0002 no answer, else answered for 20 s.
        $scenario = match (substr($p['customer_e164'], -4)) {
            '0001' => 'busy',
            '0002' => 'no_answer',
            '0003' => 'agent_unanswered',
            default => 'answered',
        };
        SimulateCallProgress::dispatch($call->company_id, $call->uuid, $scenario)->delay(now()->addSeconds(1));
    }

    public function hangup(Call $call, string $cause): void
    {
        SimulateCallProgress::dispatch($call->company_id, $call->uuid, 'hangup:' . $cause);
    }

    public function health(): array
    {
        return ['ok' => true, 'detail' => 'SIMULATOR (development only — no real calls)'];
    }
}
