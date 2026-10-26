<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Site\SiteController;
use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** E-mail code before trial / buy, one password rule, trial duration setting, authenticator switch (27 Oct 2026). */
final class SignUpSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $trial = ['company' => 'Zeta Recoveries', 'workspace' => 'zeta', 'name' => 'Zara', 'phone' => '9833333333', 'email' => 'z@zeta.test',
        'password' => 'Zeta1234', 'password_confirmation' => 'Zeta1234', 'terms' => '1'];

    private function token(string $html): string
    {
        preg_match('/name="token" value="([a-f0-9]{32})"/', $html, $m);
        $this->assertNotEmpty($m);
        return $m[1];
    }

    public function test_trial_needs_the_email_code_resend_replaces_it_and_wrong_codes_are_limited(): void
    {
        $t = $this->token($this->post('/trial', $this->trial)->assertOk()->assertSee('Check your e-mail')->assertSee('Send a new code')->getContent());
        $first = $this->lastEmailCode();
        $this->assertSame(0, Company::query()->where('slug', 'zeta')->count());                       // nothing before the code
        $this->post('/verify-email/resend', ['token' => $t])->assertSee('Please wait');                  // too soon
        $this->travel(SiteController::OTP_RESEND_SECONDS + 1)->seconds();
        $this->post('/verify-email/resend', ['token' => $t])->assertSee('A new code is on its way');
        $second = $this->lastEmailCode();
        if ($first !== $second) {
            $this->post('/verify-email', ['token' => $t, 'code' => $first])->assertSee('That code is not right. 4 tries left.');   // old code dead
        }
        $this->post('/verify-email', ['token' => $t, 'code' => '12'])->assertSee('Enter the 6-digit code');
        $this->post('/verify-email', ['token' => $t, 'code' => $second])->assertSee('Your trial is ready');
        $this->assertSame(1, Company::query()->where('slug', 'zeta')->count());
        $this->post('/verify-email', ['token' => $t, 'code' => $second])->assertSee('This code has expired');   // one use

        // 5 wrong codes cancel the request; an expired code is refused.
        $this->travel(2)->minutes();   // the website allows 10 form posts a minute per address
        $t2 = $this->token($this->post('/trial', ['workspace' => 'zeta-two', 'email' => 'z2@zeta.test'] + $this->trial)->getContent());
        $good = $this->lastEmailCode();
        $bad = $good === '000000' ? '111111' : '000000';
        for ($i = 1; $i < SiteController::OTP_TRIES; $i++) {
            $this->post('/verify-email', ['token' => $t2, 'code' => $bad])->assertSee('That code is not right');
        }
        $this->post('/verify-email', ['token' => $t2, 'code' => $bad])->assertSee('Too many wrong codes');
        $this->post('/verify-email', ['token' => $t2, 'code' => $good])->assertSee('This code has expired');
        $this->travel(2)->minutes();
        $t3 = $this->token($this->post('/trial', ['workspace' => 'zeta-three', 'email' => 'z3@zeta.test'] + $this->trial)->getContent());
        $this->travel(SiteController::OTP_MINUTES + 1)->minutes();
        $this->post('/verify-email', ['token' => $t3, 'code' => $this->lastEmailCode()])->assertSee('This code has expired');
        $this->assertSame(0, Company::query()->whereIn('slug', ['zeta-two', 'zeta-three'])->count());
    }

    public function test_buying_needs_the_email_code_before_the_order_exists(): void
    {
        $form = ['plan' => 'starter', 'users' => 10, 'billing' => 'annual', 'buyer_company' => 'Gamma Ltd', 'buyer_name' => 'Ganesh', 'buyer_email' => 'g@gamma.test',
            'buyer_phone' => '9822222222', 'state_code' => '36', 'workspace' => 'gamma', 'password' => 'Gamma123', 'password_confirmation' => 'Gamma123', 'terms' => '1'];
        $t = $this->token($this->post('/buy', $form)->assertOk()->assertSee('continue to payment')->getContent());
        $this->assertSame(0, SalesOrder::query()->count());
        $res = $this->post('/verify-email', ['token' => $t, 'code' => $this->lastEmailCode()]);
        $res->assertRedirect(SalesOrder::query()->firstOrFail()->payUrl());
        Company::query()->create(['slug' => 'taken', 'name' => 'T', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $this->post('/buy', ['workspace' => 'taken'] + $form)->assertSessionHasErrors('order');           // checked before any code is sent
    }

    public function test_no_code_can_be_sent_without_email_sending(): void
    {
        config(['mail.default' => 'log']);   // e-mails would only be written to the log: nobody can receive the code
        $this->assertFalse(PlatformSettings::mailReady());
        $this->post('/trial', $this->trial)->assertRedirect()->assertSessionHasErrors('trial');
        $this->assertSame(0, Company::query()->count());
    }

    public function test_one_password_rule_minimum_8_any_characters(): void
    {
        $short = ['password' => 'Abc1234', 'password_confirmation' => 'Abc1234'] + $this->trial;              // 7
        $this->post('/trial', $short)->assertSessionHasErrors('password');
        foreach (['12345678', 'abcdefgh', 'Hyd@2026'] as $i => $pw) {                                     // only digits / only letters / anything: fine
            $this->post('/trial', ['password' => $pw, 'password_confirmation' => $pw, 'workspace' => "zeta-pw{$i}", 'email' => "pw{$i}@zeta.test"] + $this->trial)
                ->assertOk()->assertSee('Check your e-mail');
        }
    }

    public function test_trial_duration_is_set_by_super_admin_only_and_used_for_new_trials(): void
    {
        $super = PlatformAdmin::query()->create(['email' => 'super@ametecs.test', 'name' => 'S', 'password' => 'Staff123', 'role' => 'super', 'status' => 'active']);
        $sales = PlatformAdmin::query()->create(['email' => 'sales@ametecs.test', 'name' => 'S', 'password' => 'Staff123', 'role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales, 'platform')->withHeaders(['Referer' => 'http://localhost/'])->putJson('/api/super/settings', ['trial_days' => 3])->assertForbidden();
        $this->actingAs($super, 'platform')->putJson('/api/super/settings', ['trial_days' => 400])->assertStatus(422);
        $this->actingAs($super, 'platform')->putJson('/api/super/settings', ['trial_days' => 7])->assertOk();
        $this->postWithEmailCode('/trial', $this->trial)->assertSee('Your trial is ready');
        $l = Licence::query()->where('company_id', Company::query()->where('slug', 'zeta')->value('id'))->firstOrFail();
        $this->assertTrue($l->expires_at->between(now()->addDays(6), now()->addDays(8)));
    }

    public function test_authenticator_is_off_by_default_for_every_console(): void
    {
        config(['ammp.sessions.admin_mfa_required' => false]);   // the shipped default (phpunit.xml switches it on for the older MFA tests)
        PlatformSettings::set('staff_mfa_required', '1');         // even if it was compulsory before
        $a = PlatformAdmin::query()->create(['email' => 'super@ametecs.test', 'name' => 'S', 'password' => 'Staff123', 'role' => 'super', 'status' => 'active', 'mfa_enabled_at' => now()]);
        $this->withHeaders(['Referer' => 'http://localhost/'])->postJson('/api/super/auth/login', ['email' => 'super@ametecs.test', 'password' => 'Staff123'])
            ->assertOk()->assertJsonMissingPath('mfa')->assertJsonPath('mfa_available', false)->assertJsonPath('mfa_enabled', false);
        $this->getJson('/api/super/dashboard')->assertOk();
        $this->assertNotNull($a->fresh()->last_login_at);
    }
}
