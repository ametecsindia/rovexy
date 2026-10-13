<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Devices\ApprovalState;
use App\Domain\DomainException;
use App\Events\AdminFeed;
use App\Events\DeviceChannelEvent;
use App\Models\DeviceApprovalEvent;
use App\Models\DeviceAssignment;
use App\Models\DeviceSession;
use App\Models\ManagedDevice;
use App\Models\PolicyAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class DeviceApprovalService
{
    public function __construct(
        private readonly PolicyService $policies,
        private readonly PushService $push,
        private readonly MediaSessionService $media,
        private readonly CallService $calls,
    ) {
    }

    public function approve(ManagedDevice $device, User $actor, int $userId, ?int $teamId, ?string $assetTag, ?int $profileId, string $code): ManagedDevice
    {
        app(\App\Services\Platform\Licensing::class)->assertUsable();   // no new phones on an expired / suspended licence
        if (!hash_equals($device->approval_code, strtoupper(trim($code)))) {
            throw new DomainException('approval_code_mismatch', 'The code does not match the code shown on the phone.');
        }
        $employee = User::query()->whereKey($userId)->first() ?? throw new DomainException('unknown_employee');
        if (!$employee->isActive() || !$employee->hasPermission('mobile.work')) {
            throw new DomainException('employee_not_eligible', 'Employee is inactive or lacks the Employee role.');
        }
        if ($teamId !== null && !Team::query()->whereKey($teamId)->exists()) {
            throw new DomainException('unknown_team');
        }
        if ($profileId !== null && !\App\Models\PolicyProfile::query()->whereKey($profileId)->exists()) {
            throw new DomainException('unknown_policy_profile');
        }
        $device = DB::transaction(function () use ($device, $actor, $employee, $teamId, $assetTag, $profileId) {
            /** @var ManagedDevice $d */
            $d = ManagedDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $d->approval_state = $d->approval_state->transitionTo(ApprovalState::Approved);
            $epoch = $d->credential_epoch + 1;   // fresh epoch: any earlier session is invalid
            $d->forceFill([
                'credential_epoch' => $epoch,
                'assigned_user_id' => $employee->id,
                'team_id' => $teamId,
                'asset_tag' => $assetTag ?: $d->asset_tag,
                'approval_code_expires_at' => now(),
            ])->save();
            $d->credentials()->where('status', 'active')->update(['epoch' => $epoch]);
            DeviceAssignment::query()->create(['device_id' => $d->id, 'user_id' => $employee->id, 'team_id' => $teamId, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
            if ($profileId !== null) {
                PolicyAssignment::query()->updateOrCreate(['target_type' => 'device', 'target_id' => $d->id], ['profile_id' => $profileId]);
            }
            DeviceApprovalEvent::query()->create(['device_id' => $d->id, 'action' => 'approved', 'actor_user_id' => $actor->id, 'meta' => ['employee_id' => $employee->id, 'team_id' => $teamId, 'epoch' => $epoch]]);
            Audit::record('device.approved', $d, ['employee_id' => $employee->id, 'team_id' => $teamId, 'asset_tag' => $assetTag, 'profile_id' => $profileId]);
            return $d;
        });
        $this->policies->refreshDevice($device);
        $this->push->ping($device, 'approval');
        AdminFeed::dispatchFor($device->company_id, 'device.updated', ['device_id' => $device->id]);
        return $device;
    }

    public function reject(ManagedDevice $device, User $actor, string $reason): void
    {
        DB::transaction(function () use ($device, $actor, $reason) {
            $d = ManagedDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $d->approval_state = $d->approval_state->transitionTo(ApprovalState::Rejected);
            $d->forceFill(['credential_epoch' => $d->credential_epoch + 1])->save();
            $d->credentials()->update(['status' => 'revoked', 'revoked_at' => now()]);
            DeviceApprovalEvent::query()->create(['device_id' => $d->id, 'action' => 'rejected', 'actor_user_id' => $actor->id, 'reason' => mb_substr($reason, 0, 255)]);
            Audit::record('device.rejected', $d, ['reason' => $reason]);
        });
        AdminFeed::dispatchFor($device->company_id, 'device.updated', ['device_id' => $device->id]);
    }

    /**
     * Revokes work access. Does NOT wipe the device and does NOT remove management restrictions.
     * Effects: credentials revoked, epoch bumped (all tokens dead immediately), work sessions ended, media sessions ended,
     * active call handled per policy, WebSocket notified, push ping sent.
     */
    public function revoke(ManagedDevice $device, User $actor, string $reason): void
    {
        $device = DB::transaction(function () use ($device, $actor, $reason) {
            $d = ManagedDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $d->approval_state = $d->approval_state->transitionTo(ApprovalState::Revoked);
            $d->forceFill(['credential_epoch' => $d->credential_epoch + 1, 'compliant' => false, 'work_state' => 'signed_out'])->save();
            $d->credentials()->update(['status' => 'revoked', 'revoked_at' => now()]);
            DeviceSession::query()->where('device_id', $d->id)->delete();
            WorkSession::query()->where('device_id', $d->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'revoked']);
            DeviceAssignment::query()->where('device_id', $d->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'revoked']);
            DeviceApprovalEvent::query()->create(['device_id' => $d->id, 'action' => 'revoked', 'actor_user_id' => $actor->id, 'reason' => mb_substr($reason, 0, 255)]);
            Audit::record('device.revoked', $d, ['reason' => $reason]);
            return $d;
        });
        $this->media->endAllForDevice($device, 'revoked');
        $this->calls->handleRevocation($device);
        DeviceChannelEvent::dispatchFor($device, 'session.revoked', []);
        $this->push->ping($device, 'revoked');
        AdminFeed::dispatchFor($device->company_id, 'device.updated', ['device_id' => $device->id]);
    }

    /** Changes the employee on an approved device: ends the old employee's sessions; device approval/policy unchanged. */
    public function reassign(ManagedDevice $device, User $actor, int $userId, ?int $teamId): void
    {
        $employee = User::query()->whereKey($userId)->first() ?? throw new DomainException('unknown_employee');
        if (!$device->approval_state->permitsWork()) {
            throw new DomainException('device_not_approved');
        }
        if (!$employee->isActive() || !$employee->hasPermission('mobile.work')) {
            throw new DomainException('employee_not_eligible');
        }
        if ($teamId !== null && !Team::query()->whereKey($teamId)->exists()) {
            throw new DomainException('unknown_team');
        }
        DB::transaction(function () use ($device, $actor, $employee, $teamId) {
            WorkSession::query()->where('device_id', $device->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'reassigned']);
            DeviceAssignment::query()->where('device_id', $device->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'reassigned']);
            DeviceAssignment::query()->create(['device_id' => $device->id, 'user_id' => $employee->id, 'team_id' => $teamId, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
            $device->forceFill(['assigned_user_id' => $employee->id, 'team_id' => $teamId, 'work_state' => 'signed_out'])->save();
            DeviceApprovalEvent::query()->create(['device_id' => $device->id, 'action' => 'reassigned', 'actor_user_id' => $actor->id, 'meta' => ['employee_id' => $employee->id, 'team_id' => $teamId]]);
            Audit::record('device.reassigned', $device, ['employee_id' => $employee->id, 'team_id' => $teamId]);
        });
        $this->policies->refreshDevice($device);
        DeviceChannelEvent::dispatchFor($device, 'session.ended', ['reason' => 'reassigned']);
        $this->push->ping($device, 'reassigned');
    }
}
