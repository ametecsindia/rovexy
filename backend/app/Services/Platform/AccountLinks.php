<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Set your password" (new workspace, 72 h) and "Reset your password" (Forgot password, 60 min) links for company users.
 * The link carries a random 48-character token; only its SHA-256 is stored. Issuing a new link cancels older unused ones
 * of the same kind. A link works once.
 */
final class AccountLinks
{
    public const HOURS = ['welcome' => 72, 'reset' => 1];

    public static function issue(User $u, string $purpose): string
    {
        $token = Str::random(48);
        DB::transaction(function () use ($u, $purpose, $token) {
            DB::table('account_tokens')->where('user_id', $u->id)->where('purpose', $purpose)->whereNull('used_at')->delete();
            DB::table('account_tokens')->insert(['company_id' => $u->company_id, 'user_id' => $u->id, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::HOURS[$purpose]), 'created_at' => now()]);
        });
        return url('/password/' . $token);
    }

    /** The live token row (not used, not expired), or null. */
    public static function find(string $token): ?object
    {
        if (!preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }
        return DB::table('account_tokens')->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    /** Welcome e-mail for the main admin of a new workspace: web address, workspace, sign-in e-mail, set-password link. */
    public static function sendWelcome(Company $c, User $admin): string
    {
        $link = self::issue($admin, 'welcome');
        $console = Workspaces::consoleUrl($c);
        Sales::send($admin->email, 'Your ROVEXY workspace is ready — set your password', self::frame(
            '<p>Dear ' . e($admin->name) . ',</p><p>Your ROVEXY workspace for <b>' . e($c->name) . '</b> is ready.</p>'
            . '<table style="border-collapse:collapse;margin:14px 0">'
            . '<tr><td style="padding:4px 14px 4px 0;color:#5d6b79">Console</td><td><a href="' . e($console) . '">' . e($console) . '</a></td></tr>'
            . '<tr><td style="padding:4px 14px 4px 0;color:#5d6b79">Workspace</td><td><b>' . e($c->slug) . '</b></td></tr>'
            . '<tr><td style="padding:4px 14px 4px 0;color:#5d6b79">Sign-in e-mail</td><td>' . e($admin->email) . '</td></tr></table>'
            . '<p>First, choose your own password:</p>' . self::button($link, 'Set my password')
            . '<p style="color:#5d6b79;font-size:13px">This link works once and expires in ' . self::HOURS['welcome'] . ' hours. If it expires, use “Forgot password?” on the sign-in page.</p>'));
        return $link;
    }

    public static function sendReset(Company $c, User $u): string
    {
        $link = self::issue($u, 'reset');
        Sales::send($u->email, 'Reset your ROVEXY password', self::frame(
            '<p>Dear ' . e($u->name) . ',</p><p>We received a request to reset the password for <b>' . e($u->email) . '</b> in the <b>' . e($c->name) . '</b> workspace.</p>'
            . self::button($link, 'Reset my password')
            . '<p style="color:#5d6b79;font-size:13px">This link works once and expires in 60 minutes. If you did not ask for this, ignore this e-mail: your password stays the same.</p>'));
        return $link;
    }

    private static function button(string $href, string $label): string
    {
        return '<p style="margin:18px 0"><a href="' . e($href) . '" style="background:#0A2F6B;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px;display:inline-block">'
            . e($label) . '</a></p><p style="color:#5d6b79;font-size:12px">Or open: ' . e($href) . '</p>';
    }

    private static function frame(string $body): string
    {
        return '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0f2238;max-width:560px">'
            . '<div style="border-bottom:3px solid #072654;padding-bottom:10px;margin-bottom:4px"><img src="' . e(url('/brand/rovexy-logo.png')) . '" alt="ROVEXY" height="40" style="height:40px"></div>'
            . '<div style="height:2px;background:#C89B2D;margin-bottom:16px"></div>' . $body
            . '<p style="color:#8a96a3;font-size:12px;margin-top:24px">— Ametecs India · ROVEXY</p></div>';
    }
}
