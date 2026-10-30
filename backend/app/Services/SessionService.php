<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Domain\Security\OpaqueToken;
use App\Models\ActivityEvent;
use App\Models\DeviceSession;
use App\Models\ManagedDevice;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;

final class SessionService
{
    /** Signed device → short-lived device token. Requires current approval. */
    public function createDeviceSession(ManagedDevice $device): array
    {
        if (!$device->approval_state->permitsWork()) {
            throw new DomainException('device_not_approved');
        }
        $t = OpaqueToken::issue('dst');
        $ttl = (int) config('ammp.sessions.device_token_ttl');
        DeviceSession::query()->where('device_id', $device->id)->where('expires_at', '<', now())->delete();
        DeviceSession::query()->create(['device_id' => $device->id, 'token_hash' => $t['hash'], 'epoch' => $device->credential_epoch, 'expires_at' => now()->addSeconds($ttl)]);
        return ['device_token' => $t['plain'], 'expires_in' => $ttl];
    }

    /** $lat/$lng: the phone's position at sign-in, checked against the SmartPRS geofence when that integration is on. */
    public function login(ManagedDevice $device, string $employeeCode, string $password, ?float $lat = null, ?float $lng = null, ?float $accuracy = null): array
    {
        $user = User::query()->where('employee_code', $employeeCode)->first();
        // Constant work whether or not the user exists.
        $ok = Hash::check($password, $user?->password ?? '$2y$12$toXeR8UVhISFlgJi7YZfQ.yBvmaMJCYf2mhgfvO19HZEYqRmZ4dWm');
        if ($user === null || !$ok) {
            Audit::record('mobile.login_failed', $device, ['employee_code' => mb_substr($employeeCode, 0, 64)]);
            throw new DomainException('invalid_credentials', 'User ID or password is incorrect.');
        }
        if (!$user->isActive() || !$user->hasPermission('mobile.work')) {
            throw new DomainException('forbidden', 'This account cannot use the mobile app.');
        }
        app(\App\Services\Platform\Licensing::class)->assertUsable();   // licence expired past grace / suspended: no work sessions
        if ($device->assigned_user_id !== $user->id) {
            $this->handOver($device, $user);   // shared phone: no revoke / re-approve needed to change who uses it
        }
        $ff = app(FieldForceIntegration::class);
        $geo = $ff->geofenceGate($user, $lat, $lng);   // throws outside_geofence when SmartPRS blocks this sign-in
        WorkSession::query()->where('device_id', $device->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'superseded']);
        $tokens = $this->issueWorkSession($device, $user);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $device->forceFill(['work_state' => 'idle'])->saveQuietly();
        ActivityEvent::query()->create(['device_id' => $device->id, 'user_id' => $user->id, 'type' => 'signed_in', 'occurred_at' => now()]);
        Audit::record('mobile.login', $device, ['user_id' => $user->id, 'geofence' => $geo['status']]);
        $ff->recordSession($user, 'login', $lat, $lng, $accuracy, $geo['status']);
        return $tokens + ['employee' => ['id' => $user->id, 'name' => $user->name, 'employee_code' => $user->employee_code]];
    }

    /**
     * An approved phone moves to whichever eligible employee signs in on it. The previous employee's session ends (below,
     * "superseded"); the phone's approval and policy stay. Recorded in the phone's history and the audit log.
     */
    private function handOver(ManagedDevice $device, User $user): void
    {
        $teamId = $user->teams()->value('teams.id') ?? $device->team_id;
        \Illuminate\Support\Facades\DB::transaction(function () use ($device, $user, $teamId) {
            $from = $device->assigned_user_id;
            \App\Models\DeviceAssignment::query()->where('device_id', $device->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'signed_in_by_other']);
            \App\Models\DeviceAssignment::query()->create(['device_id' => $device->id, 'user_id' => $user->id, 'team_id' => $teamId, 'assigned_by' => $user->id, 'assigned_at' => now()]);
            $device->forceFill(['assigned_user_id' => $user->id, 'team_id' => $teamId])->save();
            \App\Models\DeviceApprovalEvent::query()->create(['device_id' => $device->id, 'action' => 'reassigned', 'actor_user_id' => $user->id, 'meta' => ['employee_id' => $user->id, 'from_employee_id' => $from, 'team_id' => $teamId, 'via' => 'sign_in']]);
            Audit::record('device.handed_over', $device, ['from_employee_id' => $from, 'employee_id' => $user->id]);
        });
        rescue(fn () => app(PolicyService::class)->refreshDevice($device));
    }

    /** Refresh needs the refresh token AND a valid device signature (enforced by route middleware). Rotates both tokens. */
    public function refresh(ManagedDevice $device, string $refresh): array
    {
        if (!OpaqueToken::looksValid($refresh, 'wsr')) {
            throw new DomainException('unauthenticated');
        }
        app(\App\Services\Platform\Licensing::class)->assertUsable();
        $ws = WorkSession::query()->where('refresh_hash', OpaqueToken::hash($refresh))->where('device_id', $device->id)->first();
        if ($ws === null || $ws->ended_at !== null || $ws->refresh_expires_at->isPast() || $ws->epoch !== $device->credential_epoch) {
            throw new DomainException('unauthenticated');
        }
        $user = User::query()->findOrFail($ws->user_id);
        if (!$device->approval_state->permitsWork() || !$user->isActive() || $device->assigned_user_id !== $user->id) {
            $ws->forceFill(['ended_at' => now(), 'end_reason' => 'no_longer_authorised'])->save();
            throw new DomainException('forbidden');
        }
        $ws->forceFill(['ended_at' => now(), 'end_reason' => 'rotated'])->save();
        return $this->issueWorkSession($device, $user);
    }

    /** Ends the employee's work session only. Device approval and management restrictions stay in force. */
    public function logout(WorkSession $ws): void
    {
        $ws->forceFill(['ended_at' => now(), 'end_reason' => 'logout'])->save();
        ManagedDevice::query()->whereKey($ws->device_id)->update(['work_state' => 'signed_out']);
        ActivityEvent::query()->create(['device_id' => $ws->device_id, 'user_id' => $ws->user_id, 'type' => 'signed_out', 'occurred_at' => now()]);
        app(FieldForceIntegration::class)->recordSession(User::query()->findOrFail($ws->user_id), 'logout');
    }

    private function issueWorkSession(ManagedDevice $device, User $user): array
    {
        $a = OpaqueToken::issue('wst');
        $r = OpaqueToken::issue('wsr');
        $lease = (int) data_get($device->compliance_detail, 'offline_access_minutes', 240);
        $ws = WorkSession::query()->create([
            'device_id' => $device->id, 'user_id' => $user->id, 'access_hash' => $a['hash'], 'refresh_hash' => $r['hash'],
            'epoch' => $device->credential_epoch,
            'access_expires_at' => now()->addSeconds((int) config('ammp.sessions.work_access_ttl')),
            'refresh_expires_at' => now()->addSeconds((int) config('ammp.sessions.work_refresh_ttl')),
            'lease_expires_at' => now()->addMinutes($lease),
            'last_validated_at' => now(),
        ]);
        return [
            'access_token' => $a['plain'], 'refresh_token' => $r['plain'],
            'expires_in' => (int) config('ammp.sessions.work_access_ttl'),
            'offline_lease_expires_at' => $ws->lease_expires_at->toAtomString(),
        ];
    }
}
