<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ManagedDevice;
use Illuminate\Http\Request;

/** Throttled last-contact bookkeeping (at most one write per device per 30 s). */
final class DeviceTouch
{
    public static function touch(ManagedDevice $device, Request $request): void
    {
        if ($device->last_contact_at === null || $device->last_contact_at->lt(now()->subSeconds(30)) || $device->presence !== 'online') {
            $device->forceFill(['last_contact_at' => now(), 'last_ip' => $request->ip(), 'presence' => 'online'])->saveQuietly();
        }
    }
}
