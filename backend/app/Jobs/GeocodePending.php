<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AutoGeocode;

/** G5: pins for one company's new addresses, in batches, until none are left (or the monthly limit / an outage stops it). */
final class GeocodePending extends TenantJob
{
    public int $timeout = 900;
    public int $tries = 1;

    public function run(): void
    {
        $r = AutoGeocode::run();
        if ($r['stopped'] === null && $r['left'] > 0) {
            self::dispatch($this->companyId)->delay(now()->addSeconds(2));
        }
    }
}
