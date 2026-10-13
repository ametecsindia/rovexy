<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Management\AmapiClient;
use App\Models\ManagedDevice;
use App\Services\PolicyService;

/** Pushes the device's effective device-level controls to its AMAPI policy (one policy per device: policies/dev-{id}). */
final class SyncAmapiPolicy extends TenantJob
{
    public int $tries = 5;

    public function __construct(int $companyId, public readonly int $deviceId)
    {
        parent::__construct($companyId);
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function run(PolicyService $policies, AmapiClient $amapi): void
    {
        $device = ManagedDevice::query()->findOrFail($this->deviceId);
        if ($device->management_mode !== 'amapi' || !$device->amapi_device_name) {
            return;
        }
        $snap = $policies->currentSnapshot($device);
        $amapi->applyDevicePolicy($device, $snap->values);
        $detail = (array) $device->compliance_detail;
        $detail['amapi'] = array_merge((array) ($detail['amapi'] ?? []), ['synced_fingerprint' => $snap->fingerprint, 'policy_compliant' => null, 'non_compliance' => []]);
        $device->forceFill(['compliance_detail' => $detail])->save();   // wait for a fresh STATUS_REPORT before trusting compliance
    }
}
