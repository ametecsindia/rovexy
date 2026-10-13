<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DomainException;
use App\Domain\Security\OpaqueToken;
use App\Models\DeviceSession;
use App\Models\ManagedDevice;
use Closure;
use Illuminate\Http\Request;

/** Device-level bearer (pre-employee-login): branding, policy download/ack, push registration, employee login. */
final class AuthenticateDeviceSession
{
    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->bearerToken();
        if (!OpaqueToken::looksValid($token, 'dst')) {
            throw new DomainException('unauthenticated');
        }
        $session = DeviceSession::query()->where('token_hash', OpaqueToken::hash($token))->first();
        if ($session === null || $session->expires_at->isPast()) {
            throw new DomainException('unauthenticated');
        }
        /** @var ManagedDevice $device */
        $device = ManagedDevice::query()->findOrFail($session->device_id);
        if (!$device->approval_state->permitsWork()) {
            throw new DomainException($device->approval_state->value === 'revoked' ? 'device_revoked' : 'device_not_approved');
        }
        if ($session->epoch !== $device->credential_epoch) {
            throw new DomainException('unauthenticated'); // superseded by re-approval/revocation
        }
        $request->attributes->set('device', $device);
        DeviceTouch::touch($device, $request);
        return $next($request);
    }
}
