<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Admin sign-in: email + password → TOTP (mandatory; first sign-in enrols it). Cookie session, CSRF-protected,
 * 30-min idle / 12-h absolute expiry. Only users holding an admin-type role can sign in here.
 */
final class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        $user = User::query()->where('email', strtolower($data['email']))->first();
        $ok = Hash::check($data['password'], $user?->password ?? '$2y$12$toXeR8UVhISFlgJi7YZfQ.yBvmaMJCYf2mhgfvO19HZEYqRmZ4dWm');
        if (!$user || !$ok || !$user->isActive() || !$user->isAdminUser()) {
            Audit::record('admin.login_failed', null, ['email' => $data['email']], ['type' => 'system', 'id' => null]);
            return response()->json(['error' => 'invalid_credentials'], 422);
        }
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        $r->session()->put('auth_started_at', time());
        $r->session()->forget('mfa_verified_at');
        Audit::record('admin.password_ok', $user);
        if (!config('ammp.sessions.admin_mfa_required')) {
            $r->session()->put('mfa_verified_at', time());   // password-only sign-in (config switch)
            Audit::record('admin.login', $user, ['mfa' => 'disabled']);
            return response()->json(['mfa' => 'none']);
        }
        return response()->json(['mfa' => $user->mfa_enabled_at ? 'verify' : 'enrol']);
    }

    /**
     * Sign-in on rovexy.app with no workspace in the address (e.g. the Admin app with the workspace box left empty):
     * the right email + password tells which workspace it is. Only answers for a correct password, so it never
     * reveals which emails exist. Signs nobody in — the console then signs in normally inside that workspace.
     */
    public function findWorkspace(Request $r)
    {
        $data = $r->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        $ctx = app(\App\Support\CurrentCompany::class);
        $users = $ctx->withoutScope(fn () => User::query()->where('email', strtolower($data['email']))->get());
        $slugs = [];
        foreach ($users as $u) {
            $c = \App\Models\Company::query()->whereKey($u->company_id)->where('status', 'active')->first();
            if ($c && Hash::check($data['password'], (string) $u->password) && $ctx->run($c, fn () => $u->isActive() && $u->isAdminUser())) {
                $slugs[] = $c->slug;
            }
        }
        if (count($slugs) !== 1) {
            Hash::check($data['password'], '$2y$12$toXeR8UVhISFlgJi7YZfQ.yBvmaMJCYf2mhgfvO19HZEYqRmZ4dWm');   // same timing either way
            Audit::record('admin.login_failed', null, ['email' => $data['email'], 'workspace' => 'none'], ['type' => 'system', 'id' => null]);
            return $slugs
                ? response()->json(['error' => 'workspace_needed', 'message' => 'This email works in more than one company. Enter the company workspace.'], 422)
                : response()->json(['error' => 'invalid_credentials'], 422);
        }
        return response()->json(['workspace' => $slugs[0]]);
    }

    public function mfaSetup(Request $r)
    {
        $user = $r->user();
        if ($user->mfa_enabled_at !== null) {
            return response()->json(['error' => 'already_enrolled'], 409);
        }
        $secret = Totp::generateSecret();
        $r->session()->put('mfa_pending_secret', $secret);
        return response()->json(['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($secret, $user->email, (string) config('ammp.product_name'))]);
    }

    public function mfaVerify(Request $r)
    {
        $data = $r->validate(['code' => 'required|digits:6']);
        $user = $r->user();
        $secret = $user->mfa_enabled_at ? $user->totp_secret : $r->session()->get('mfa_pending_secret');
        $step = $secret ? Totp::verify($secret, $data['code'], time(), $user->totp_last_step) : null;
        if ($step === null) {
            Audit::record('admin.mfa_failed', $user);
            return response()->json(['error' => 'invalid_code'], 422);
        }
        $fill = ['totp_last_step' => $step];
        if ($user->mfa_enabled_at === null) {
            $fill += ['totp_secret' => $secret, 'mfa_enabled_at' => now()];
            $r->session()->forget('mfa_pending_secret');
            Audit::record('admin.mfa_enrolled', $user);
        }
        $user->forceFill($fill)->save();
        $r->session()->regenerate();
        $r->session()->put('mfa_verified_at', time());
        Audit::record('admin.login', $user);
        return $this->me($r);
    }

    public function me(Request $r)
    {
        $u = $r->user();
        return response()->json([
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'mfa_verified' => (bool) $r->session()->get('mfa_verified_at'),
            'permissions' => $u->permissionKeys(),
            'roles' => $u->roles()->pluck('key'),
            'company' => ['id' => $u->company->id, 'slug' => $u->company->slug, 'name' => $u->company->name, 'timezone' => $u->company->timezone],
            'product_name' => config('ammp.product_name'),
            'realtime' => config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? [
                'key' => config('broadcasting.connections.reverb.key'), 'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'), 'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ] : null,
        ]);
    }

    /** Change your own password (company console). */
    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|string|max:200', 'password' => ['required', 'string', 'max:200', 'confirmed', 'different:current_password',
            \Illuminate\Validation\Rules\Password::defaults()]], ['password.different' => 'The new password must be different from the current one.']);
        $u = $r->user();
        if (!Hash::check($d['current_password'], $u->password)) {
            Audit::record('admin.password_change_failed', $u);
            return response()->json(['error' => 'invalid_password', 'message' => 'Your current password is incorrect.', 'errors' => ['current_password' => ['Your current password is incorrect.']]], 422);
        }
        $u->forceFill(['password' => $d['password']])->save();
        $r->session()->regenerate();
        Audit::record('admin.password_changed', $u);
        return response()->json(['ok' => true]);
    }

        public function logout(Request $r)
    {
        Audit::record('admin.logout', $r->user());
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return response()->json(['ok' => true]);
    }
}
