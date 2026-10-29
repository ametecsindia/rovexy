<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Policy\PolicyCatalog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\ManagedDevice;
use App\Models\Role;
use App\Models\User;
use App\Services\AutoGeocode;
use App\Services\GoogleMaps;
use App\Services\MapsUsage;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MobileClient;
use Tests\TestCase;

/** Phase 2 of the 29 Oct 2026 gap analysis: G5 auto pins, G7 circles, G8 plans, G9 today's route, G12 usage limits, G19 fake GPS, G1 app page. */
final class Phase2FieldTest extends TestCase
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
            $mk = function (string $code, string $role, ?string $email = null) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $email, 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                return $u;
            };
            $this->u = ['admin' => $mk('admin', 'company_admin', 'admin@alpha.test'), 'emp' => $mk('EMP1', 'employee'), 'emp2' => $mk('EMP2', 'employee')];
        });
    }

    private function admin()
    {
        return $this->actingAs($this->u['admin'], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    private function phone(): MobileClient
    {
        app(CurrentCompany::class)->run($this->a, function () {   // only app-level controls mandatory (as in AcceptanceTest)
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

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->run($this->a, $fn);
    }

    private function mapsKey(): void
    {
        $this->in(fn () => IntegrationConnection::query()->create(['system' => GoogleMaps::SERVER, 'base_url' => 'https://maps.googleapis.com', 'api_key' => 'server-key', 'enabled' => true]));
    }

    private function upload(string $csv): void
    {
        $job = $this->admin()->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('a.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->admin()->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk();
        $this->admin()->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->admin()->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
    }

    // ── G5 ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_upload_pins_addresses_automatically_and_reuses_earlier_answers(): void
    {
        $this->mapsKey();
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => function ($req) {
            return match (true) {
                str_contains($req['address'], 'Nowhere') => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
                str_contains($req['address'], 'Kondapur, Hyderabad') && !str_contains($req['address'], 'Plot') => Http::response(['status' => 'OK', 'results' => [[
                    'formatted_address' => 'Kondapur', 'geometry' => ['location' => ['lat' => 17.46, 'lng' => 78.36], 'location_type' => 'APPROXIMATE']]]]),
                default => Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'Plot 5, Kondapur',
                    'geometry' => ['location' => ['lat' => 17.4612, 'lng' => 78.3549], 'location_type' => 'ROOFTOP']]]]),
            };
        }]);
        $this->upload("Customer ID,Customer Name,Phone Number,Caller ID,Address,City\n"
            . "A-1,Asha,9876544441,EMP1,Plot 5 Kondapur,Hyderabad\nA-2,Ravi,9876544442,EMP1,Plot 5 Kondapur,Hyderabad\n"
            . "A-3,Mani,9876544443,EMP1,Kondapur,Hyderabad\nA-4,Lata,9876544444,EMP1,Nowhere Lane,Hyderabad\n");
        $c = $this->in(fn () => Customer::query()->orderBy('customer_ref')->get()->keyBy('customer_ref'));
        $this->assertSame([17.4612, 'google', 'ROOFTOP'], [$c['A-1']->lat, $c['A-1']->geo_status, $c['A-1']->geo_precision]);
        $this->assertSame(17.4612, $c['A-2']->lat);
        $this->assertSame([null, 'approx'], [$c['A-3']->lat, $c['A-3']->geo_status]);   // area only: no pin
        $this->assertSame('not_found', $c['A-4']->geo_status);
        Http::assertSentCount(3);   // the same address twice = one Google call
        $this->assertSame(3, $this->in(fn () => MapsUsage::used()['geocode']));
        $job = $this->in(fn () => \App\Models\ImportJob::query()->latest('id')->value('id'));
        $this->admin()->getJson("/api/admin/imports/{$job}")->assertJsonPath('geo.pinned', 2)->assertJsonPath('geo.area_only', 1)->assertJsonPath('geo.not_found', 1)->assertJsonPath('geo.waiting', 0);

        // next month's file, same address for a new case: no new Google call; a changed address drops the old pin and is looked up
        $this->upload("Customer ID,Customer Name,Phone Number,Caller ID,Address,City\nA-5,Uma,9876544445,EMP1,Plot 5 Kondapur,Hyderabad\nA-1,Asha,9876544441,EMP1,Plot 9 Kondapur,Hyderabad\n");
        $this->in(function () {
            $this->assertSame(17.4612, Customer::query()->where('customer_ref', 'A-5')->value('lat'));
            $this->assertSame('google', Customer::query()->where('customer_ref', 'A-1')->value('geo_status'));
        });
        Http::assertSentCount(4);
    }

    // ── G12 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_monthly_limit_stops_paid_calls_and_the_admin_sees_usage(): void
    {
        $this->mapsKey();
        $this->admin()->putJson('/api/admin/integrations/maps/limits', ['limits' => ['geocode' => 1, 'matrix' => null]])->assertOk()
            ->assertJsonPath('usage.0.sku', 'geocode')->assertJsonPath('usage.0.limit', 1)->assertJsonPath('usage.1.limit', 70000);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'x', 'geometry' => ['location' => ['lat' => 17.4, 'lng' => 78.4], 'location_type' => 'ROOFTOP']]]])]);
        $this->a->refresh();   // the saved limit (a new request would load it fresh)
        $this->in(function () {
            Customer::query()->create(['customer_ref' => 'L1', 'name' => 'L1', 'phone' => '+919800000001', 'status' => 'new', 'address' => 'One Road', 'city' => 'Hyderabad']);
            Customer::query()->create(['customer_ref' => 'L2', 'name' => 'L2', 'phone' => '+919800000002', 'status' => 'new', 'address' => 'Two Road', 'city' => 'Hyderabad']);
            $r = AutoGeocode::run();
            $this->assertSame([1, 1], [$r['saved'], $r['left']]);
            $this->assertStringContainsString('limit', (string) $r['stopped']);
        });
        Http::assertSentCount(1);
        $this->admin()->getJson('/api/admin/integrations/maps')->assertJsonPath('usage.0.used', 1);
    }

    // ── G7 + G8 + G9 ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_circle_assignment_plan_optimise_publish_and_todays_route_on_the_phone(): void
    {
        $this->mapsKey();
        $emp = $this->u['emp'];
        $ids = $this->in(function () use ($emp) {
            $mk = fn ($ref, $lat, $lng, $user = null) => Customer::query()->create(['customer_ref' => $ref, 'name' => $ref, 'phone' => '+9198000' . random_int(10000, 99999), 'status' => 'new',
                'lat' => $lat, 'lng' => $lng, 'assigned_user_id' => $user])->id;
            return ['in1' => $mk('IN1', 17.441, 78.351), 'in2' => $mk('IN2', 17.445, 78.356), 'in3' => $mk('IN3', 17.438, 78.349, $emp->id),
                'other' => $mk('OTH', 17.443, 78.352, $this->u['emp2']->id), 'far' => $mk('FAR', 17.60, 78.60)];
        });
        $this->admin()->putJson("/api/admin/field/circles/{$emp->id}", ['lat' => 17.44, 'lng' => 78.35, 'radius_m' => 2000, 'name' => 'Kondapur'])->assertOk();
        // unassigned pins inside the circle go to EMP1; EMP2's case and the far one stay where they are
        $this->admin()->postJson("/api/admin/field/circles/{$emp->id}/assign")->assertOk()->assertJsonPath('assigned', 2);
        $this->in(fn () => $this->assertSame([$emp->id, $emp->id, $this->u['emp2']->id, null],
            Customer::query()->whereIn('id', [$ids['in1'], $ids['in2'], $ids['other'], $ids['far']])->orderBy('id')->pluck('assigned_user_id')->all()));
        $this->admin()->getJson('/api/admin/field/employees')->assertOk()->assertJsonPath('employees.0.name', 'EMP1')->assertJsonPath('employees.0.inside', 3);

        $show = $this->admin()->getJson("/api/admin/field/plans/{$emp->id}")->assertOk();
        $this->assertCount(3, $show->json('candidates'));
        $this->assertTrue($show->json('candidates.0.inside'));
        // save: straight-line order from the circle centre, no Google call
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 4200, 'duration' => '900s', 'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC'],
            'optimizedIntermediateWaypointIndex' => [1, 0], 'legs' => [['distanceMeters' => 1000, 'duration' => '200s'], ['distanceMeters' => 1200, 'duration' => '300s'], ['distanceMeters' => 2000, 'duration' => '400s']]]]])]);
        $p = $this->admin()->putJson("/api/admin/field/plans/{$emp->id}", ['stops' => [$ids['in2'], $ids['in1'], $ids['in3'], $ids['other']]])->assertOk();
        $this->assertSame([$ids['in1'], $ids['in3'], $ids['in2']], $p->json('stops'));   // EMP2's case dropped; nearest-next from the centre 17.44, 78.35
        $this->assertFalse($p->json('optimised'));
        Http::assertNothingSent();
        $this->phone()->work('GET', '/api/mobile/v1/field/plan')->assertOk()->assertJsonPath('plan', null);   // not published yet

        // optimise: ONE two-wheeler call; again with the same stops reuses it
        $o = $this->admin()->postJson("/api/admin/field/plans/{$emp->id}/optimise")->assertOk();
        $this->assertTrue($o->json('optimised'));
        $this->assertSame(4200, $o->json('distance_m'));
        $this->admin()->postJson("/api/admin/field/plans/{$emp->id}/optimise")->assertOk()->assertJsonPath('reused', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['travelMode'] === 'TWO_WHEELER' && ($r['optimizeWaypointOrder'] ?? false) === true && !isset($r['routingPreference']));
        $this->in(fn () => $this->assertSame(1, MapsUsage::used()['routes_2w']));

        $this->admin()->postJson("/api/admin/field/plans/{$emp->id}/publish")->assertOk()->assertJsonPath('status', 'published');
        $m = $this->phone();
        $plan = $m->work('GET', '/api/mobile/v1/field/plan')->assertOk()->json('plan');
        $this->assertSame([$o->json('stops'), 'published', []], [$plan['stops'], $plan['status'], $plan['visited']]);
        $this->assertNotEmpty($plan['points']);
        // check in at the first stop → shown as visited
        $first = $this->in(fn () => Customer::query()->find($plan['stops'][0]));
        $m->work('POST', "/api/mobile/v1/customers/{$first->id}/visit", ['lat' => $first->lat, 'lng' => $first->lng, 'accuracy_m' => 5])->assertOk();
        $m->work('GET', '/api/mobile/v1/field/plan')->assertJsonPath('plan.visited', [$first->id]);
        Http::assertSentCount(1);   // the phone never called Google

        // changing the stops makes it a draft again (must be re-published), and drops the optimised line
        $this->admin()->putJson("/api/admin/field/plans/{$emp->id}", ['stops' => [$ids['in1']]])->assertJsonPath('status', 'draft')->assertJsonPath('optimised', false);
        // EMP2 is not this admin's… (company admin sees all) — but a non-field user is refused
        $this->admin()->getJson("/api/admin/field/plans/{$this->u['admin']->id}")->assertNotFound();
    }

    // ── G19 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_fake_gps_check_in_is_refused_and_flagged(): void
    {
        $id = $this->in(fn () => Customer::query()->create(['customer_ref' => 'M1', 'name' => 'M1', 'phone' => '+919800000009', 'status' => 'new', 'lat' => 17.44, 'lng' => 78.35,
            'assigned_user_id' => $this->u['emp']->id])->id);
        $m = $this->phone();
        $m->work('POST', "/api/mobile/v1/customers/{$id}/visit", ['lat' => 17.44, 'lng' => 78.35, 'mock' => true])->assertStatus(422)->assertJsonPath('message', fn ($t) => str_contains($t, 'fake location'));
        $this->in(fn () => $this->assertSame(0, \App\Models\CustomerEvent::query()->where('type', 'visit')->count()));
        $ev = fn (bool $mock) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'location', 'occurred_at' => now()->toAtomString(), 'payload' => ['lat' => 17.44, 'lng' => 78.35, 'mock' => $mock]];
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [$ev(true), $ev(true), $ev(false)]])->assertOk();
        $this->in(function () {
            $this->assertSame(2, \App\Models\LocationEvent::query()->where('mock', true)->count());
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audit_events')->where('action', 'location.mock_detected')->count());   // once a day per phone
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audit_events')->where('action', 'customer.visit_mock_refused')->count());
        });
        $this->admin()->getJson('/api/admin/live-locations')->assertOk()->assertJsonPath('employees.0.fake_gps', true);
    }

    // ── G1 ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_app_download_page_on_the_companys_own_server(): void
    {
        $apk = public_path('downloads/rovexy.apk');
        @unlink($apk);
        $this->get('/get-app')->assertOk()->assertSee('not on this server');
        $this->get('/get-app/download')->assertNotFound();
        file_put_contents($apk, 'PK fake apk');
        file_put_contents(public_path('downloads/rovexy-apk.json'), json_encode(['version' => '1.0.9']));
        try {
            $this->get('/get-app')->assertOk()->assertSee('Download the ROVEXY app')->assertSee('Version 1.0.9')->assertSee('uninstall it first');
            $this->get('/get-app/download')->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        } finally {
            @unlink($apk);
            @unlink(public_path('downloads/rovexy-apk.json'));
        }
    }
}
