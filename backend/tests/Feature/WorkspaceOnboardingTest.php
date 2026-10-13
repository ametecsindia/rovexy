<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\AccountLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** New client workspace: automatic workspace name / sub-domain, welcome e-mail with a set-password link, forgot & change password. */
final class WorkspaceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function super()
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => 'super@ametecs.test'], ['name' => 'Super', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function createCompany(array $over = [])
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        return $this->super()->postJson('/api/super/companies', $over + ['name' => 'Delta Collections Pvt. Ltd.', 'admin_name' => 'Kiran', 'admin_email' => 'Kiran@Delta.test',
            'plan_id' => $plan->id, 'kind' => 'subscription']);
    }

    private function tokenFrom(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }

    public function test_workspace_name_is_made_automatically_and_unique(): void
    {
        $first = $this->createCompany()->assertCreated()->assertJsonPath('slug', 'delta-collections-pvt-ltd')->assertJsonPath('email_sent', true)->json();
        $this->assertStringEndsWith('/w/delta-collections-pvt-ltd/admin', $first['console_url']);
        $this->createCompany(['admin_email' => 'b@delta.test'])->assertCreated()->assertJsonPath('slug', 'delta-collections-pvt-ltd-2');
        $this->createCompany(['name' => 'Admin', 'admin_email' => 'c@x.test'])->assertCreated()->assertJsonPath('slug', 'admin-team');     // reserved name
        config(['ammp.tenancy.base_domain' => 'rovexy.in']);
        $this->createCompany(['name' => 'Orbit', 'admin_email' => 'o@orbit.test'])->assertCreated()->assertJsonPath('console_url', 'https://orbit.rovexy.in/admin');
        // the path address serves the console and the API finds the company from the workspace header
        $this->get('/w/delta-collections-pvt-ltd/admin')->assertOk();
    }

    public function test_welcome_link_sets_the_password_once(): void
    {
        $r = $this->createCompany()->json();
        $company = Company::query()->where('slug', $r['slug'])->first();
        $admin = User::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_code', 'admin')->first();
        $this->assertSame('kiran@delta.test', $admin->email);
        $token = $this->tokenFrom($r['set_password_url']);
        $this->assertSame(1, DB::table('account_tokens')->where('user_id', $admin->id)->where('purpose', 'welcome')->count());
        $this->assertFalse(DB::table('account_tokens')->where('token_hash', $token)->exists());     // only the hash is stored

        $this->get("/password/{$token}")->assertOk()->assertSee('Set your password')->assertSee('delta-collections-pvt-ltd');
        $this->post("/password/{$token}", ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post("/password/{$token}", ['password' => 'Kiran-password-9', 'password_confirmation' => 'Kiran-password-9'])->assertOk()->assertSee('Password saved');
        $this->assertTrue(Hash::check('Kiran-password-9', $admin->fresh()->password));
        $this->get("/password/{$token}")->assertOk()->assertSee('This link no longer works');                              // works once
        $this->post("/password/{$token}", ['password' => 'Other-password-9', 'password_confirmation' => 'Other-password-9'])->assertStatus(410);
        // the admin can now sign in to their console
        $this->withHeaders(['X-Workspace' => $company->slug, 'Referer' => 'http://localhost/'])
            ->postJson('/api/admin/auth/login', ['email' => 'kiran@delta.test', 'password' => 'Kiran-password-9'])->assertOk();
    }

    public function test_expired_link_and_resend_from_super_admin(): void
    {
        $r = $this->createCompany()->json();
        $company = Company::query()->where('slug', $r['slug'])->first();
        DB::table('account_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->get('/password/' . $this->tokenFrom($r['set_password_url']))->assertSee('This link no longer works');
        $again = $this->super()->postJson("/api/super/companies/{$company->id}/invite")->assertOk()->assertJsonPath('email', 'kiran@delta.test')->json();
        $this->get('/password/' . $this->tokenFrom($again['set_password_url']))->assertSee('Set your password');
        $this->assertSame(1, DB::table('account_tokens')->whereNull('used_at')->where('expires_at', '>', now())->count());
    }

    public function test_forgot_password_and_change_own_password(): void
    {
        $r = $this->createCompany(['admin_password' => 'Typed-password-1', 'send_email' => false])->assertJsonPath('email_sent', false)->json();
        $company = Company::query()->where('slug', $r['slug'])->first();
        $this->get('/password-forgot?workspace=' . $company->slug)->assertOk()->assertSee('Forgot your password?');
        // same answer whether or not the e-mail exists
        $this->post('/password-forgot', ['workspace' => $company->slug, 'email' => 'nobody@delta.test'])->assertOk()->assertSee('If <b>nobody@delta.test</b>', false);
        $this->assertSame(0, DB::table('account_tokens')->count());
        $this->post('/password-forgot', ['workspace' => $company->slug, 'email' => 'KIRAN@delta.test'])->assertOk();
        $this->assertSame(1, DB::table('account_tokens')->where('purpose', 'reset')->count());

        $admin = User::withoutGlobalScopes()->where('company_id', $company->id)->where('employee_code', 'admin')->first();
        $link = AccountLinks::issue($admin, 'reset');
        $this->post('/password/' . $this->tokenFrom($link), ['password' => 'Reset-password-2', 'password_confirmation' => 'Reset-password-2'])->assertOk();
        $this->assertTrue(Hash::check('Reset-password-2', $admin->fresh()->password));

        // change own password inside the console
        $console = $this->actingAs($admin->fresh(), 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => $company->slug, 'Referer' => 'http://localhost/']);
        $console->putJson('/api/admin/auth/password', ['current_password' => 'wrong', 'password' => 'Brand-new-pass-3', 'password_confirmation' => 'Brand-new-pass-3'])->assertStatus(422);
        $console->putJson('/api/admin/auth/password', ['current_password' => 'Reset-password-2', 'password' => 'Brand-new-pass-3', 'password_confirmation' => 'Brand-new-pass-3'])->assertOk();
        $this->assertTrue(Hash::check('Brand-new-pass-3', $admin->fresh()->password));
    }

    public function test_each_workspace_gets_its_own_logo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('company_files');
        $urls = [];
        foreach (['one-co' => 'LOGO-ONE', 'two-co' => 'LOGO-TWO'] as $slug => $bytes) {
            $c = \App\Models\Company::query()->create(['slug' => $slug, 'name' => $slug, 'timezone' => 'UTC', 'default_country' => 'IN']);
            \Illuminate\Support\Facades\Storage::disk('company_files')->put("companies/{$c->id}/branding/logo.png", $bytes);
            $b = app(\App\Support\CurrentCompany::class)->run($c, fn () => \App\Models\CompanyBranding::query()->create(['display_name' => $slug, 'logo_key' => "companies/{$c->id}/branding/logo.png",
                'logo_mime' => 'image/png', 'primary_color' => '#000000', 'accent_color' => '#ffffff', 'version' => 1]));
            $urls[$slug] = $b->logoUrl();
        }
        $this->assertNotSame($urls['one-co'], $urls['two-co']);                                   // same version number, different link → no cross-company cache
        foreach (['one-co' => 'LOGO-ONE', 'two-co' => 'LOGO-TWO'] as $slug => $bytes) {
            $this->assertStringContainsString("/branding/{$slug}/logo", $urls[$slug]);
            $this->assertSame($bytes, $this->get(parse_url($urls[$slug], PHP_URL_PATH))->assertOk()->streamedContent());   // no X-Workspace header, like an <img>
        }
        $this->get('/api/mobile/v1/branding/no-such-co/logo')->assertNotFound();
    }
}
