<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ManagedDevice;
use App\Models\Platform\Lead;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MobileClient;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ROVEXY website, Super Admin, licensing and Razorpay (12 Oct 2026). */
final class LicensingTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'UTC', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($this->a, function () {
            CompanyDefaults::apply($this->a);
            foreach (['admin' => 'company_admin', 'EMP1' => 'employee', 'EMP2' => 'employee'] as $code => $role) {
                $x = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => strtolower($code) . '@alpha.test', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $x->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                $this->u[$code] = $x;
            }
        });
    }

    private function admin()
    {
        return $this->actingAs($this->u['admin'], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    private function licence(array $over = []): Licence
    {
        return Licence::query()->create($over + ['key' => LicenceIssuer::newKey(), 'company_id' => $this->a->id, 'holder_name' => 'Alpha', 'kind' => 'subscription',
            'max_users' => 10, 'features' => ['calling', 'recording', 'customers'], 'status' => 'active', 'starts_at' => now()->subMonth(), 'expires_at' => now()->addMonths(11), 'grace_days' => 7]);
    }

    private function staff(string $role)
    {
        $s = PlatformAdmin::query()->create(['email' => "{$role}@ametecs.test", 'name' => ucfirst($role), 'password' => 'Staff-password-1', 'role' => $role, 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    public function test_gst_split_and_plans_on_the_website(): void
    {
        $plan = Plan::query()->with('cloudTiers', 'perpetualBands')->where('code', 'starter')->firstOrFail();   // ₹199 / user / month seeded (5–25 users)
        $spec = ['plan' => $plan, 'kind' => 'new', 'licence_kind' => 'subscription', 'deployment' => 'cloud', 'billing' => 'annual', 'users' => 10];
        $in = \App\Services\Platform\Pricing::build($spec + ['state' => '36']);
        $this->assertSame([23880.0, 2149.2, 2149.2, 0.0, 28178.4], [$in['amount'], $in['cgst'], $in['sgst'], $in['igst'], $in['total']]);
        $out = \App\Services\Platform\Pricing::build($spec + ['state' => '29']);
        $this->assertSame([0.0, 4298.4, 28178.4], [$out['cgst'], $out['igst'], $out['total']]);
        $this->get('/')->assertOk()->assertSee('Starter')->assertSee('Business')->assertSee('/ user / month', false)->assertSee('On-premises (your server)');
        PlatformSettings::set('site_hero_title', 'Collections, recorded.');
        PlatformSettings::set('site_announcement', 'Diwali offer: 20% off');
        $this->get('/')->assertSee('Collections, recorded.')->assertSee('Diwali offer: 20% off');
        $this->get('/buy?plan=business')->assertOk()->assertSee('Pay securely');
        $this->get('/')->assertSee('One portfolio, start to finish')->assertSee('FAQPage', false)->assertSee('Policy profiles')->assertSee('Team status')->assertSee('wa.me/919000098877', false);
        foreach (['privacy' => 'Privacy policy', 'terms' => 'Terms of service', 'refund' => 'Refund', 'delivery' => 'Service delivery', 'contact' => 'sales@ametecsindia.com'] as $page => $text) {
            $this->get('/' . $page)->assertOk()->assertSee($text, false);
        }
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /super');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/refund', false);
    }

    public function test_free_trial_creates_a_workspace_with_a_trial_licence(): void
    {
        $this->post('/trial', ['company' => 'Beta Recoveries', 'workspace' => 'beta-rec', 'name' => 'Bina', 'phone' => '9811111111', 'email' => 'bina@beta.test',
            'password' => 'Trial-pass-123', 'password_confirmation' => 'Trial-pass-123', 'terms' => '1'])->assertOk()->assertSee('Your trial is ready');
        $c = Company::query()->where('slug', 'beta-rec')->firstOrFail();
        $l = Licence::query()->where('company_id', $c->id)->firstOrFail();
        $this->assertSame('trial', $l->kind);
        $this->assertSame(5, $l->max_users);
        $this->assertTrue($l->expires_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertDatabaseHas('leads', ['email' => 'bina@beta.test', 'source' => 'trial']);
        // honeypot: looks fine to a bot, creates nothing
        $this->post('/trial', ['website' => 'spam', 'company' => 'X'])->assertRedirect('/thank-you');
        $this->assertSame(2, Company::query()->count());
    }

    public function test_user_limit_expiry_grace_and_features_are_enforced_on_the_server(): void
    {
        $l = $this->licence(['max_users' => 3]);                             // admin + EMP1 + EMP2 = 3 active
        $this->admin()->postJson('/api/admin/employees', ['employee_code' => 'EMP3', 'name' => 'Three', 'roles' => ['employee']])
            ->assertStatus(422)->assertJsonPath('error', 'licence_user_limit');
        $l->forceFill(['max_users' => 4])->save();
        $this->admin()->postJson('/api/admin/employees', ['employee_code' => 'EMP3', 'name' => 'Three', 'roles' => ['employee']])->assertCreated();

        // features: no live_map in this plan
        $this->admin()->getJson('/api/admin/live-locations')->assertStatus(403)->assertJsonPath('error', 'feature_not_in_plan');
        $this->admin()->getJson('/api/admin/licence')->assertOk()->assertJsonPath('state', 'active')->assertJsonPath('used_users', 4);

        // a phone approved while active, then the licence runs out
        $m = new MobileClient($this, 'alpha');
        $code = $m->register()->assertCreated()->json('approval_code');
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail());
        $this->admin()->postJson("/api/admin/devices/{$device->id}/approve", ['code' => $code, 'user_id' => $this->u['EMP1']->id])->assertOk();
        $m->deviceToken = $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->json('device_token');

        $l->forceFill(['expires_at' => now()->subDays(3)])->save();          // within 7 grace days: still works, with a warning
        $this->admin()->getJson('/api/admin/licence')->assertJsonPath('state', 'grace');
        $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456'])->assertOk();

        $l->forceFill(['expires_at' => now()->subDays(10)])->save();         // past grace: phones stop, console stays open
        $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456'])->assertStatus(403)->assertJsonPath('error', 'licence_inactive');
        $this->admin()->getJson('/api/admin/licence')->assertOk()->assertJsonPath('state', 'expired');
        $this->admin()->postJson('/api/admin/employees', ['employee_code' => 'EMP4', 'name' => 'Four', 'roles' => ['employee']])->assertStatus(403);
    }

    public function test_buy_pay_with_razorpay_provisions_once(): void
    {
        PlatformSettings::set('razorpay_key_id', 'rzp_test_x');
        PlatformSettings::set('razorpay_key_secret', 'shh');
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_RZ1'])]);
        $res = $this->post('/buy', ['plan' => 'starter', 'users' => 10, 'billing' => 'annual', 'buyer_company' => 'Gamma Ltd', 'buyer_name' => 'Ganesh', 'buyer_email' => 'g@gamma.test',
            'buyer_phone' => '9822222222', 'state_code' => '29', 'workspace' => 'gamma', 'password' => 'Gamma-pass-123', 'password_confirmation' => 'Gamma-pass-123', 'terms' => '1']);
        $o = SalesOrder::query()->firstOrFail();
        $res->assertRedirect($o->payUrl());
        $this->assertSame(28178.4, $o->total);
        $base = "/pay/{$o->number}/{$o->token()}";
        $this->get("/pay/{$o->number}/wrongtoken")->assertNotFound();
        $this->postJson("{$base}/razorpay")->assertOk()->assertJsonPath('order_id', 'order_RZ1')->assertJsonPath('amount', 2817840);
        $this->postJson("{$base}/verify", ['razorpay_order_id' => 'order_RZ1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged'])->assertStatus(422);
        $sig = hash_hmac('sha256', 'order_RZ1|pay_1', 'shh');
        $this->postJson("{$base}/verify", ['razorpay_order_id' => 'order_RZ1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])->assertOk();
        $this->postJson("{$base}/verify", ['razorpay_order_id' => 'order_RZ1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])->assertOk();   // browser + retry
        $o->refresh();
        $this->assertSame('paid', $o->status);
        $this->assertMatchesRegularExpression('#^SC/\d{4}-\d{2}/0001$#', $o->invoice_number);
        $this->assertNull($o->admin_password);
        $c = Company::query()->where('slug', 'gamma')->firstOrFail();
        $this->assertSame(1, Licence::query()->where('company_id', $c->id)->where('kind', 'subscription')->count());
        $this->get("{$base}/invoice")->assertOk()->assertSee($o->invoice_number)->assertSee('IGST');
        // the webhook arriving later does nothing more
        PlatformSettings::set('razorpay_webhook_secret', 'wh');
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_RZ1', 'amount' => 2817840]]]]);
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'wh'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(1, Company::query()->where('slug', 'gamma')->count());
    }

    public function test_staff_recorded_renewal_extends_from_the_current_expiry(): void
    {
        $l = $this->licence(['plan_id' => Plan::query()->where('code', 'starter')->value('id'), 'expires_at' => now()->addDays(20)->startOfDay()]);
        $sales = $this->staff('sales');
        $sales->postJson('/api/super/orders', ['kind' => 'renewal', 'plan_id' => Plan::query()->where('code', 'business')->value('id'), 'buyer_company' => 'Alpha', 'buyer_name' => 'A',
            'buyer_email' => 'a@alpha.test'])->assertStatus(422);                                     // a renewal is always on a licence
        $o = $sales->postJson('/api/super/orders', ['kind' => 'renewal', 'users' => 50, 'plan_id' => Plan::query()->where('code', 'business')->value('id'), 'licence_id' => $l->id, 'buyer_company' => 'Alpha',
            'buyer_name' => 'A', 'buyer_email' => 'admin@alpha.test', 'state_code' => '36'])->assertCreated()->json();
        $sales->postJson("/api/super/orders/{$o['id']}/payment", ['method' => 'bank', 'reference' => 'UTR123'])->assertOk()->assertJsonPath('status', 'paid');
        $l->refresh();
        $this->assertSame(50, $l->max_users);
        $csv = $sales->get('/api/super/orders.csv?from=' . now()->subDay()->toDateString() . '&to=' . now()->toDateString())->assertOk()->streamedContent();
        $this->assertStringContainsString('UTR123', $csv);
        $sales->getJson('/api/super/companies')->assertOk()->assertJsonPath('data.0.slug', 'alpha')->assertJsonPath('data.0.calls_30d', 0);
        $this->assertSame(now()->addDays(20)->startOfDay()->addMonthsNoOverflow(12)->toDateString(), $l->expires_at->toDateString());
    }

    public function test_super_admin_roles_are_enforced(): void
    {
        $this->postJson('/api/super/auth/login', ['email' => 'nobody@x.test', 'password' => 'x'], ['Referer' => 'http://localhost/'])->assertStatus(422);
        $this->getJson('/api/super/licences')->assertStatus(401);
        $support = $this->staff('support');
        $l = $this->licence();
        $support->getJson('/api/super/licences')->assertOk();
        $support->patchJson("/api/super/licences/{$l->id}", ['status' => 'suspended'])->assertStatus(403);
        $support->getJson('/api/super/settings')->assertStatus(403);
        $sales = $this->staff('sales');
        $sales->patchJson("/api/super/licences/{$l->id}", ['status' => 'suspended', 'reason' => 'unpaid'])->assertOk();
        $sales->putJson('/api/super/plans/1', ['code' => 'starter', 'name' => 'Cheap', 'months' => 12, 'max_users' => 10, 'features' => ['calling']])->assertStatus(403);
        $this->admin()->getJson('/api/admin/licence')->assertJsonPath('state', 'suspended');
        $this->assertDatabaseHas('platform_audit', ['action' => 'licence.updated', 'subject_id' => $l->id]);
    }

    public function test_coupons_discount_before_gst_and_count_uses_once_paid(): void
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        \App\Models\Platform\Coupon::query()->create(['code' => 'DIWALI20', 'type' => 'percent', 'value' => 20, 'max_uses' => 1, 'active' => true]);
        \App\Models\Platform\Coupon::query()->create(['code' => 'NEWONLY', 'type' => 'flat', 'value' => 5000, 'new_only' => true, 'active' => true]);
        \App\Models\Platform\Coupon::query()->create(['code' => 'OLD', 'type' => 'flat', 'value' => 1000, 'valid_until' => now()->subDay(), 'active' => true]);
        $this->postJson('/price-check', ['code' => 'diwali20', 'plan' => 'starter', 'users' => 10, 'state_code' => '29'])->assertOk()   // 23,880 − 20%
            ->assertJsonPath('discount', 4776.0)->assertJsonPath('amount', 19104.0)->assertJsonPath('igst', 3438.72)->assertJsonPath('total', 22542.72);
        $this->postJson('/price-check', ['code' => 'OLD', 'plan' => 'starter', 'users' => 10])->assertStatus(422)->assertJsonPath('message', 'This code has expired.');
        $this->postJson('/price-check', ['code' => 'NOPE', 'plan' => 'starter', 'users' => 10])->assertStatus(422);

        // renewal from the company console: a new-buyers-only code is refused, a general one applies
        $l = $this->licence(['plan_id' => $plan->id]);
        $this->admin()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $plan->id, 'state_code' => '36', 'coupon' => 'NEWONLY'])->assertStatus(422)->assertJsonPath('error', 'bad_coupon');
        $r = $this->admin()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $plan->id, 'state_code' => '36', 'coupon' => 'DIWALI20'])->assertCreated();
        $this->assertSame(22542.72, (float) $r->json('total'));
        $o = SalesOrder::query()->where('number', $r->json('number'))->firstOrFail();
        $this->assertSame('DIWALI20', $o->coupon_code);
        Sales::markPaid($o, 'bank', 'UTR9');
        $this->assertSame(1, \App\Models\Platform\Coupon::query()->where('code', 'DIWALI20')->value('used_count'));
        $this->postJson('/price-check', ['code' => 'DIWALI20', 'plan' => 'starter', 'users' => 10])->assertStatus(422)->assertJsonPath('message', 'This code has been fully used.');
        $this->get("/pay/{$o->number}/{$o->token()}/invoice")->assertOk()->assertSee('DIWALI20')->assertSee('Taxable value');
    }

    public function test_trial_with_email_code(): void
    {
        PlatformSettings::set('trial_verify_email', '1');
        $form = ['company' => 'Zeta', 'workspace' => 'zeta', 'name' => 'Zara', 'phone' => '9833333333', 'email' => 'z@zeta.test',
            'password' => 'Trial-pass-123', 'password_confirmation' => 'Trial-pass-123', 'terms' => '1'];
        $page = $this->post('/trial', $form)->assertOk()->assertSee('Check your e-mail');
        $this->assertSame(0, Company::query()->where('slug', 'zeta')->count());          // nothing until the code comes back
        preg_match('/name="token" value="([a-f0-9]{32})"/', $page->getContent(), $m);
        $code = null;
        foreach (app('mailer')->getSymfonyTransport()->messages() as $msg) {
            preg_match('/(\d{6}) is your ROVEXY code/', $msg->getOriginalMessage()->getSubject(), $c) && $code = $c[1];
        }
        $this->assertNotNull($code);
        $this->post('/trial/verify', ['token' => $m[1], 'code' => $code === '000000' ? '111111' : '000000'])->assertSee('That code is not right');
        $this->post('/trial/verify', ['token' => $m[1], 'code' => $code])->assertSee('Your trial is ready');
        $this->assertSame(1, Company::query()->where('slug', 'zeta')->count());
        $this->post('/trial/verify', ['token' => $m[1], 'code' => $code])->assertSee('This code has expired');   // one use only
    }

    public function test_reminders_go_once(): void
    {
        $this->licence(['expires_at' => now()->addDays(7)]);
        $this->artisan('ammp:reminders')->expectsOutputToContain('Sent 1 e-mail')->assertSuccessful();
        $this->artisan('ammp:reminders')->expectsOutputToContain('Sent 0 e-mail')->assertSuccessful();
        $this->assertDatabaseHas('platform_mail_log', ['kind' => 'package_7d', 'email' => 'admin@alpha.test']);
        PlatformSettings::set('reminders_enabled', '0');
        $this->artisan('ammp:reminders')->expectsOutputToContain('switched off')->assertSuccessful();
    }

    public function test_smtp_settings_are_saved_encrypted_and_applied(): void
    {
        $super = $this->staff('super');
        $super->postJson('/api/super/settings/test-mail', ['to' => 'me@ametecs.test'])->assertOk()->assertJsonPath('mailer', 'array');   // nothing saved yet: .env mailer
        $super->putJson('/api/super/settings', ['mail_host' => 'http://bad host'])->assertStatus(422);
        $super->putJson('/api/super/settings', ['mail_host' => 'smtp.gmail.com', 'mail_port' => 465, 'mail_encryption' => 'ssl', 'mail_username' => 'billing@ametecs.test',
            'mail_password' => 'app-pass-1234', 'mail_from_name' => 'ROVEXY'])->assertOk()
            ->assertJsonPath('mail_password_set', true)->assertJsonPath('mail_configured', true)->assertJsonMissingPath('mail_password');
        $this->assertNotSame('app-pass-1234', DB::table('platform_settings')->where('key', 'mail_password')->value('value'));
        $super->putJson('/api/super/settings', ['mail_password' => ''])->assertOk();   // blank keeps the saved password
        PlatformSettings::applyMail();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame(['smtps', 'smtp.gmail.com', 465, 'app-pass-1234'], [config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.password')]);
        $this->assertSame('billing@ametecs.test', config('mail.from.address'));
        $this->staff('sales')->postJson('/api/super/settings/test-mail', ['to' => 'me@ametecs.test'])->assertStatus(403);
    }

    public function test_delete_keeps_what_money_depends_on(): void
    {
        $super = $this->staff('super');
        $me = PlatformAdmin::query()->where('email', 'super@ametecs.test')->first();
        $super->deleteJson("/api/super/admins/{$me->id}")->assertStatus(422);                      // not yourself
        $other = PlatformAdmin::query()->create(['email' => 'old@ametecs.test', 'name' => 'Old', 'password' => 'Staff-password-1', 'role' => 'sales', 'status' => 'active']);
        $super->deleteJson("/api/super/admins/{$other->id}")->assertOk();
        $this->assertDatabaseMissing('platform_admins', ['id' => $other->id]);

        $used = Plan::query()->where('code', 'starter')->firstOrFail();
        $this->licence(['plan_id' => $used->id]);
        $super->deleteJson("/api/super/plans/{$used->id}")->assertStatus(422);                     // a licence uses it
        $spare = Plan::query()->create(['code' => 'spare', 'name' => 'Spare', 'months' => 12, 'max_users' => 5, 'features' => ['calling'], 'active' => true, 'is_public' => false, 'sort' => 99]);
        $super->deleteJson("/api/super/plans/{$spare->id}")->assertOk();

        $lead = Lead::query()->create(['name' => 'Spam', 'source' => 'website', 'status' => 'new']);
        $this->staff('support')->deleteJson("/api/super/leads/{$lead->id}")->assertStatus(403);
        $this->actingAs($me, 'platform')->deleteJson("/api/super/leads/{$lead->id}")->assertOk();
        $this->assertDatabaseHas('platform_audit', ['action' => 'lead.deleted']);
    }
}
