<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Super Admin / Accounts access: `platform:<module>`. Signed-in, active staff only; GET needs `view` on the module,
 * anything else `manage`. Super is `manage` everywhere. The same matrix drives both consoles' menus (GET /api/super/auth/me).
 *
 * Session rules (set by Platform\AuthController at sign-in):
 *  - `platform_mfa_pending`: password was right but the 2-step code isn't done yet → nothing opens until it is.
 *  - `platform_pw`: fingerprint of the password hash at sign-in. If the password is changed (by the user, or reset by a
 *    super admin) every other session of that user is signed out on its next request.
 */
final class PlatformAccess
{
    public const MODULES = ['dashboard' => 'Dashboard', 'companies' => 'Companies', 'licences' => 'Licences', 'orders' => 'Orders & payments',
        'leads' => 'Leads', 'plans' => 'Plans & pricing', 'audit' => 'Audit log', 'admins' => 'Staff users', 'settings' => 'Settings', 'accounts' => 'Accounts'];

    public const ROLES = ['super' => 'Super admin', 'sales' => 'Sales', 'support' => 'Support', 'accountant' => 'Accountant'];

    /** role → module → none|view|manage (ponytail: fixed in code; make it editable if custom staff roles are ever needed). */
    public const MATRIX = [
        'sales' => ['dashboard' => 'view', 'companies' => 'manage', 'licences' => 'manage', 'orders' => 'manage', 'leads' => 'manage', 'plans' => 'view', 'audit' => 'view'],
        'support' => ['dashboard' => 'view', 'companies' => 'view', 'licences' => 'view', 'orders' => 'view', 'leads' => 'view', 'plans' => 'view', 'audit' => 'view'],
        // Accountants see the books and nothing else: no companies, licences, staff or system settings.
        'accountant' => ['accounts' => 'manage'],
    ];

    public static function level(string $role, string $module): string
    {
        return $role === 'super' ? 'manage' : (self::MATRIX[$role][$module] ?? 'none');
    }

    public static function fingerprint(string $hash): string
    {
        return substr(hash('sha256', $hash), 0, 24);
    }

    public function handle(Request $request, Closure $next, ?string $module = null)
    {
        $admin = auth('platform')->user();
        if (!$admin || $admin->status !== 'active') {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Please sign in.'], 401);
        }
        $session = $request->hasSession() ? $request->session() : null;
        if ($session?->get('platform_mfa_pending')) {
            return response()->json(['error' => 'mfa_required', 'message' => 'Enter your 2-step code to continue.'], 401);
        }
        if ($session && $session->has('platform_pw') && $session->get('platform_pw') !== self::fingerprint((string) $admin->password)) {
            Auth::guard('platform')->logout();
            $session->invalidate();
            return response()->json(['error' => 'unauthenticated', 'message' => 'Your password was changed. Please sign in again.'], 401);
        }
        if ($module !== null) {
            $need = $request->isMethodSafe() ? ['view', 'manage'] : ['manage'];
            if (!in_array(self::level($admin->role, $module), $need, true)) {
                $name = self::MODULES[$module] ?? $module;
                return response()->json(['error' => 'forbidden', 'message' => "Your role does not have permission to " . ($request->isMethodSafe() ? 'see' : 'make changes in') . " '{$name}'. Ask a super admin if you need it."], 403);
            }
        }
        // Super Admin routes act as the staff user: rate limits (throttle:N,M) call $request->user(), which would otherwise
        // read the company-console login in the same browser session and crash (tenant-scoped User, no company bound).
        Auth::shouldUse('platform');
        return $next($request);
    }
}
