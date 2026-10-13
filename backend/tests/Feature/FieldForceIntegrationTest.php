<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntegrationConnection;
use App\Models\ManagedDevice;
use App\Models\Role;
use App\Models\User;
use App\Services\FieldForceIntegration;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MobileClient;
use Tests\TestCase;

/** Caller ⇄ SmartPRS2 ⇄ SmartEPT: geofenced sign-in, attendance + punch + location delivery via the outbox. */
final class FieldForceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $c;
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        $this->c = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($this->c, function () {
            CompanyDefaults::apply($this->c);
            foreach (['admin' => 'company_admin', 'EMP1' => 'employee'] as $code => $role) {
                $this->u[$code] = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $code === 'admin' ? 'admin@alpha.test' : null,
                    'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $this->u[$code]->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->c->id]);
            }
            IntegrationConnection::query()->create(['system' => 'smartprs', 'base_url' => 'https://prs.test', 'api_key' => 'sk_prs_x_1', 'enabled' => true, 'enforce_geofence' => true]);
            IntegrationConnection::query()->create(['system' => 'smartept', 'base_url' => 'https://ept.test', 'api_key' => 'sk_ept_1', 'enabled' => true]);
        });
    }

    private function phone(): MobileClient
    {
        $m = new MobileClient($this, 'alpha');
        $code = $m->register()->assertCreated()->json('approval_code');
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail());
        $this->actingAs($this->u['admin'], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/'])
            ->postJson("/api/admin/devices/{$device->id}/approve", ['code' => $code, 'user_id' => $this->u['EMP1']->id])->assertOk();
        $m->deviceToken = $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->json('device_token');
        return $m;
    }

    private function fakeRemotes(array $prsVerdict): void
    {
        Http::fake([
            'prs.test/api/v1/fieldforce/geofence/check' => Http::response(['ok' => true] + $prsVerdict),
            'prs.test/api/v1/fieldforce/attendance' => Http::response(['ok' => true, 'results' => []]),
            'ept.test/*' => Http::response(['ok' => true], 202),
        ]);
    }

    private function outbox(): \Illuminate\Support\Collection
    {
        return DB::table('integration_outbox')->orderBy('id')->get();
    }

    public function test_sign_in_outside_the_prs_geofence_is_blocked_and_nothing_is_recorded(): void
    {
        $this->fakeRemotes(['allowed' => false, 'status' => 'outside', 'message' => 'Outside your allowed area: 3.1 km.']);
        $m = $this->phone();

        $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456', 'lat' => 17.38, 'lng' => 78.48])
            ->assertStatus(422)->assertJsonPath('error', 'outside_geofence')->assertJsonPath('message', 'Outside your allowed area: 3.1 km.');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/geofence/check') && $r['emp_code'] === 'EMP1' && $r['lat'] == 17.38 && $r->header('X-Api-Key')[0] === 'sk_prs_x_1');
        $this->assertCount(0, $this->outbox());
        $this->assertSame(0, DB::table('work_sessions')->count());
    }

    public function test_sign_in_inside_records_attendance_in_prs_punch_and_trail_in_ept(): void
    {
        $this->fakeRemotes(['allowed' => true, 'status' => 'within', 'distance_km' => 0.1,
            'fence' => ['lat' => 17.46, 'lng' => 78.36, 'radius_km' => 0.5, 'outside' => 'strict', 'limit_km' => 0.5]]);
        $m = $this->phone();
        $r = $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456', 'lat' => 17.4601, 'lng' => 78.3601])->assertOk();
        $m->accessToken = $r->json('access_token');

        // A GPS fix 3 km away → SmartEPT location marked outside.
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'location',
            'occurred_at' => now()->toAtomString(), 'payload' => ['lat' => 17.4600, 'lng' => 78.3880, 'accuracy_m' => 12]]]])->assertOk();
        $m->work('POST', '/api/mobile/v1/auth/logout')->assertOk();

        $rows = $this->outbox();
        $this->assertSame(['smartprs.attendance', 'smartept.punch', 'smartept.location', 'smartprs.attendance', 'smartept.punch'],
            $rows->map(fn ($r) => "$r->system.$r->kind")->all());
        $loc = json_decode($rows[2]->payload, true);
        $this->assertSame('outside', $loc['geo_status']);
        $this->assertSame('OUT', json_decode($rows[4]->payload, true)['punch_type']);

        app(FieldForceIntegration::class)->flushCompany($this->c);
        $this->assertSame(['sent'], $this->outbox()->pluck('status')->unique()->values()->all());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/fieldforce/attendance') && $r['events'][0]['type'] === 'login');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/attendance/punches') && $r['punches'][0]['punch_type'] === 'IN' && $r['punches'][0]['geo_status'] === 'within');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/fieldforce/locations') && $r['locations'][0]['employee_code'] === 'EMP1');
    }

    public function test_prs_outage_does_not_lock_staff_out_and_delivery_retries_later(): void
    {
        Http::fake(['prs.test/*' => Http::response('down', 503), 'ept.test/*' => Http::response(['ok' => true], 202)]);
        $m = $this->phone();
        $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456'])->assertOk();

        app(FieldForceIntegration::class)->flushCompany($this->c);
        $prs = DB::table('integration_outbox')->where('system', 'smartprs')->first();
        $this->assertSame('pending', $prs->status);
        $this->assertSame(1, (int) $prs->attempts);
        $this->assertNotNull($prs->next_attempt_at);
        $this->assertSame('sent', DB::table('integration_outbox')->where('system', 'smartept')->value('status'));
    }
}
