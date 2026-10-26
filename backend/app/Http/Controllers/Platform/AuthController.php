<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use App\Http\Middleware\PlatformAccess;
use App\Models\Platform\PlatformAdmin;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Staff sign-in for both Ametecs consoles: /super (super, sales, support) and /accounts (accountant, and super).
 * Email + password → 2-step code when the account has it switched on (or Settings make it compulsory; first sign-in then
 * enrols). Cookie session, rate-limited, every attempt audited. The portal decides which accounts may sign in where.
 */
final class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200', 'portal' => 'nullable|in:staff,accounts']);
        $portal = $d['portal'] ?? 'staff';
        $a = PlatformAdmin::query()->where('email', strtolower($d['email']))->first();
        $ok = Hash::check($d['password'], $a?->password ?? '$2y$12$toXeR8UVhISFlgJi7YZfQ.yBvmaMJCYf2mhgfvO19HZEYqRmZ4dWm');
        if (!$a || !$ok || $a->status !== 'active') {
            PlatformAudit::record('super.login_failed', null, ['email' => $d['email'], 'portal' => $portal]);
            return response()->json(['error' => 'invalid_credentials', 'message' => 'Email or password is incorrect.'], 422);
        }
        if ($portal === 'staff' && $a->role === 'accountant') {
            PlatformAudit::record('super.login_wrong_portal', $a, ['portal' => $portal]);
            return response()->json(['error' => 'wrong_portal', 'message' => 'This is an Accountant account. Use the Accountant sign-in.', 'portal' => 'accounts'], 422);
        }
        if ($portal === 'accounts' && !in_array($a->role, ['accountant', 'super'], true)) {
            PlatformAudit::record('super.login_wrong_portal', $a, ['portal' => $portal]);
            return response()->json(['error' => 'wrong_portal', 'message' => 'This account cannot open Accounts. Use the Super Admin sign-in.', 'portal' => 'staff'], 422);
        }
        Auth::guard('platform')->login($a);
        $r->session()->regenerate();
        $r->session()->put('platform_pw', PlatformAccess::fingerprint((string) $a->password));
        $r->session()->put('platform_portal', $portal);
        $mfa = !self::mfaOn() ? 'none' : ($a->mfa_enabled_at ? 'verify' : (self::mfaRequired() ? 'enrol' : 'none'));
        if ($mfa !== 'none') {
            $r->session()->put('platform_mfa_pending', $mfa);
            PlatformAudit::record('super.password_ok', $a, ['portal' => $portal]);
            return response()->json(['mfa' => $mfa]);
        }
        return $this->signedIn($r, $a);
    }

    /** Authenticator codes at all (one server switch for every console: ADMIN_MFA_REQUIRED, default off). */
    private static function mfaOn(): bool
    {
        return (bool) config('ammp.sessions.admin_mfa_required');
    }

    private static function mfaRequired(): bool
    {
        return self::mfaOn() && PlatformSettings::get('staff_mfa_required') === '1';
    }

    private function signedIn(Request $r, PlatformAdmin $a)
    {
        $a->forceFill(['last_login_at' => now()])->save();
        PlatformAudit::record('super.login', $a, ['portal' => $r->session()->get('platform_portal'), 'mfa' => (bool) $a->mfa_enabled_at]);
        return $this->me($r);
    }

    /** New authenticator secret: during a compulsory first sign-in, or from Profile to switch 2-step on. */
    public function mfaSetup(Request $r)
    {
        $a = auth('platform')->user();
        if (!$a || $a->status !== 'active') {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Please sign in.'], 401);
        }
        if ($a->mfa_enabled_at) {
            return response()->json(['error' => 'already_enrolled', 'message' => '2-step sign-in is already on for this account.'], 409);
        }
        $secret = Totp::generateSecret();
        $r->session()->put('platform_mfa_secret', $secret);
        return response()->json(['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($secret, $a->email, 'ROVEXY Staff')]);
    }

    public function mfaVerify(Request $r)
    {
        $d = $r->validate(['code' => 'required|digits:6']);
        $a = auth('platform')->user();
        if (!$a || $a->status !== 'active') {
            return response()->json(['error' => 'unauthenticated', 'message' => 'Please sign in.'], 401);
        }
        $secret = $a->mfa_enabled_at ? $a->totp_secret : $r->session()->get('platform_mfa_secret');
        $step = $secret ? Totp::verify($secret, $d['code'], time(), $a->totp_last_step) : null;
        if ($step === null) {
            PlatformAudit::record('super.mfa_failed', $a);
            return response()->json(['error' => 'invalid_code', 'message' => 'That code is not valid. Check the time on your phone and try the newest code.'], 422);
        }
        $fill = ['totp_last_step' => $step];
        if (!$a->mfa_enabled_at) {
            $fill += ['totp_secret' => $secret, 'mfa_enabled_at' => now()];
            PlatformAudit::record('super.mfa_enrolled', $a);
        }
        $a->forceFill($fill)->save();
        $r->session()->forget(['platform_mfa_secret']);
        if ($r->session()->pull('platform_mfa_pending')) {
            $r->session()->regenerate();
            return $this->signedIn($r, $a);
        }
        return $this->me($r);
    }

    /** Switch your own 2-step sign-in off (password needed). Not allowed while Settings make it compulsory. */
    public function mfaDisable(Request $r)
    {
        $d = $r->validate(['password' => 'required|string|max:200']);
        $a = auth('platform')->user();
        if (!Hash::check($d['password'], $a->password)) {
            return response()->json(['error' => 'invalid_password', 'message' => 'Your current password is incorrect.'], 422);
        }
        if (self::mfaRequired()) {
            return response()->json(['error' => 'mfa_required', 'message' => '2-step sign-in is compulsory for all staff (Settings).'], 422);
        }
        $a->forceFill(['totp_secret' => null, 'totp_last_step' => null, 'mfa_enabled_at' => null])->save();
        PlatformAudit::record('super.mfa_disabled', $a);
        return $this->me($r);
    }

    public function me(Request $r)
    {
        $a = auth('platform')->user();
        if (!$a || $a->status !== 'active') {
            if ($a) {
                Auth::guard('platform')->logout();
            }
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        if ($pending = $r->session()->get('platform_mfa_pending')) {
            return response()->json(['mfa_required' => $pending, 'name' => $a->name, 'email' => $a->email]);
        }
        return response()->json(['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone, 'role' => $a->role,
            'role_label' => PlatformAccess::ROLES[$a->role] ?? $a->role, 'mfa_enabled' => self::mfaOn() && (bool) $a->mfa_enabled_at, 'mfa_compulsory' => self::mfaRequired(), 'mfa_available' => self::mfaOn(),
            'last_login_at' => $a->last_login_at,
            'access' => collect(PlatformAccess::MODULES)->keys()->mapWithKeys(fn ($m) => [$m => PlatformAccess::level($a->role, $m)]),
        ]);
    }

    /** Your own name and phone (e-mail and role are changed by a super admin under Staff users). */
    public function profile(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/']],
            ['phone.regex' => 'Phone: digits, spaces, + and - only.']);
        $a = auth('platform')->user();
        $a->forceFill(['name' => trim($d['name']), 'phone' => $d['phone'] ?? null])->save();
        PlatformAudit::record('staff.profile_updated', $a);
        return $this->me($r);
    }

    /** Change your own password. Every other signed-in session of yours is signed out on its next request. */
    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|string|max:200', 'password' => ['required', 'string', 'max:200', 'confirmed', 'different:current_password', Password::defaults()]],
            ['password.different' => 'The new password must be different from the current one.']);
        $a = auth('platform')->user();
        if (!Hash::check($d['current_password'], $a->password)) {
            PlatformAudit::record('staff.password_change_failed', $a);
            return response()->json(['error' => 'invalid_password', 'message' => 'Your current password is incorrect.', 'errors' => ['current_password' => ['Your current password is incorrect.']]], 422);
        }
        $a->forceFill(['password' => $d['password']])->save();
        $r->session()->regenerate();
        $r->session()->put('platform_pw', PlatformAccess::fingerprint((string) $a->fresh()->password));
        PlatformAudit::record('staff.password_changed', $a);
        return response()->json(['ok' => true]);
    }

    public function logout(Request $r)
    {
        PlatformAudit::record('super.logout');
        Auth::guard('platform')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return response()->json(['ok' => true]);
    }
}
