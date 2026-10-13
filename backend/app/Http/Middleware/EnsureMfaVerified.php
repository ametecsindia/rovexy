<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Administrator routes require a TOTP-verified session and an absolute session age limit. */
final class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $verifiedAt = (int) $request->session()->get('mfa_verified_at', 0);
        $startedAt = (int) $request->session()->get('auth_started_at', 0);
        $absolute = (int) config('ammp.sessions.admin_absolute_hours', 12) * 3600;
        $mfaRequired = (bool) config('ammp.sessions.admin_mfa_required');
        if ($user === null || ($mfaRequired && $user->mfa_enabled_at === null) || $verifiedAt === 0) {
            return response()->json(['error' => 'mfa_required'], 403);
        }
        if ($startedAt === 0 || time() - $startedAt > $absolute) {
            auth('web')->logout();
            $request->session()->invalidate();
            return response()->json(['error' => 'session_expired'], 401);
        }
        if ($user->company_id !== app(\App\Support\CurrentCompany::class)->id() || !$user->isActive()) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        return $next($request);
    }
}
