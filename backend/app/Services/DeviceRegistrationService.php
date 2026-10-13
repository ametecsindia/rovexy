<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Devices\ApprovalCode;
use App\Domain\Devices\ApprovalState;
use App\Domain\Devices\InstallationId;
use App\Domain\DomainException;
use App\Domain\Security\DevicePublicKey;
use App\Events\AdminFeed;
use App\Models\DeviceApprovalEvent;
use App\Models\DeviceCredential;
use App\Models\ManagedDevice;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated bootstrap: records a device + its public key and returns a short matching code.
 * Idempotent: the same installation ID with the same key returns the same pending record (no duplicates);
 * a different key for an existing installation ID is a conflict.
 */
final class DeviceRegistrationService
{
    public function register(array $in): ManagedDevice
    {
        $installation = InstallationId::assertValid((string) ($in['installation_id'] ?? ''));
        $key = DevicePublicKey::fromBase64Der((string) ($in['public_key'] ?? ''));
        $info = [
            'manufacturer' => mb_substr((string) ($in['manufacturer'] ?? ''), 0, 64) ?: null,
            'model' => mb_substr((string) ($in['model'] ?? ''), 0, 64) ?: null,
            'os_version' => mb_substr((string) ($in['os_version'] ?? ''), 0, 32) ?: null,
            'sdk_int' => isset($in['sdk_int']) ? max(0, min(99, (int) $in['sdk_int'])) : null,
            'app_version' => mb_substr((string) ($in['app_version'] ?? ''), 0, 32) ?: null,
        ];
        $amapiName = isset($in['amapi_device_name']) && preg_match('#^enterprises/[\w-]+/devices/[\w-]+$#', (string) $in['amapi_device_name'])
            ? (string) $in['amapi_device_name'] : null;

        return DB::transaction(function () use ($installation, $key, $info, $amapiName) {
            $existing = ManagedDevice::query()->where('installation_id', $installation)->lockForUpdate()->first();
            if ($existing !== null) {
                $latest = $existing->credentials()->latest('id')->first();
                if ($latest === null || !hash_equals($latest->fingerprint, $key->fingerprint)) {
                    throw new DomainException('installation_conflict', 'This installation is already registered with a different key.');
                }
                if ($existing->approval_state === ApprovalState::Pending && $existing->approval_code_expires_at?->isPast()) {
                    $existing->forceFill(['approval_code' => $this->uniqueCode(), 'approval_code_expires_at' => now()->addDay()])->save();
                }
                $existing->forceFill($info)->save();
                return $existing;
            }

            $device = new ManagedDevice($info + [
                'installation_id' => $installation,
                'approval_state' => ApprovalState::Pending,
                'approval_code' => $this->uniqueCode(),
                'approval_code_expires_at' => now()->addDay(),
                'management_mode' => $amapiName !== null ? 'amapi' : 'app_only',
                'management_status' => $amapiName !== null ? 'provisioning' : 'unenrolled',
                'amapi_device_name' => $amapiName,
                'credential_epoch' => 0,
            ]);
            $device->save();
            try {
                DeviceCredential::query()->create([
                    'device_id' => $device->id, 'public_key_pem' => $key->pem, 'fingerprint' => $key->fingerprint,
                    'epoch' => 0, 'status' => 'active',
                ]);
            } catch (QueryException) {
                throw new DomainException('installation_conflict', 'This key is already registered.');
            }
            $previous = $amapiName ? ManagedDevice::query()->where('amapi_device_name', $amapiName)->where('id', '!=', $device->id)->latest('id')->first() : null;
            DeviceApprovalEvent::query()->create([
                'device_id' => $device->id, 'action' => $previous ? 're_registered' : 'registered',
                'meta' => array_filter(['previous_device_id' => $previous?->id, 'previous_asset_tag' => $previous?->asset_tag]),
            ]);
            Audit::record('device.registered', $device, ['model' => $info['model'], 'reinstall_of' => $previous?->id], ['type' => 'device', 'id' => null]);
            AdminFeed::dispatchFor(app(CurrentCompany::class)->id(), 'device.pending', ['device_id' => $device->id]);
            return $device;
        });
    }

    private function uniqueCode(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $code = ApprovalCode::generate();
            if (!ManagedDevice::query()->where('approval_code', $code)->exists()) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not allocate an approval code.');
    }
}
