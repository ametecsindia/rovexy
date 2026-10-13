<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Broadcasting auth: an admin web session (MFA-verified) or a mobile work session. */
final class AuthenticateAnyPrincipal
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->bearerToken() !== null) {
            return app(AuthenticateWorkSession::class)->handle($request, $next);
        }
        $request->setUserResolver(fn () => auth('web')->user());
        return app(EnsureMfaVerified::class)->handle($request, $next);
    }
}
