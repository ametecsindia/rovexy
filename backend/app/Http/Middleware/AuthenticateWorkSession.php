<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DomainException;
use App\Domain\Security\OpaqueToken;
use App\Models\ManagedDevice;
use App\Models\User;
use App\Models\WorkSession;
use Closure;
use Illuminate\Http\Request;

/**
 * Every protected mobile request passes, in order: company (ResolveCompany) → work-session token → device approval
 * and credential epoch → employee active → employee/device assignment → (route) permission → (route) compliance.
 * All checks read current DB state, so revocation takes effect on the very next request.
 */
final class AuthenticateWorkSession
{
    public function handle(Request $request, Closure $next, ?string $permission = null)
    {
        $token = (string) $request->bearerToken();
        if (!OpaqueToken::looksValid($token, 'wst')) {
            throw new DomainException('unauthenticated');
        }
        $ws = WorkSession::query()->where('access_hash', OpaqueToken::hash($token))->first();
        if ($ws === null || $ws->ended_at !== null || $ws->access_expires_at->isPast()) {
            throw new DomainException('unauthenticated');
        }
        /** @var ManagedDevice $device */
        $device = ManagedDevice::query()->findOrFail($ws->device_id);
        if (!$device->approval_state->permitsWork()) {
            throw new DomainException($device->approval_state->value === 'revoked' ? 'device_revoked' : 'device_not_approved');
        }
        if ($ws->epoch !== $device->credential_epoch) {
            throw new DomainException('unauthenticated');
        }
        /** @var User $user */
        $user = User::query()->findOrFail($ws->user_id);
        if (!$user->isActive()) {
            throw new DomainException('forbidden', 'Employee account is not active.');
        }
        if ($device->assigned_user_id !== $user->id) {
            throw new DomainException('not_assigned', 'This device is not assigned to this employee.');
        }
        if (!$user->hasPermission('mobile.work') || ($permission !== null && !$user->hasPermission($permission))) {
            throw new DomainException('forbidden', 'Permission denied.');
        }

        $request->attributes->set('device', $device);
        $request->attributes->set('work_session', $ws);
        $request->setUserResolver(fn () => $user);

        // The lease that applied BEFORE this request: offline-queued events are judged against it (SyncController).
        $request->attributes->set('lease_before', $ws->lease_expires_at);
        $lease = (int) data_get($device->compliance_detail, 'offline_access_minutes', 240);
        if ($ws->last_validated_at->lt(now()->subSeconds(60))) {
            $ws->forceFill(['last_validated_at' => now(), 'lease_expires_at' => now()->addMinutes($lease)])->save();
        }
        DeviceTouch::touch($device, $request);

        $response = $next($request);
        $response->headers->set('X-Offline-Lease-Expires', $ws->lease_expires_at->toAtomString());
        return $response;
    }
}
