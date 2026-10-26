<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\AccountLinks;
use App\Services\Platform\PlatformAudit;
use App\Services\Platform\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Company users' own password pages (no sign-in needed):
 *  - /password/{token}: set a password from the welcome e-mail, or reset it from a Forgot-password e-mail.
 *  - /password-forgot: ask for a reset link (always the same answer, so it never tells whether an e-mail exists).
 */
final class PasswordController extends Controller
{
    public function show(string $token)
    {
        $row = AccountLinks::find($token);
        $user = $row ? User::withoutGlobalScopes()->find($row->user_id) : null;
        $company = $row ? Company::query()->find($row->company_id) : null;
        return response()->view('site.password', ['mode' => 'set', 'token' => $token, 'row' => $row, 'user' => $user, 'company' => $company]);
    }

    public function store(Request $r, string $token)
    {
        $row = AccountLinks::find($token);
        if (!$row) {
            return response()->view('site.password', ['mode' => 'set', 'token' => $token, 'row' => null, 'user' => null, 'company' => null], 410);
        }
        $r->validate(['password' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()]],
            ['password.confirmed' => 'The two passwords do not match.']);
        $user = User::withoutGlobalScopes()->findOrFail($row->user_id);
        $company = Company::query()->findOrFail($row->company_id);
        DB::transaction(function () use ($row, $user, $r) {
            $user->forceFill(['password' => $r->input('password')])->save();
            DB::table('account_tokens')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('account_tokens')->where('user_id', $user->id)->whereNull('used_at')->delete();   // any other open link is now useless
        });
        PlatformAudit::record($row->purpose === 'welcome' ? 'company.admin_password_set' : 'company.user_password_reset', $company, ['user' => $user->email], 'link');
        return response()->view('site.password', ['mode' => 'done', 'company' => $company, 'user' => $user, 'console' => Workspaces::consoleUrl($company)]);
    }

    public function forgot(Request $r)
    {
        return response()->view('site.password', ['mode' => 'forgot', 'workspace' => (string) $r->query('workspace', ''), 'sent' => false]);
    }

    public function sendReset(Request $r)
    {
        $d = $r->validate(['workspace' => ['required', 'string', 'max:40', 'regex:' . Workspaces::SLUG], 'email' => 'required|email|max:190'],
            ['workspace.regex' => 'Workspace: the short name in your console address, e.g. delta-collections.']);
        $company = Company::query()->where('slug', strtolower($d['workspace']))->where('status', 'active')->first();
        $user = $company ? User::withoutGlobalScopes()->where('company_id', $company->id)->where('email', strtolower($d['email']))->where('status', 'active')->first() : null;
        if ($company && $user) {
            AccountLinks::sendReset($company, $user);
        }
        PlatformAudit::record('company.password_reset_requested', $company, ['email' => $d['email'], 'found' => (bool) $user], 'website');
        return response()->view('site.password', ['mode' => 'forgot', 'workspace' => $d['workspace'], 'sent' => true]);
    }
}
