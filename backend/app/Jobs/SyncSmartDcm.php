<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Services\SmartDcm\SmartDcmSync;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Pull from SmartDCM for one company (scheduler every 5 min, SmartDCM's webhook, "Sync now"); continues itself until up to date. */
final class SyncSmartDcm extends TenantJob
{
    public int $timeout = 300;
    public int $tries = 1;

    /** One sync per company at a time; a second one asked meanwhile is dropped (the running one reads everything anyway). */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('smartdcm-sync-' . $this->companyId))->dontRelease()->expireAfter(360)];
    }

    public function run(): void
    {
        $conn = IntegrationConnection::query()->where('system', SmartDcmSync::SYSTEM)->where('enabled', true)->first();
        if (!$conn || !app(\App\Services\Platform\Licensing::class)->has('smartdcm')) {
            return;
        }
        try {
            $r = SmartDcmSync::for($conn)->run(240);
        } catch (\Throwable) {
            return;   // the reason is on the connection (last_status); the scheduler tries again in 5 minutes
        }
        if (!$r['done'] && config('queue.default') !== 'sync') {   // sync queue (a dev PC): the scheduler continues instead
            self::dispatch($this->companyId)->delay(now()->addSeconds(5));   // big first sync: the next part
        }
    }
}
