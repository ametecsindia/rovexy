<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Policy\PolicyCatalog;
use App\Models\Call;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\ManagedDevice;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Role;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\Platform\LicenceIssuer;
use App\Support\CurrentCompany;
use App\Telephony\TelephonyManager;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MobileClient;
use Tests\TestCase;

/** Phases 3 + 4 of the 29 Oct 2026 gap analysis: G6, G10, G11, G21 · G14, G15, G16, G22. */
final class Phase34Test extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $this->in(function () {
            CompanyDefaults::apply($this->a);
            $mk = function (string $code, string $role, ?string $email = null) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $email, 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                return $u;
            };
            $this->u = ['admin' => $mk('admin', 'company_admin', 'admin@alpha.test'), 'emp' => $mk('EMP1', 'employee', 'emp1@alpha.test')];
            $this->u['emp']->roles()->attach(Role::query()->where('key', 'telecaller')->value('id'), ['company_id' => $this->a->id]);   // G14: also a desk telecaller
        });
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->run($this->a, $fn);
    }

    private function as(string $who)
    {
        return $this->actingAs($this->u[$who], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
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
        $this->as('admin')->postJson("/api/admin/devices/{$device->id}/approve", ['code' => $code, 'user_id' => $this->u['emp']->id])->assertOk();
        $m->deviceToken = $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->json('device_token');
        $r = $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP1', 'password' => 'Secret-123456'])->assertOk();
        [$m->accessToken, $m->refreshToken] = [$r->json('access_token'), $r->json('refresh_token')];
        $fp = $m->deviceCall('GET', '/api/mobile/v1/policy')->assertOk()->json('fingerprint');
        $m->deviceCall('POST', '/api/mobile/v1/policy/ack', ['fingerprint' => $fp, 'results' => array_fill_keys(array_keys(PolicyCatalog::all()), ['status' => 'applied', 'verified' => true])])->assertOk();
        return $m;
    }

    // ── G6 + G11 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_accurate_check_in_becomes_the_verified_pin_and_survives_the_next_upload(): void
    {
        $this->assertSame(120, PolicyCatalog::standard()['location.interval_seconds']);   // G11
        $id = $this->in(fn () => Customer::query()->create(['customer_ref' => 'V1', 'name' => 'V1', 'phone' => '+919800000001', 'status' => 'new',
            'address' => '5 Lake Road', 'city' => 'Hyderabad', 'lat' => 17.44, 'lng' => 78.35, 'geo_status' => 'google', 'assigned_user_id' => $this->u['emp']->id])->id);
        $m = $this->phone();
        // ±60 m: a visit, but not accurate enough to move the pin
        $m->work('POST', "/api/mobile/v1/customers/{$id}/visit", ['lat' => 17.4402, 'lng' => 78.35, 'accuracy_m' => 60])->assertOk()->assertJsonPath('pin_verified', false);
        // ±8 m: the pin moves to where the employee stood, marked verified
        $m->work('POST', "/api/mobile/v1/customers/{$id}/visit", ['lat' => 17.4405, 'lng' => 78.3504, 'accuracy_m' => 8])->assertOk()->assertJsonPath('pin_verified', true);
        $c = $this->in(fn () => Customer::query()->find($id));
        $this->assertSame([17.4405, 78.3504, 'verified'], [$c->lat, $c->lng, $c->geo_status]);
        // next month's file carries the old lat/long for the same address: the verified pin is kept
        $csv = "Customer ID,Customer Name,Phone Number,Caller ID,Address,City,Latitude,Longitude\nV1,V1,9800000001,EMP1,5 Lake Road,Hyderabad,17.44,78.35\n";
        $job = $this->as('admin')->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('a.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->as('admin')->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk();
        $this->as('admin')->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->as('admin')->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        $this->in(fn () => $this->assertSame([17.4405, 'verified'], [Customer::query()->find($id)->lat, Customer::query()->find($id)->geo_status]));
        // another case at the same address gets the verified pin without Google
        $this->in(function () {
            \App\Models\IntegrationConnection::query()->create(['system' => 'gmaps', 'base_url' => 'https://maps.googleapis.com', 'api_key' => 'k', 'enabled' => true]);
            Customer::query()->create(['customer_ref' => 'V2', 'name' => 'V2', 'phone' => '+919800000002', 'status' => 'new', 'address' => '5 Lake Road', 'city' => 'Hyderabad']);
            Http::fake();
            \App\Services\AutoGeocode::run();
            Http::assertNothingSent();
            $this->assertSame(17.4405, Customer::query()->where('customer_ref', 'V2')->value('lat'));
        });
    }

    // ── G10 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_plan_board_shows_done_left_off_plan_and_alerts(): void
    {
        $emp = $this->u['emp'];
        [$a, $b, $c] = $this->in(fn () => array_map(fn ($r) => Customer::query()->create(['customer_ref' => $r, 'name' => $r, 'phone' => '+9198000' . random_int(10000, 99999),
            'status' => 'new', 'lat' => 17.44, 'lng' => 78.35, 'assigned_user_id' => $emp->id])->id, ['P1', 'P2', 'X1']));
        $this->in(function () use ($emp, $a, $b, $c) {
            RoutePlan::query()->create(['user_id' => $emp->id, 'plan_date' => now()->toDateString(), 'status' => 'published', 'start_lat' => 17.44, 'start_lng' => 78.35, 'stops' => [$a, $b]]);
            CustomerEvent::query()->create(['customer_id' => $a, 'user_id' => $emp->id, 'type' => 'visit', 'body' => 'x']);
            CustomerEvent::query()->create(['customer_id' => $c, 'user_id' => $emp->id, 'type' => 'visit', 'body' => 'x']);   // not in the plan
        });
        $r = $this->as('admin')->getJson('/api/admin/field/board')->assertOk();
        $r->assertJsonPath('plans.0.planned', 2)->assertJsonPath('plans.0.done', 1)->assertJsonPath('plans.0.off_plan', 1)
            ->assertJsonPath('plans.0.next.name', 'P2')->assertJsonPath('plans.0.alerts.0', 'No location today')->assertJsonPath('totals.alerts', 1);
    }

    // ── G21 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_exports_and_audit_pack_with_recordings_and_checksums(): void
    {
        $emp = $this->u['emp'];
        $this->in(function () use ($emp) {
            $c = Customer::query()->create(['customer_ref' => 'E1', 'name' => '=HYPERLINK("x")', 'phone' => '+919800000001', 'status' => 'new', 'assigned_user_id' => $emp->id]);
            CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $emp->id, 'type' => 'visit', 'body' => 'x', 'meta' => ['distance_m' => 12, 'accuracy_m' => 5, 'lat' => 17.4, 'lng' => 78.3]]);
            $call = Call::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'sim', 'user_id' => $emp->id, 'customer_id' => $c->id, 'destination' => '+919800000001',
                'which_number' => 'primary', 'agent_leg' => 'sim', 'state' => 'completed', 'initiated_at' => now(), 'recording_status' => 'available']);
            \Illuminate\Support\Facades\Storage::disk(config('ammp.recordings.disk'))->put('companies/x/rec.m4a', 'AUDIO-BYTES');
            \App\Models\Recording::query()->create(['call_id' => $call->id, 'status' => 'available', 'object_key' => 'companies/x/rec.m4a', 'format' => 'm4a', 'mime' => 'audio/mp4']);
            \App\Models\CallDisposition::query()->create(['call_id' => $call->id, 'user_id' => $emp->id, 'code' => 'PAID', 'submitted_at' => now(), 'amount' => 5000, 'payment_mode' => 'upi', 'paid_on' => now()->toDateString()]);
        });
        $q = '?from=' . now()->subDay()->toDateString() . '&to=' . now()->toDateString();
        $visits = $this->as('admin')->get("/api/admin/reports/export/visits.csv{$q}")->assertOk()->streamedContent();
        $this->assertStringContainsString('E1', $visits);
        $this->assertStringContainsString(',12,5,', $visits);
        $cust = $this->as('admin')->get("/api/admin/reports/export/customers.csv{$q}")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $cust);   // spreadsheet formula injection neutralised
        $this->as('admin')->get("/api/admin/reports/export/payments.csv{$q}")->assertOk();
        $this->as('admin')->get("/api/admin/reports/export/nope.csv{$q}")->assertNotFound();

        $res = $this->as('admin')->get("/api/admin/reports/audit-pack.zip{$q}")->assertOk();
        $file = $res->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        foreach (['customers.csv', 'calls.csv', 'visits.csv', 'payments.csv', 'follow-ups.csv', 'audit.csv', 'README.txt', 'MANIFEST.csv'] as $n) {
            $this->assertContains($n, $names);
        }
        $rec = collect($names)->first(fn ($n) => str_starts_with($n, 'recordings/'));
        $this->assertSame('AUDIO-BYTES', $zip->getFromName($rec));
        $this->assertStringContainsString($rec . ',11,' . hash('sha256', 'AUDIO-BYTES'), $zip->getFromName('MANIFEST.csv'));
        $zip->close();
        $this->in(fn () => $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audit_events')->where('action', 'report.audit_pack')->count()));
    }

    // ── G14 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_telecaller_calls_own_customer_from_the_console_with_click2call(): void
    {
        config(['ammp.telephony.routes' => ['sim', 'server'], 'ammp.telephony.driver' => 'click2call', 'ammp.telephony.click2call' => [
            'start_url' => 'https://sip.telozar.test/api/webrtc/c2c_call', 'cdr_url' => 'https://obd.gateway.test/admin/click2call_data',
            'gateway_secret' => 'gs', 'did' => '918047000000', 'agent_caller_id' => null, 'webhook_secret' => 'wh', 'timeout' => 15, 'timezone' => 'Asia/Kolkata']]);
        app(TelephonyManager::class)->swap(new \App\Telephony\Click2CallAdapter(config('ammp.telephony.click2call')));
        Http::fake(['sip.telozar.test/*' => Http::response(['call_id' => 'TZ9']), 'obd.gateway.test/*' => Http::response(['status' => 200, 'data' => ['response' => json_encode(['A_DIAL_STATUS' => 'ANSWERED'])]])]);
        $this->in(function () {
            $p = \App\Models\PolicyProfile::query()->first();
            $v = \App\Models\PolicyVersion::query()->where('profile_id', $p->id)->first();
            $v->forceFill(['settings' => $v->settings + ['calling.hours' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '23:59']]])->save();
        });
        [$mine, $other] = $this->in(fn () => [
            Customer::query()->create(['customer_ref' => 'T1', 'name' => 'Mine', 'phone' => '+919876543210', 'status' => 'new', 'assigned_user_id' => $this->u['emp']->id])->id,
            Customer::query()->create(['customer_ref' => 'T2', 'name' => 'Other', 'phone' => '+919876543211', 'status' => 'new'])->id]);
        $this->in(fn () => $this->assertTrue($this->u['emp']->fresh()->hasPermission('calls.console')));
        $this->as('admin')->getJson('/api/admin/my/customers')->assertForbidden();   // admins don't place calls here
        $list = $this->as('emp')->getJson('/api/admin/my/customers')->assertOk()->assertJsonPath('can_call', true)->assertJsonCount(1, 'data');
        $this->assertSame('•••••• 3210', $list->json('data.0.phone'));   // never the full number
        $this->as('emp')->postJson("/api/admin/my/customers/{$other}/call", ['which' => 'primary'])->assertStatus(422);   // not assigned to me
        $uuid = $this->as('emp')->postJson("/api/admin/my/customers/{$mine}/call", ['which' => 'primary'])->assertCreated()->json('call_id');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'c2c_call') && $r['a_party_no'] === '9811111111' && $r['b_party_no'] === '9876543210');
        $this->in(fn () => $this->assertNull(Call::query()->where('uuid', $uuid)->value('device_id')));
        $this->as('emp')->getJson("/api/admin/my/calls/{$uuid}")->assertOk()->assertJsonPath('state', 'ringing');
    }

    // ── G15 + G16 + G22 ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_calling_add_on_counts_deposits_amc_and_recording_verified(): void
    {
        $emp = $this->u['emp'];
        $this->in(function () use ($emp) {
            \App\Models\Smart31Number::query()->create(['number' => '8047000101', 'active' => true]);
            $mk = fn (array $x) => Call::query()->create($x + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $emp->id, 'destination' => '+919800000001', 'which_number' => 'primary', 'agent_leg' => 'pstn_callback',
                'state' => 'completed', 'initiated_at' => now()]);
            $mk(['provider' => 'click2call', 'caller_number' => '8047000101']);
            $mk(['provider' => 'click2call', 'caller_number' => '8047000000', 'is_test' => true]);
            $this->assertSame(['c2c_users' => 1, 'smart31_users' => 1], array_intersect_key(\App\Services\Platform\CallingUsage::forMonth(now()->format('Y-m')), ['c2c_users' => 1, 'smart31_users' => 1]));
        });
        $this->assertSame(550.0 + 350.0 * 2, \App\Services\Platform\CallingUsage::amount(3, 1));   // 2 Click2Call-only + 1 Smart31

        // on-prem report at check-in (never lowers a count) + deposits + AMC amount
        $dir = sys_get_temp_dir() . '/rovexy-licence-test';
        config(['ammp.licence.private_key' => "{$dir}/private.pem", 'ammp.licence.public_key' => "{$dir}/public.pem"]);
        if (!is_file("{$dir}/private.pem")) \App\Services\Licensing\LicenceSigner::generate();
        $l = LicenceIssuer::issue(Plan::query()->where('code', 'starter')->firstOrFail(), 'Acme', 'it@acme.test', null, 'perpetual', 50, 'onprem', null, 'test');
        $fp = str_repeat('c', 40);
        $month = now()->format('Y-m');
        $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => $fp, 'usage' => [['month' => $month, 'c2c_users' => 12, 'smart31_users' => 4]]])->assertOk();
        $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => $fp, 'usage' => [['month' => $month, 'c2c_users' => 3, 'smart31_users' => 1]]])->assertOk();
        $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => str_repeat('d', 40), 'usage' => [['month' => $month, 'c2c_users' => 999]]])->assertStatus(403);
        $staff = $this->actingAs(PlatformAdmin::query()->create(['email' => 's@ametecs.test', 'name' => 'S', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']), 'platform')
            ->withHeaders(['Referer' => 'http://localhost/']);
        $staff->postJson("/api/super/licences/{$l->id}/deposits", ['kind' => 'received', 'fos_count' => 50, 'on_date' => now()->toDateString(), 'reference' => 'UTR1'])
            ->assertOk()->assertJsonPath('deposit.balance', 50000)->assertJsonPath('deposit.fos', 50);
        $staff->postJson("/api/super/licences/{$l->id}/deposits", ['kind' => 'refund', 'amount' => 60000, 'on_date' => now()->toDateString()])->assertStatus(422);   // more than held
        $staff->postJson("/api/super/licences/{$l->id}/deposits", ['kind' => 'refund', 'fos_count' => 10, 'on_date' => now()->toDateString(), 'note' => 'reduced to 40'])->assertJsonPath('deposit.balance', 40000);
        $staff->patchJson("/api/super/licences/{$l->id}", ['amc_amount' => 12000, 'reason' => 'AMC amount'])->assertOk()->assertJsonPath('amc_amount', fn ($v) => (float) $v === 12000.0);
        $row = collect($staff->getJson("/api/super/billing-overview?month={$month}")->assertOk()->json('rows'))->firstWhere('licence_id', $l->id);
        $this->assertSame([12, 4, 'reported', 40000, 40], [$row['c2c_users'], $row['smart31_users'], $row['usage_source'], (int) $row['deposit_balance'], $row['deposit_fos']]);
        $this->assertSame(8 * 350 + 4 * 550, (int) $row['amount']);

        // G22: the first SIM recording from a phone marks its recorder verified
        config(['ammp.telephony.routes' => ['sim']]);
        $m = $this->phone();
        $cid = $this->in(fn () => Customer::query()->create(['customer_ref' => 'R1', 'name' => 'R1', 'phone' => '+919800000003', 'status' => 'new', 'assigned_user_id' => $emp->id])->id);
        $uuid = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'sim'])->assertCreated()->json('call_id');
        $m->work('POST', "/api/mobile/v1/calls/{$uuid}/sim-result", ['talk_seconds' => 20])->assertOk();
        $this->in(fn () => $this->assertNull(ManagedDevice::query()->first()->recording_verified_at));
        $m->upload("/api/mobile/v1/calls/{$uuid}/recording", [], ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('rec.wav', 'RIFF' . str_repeat("\x00", 4000))])->assertOk();
        $this->in(fn () => $this->assertNotNull(ManagedDevice::query()->first()->recording_verified_at));
        $this->as('admin')->getJson('/api/admin/devices')->assertOk()->assertJsonPath('data.0.recording_verified_at', fn ($v) => $v !== null);
    }
    public function test_employee_profile_for_the_live_map(): void
    {
        $this->in(fn () => Customer::query()->create(['customer_ref' => 'C9', 'name' => 'Cust', 'phone' => '+919822222222', 'assigned_user_id' => $this->u['emp']->id]));
        $this->as('admin')->getJson('/api/admin/employees/' . $this->u['emp']->id . '/profile')->assertOk()
            ->assertJsonPath('user.employee_code', 'EMP1')->assertJsonPath('work.customers_assigned', 1)->assertJsonPath('work.calls_30d', 0)->assertJsonStructure(['devices', 'user' => ['roles', 'teams']]);
        $this->as('emp')->getJson('/api/admin/employees/' . $this->u['admin']->id . '/profile')->assertForbidden();
    }
}
