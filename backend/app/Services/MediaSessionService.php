<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Domain\Media\MediaSessionState;
use App\Domain\Security\TurnCredentials;
use App\Events\AdminFeed;
use App\Events\DeviceChannelEvent;
use App\Models\ManagedDevice;
use App\Models\MediaSession;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;

/**
 * Employee-visible screen and camera sessions. Every session: authorised requester → employee accepts in-app →
 * OS consent (MediaProjection / camera permission) → visible indicator. No unattended or hidden capture exists.
 */
final class MediaSessionService
{
    public function __construct(private readonly PolicyService $policies, private readonly PushService $push)
    {
    }

    public function request(User $supervisor, ManagedDevice $device, string $kind, string $purpose): MediaSession
    {
        // voice = internet (WebRTC) audio call from the Messages page; anyone allowed to chat may call.
        $perm = match ($kind) { 'screen' => 'media.screen', 'voice' => 'chat.use', default => 'media.camera' };
        if (!$supervisor->hasPermission($perm)) {
            throw new DomainException('forbidden');
        }
        if (!$device->approval_state->permitsWork() || $device->assigned_user_id === null || $device->work_state === 'signed_out') {
            throw new DomainException('device_unavailable', 'The employee must be signed in on an approved device.');
        }
        if ($device->last_contact_at === null || $device->last_contact_at->lt(now()->subMinutes(10))) {
            throw new DomainException('device_offline', 'This phone is offline (not seen in the last 10 minutes). Ask the employee to open the app, then try again.');
        }
        $employee = User::query()->findOrFail($device->assigned_user_id);
        if (!$supervisor->canSeeUser($employee)) {
            throw new DomainException('forbidden', 'Employee is outside your teams.');
        }
        $v = $this->policies->currentSnapshot($device)->values;
        if ($kind === 'screen' && !$v['live_view.enabled']) {
            throw new DomainException('disabled_by_policy', 'Live view is disabled by policy.');
        }
        if ($kind === 'camera' && (!$v['camera_session.enabled'] || $v['device.camera'] === 'blocked')) {
            throw new DomainException('disabled_by_policy', 'Camera sessions are disabled by policy.');
        }
        $purpose = trim($purpose);
        if (mb_strlen($purpose) < 5) {
            throw new DomainException('purpose_required', 'State the purpose of the session.');
        }
        $this->expireStale();
        if ($kind === 'screen' && MediaSession::query()->where('kind', 'screen')->whereIn('state', ['requested', 'accepted', 'active'])->count() >= $v['live_view.max_concurrent']) {
            throw new DomainException('too_many_sessions');
        }
        if (MediaSession::query()->where('device_id', $device->id)->whereIn('state', ['requested', 'accepted', 'active'])->exists()) {
            throw new DomainException('session_exists', 'A session with this device is already open.');
        }
        $s = MediaSession::query()->create([
            'uuid' => (string) Str::uuid(), 'kind' => $kind, 'requested_by' => $supervisor->id, 'device_id' => $device->id,
            'user_id' => $employee->id, 'purpose' => mb_substr($purpose, 0, 255), 'state' => 'requested', 'requested_at' => now(),
            'timeout_minutes' => match ($kind) { 'screen' => $v['live_view.timeout_minutes'], 'voice' => 60, default => $v['camera_session.timeout_minutes'] },
            'options' => match ($kind) {
                'screen' => ['quality' => $v['live_view.quality'], 'fps' => $v['live_view.fps'], 'screenshots' => $v['live_view.screenshots']],
                'voice' => ['audio' => true],
                default => ['quality' => 'medium'],
            },
        ]);
        Audit::record("media.{$kind}.requested", $s, ['device_id' => $device->id, 'employee_id' => $employee->id, 'purpose' => $s->purpose]);
        DeviceChannelEvent::dispatchFor($device, 'media.requested', ['session_id' => $s->uuid]);
        $this->push->ping($device, 'media');
        return $s;
    }

    public function act(MediaSession $s, string $action, ?string $reason = null, ?User $actor = null): MediaSession
    {
        $next = MediaSessionState::transition($s->snapshot(), $action, new \DateTimeImmutable(), $reason);
        $s->forceFill([
            'state' => $next['state'], 'end_reason' => $next['end_reason'] ?? $s->end_reason,
            'accepted_at' => $action === 'accept' ? now() : $s->accepted_at,
            'started_at' => $next['started_at'] ?? $s->started_at,
            'ended_at' => isset($next['ended_at']) ? new \DateTimeImmutable($next['ended_at']) : ($next['state'] === 'expired' ? now() : $s->ended_at),
        ])->save();
        Audit::record("media.{$s->kind}.{$action}", $s, ['state' => $s->state, 'reason' => $s->end_reason, 'by' => $actor?->id]);
        $device = ManagedDevice::query()->findOrFail($s->device_id);
        DeviceChannelEvent::dispatchFor($device, 'media.updated', ['session_id' => $s->uuid, 'state' => $s->state]);
        AdminFeed::dispatchFor($s->company_id, 'media.updated', ['session_id' => $s->uuid, 'state' => $s->state]);
        return $s;
    }

    public function iceServers(MediaSession $s): array
    {
        $cfg = config('ammp.media');
        $servers = [];
        if ($cfg['stun_urls']) {
            $servers[] = ['urls' => array_values($cfg['stun_urls'])];
        }
        if ($cfg['turn_urls'] && $cfg['turn_secret']) {
            $c = TurnCredentials::issue($cfg['turn_secret'], $s->company_id, $s->uuid, time(), 600);
            $servers[] = ['urls' => array_values($cfg['turn_urls']), 'username' => $c['username'], 'credential' => $c['credential']];
        }
        return $servers;
    }

    public function endAllForDevice(ManagedDevice $device, string $reason): void
    {
        MediaSession::query()->where('device_id', $device->id)->whereIn('state', ['requested', 'accepted', 'active'])
            ->each(fn (MediaSession $s) => $this->act($s, 'end', $reason));
    }

    public function expireStale(): void
    {
        MediaSession::query()->whereIn('state', ['requested', 'accepted', 'active'])->each(function (MediaSession $s) {
            $n = MediaSessionState::expireIfDue($s->snapshot(), new \DateTimeImmutable());
            if ($n['state'] !== $s->state) {
                $s->forceFill(['state' => $n['state'], 'end_reason' => $n['end_reason'], 'ended_at' => now()])->save();
                Audit::record("media.{$s->kind}.{$n['state']}", $s, ['reason' => $n['end_reason']]);
                DeviceChannelEvent::dispatchFor(ManagedDevice::query()->findOrFail($s->device_id), 'media.updated', ['session_id' => $s->uuid, 'state' => $s->state]);
            }
        });
    }
}
