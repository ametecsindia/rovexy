<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InstallationLicence;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Licensing\LicenceAuthority;
use App\Services\Licensing\LicenceClient;
use App\Services\Licensing\LicenceSigner;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\Licensing;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Pricing;
use App\Services\Platform\Sales;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cloud + on-premises licensing with per-user pricing (13 Oct 2026, SmartEPT model). */
final class OnPremLicensingTest extends TestCase
{
    use RefreshDatabase;

    private const FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FP2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/rovexy-licence-test';
        config(['ammp.licence.private_key' => "{$dir}/private.pem", 'ammp.licence.public_key' => "{$dir}/public.pem"]);
        if (!is_file("{$dir}/private.pem")) {
            LicenceSigner::generate();
        }
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->with('cloudTiers', 'perpetualBands')->where('code', $code)->firstOrFail();
    }

    private function staff(string $role = 'super')
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => "{$role}@ametecs.test"], ['name' => ucfirst($role), 'password' => 'Staff-password-1', 'role' => $role, 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function onprem(string $kind = 'perpetual', int $users = 10): Licence
    {
        return LicenceIssuer::issue($this->plan('starter'), 'Acme Recoveries', 'it@acme.test', null, $kind, $users, 'onprem', $kind === 'subscription' ? 'annual' : null, 'test');
    }

    public function test_pricing_engine_cycles_bands_setup_and_custom_quote(): void
    {
        $p = $this->plan('starter');   // cloud ₹199 (5–25), ₹179 (26–100) …; perpetual 10 = 40,000, 25 = 85,000, 50 = 1,50,000 …
        $this->assertSame(199.0, Pricing::cloudRate($p, 10, 'annual'));
        $this->assertSame(265.33, Pricing::cloudRate($p, 10, 'quarterly'));                // annual is 25% cheaper than quarterly
        $this->assertSame(238.8, Pricing::cloudRate($p, 10, 'half_yearly'));               // quarterly − 10%
        $this->assertSame(179.0, Pricing::cloudRate($p, 30, 'annual'));                    // bigger band, lower rate
        $this->assertNull(Pricing::cloudRate($p, 501, 'annual'));                          // beyond the bands = custom quote
        $this->assertSame(40000.0, Pricing::perpetualPrice($p, 6));                        // flat minimum package
        $this->assertSame(55000.0, Pricing::perpetualPrice($p, 15));                       // straight line 10 → 25 users
        $this->assertSame(85000.0, Pricing::perpetualPrice($p, 25));
        $this->assertNull(Pricing::perpetualPrice($p, 501));
        $this->assertSame(5000.0, Pricing::setupFee(20));
        $this->assertSame(5500.0, Pricing::setupFee(30));                                  // ₹100 per user above 25

        $q = fn (array $x) => Pricing::build($x + ['plan' => $p, 'kind' => 'new', 'licence_kind' => 'subscription', 'deployment' => 'cloud', 'billing' => 'quarterly', 'users' => 10]);
        $this->assertSame([7959.9], array_column($q([])['lines'], 'amount'));             // 10 × 265.33 × 3, no setup on cloud
        $perp = $q(['licence_kind' => 'perpetual', 'deployment' => 'onprem', 'users' => 30]);
        $this->assertSame([['perpetual_licence', 98000.0], ['setup', 5500.0]], array_map(fn ($l) => [$l['type'], $l['amount']], $perp['lines']));
        $this->assertNull($perp['billing']);
        $this->assertSame(98000.0, $q(['licence_kind' => 'perpetual', 'deployment' => 'onprem', 'users' => 30, 'setup' => false])['amount']);   // "no setup" (85,000 + 5 × 65,000 ÷ 25)
        PlatformSettings::set('setup_on_cloud', '1');
        $this->assertSame('setup', $q([])['lines'][1]['type']);
        foreach ([['users' => 3], ['users' => 501], ['licence_kind' => 'perpetual', 'deployment' => 'cloud']] as $bad) {
            try {
                $q($bad);
                $this->fail('should refuse ' . json_encode($bad));
            } catch (\App\Domain\DomainException $e) {
                $this->assertContains($e->reason, ['below_minimum', 'custom_quote', 'bad_order']);
            }
        }
        $this->assertSame(250000.0, $q(['users' => 501, 'custom_amount' => 250000, 'setup' => false])['amount']);   // a typed price prices a custom quote
    }

    public function test_staff_sell_an_onprem_perpetual_licence_then_more_users_and_amc(): void
    {
        $s = $this->staff();
        $buyer = ['buyer_company' => 'Acme Recoveries', 'buyer_name' => 'Ravi', 'buyer_email' => 'it@acme.test', 'state_code' => '29'];
        $pre = $s->postJson('/api/super/orders/preview', ['kind' => 'new', 'deployment' => 'onprem', 'licence_kind' => 'perpetual', 'plan_id' => $this->plan('starter')->id, 'users' => 10] + $buyer)->assertOk();
        $this->assertSame([40000.0, 5000.0], array_map('floatval', array_column($pre->json('lines'), 'amount')));
        $this->staff('sales')->postJson('/api/super/orders', ['kind' => 'new', 'deployment' => 'onprem', 'licence_kind' => 'perpetual', 'plan_id' => $this->plan('starter')->id,
            'users' => 10, 'setup' => 'none'] + $buyer)->assertForbidden();                                       // waiving setup is super-only
        $o = $s->postJson('/api/super/orders', ['kind' => 'new', 'deployment' => 'onprem', 'licence_kind' => 'perpetual', 'plan_id' => $this->plan('starter')->id, 'users' => 10] + $buyer)
            ->assertCreated()->json();
        $this->assertSame(53100.0, (float) $o['total']);                                                         // (40,000 + 5,000) + 18% IGST
        $s->postJson("/api/super/orders/{$o['id']}/payment", ['method' => 'bank', 'reference' => 'UTR55'])->assertOk();
        $l = Licence::query()->where('deployment', 'onprem')->firstOrFail();
        $this->assertSame(['perpetual', null, 10, null], [$l->kind, $l->company_id, $l->max_users, $l->expires_at]);
        $this->assertSame(now()->addYear()->toDateString(), $l->amc_expires_at->toDateString());
        $this->assertTrue(LicenceIssuer::validKey($l->key));
        $this->assertFalse(LicenceIssuer::validKey(substr($l->key, 0, -1) . ($l->key[-1] === 'A' ? 'B' : 'A')));  // a typo is caught offline
        $this->get("/pay/{$o['number']}/" . SalesOrder::query()->find($o['id'])->token() . '/invoice')->assertOk()->assertSee('on-premises')->assertSee('Setup &amp; onboarding', false);

        // more users: price(25) − price(10)
        $m = $s->postJson('/api/super/orders', ['kind' => 'seats', 'licence_id' => $l->id, 'users' => 25] + $buyer)->assertCreated()->json();
        $this->assertSame([45000.0], array_map('floatval', array_column($m['lines'], 'amount')));
        Sales::markPaid(SalesOrder::query()->findOrFail($m['id']), 'bank', 'UTR56');
        $this->assertSame(25, $l->fresh()->max_users);
        $this->assertNull($l->fresh()->expires_at);
        $s->postJson('/api/super/orders/preview', ['kind' => 'seats', 'licence_id' => $l->id, 'users' => 20] + $buyer)->assertStatus(422);   // never cheaper than now

        // AMC: 18% of today's price for 25 users (85,000)
        $a = $s->postJson('/api/super/orders', ['kind' => 'amc', 'licence_id' => $l->id] + $buyer)->assertCreated()->json();
        $this->assertSame([15300.0], array_map('floatval', array_column($a['lines'], 'amount')));
        Sales::markPaid(SalesOrder::query()->findOrFail($a['id']), 'bank', 'UTR57');
        $this->assertSame(now()->addYears(2)->toDateString(), $l->fresh()->amc_expires_at->toDateString());   // from the current AMC end, not today
        $s->postJson('/api/super/orders/preview', ['kind' => 'renewal', 'licence_id' => $l->id] + $buyer)->assertStatus(422);   // perpetual never renews
    }

    public function test_cloud_add_users_mid_term_is_prorated_and_keeps_expiry(): void
    {
        $c = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $l = LicenceIssuer::issue($this->plan('starter'), 'Alpha', 'a@alpha.test', $c->id, 'subscription', 10, 'cloud', 'annual', 'test');
        $l->forceFill(['expires_at' => now()->addDays(183)])->save();
        [, $pr] = Sales::quote('seats', ['users' => 30], ['buyer_email' => 'a@alpha.test', 'state_code' => '36'], $l);
        // (30 × 179 − 10 × 199) × 183 ÷ 30.44 = 3,380 × 6.0118… = 20,319.97
        $this->assertSame(round((30 * 179 - 10 * 199) * 183 / 30.44, 2), $pr['amount']);
        $o = Sales::createOrder('seats', ['users' => 30], ['buyer_company' => 'Alpha', 'buyer_name' => 'A', 'buyer_email' => 'a@alpha.test', 'state_code' => '36'], $l, 'test');
        $before = $l->expires_at->toDateString();
        Sales::markPaid($o, 'bank', 'U1');
        $this->assertSame([30, $before], [$l->fresh()->max_users, $l->fresh()->expires_at->toDateString()]);
    }

    public function test_check_in_binds_the_first_server_and_refuses_the_rest(): void
    {
        PlatformSettings::set('seller_email', 'sales@ametecs.test');
        $l = $this->onprem();
        $v = fn (string $key, string $fp) => $this->postJson('/api/v1/license/validate', ['key' => $key, 'fingerprint' => $fp, 'users' => 4]);
        $v('RX-AAAA-BBBB-CCCC-DDDD', self::FP)->assertStatus(403)->assertJsonPath('reason', 'unknown_key');
        $ok = $v($l->key, self::FP)->assertOk()->assertJsonPath('ok', true);
        $bundle = LicenceSigner::verify($ok->json('licence'));
        $this->assertSame([$l->key, 'perpetual', 10, self::FP, 'online'], [$bundle['key'], $bundle['kind'], $bundle['max_users'], $bundle['fingerprint'], $bundle['source']]);
        [$body, $sig] = explode('.', $ok->json('licence'));
        $forged = rtrim(strtr(base64_encode(str_replace('"max_users":10', '"max_users":999', base64_decode(strtr($body, '-_', '+/')))), '+/', '-_'), '=') . '.' . $sig;
        $this->assertNull(LicenceSigner::verify($forged));                                                      // tampering breaks the signature
        $this->assertSame([self::FP, 4], [$l->fresh()->server_fingerprint, $l->fresh()->reported_users]);

        $v($l->key, self::FP2)->assertStatus(403)->assertJsonPath('reason', 'server_mismatch');
        $v($l->key, self::FP2)->assertStatus(403);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('platform_audit')->where('action', 'licence.server_mismatch')->count());   // logged + e-mailed once a day
        $this->postJson('/api/v1/license/validate', ['key' => $l->key])->assertStatus(422);                     // server ID is mandatory

        $this->staff('support')->postJson("/api/super/licences/{$l->id}/release", ['reason' => 'new server'])->assertForbidden();
        $this->staff()->postJson("/api/super/licences/{$l->id}/release", ['reason' => 'Server replaced'])->assertOk();
        $v($l->key, self::FP2)->assertOk();                                                                     // the new server binds

        $l->forceFill(['status' => 'suspended'])->save();
        $v($l->key, self::FP2)->assertStatus(403)->assertJsonPath('reason', 'licence_suspended');
        $sub = $this->onprem('subscription');
        $sub->forceFill(['expires_at' => now()->subDays(8)])->save();                                           // past expiry + 7 grace days
        $v($sub->key, self::FP)->assertStatus(403)->assertJsonPath('reason', 'licence_expired');
        $cloud = LicenceIssuer::issue($this->plan('starter'), 'X', null, null, 'subscription', 5, 'cloud', 'annual');
        $v($cloud->key, self::FP)->assertStatus(403)->assertJsonPath('reason', 'unknown_key');                  // cloud keys don't activate installs
    }

    public function test_offline_licence_file_is_machine_locked(): void
    {
        $l = $this->onprem();
        $res = $this->staff()->post("/api/super/licences/{$l->id}/file", ['fingerprint' => self::FP], ['Accept' => 'application/json'])->assertOk();
        $p = LicenceSigner::verify($res->getContent());
        $this->assertSame(['file', self::FP, 'active'], [$p['source'], $p['fingerprint'], $p['status']]);
        $this->assertSame(self::FP, $l->fresh()->server_fingerprint);
        $l->forceFill(['status' => 'revoked'])->save();
        $this->staff()->postJson("/api/super/licences/{$l->id}/file", ['fingerprint' => self::FP])->assertStatus(422);   // never for a non-active licence
    }

    public function test_install_client_demotes_only_on_a_real_verdict_and_verifies_on_every_read(): void
    {
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => 'https://central.test', 'ammp.licence.machine_id' => 'test-machine']);
        \Illuminate\Support\Facades\Cache::forget('rovexy.server_fingerprint');
        $fp = LicenceClient::fingerprint();
        $c = Company::query()->create(['slug' => 'onprem', 'name' => 'Acme', 'timezone' => 'UTC', 'default_country' => 'IN']);
        app(CurrentCompany::class)->set($c);
        $client = app(LicenceClient::class);
        $lic = fn () => tap(app(Licensing::class))->forget()->current();

        $this->assertSame(['active', 'evaluation'], [$lic()['state'], $lic()['kind']]);                          // fresh install: 14-day evaluation
        $client->row()->forceFill(['installed_at' => now()->subDays(15)])->save();
        $this->assertSame('unlicensed', $lic()['state']);

        $l = $this->onprem('subscription', 25);
        $l->forceFill(['server_fingerprint' => $fp])->save();
        Http::fake(['central.test/*' => Http::sequence()
            ->push(['ok' => true, 'licence' => LicenceSigner::sign(LicenceAuthority::payload($l, 'online', $fp))])
            ->push('<html>Bad gateway</html>', 502)                                                             // proxy / outage
            ->push(['message' => 'Forbidden'], 403)                                                             // a 403 without a verdict is an outage too
            ->push(['ok' => false, 'reason' => 'server_mismatch', 'message' => 'Registered to another server.'], 403)]);
        $this->expectsNoBadKeys($client);
        $client->saveKey($l->key);
        $this->assertSame(['active', 25, 'subscription'], [$lic()['state'], $lic()['max_users'], $lic()['kind']]);

        $client->check();                                                                                       // 502
        $client->check();                                                                                       // 403, no reason
        $this->assertSame('active', $lic()['state']);
        $this->assertNotNull($client->row()->unreachable_since);

        $client->check();                                                                                       // real verdict
        $this->assertSame('server_mismatch', $lic()['state']);

        // the cached licence can't be forged in the database
        InstallationLicence::query()->update(['status' => 'active', 'token' => 'eyJmYWtlIjoxfQ.c2ln']);
        $this->assertSame(['unverified', 10], [$lic()['kind'], $lic()['max_users']]);                           // not the paid 25-user licence: only the 7-day key window
        InstallationLicence::query()->update(['key_saved_at' => now()->subDays(8)]);
        $this->assertSame('unlicensed', $lic()['state']);
    }

    private function expectsNoBadKeys(LicenceClient $client): void
    {
        try {
            $client->saveKey('RX-AAAA-BBBB-CCCC-ZZZZ');
            $this->fail('a mistyped key must be refused');
        } catch (\App\Domain\DomainException $e) {
            $this->assertSame('bad_key', $e->reason);
        }
    }

    public function test_install_takes_a_licence_file_and_hides_the_central_screens(): void
    {
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => '', 'ammp.licence.machine_id' => 'test-machine-2']);
        \Illuminate\Support\Facades\Cache::forget('rovexy.server_fingerprint');
        $fp = LicenceClient::fingerprint();
        $c = Company::query()->create(['slug' => 'onprem', 'name' => 'Acme', 'timezone' => 'UTC', 'default_country' => 'IN']);
        app(CurrentCompany::class)->set($c);
        $l = $this->onprem('perpetual', 40);
        $client = app(LicenceClient::class);
        try {
            $client->importFile(LicenceSigner::sign(LicenceAuthority::payload($l, 'file', self::FP2)));
            $this->fail('a file for another server must be refused');
        } catch (\App\Domain\DomainException $e) {
            $this->assertStringContainsString('another server', $e->getMessage());
        }
        $client->importFile(LicenceSigner::sign(LicenceAuthority::payload($l, 'file', $fp)));
        $now = tap(app(Licensing::class))->forget()->current();
        $this->assertSame(['active', 40, 'perpetual', null], [$now['state'], $now['max_users'], $now['kind'], $now['expires_at']]);
        $client->check();                                                                                       // never phones home over a file
        $this->assertSame('file', $client->row()->source);

        $this->getJson('/api/super/dashboard')->assertNotFound();                                               // no Super Admin on an install
        $this->get('/buy')->assertNotFound();
        $this->get('/')->assertRedirect('/admin');
        $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => $fp])->assertNotFound(); // an install is not a licence server

        // an on-prem install runs in fixed mode: a local request with no workspace header is its one company (not the dev workspace)
        config(['ammp.tenancy.mode' => 'fixed', 'ammp.tenancy.fixed_company_id' => $c->id]);
        app()['env'] = 'local';
        $this->getJson('/api/admin/auth/me')->assertStatus(401);                                                // reached the company; just not signed in
        app()['env'] = 'testing';
    }

    public function test_plan_ladders_are_validated_and_audited(): void
    {
        $p = $this->plan('starter');
        $base = ['code' => 'starter', 'name' => 'Starter', 'min_users' => 5, 'features' => ['calling'], 'is_public' => true, 'active' => true,
            'cloud_tiers' => [['min_users' => 5, 'max_users' => 50, 'rate_inr' => 150], ['min_users' => 51, 'max_users' => null, 'rate_inr' => 0]],
            'perpetual_bands' => [['min_users' => 5, 'max_users' => 10, 'price_inr' => 30000], ['min_users' => 11, 'max_users' => 50, 'price_inr' => 90000], ['min_users' => 51, 'max_users' => null, 'price_inr' => null]]];
        $put = fn (array $x) => $this->staff()->putJson("/api/super/plans/{$p->id}", $x + $base);
        $put(['perpetual_bands' => [['min_users' => 5, 'max_users' => 10, 'price_inr' => 30000], ['min_users' => 12, 'max_users' => 50, 'price_inr' => 90000]]])
            ->assertStatus(422)->assertJsonPath('error', 'bad_ladder');                                         // gap 10 → 12
        $put(['perpetual_bands' => [['min_users' => 5, 'max_users' => 10, 'price_inr' => 30000], ['min_users' => 11, 'max_users' => 50, 'price_inr' => 20000]]])
            ->assertStatus(422);                                                                                // price goes down
        $put(['cloud_tiers' => [['min_users' => 5, 'max_users' => null, 'rate_inr' => 0], ['min_users' => 6, 'max_users' => 9, 'rate_inr' => 1]]])
            ->assertStatus(422);                                                                                // open row not last
        $this->staff('sales')->putJson("/api/super/plans/{$p->id}", $base)->assertForbidden();                 // prices: super only
        $put([])->assertOk();
        $this->assertSame(60000.0, Pricing::perpetualPrice($this->plan('starter'), 30));                       // 30,000 + 20 × 60,000 ÷ 40
        $this->assertDatabaseHas('platform_audit', ['action' => 'plan.updated']);
    }

    public function test_website_sells_an_onprem_perpetual_licence_without_a_workspace(): void
    {
        $this->get('/buy?kind=perpetual&plan=business&users=40')->assertOk()->assertSee('Own · Perpetual', false)->assertSee('Pay securely');
        $this->postWithEmailCode('/buy', ['plan' => 'starter', 'kind' => 'perpetual', 'users' => 10, 'buyer_company' => 'Acme', 'buyer_name' => 'Imran', 'buyer_email' => 'it@acme.test',
            'buyer_phone' => '9800000000', 'state_code' => '29', 'terms' => '1'])->assertRedirect();
        $o = SalesOrder::query()->firstOrFail();
        $this->assertSame(['onprem', 'perpetual', null, null], [$o->deployment, $o->licence_kind, $o->workspace, $o->billing]);
        $this->assertSame([40000.0, 5000.0], array_map('floatval', array_column($o->lines, 'amount')));   // licence + setup
        Sales::markPaid($o, 'bank', 'U1');
        $l = Licence::query()->where('deployment', 'onprem')->firstOrFail();
        $this->assertSame(['perpetual', null, 10], [$l->kind, $l->company_id, $l->max_users]);
        $this->assertSame(0, Company::query()->count());                                                        // no cloud workspace made
        $this->post('/buy', ['plan' => 'starter', 'kind' => 'cloud', 'users' => 10, 'billing' => 'annual', 'buyer_company' => 'B', 'buyer_name' => 'B', 'buyer_email' => 'b@b.test',
            'buyer_phone' => '9800000001', 'state_code' => '29', 'terms' => '1'])->assertSessionHasErrors(['workspace', 'password']);   // cloud still needs them
    }

    public function test_cloud_licence_sold_as_a_key_is_added_in_the_workspace(): void
    {
        $mk = function (string $slug): array {
            $c = Company::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'timezone' => 'UTC', 'default_country' => 'IN']);
            $u = app(CurrentCompany::class)->run($c, function () use ($c, $slug) {
                CompanyDefaults::apply($c);
                $u = User::query()->create(['employee_code' => 'admin', 'name' => 'Admin', 'email' => "admin@{$slug}.test", 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $c->id]);
                return $u;
            });
            return [$c, fn () => $this->actingAs($u, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
                ->withHeaders(['X-Workspace' => $slug, 'Referer' => 'http://localhost/'])];
        };
        [$alpha, $asAlpha] = $mk('alpha');
        [, $asBravo] = $mk('bravo');
        $trial = LicenceIssuer::issue($this->plan('starter'), 'Alpha', null, $alpha->id, 'trial', 5, 'cloud', null, 'test');

        // staff sell a cloud licence with no workspace → a key is e-mailed
        $buyer = ['buyer_company' => 'Alpha', 'buyer_name' => 'A', 'buyer_email' => 'a@alpha.test', 'state_code' => '36'];
        $o = $this->staff()->postJson('/api/super/orders', ['kind' => 'new', 'deployment' => 'cloud', 'plan_id' => $this->plan('business')->id, 'users' => 20, 'billing' => 'annual'] + $buyer)->assertCreated()->json();
        Sales::markPaid(SalesOrder::query()->findOrFail($o['id']), 'bank', 'UTR1');
        $key = Licence::query()->where('deployment', 'cloud')->whereNull('company_id')->value('key');
        $this->assertNotNull($key);
        $this->assertSame(0, Company::query()->where('slug', '!=', 'alpha')->where('slug', '!=', 'bravo')->count());   // no workspace created

        $onprem = $this->onprem();
        $asAlpha()->postJson('/api/admin/licence/key', ['key' => $onprem->key])->assertStatus(422);                     // on-prem key
        $asAlpha()->postJson('/api/admin/licence/key', ['key' => 'RX-AAAA-BBBB-CCCC-DDDD'])->assertStatus(422);         // unknown
        $asAlpha()->postJson('/api/admin/licence/key', ['key' => strtolower($key)])->assertOk()->assertJsonPath('verdict', 'active');
        $asAlpha()->getJson('/api/admin/licence')->assertJsonPath('max_users', 20)->assertJsonPath('plan', 'Business')->assertJsonPath('licence_kind', 'subscription');
        $this->assertSame('superseded', $trial->fresh()->status);
        $asBravo()->postJson('/api/admin/licence/key', ['key' => $key])->assertStatus(422);                             // one workspace per key
        $asAlpha()->postJson('/api/admin/licence/key', ['key' => $key])->assertStatus(422);                             // already added

        // offline file (SmartEPT logic): a second cloud key, locked to bravo's workspace fingerprint
        $o2 = $this->staff()->postJson('/api/super/orders', ['kind' => 'new', 'deployment' => 'cloud', 'plan_id' => $this->plan('starter')->id, 'users' => 8, 'billing' => 'annual'] + $buyer)->assertCreated()->json();
        Sales::markPaid(SalesOrder::query()->findOrFail($o2['id']), 'bank', 'UTR2');
        $l2 = Licence::query()->where('deployment', 'cloud')->whereNull('company_id')->firstOrFail();
        $bravoFp = $asBravo()->getJson('/api/admin/licence')->json('server_id');
        $this->assertSame(40, strlen($bravoFp));
        $this->staff()->post("/api/super/licences/{$l2->id}/file", [])->assertStatus(422);                              // cloud file needs a fingerprint
        $lic = $this->staff()->post("/api/super/licences/{$l2->id}/file", ['fingerprint' => $bravoFp])->assertOk()->getContent();
        $up = fn ($as) => $as()->post('/api/admin/licence/file', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('x.lic', $lic)], ['Accept' => 'application/json']);
        $up($asAlpha)->assertStatus(422);                                                                               // locked to bravo
        $up($asBravo)->assertOk()->assertJsonPath('verdict', 'active');
        $up($asBravo)->assertOk();                                                                                      // re-import is harmless
        $asBravo()->getJson('/api/admin/licence')->assertJsonPath('max_users', 8);
    }
}
