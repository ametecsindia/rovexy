<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DomainException;
use Closure;
use Illuminate\Http\Request;

/** Normal work (customers, calls) is blocked until all mandatory policy controls are applied and verified. */
final class RequireCompliance
{
    public function handle(Request $request, Closure $next)
    {
        $device = $request->attributes->get('device');
        if ($device === null || !$device->workCompliant()) {
            throw new DomainException('device_not_compliant', 'Required device policy is not applied. Open Policy status on the phone.');
        }
        return $next($request);
    }
}
