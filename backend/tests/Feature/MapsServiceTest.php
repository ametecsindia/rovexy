<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Policy\PolicyCatalog;
use App\Models\Company;
use App\Models\IntegrationConnection;
use App\Models\ManagedDevice;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleMaps;
use App\Services\MapsPricing;
use App\Services\MapsUsage;
use App\Services\Platform\LicenceIssuer;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MobileClient;
use Tests\TestCase;

/** Google Maps service management (13 Nov 2026): licence switch, daily per-company usage, cost estimate, reports. */
final class MapsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $this->b = Company::query()->create(['slug' => 'beta', 'name' => 'Beta', 'timezone' => 'UTC', 'default_country' => 'IN']);
        foreach (['a' => $this->a, 'b' => $this->b] as $k => $co) {
            app(CurrentCompany::class)->run($co, function () use ($k, $co) {
                CompanyDefaults::apply($co);
                $mk = function (string $code, string $role, ?string $email = null) use ($co) {
                    $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $email, 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                    $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $co->id]);
                    return $u;
                };
                $this->u[$k] = $mk('admin', 'company_admin', "admin@{$co->slug}.test");
                if ($k === 'a') $this->u['emp'] = $mk('EMP1', 'employee');
                IntegrationConnection::query()->create(['system' => GoogleMaps::SERVER, 'base_url' => 'https://maps.googleapis.com', 'api_key' => 'server-key', 'enabled' => true]);
                IntegrationConnection::query()->create(['system' => GoogleMaps::DISPLAY, 'base_url' => 'https://maps.googleapis.com', 'api_key' => 'display-key', 'enabled' => true]);
            });
        }
    }

    private function admin(string $k = 'a')
    {
        return $this->actingAs($this->u[$k], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => $k === 'a' ? 'alpha' : 'beta', 'Referer' => 'http://localhost/']);
    }

    private function in(callable $fn, ?Company $c = null): mixed
    {
        return app(CurrentCompany::class)->run($c ?? $this->a, $fn);
    }

    private function phone(): MobileClient
    {
        $this->in(function () {
            $p = \App\Models\PolicyProfile::query()->first();
            $v = \App\Models\PolicyVersion::query()->where('profile_id', $p->id)->first();
            $v->forceFill(['settings' => $v->settings + ['compliance.mandatory' => ['calling.enabled', 'recording.mode'],
                'calling.hours' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '23:59']]])->save();
        });
        $m = new MobileClient($this, 'alpha');
        $code = $m->register()->assertCreated()->json('approval_code');
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail());
        $this->admin()->postJson("/api/admin/devices/{$device->id}/approve", ['code' => $code, 'user_id' => $this->u['emp']->id])->assertOk();
        $m->deviceToken = $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->json('device_token');
        $r = $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456'])->assertOk();
        [$m->accessToken, $m->refreshToken] = [$r->json('access_token'), $r->json('refresh_token')];
        $fp = $m->deviceCall('GET', '/api/mobile/v1/policy')->assertOk()->json('fingerprint');
        $m->deviceCall('POST', '/api/mobile/v1/policy/ack', ['fingerprint' => $fp, 'results' => array_fill_keys(array_keys(PolicyCatalog::all()), ['status' => 'applied', 'verified' => true])])->assertOk();
        return $m;
    }

    public function test_cost_follows_googles_free_cap_and_tiers_per_sku(): void
    {
        $this->assertSame(0.0, MapsPricing::usd('geocode', 70000));
        $this->assertSame(13.5, MapsPricing::usd('routes_2w', 10000));                  // (10,000 − 7,000 free) × $4.50 / 1,000
        $this->assertSame(7433.0, MapsPricing::usd('geocode', 5100000));                // 4,930,000 × $1.50 + 100,000 × $0.38, per 1,000
        $this->assertSame(63.0, MapsPricing::usd('maps_js', 100000));                   // 30,000 × $2.10 / 1,000
        // one shared Google account (Ametecs Maps): the account's cost shared by usage, free allowance counted once
        $e = MapsPricing::estimate(['routes_2w' => 5000], ['routes_2w' => 10000]);
        $this->assertSame(6.75, $e['usd']);
        $this->assertSame(0.0, MapsPricing::estimate(['routes_2w' => 5000])['usd']);   // alone: inside the free 7,000
        // a replaced price table (Super Admin) is used; a broken one is ignored
        $t = MapsPricing::DEFAULT;
        $t['skus']['geocode']['tiers'][0][1] = 2.0;
        \App\Services\Platform\PlatformSettings::set('maps_prices', json_encode($t));
        $this->assertSame(20.0, MapsPricing::usd('geocode', 80000));
        \App\Services\Platform\PlatformSettings::set('maps_prices', '{"skus":[]}');
        $this->assertSame(15.0, MapsPricing::usd('geocode', 80000));
    }

    public function test_every_google_call_is_counted_per_company_per_day_with_failures_and_shown_only_to_that_company(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::sequence()->push(['status' => 'OK', 'results' => [['formatted_address' => 'X', 'geometry' => ['location' => ['lat' => 17.4, 'lng' => 78.3], 'location_type' => 'ROOFTOP']]]])
            ->push(['status' => 'REQUEST_DENIED', 'error_message' => 'key'], 200),
            'routes.googleapis.com/*' => Http::response([['originIndex' => 0, 'destinationIndex' => 0, 'distanceMeters' => 1000, 'duration' => '60s', 'condition' => 'ROUTE_EXISTS'],
                ['destinationIndex' => 1, 'distanceMeters' => 2000, 'duration' => '90s', 'condition' => 'ROUTE_EXISTS']])]);
        $maps = app(GoogleMaps::class);
        $this->in(function () use ($maps) {
            $maps->geocode('Plot 5 Kondapur', 'IN');
            try { $maps->geocode('Plot 6 Kondapur', 'IN'); } catch (\App\Domain\DomainException) {}
            $maps->matrix([17.44, 78.39], [[17.45, 78.38], [17.46, 78.37]]);
            $maps->geocode('Plot 5 Kondapur', 'IN');   // cached: not sent, not counted
        });
        $rows = collect($this->in(fn () => MapsUsage::days(now()->toDateString(), now()->toDateString())))->keyBy('sku');
        $this->assertSame(['requests' => 2, 'units' => 2, 'failed' => 1], array_intersect_key($rows['geocode'], array_flip(['requests', 'units', 'failed'])));
        $this->assertSame(['requests' => 1, 'units' => 2, 'failed' => 0], array_intersect_key($rows['matrix'], array_flip(['requests', 'units', 'failed'])));

        $rep = $this->admin()->getJson('/api/admin/integrations/maps/report')->assertOk();
        $tot = collect($rep->json('totals'))->keyBy('sku');
        $this->assertSame([2, 1, 1], [$tot['geocode']['requests'], $tot['geocode']['ok'], $tot['geocode']['failed']]);
        $this->assertSame(3, $rep->json('daily.0.requests'));
        $this->assertCount(12, $rep->json('monthly'));
        $this->assertStringContainsString('Geocoding API', $this->admin()->get('/api/admin/integrations/maps/report?format=csv')->assertOk()->streamedContent());
        // the other company sees none of it
        $this->assertSame(0, collect($this->admin('b')->getJson('/api/admin/integrations/maps/report')->assertOk()->json('totals'))->sum('requests'));
    }

    public function test_map_loads_are_counted_and_stop_at_the_limit(): void
    {
        $m = $this->phone();
        $this->assertSame('display-key', $m->work('POST', '/api/mobile/v1/field/map-load')->assertOk()->json('map_key'));
        $this->assertSame(1, $this->in(fn () => MapsUsage::used()['maps_js']));
        $this->in(fn () => $this->a->forceFill(['settings' => array_merge($this->a->settings ?? [], ['maps_limits' => ['maps_js' => 1]])])->save());
        $this->assertNull($m->work('POST', '/api/mobile/v1/field/map-load')->assertOk()->json('map_key'));
        $this->assertSame(1, $this->in(fn () => MapsUsage::used()['maps_js']));
    }

    public function test_super_admin_switches_google_maps_off_for_one_company_and_sees_company_wise_usage(): void
    {
        $la = LicenceIssuer::issue(Plan::query()->where('code', 'cloud-own')->firstOrFail(), 'Alpha', 'a@alpha.test', $this->a->id, 'subscription', 10, 'cloud', 'quarterly', 'test');
        $this->in(fn () => MapsUsage::count('geocode', 1), $this->b);
        $staff = $this->actingAs(PlatformAdmin::query()->create(['email' => 's@a.test', 'name' => 'S', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']), 'platform')
            ->withHeaders(['Referer' => 'http://localhost/']);
        $staff->patchJson("/api/super/licences/{$la->id}", ['maps_enabled' => false, 'reason' => 'unpaid Maps'])->assertOk()->assertJsonPath('maps_enabled', false);

        Http::fake();
        $this->in(function () {
            app(\App\Services\Platform\Licensing::class)->forget();
            $this->assertFalse(GoogleMaps::allowed());
            $this->assertNull(app(GoogleMaps::class)->displayKey());
            try {
                app(GoogleMaps::class)->geocode('Plot 7', 'IN');
                $this->fail('a disabled company must not reach Google');
            } catch (\App\Domain\DomainException $e) {
                $this->assertSame('maps_disabled', $e->reason);
            }
        });
        Http::assertNothingSent();
        $this->assertTrue($this->in(fn () => GoogleMaps::allowed(), $this->b));   // only that company
        $this->admin()->getJson('/api/admin/integrations/maps')->assertOk()->assertJsonPath('licensed', false);

        $rows = collect($staff->getJson('/api/super/maps/usage')->assertOk()->json('rows'))->keyBy('slug');
        $this->assertFalse($rows['alpha']['maps_enabled']);
        $this->assertSame(1, $rows['beta']['requests']);
        $staff->getJson("/api/super/maps/usage/cloud/{$this->b->id}")->assertOk()->assertJsonPath('daily.0.geocode', 1);
        $staff->putJson('/api/super/maps/prices', ['table' => ['skus' => []]])->assertStatus(422);
    }

    public function test_on_prem_payload_carries_the_switch_and_prices_and_reported_usage_is_stored(): void
    {
        $l = LicenceIssuer::issue(Plan::query()->where('code', 'starter')->firstOrFail(), 'Gamma', 'g@gamma.test', null, 'perpetual', 10, 'onprem', null, 'test');
        $l->forceFill(['maps_enabled' => false])->save();
        $p = \App\Services\Licensing\LicenceAuthority::payload($l->fresh(), 'online', null);
        $this->assertFalse($p['maps']);
        $this->assertTrue(MapsPricing::valid($p['maps_prices']));
        MapsUsage::recordReported($l->id, [['day' => '2026-11-12', 'sku' => 'routes', 'requests' => 9, 'units' => 9, 'failed' => 1], ['day' => 'x', 'sku' => 'routes']]);
        MapsUsage::recordReported($l->id, [['day' => '2026-11-12', 'sku' => 'routes', 'requests' => 4, 'units' => 4, 'failed' => 0]]);   // never goes down
        $this->assertSame(9, (int) \Illuminate\Support\Facades\DB::table('licence_maps_usage')->where('licence_id', $l->id)->value('requests'));
    }
}
