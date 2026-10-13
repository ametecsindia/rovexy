<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Platform\Licensing;
use Closure;
use Illuminate\Http\Request;

/** `feature:<key>` — the company's ROVEXY plan must include the feature (Licensing::FEATURES). */
final class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        if (!app(Licensing::class)->has($feature)) {
            return response()->json(['error' => 'feature_not_in_plan', 'message' => 'This feature is not part of your ROVEXY plan (' . (Licensing::FEATURES[$feature] ?? $feature) . '). Upgrade under Administration → Plan & licence.'], 403);
        }
        return $next($request);
    }
}
