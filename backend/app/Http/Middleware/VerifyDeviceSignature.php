<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Devices\InstallationId;
use App\Domain\DomainException;
use App\Domain\Security\DevicePublicKey;
use App\Domain\Security\RequestSignature;
use App\Models\ManagedDevice;
use App\Support\RedisNonceStore;
use Closure;
use Illuminate\Http\Request;

/**
 * Proof of possession of the device's Keystore private key. Used for approval polling and for minting device sessions.
 * Knowing an installation ID or device ID is never enough.
 * Parameter "allow_inactive": accept the most recent credential even if revoked (status endpoint only, so a revoked
 * phone can learn it was revoked). Every other use requires the active credential at the current epoch.
 */
final class VerifyDeviceSignature
{
    public function handle(Request $request, Closure $next, string $mode = 'active')
    {
        $installation = InstallationId::assertValid((string) $request->header('X-Installation-Id'));
        $device = ManagedDevice::query()->where('installation_id', $installation)->first()
            ?? throw new DomainException('signature_invalid'); // same answer as a bad signature: no enumeration

        $credential = $mode === 'allow_inactive'
            ? $device->credentials()->latest('id')->first()
            : $device->activeCredential();
        if ($credential === null) {
            throw new DomainException('signature_invalid');
        }

        (new RequestSignature(new RedisNonceStore()))->verify(
            DevicePublicKey::fromPem($credential->public_key_pem),
            $request->getMethod(),
            $request->getRequestUri(),
            (string) $request->header('X-Timestamp'),
            (string) $request->header('X-Nonce'),
            $request->getContent(),
            (string) $request->header('X-Signature'),
            time(),
        );

        $request->attributes->set('device', $device);
        $request->attributes->set('device_credential', $credential);
        return $next($request);
    }
}
