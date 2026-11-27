<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\C2c\Vmn;
use App\Models\Call;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ExternalRef;
use App\Models\ImportJob;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\Smart31Number;
use App\Models\Team;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SmartDCM ⇄ ROVEXY: pull (calling, teams, users, portfolios, cases), push back (results), webhook, recordings, WebRTC. */
final class SmartDcmIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private array $u = [];
    /** What the fake SmartDCM answers; tests change it between syncs. */
    private array $dcm = [];
    private array $posted = [];
    /** What the fake Ametecs LC Manager answers (did_voucher_validate per voucher code, smart31-details). */
    private array $lc = ['vouchers' => [], 'smart31' => ['status' => 200, 'vmn_details' => []]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ammp.sessions.admin_mfa_required' => false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        $this->in(function () {
            CompanyDefaults::apply($this->a);
            $mk = function (string $code, string $role, ?string $email) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $email, 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                return $u;
            };
            $this->u = ['admin' => $mk('admin', 'company_admin', 'admin@alpha.test'), 'emp' => $mk('EMP1', 'employee', 'emp1@alpha.test')];
            $this->u['emp']->roles()->attach(Role::query()->where('key', 'telecaller')->value('id'), ['company_id' => $this->a->id]);
        });
        $this->dcm = [
            'ping' => ['ok' => true, 'company_name' => 'Alpha Recoveries (CRM 1133)', 'crm_id' => '1133'],
            'telephony' => ['crm_id' => '1133', 'c2c' => ['enabled' => true, 'gateway_secret' => 'gw-secret'],
                'numbers' => [['number' => '918040001111', 'click2call' => true, 'webrtc' => true, 'team_ids' => [7], 'expires_on' => '2027-12-31']],
                'smart31' => ['enabled' => true, 'numbers' => [['number' => '8040002222', 'group' => 'Pool A', 'team_ids' => [7]]]],
                'webrtc' => ['enabled' => true, 'wss_url' => 'wss://sip.telozar.test:7443', 'sip_domain' => 'sip.telozar.test']],
            'teams' => ['data' => [['id' => 7, 'name' => 'Hyderabad Team', 'branch' => ['id' => 2, 'name' => 'Hyderabad']]]],
            'users' => ['data' => [
                ['id' => 101, 'employee_code' => 'EMP1', 'name' => 'Emp One', 'email' => 'emp1@alpha.test', 'mobile' => '9811111111', 'role' => 'telecaller', 'team_ids' => [7], 'active' => true, 'updated_at' => '2026-11-20T10:00:00+05:30'],
                ['id' => 102, 'employee_code' => 'FOS9', 'name' => 'Field Nine', 'email' => 'fos9@alpha.test', 'mobile' => '9822222222', 'role' => 'fos', 'team_ids' => [7], 'active' => true,
                    'password_hash' => Hash::make('Dcm-pass-123'), 'updated_at' => '2026-11-20T10:00:00+05:30'],
                ['id' => 103, 'employee_code' => 'ADM', 'name' => 'Dcm Admin', 'role' => 'admin', 'active' => true, 'updated_at' => '2026-11-20T10:00:00+05:30'],
            ], 'next' => null],
            'portfolios' => ['data' => [['id' => 55, 'name' => 'HDFC Nov-26', 'bank' => 'HDFC', 'updated_at' => '2026-11-21T09:00:00+05:30']], 'next' => null],
            'cases' => ['data' => [
                ['id' => 9001, 'portfolio_id' => 55, 'account_no' => 'LN001', 'customer_name' => 'Ravi Kumar', 'mobile' => '9876543210', 'address' => '5 Lake Road', 'city' => 'Hyderabad',
                    'pos' => 12500, 'overdue_amount' => 3000, 'dpd' => 45, 'bucket' => 'B2', 'assigned_user_id' => 101, 'team_id' => 7, 'status' => 'open', 'extra' => ['Ref 1' => 'R-1'],
                    'updated_at' => '2026-11-22T09:00:00+05:30'],
                ['id' => 9002, 'portfolio_id' => 55, 'account_no' => 'LN002', 'customer_name' => 'Sita Devi', 'mobile' => '9876543211', 'assigned_user_id' => 102, 'status' => 'ptp',
                    'updated_at' => '2026-11-22T09:01:00+05:30'],
            ], 'next' => null],
        ];
        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (preg_match('#lc\.ametecsindia\.com/api/did_voucher_validate/([^/]+)/#', $r->url(), $m)) {
                if (($r->header('Secret-Key')[0] ?? '') !== 'lc-secret') {
                    return Http::response(['status' => 400, 'message' => 'Secret Key not matched']);
                }
                return Http::response($this->lc['vouchers'][$m[1]] ?? ['status' => 404, 'message' => 'Voucher not found']);
            }
            if (str_contains($r->url(), 'lc.ametecsindia.com/api/smart31-details/')) {
                return Http::response($this->lc['smart31']);
            }
            if (!str_starts_with($r->url(), 'https://dcm.test/api/rovexy/v1/')) {
                return Http::response('', 404);
            }
            if (($r->header('X-Api-Key')[0] ?? null) !== 'dcm-key') {
                return Http::response(['message' => 'bad key'], 401);
            }
            $p = substr($path, strlen('/api/rovexy/v1/'));
            if ($r->method() === 'POST') {
                $this->posted[$p][] = $r->data();
                if ($p === 'webrtc/calls') {
                    return Http::response(['dial' => '7001234', 'sip' => ['wss_url' => 'wss://sip.telozar.test:7443', 'domain' => 'sip.telozar.test', 'username' => '1133_101', 'password' => 'pw', 'display_name' => 'Emp One']]);
                }
                return Http::response(['accepted' => count($r->data()['items'] ?? [])]);
            }
            if (preg_match('#^webrtc/calls/([0-9a-f-]{36})$#', $p)) {
                return Http::response(['state' => 'completed', 'talk_seconds' => 42, 'ring_seconds' => 5, 'recording_url' => 'https://dcm.test/rec/x.mp3']);
            }
            if (preg_match('#^cases/(\d+)/trail$#', $p, $m)) {
                return Http::response(['case_url' => 'https://dcm.test/cases/' . $m[1], 'data' => [['at' => '2026-11-01T10:00:00+05:30', 'type' => 'call', 'code' => 'PTP', 'by' => 'Old caller']]]);
            }
            return Http::response($this->dcm[$p] ?? ['data' => [], 'next' => null]);
        });
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->run($this->a->fresh(), $fn);   // fresh: syncs save the company's settings
    }

    private function as(string $who)
    {
        return $this->actingAs($this->u[$who], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    private function connect(): array
    {
        return $this->as('admin')->putJson('/api/admin/integrations/smartdcm', ['base_url' => 'https://dcm.test/', 'api_key' => 'dcm-key', 'enabled' => true])->assertOk()->json();
    }

    public function test_connect_pulls_calling_teams_users_portfolios_and_cases(): void
    {
        $r = $this->connect();
        $this->assertTrue($r['ok']);
        $this->assertSame(48, strlen($r['inbound_secret']));   // shown once
        $this->in(function () {
            $team = Team::query()->where('name', 'Hyderabad Team')->firstOrFail();
            $this->assertSame('Hyderabad', $team->branch->name);
            // EMP1 already existed → linked, not duplicated; FOS9 created with SmartDCM's password; the SmartDCM admin is not created.
            $this->assertSame(1, User::query()->where('employee_code', 'EMP1')->count());
            $fos = User::query()->where('employee_code', 'FOS9')->firstOrFail();
            $this->assertTrue(Hash::check('Dcm-pass-123', $fos->password));
            $this->assertTrue($fos->roles()->where('key', 'employee')->exists());
            $this->assertNull(User::query()->where('employee_code', 'ADM')->first());
            $this->assertEqualsCanonicalizing([$team->id], $fos->teams()->pluck('teams.id')->all());
            // portfolio + cases with allocation
            $job = ImportJob::query()->where('format', 'smartdcm')->firstOrFail();
            $this->assertSame('HDFC Nov-26', $job->portfolio);
            $c = Customer::query()->where('customer_ref', 'LN001')->firstOrFail();
            $this->assertSame([$this->u['emp']->id, $team->id, $job->id, '+919876543210', 12500.0, 'B2', 'R-1'],
                [$c->assigned_user_id, $c->assigned_team_id, $c->last_import_id, $c->phone, (float) $c->outstanding, $c->details['x:Bucket'], $c->details['x:Ref 1']]);
            $this->assertSame('follow_up', Customer::query()->where('customer_ref', 'LN002')->value('status'));
            // one telephony account: CRM id, gateway secret, numbers for the team, Smart31 pool
            $this->assertSame('1133', $this->a->fresh()->setting('click2call.smartdcm.crm_id'));   // kept apart from the client's own CRM id
            $this->assertSame('choice', $this->a->fresh()->setting('click2call.mode'));
            $v = Vmn::query()->firstOrFail();
            $this->assertSame(['8040001111', [$team->id], false], [$v->virtual_number, $v->team_ids, $v->webrtc]);   // WebRTC is not offered in ROVEXY
            $this->assertSame('smartdcm', Smart31Number::query()->value('source'));
        });
        $show = $this->as('admin')->getJson('/api/admin/integrations/smartdcm')->assertOk();
        $this->assertSame(['teams' => 1, 'users' => 2, 'portfolios' => 1, 'cases' => 2], $show->json('linked'));
        $this->assertSame('Dcm Admin', $show->json('unmatched_users.0.name'));

        // Next sync asks only for what changed; a withdrawn case is withdrawn, and nothing is echoed back to SmartDCM.
        $this->dcm['cases'] = ['data' => [['id' => 9002, 'account_no' => 'LN002', 'withdrawn' => true, 'updated_at' => '2026-11-23T09:00:00+05:30']], 'next' => null];
        $this->as('admin')->postJson('/api/admin/integrations/smartdcm/sync')->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/cases?') && str_contains(urldecode($r->url()), 'updated_since=2026-11-22T09:01:00+05:30') && str_contains($r->url(), 'after_id=9002'));
        $this->in(fn () => $this->assertNotNull(Customer::query()->where('customer_ref', 'LN002')->value('withdrawn_at')));
        $this->assertSame(0, DB::table('integration_outbox')->where('system', 'smartdcm')->count());
    }

    public function test_people_sign_in_to_the_app_with_their_smartdcm_email_and_password(): void
    {
        // EMP1 is in ROVEXY as emp1@alpha.test; in SmartDCM it is ravi@agency.in with its own password.
        $this->dcm['users']['data'][0] += ['password_hash' => Hash::make('Ravi-dcm-77')];
        $this->dcm['users']['data'][0]['email'] = 'Ravi@Agency.in';
        $this->connect();
        $this->in(fn () => $this->assertSame('ravi@agency.in', $this->u['emp']->fresh()->email));

        $m = new \Tests\Support\MobileClient($this, 'alpha');
        $code = $m->register()->assertCreated()->json('approval_code');
        $device = app(CurrentCompany::class)->withoutScope(fn () => \App\Models\ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail());
        $this->as('admin')->postJson("/api/admin/devices/{$device->id}/approve", ['code' => $code, 'user_id' => $this->u['emp']->id])->assertOk();
        $m->deviceToken = $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->json('device_token');

        $login = fn (string $id, string $pw) => $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => $id, 'password' => $pw]);
        $login(' RAVI@agency.in ', 'Ravi-dcm-77')->assertOk()->assertJsonPath('employee.employee_code', 'EMP1');   // SmartDCM e-mail + password
        $login('EMP1', 'Ravi-dcm-77')->assertOk();                                                                  // employee code still works
        $login('ravi@agency.in', 'Secret-123456')->assertStatus(422);                                              // old ROVEXY password is gone
        $login('emp1@alpha.test', 'Ravi-dcm-77')->assertStatus(422);                                               // old e-mail is gone
        $login('fos9@alpha.test', 'Dcm-pass-123')->assertOk()->assertJsonPath('employee.employee_code', 'FOS9');   // created from SmartDCM

        // An e-mail another ROVEXY person already uses is never taken over.
        $this->dcm['users']['data'][1]['email'] = 'admin@alpha.test';
        $this->as('admin')->postJson('/api/admin/integrations/smartdcm/resync')->assertOk();
        $this->in(fn () => $this->assertSame('fos9@alpha.test', User::query()->where('employee_code', 'FOS9')->value('email')));
    }

    public function test_results_in_rovexy_go_back_to_smartdcm(): void
    {
        $this->connect();
        $id = $this->in(fn () => Customer::query()->where('customer_ref', 'LN001')->value('id'));
        $own = $this->in(fn () => Customer::query()->create(['customer_ref' => 'OWN1', 'name' => 'Own', 'phone' => '+919800000001', 'status' => 'new'])->id);
        $this->as('admin')->postJson("/api/admin/customers/{$id}/status", ['status' => 'closed'])->assertOk();
        $this->as('admin')->postJson("/api/admin/customers/{$own}/status", ['status' => 'closed'])->assertOk();   // not a SmartDCM case: stays here
        $this->as('admin')->postJson('/api/admin/customers/assign', ['customer_ids' => [$id], 'user_id' => $this->in(fn () => User::query()->where('employee_code', 'FOS9')->value('id'))])->assertOk();
        $this->in(fn () => app(\App\Services\FieldForceIntegration::class)->flushCompany($this->a));
        $events = collect($this->posted['case-events'] ?? [])->flatMap(fn ($b) => $b['items']);
        $this->assertSame(['status', 'allocation'], $events->pluck('type')->all());
        $this->assertSame(['9001', 'closed', 'new'], [$events[0]['case_id'], $events[0]['status'], $events[0]['previous_status']]);
        $this->assertSame(['102', 'FOS9'], [$events[1]['assigned_user_id'], $events[1]['assigned_employee_code']]);
        $this->assertSame(2, DB::table('integration_outbox')->where('system', 'smartdcm')->where('status', 'sent')->count());

        // A field entry with a PTP (no call) → a disposition for the SmartDCM case.
        $this->in(function () use ($id) {
            $call = Call::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'manual', 'user_id' => $this->u['emp']->id, 'customer_id' => $id,
                'destination' => '+919876543210', 'which_number' => 'primary', 'calling_mode' => 'field_entry', 'agent_leg' => 'none', 'state' => 'completed',
                'initiated_at' => now(), 'ended_at' => now(), 'talk_seconds' => 0, 'recording_status' => 'not_required']);
            \App\Models\CallDisposition::query()->create(['call_id' => $call->id, 'user_id' => $this->u['emp']->id, 'code' => 'PTP', 'notes' => 'Will pay Friday',
                'follow_up_at' => now()->addDays(3), 'submitted_at' => now(), 'amount' => 3000]);
            app(\App\Services\FieldForceIntegration::class)->flushCompany($this->a);
        });
        $d = $this->posted['dispositions'][0]['items'][0];
        $this->assertSame(['9001', 'LN001', '101', 'field_visit', 'PTP', 3000.0], [$d['case_id'], $d['account_no'], $d['user_id'], $d['channel'], $d['code'], (float) $d['ptp']['amount']]);

        // The customer page shows the case's SmartDCM history, read live.
        $this->as('admin')->getJson("/api/admin/customers/{$id}/smartdcm")->assertOk()->assertJsonPath('case_url', 'https://dcm.test/cases/9001')->assertJsonPath('data.0.code', 'PTP');
        $this->as('admin')->getJson("/api/admin/customers/{$own}/smartdcm")->assertOk()->assertJsonPath('linked', false);
    }

    public function test_webhook_needs_the_signature_and_recordings_need_the_secret(): void
    {
        $secret = $this->connect()['inbound_secret'];
        $uuid = $this->in(fn () => IntegrationConnection::query()->where('system', 'smartdcm')->value('uuid'));
        $body = json_encode(['event' => 'case.allocated', 'ids' => [9001]]);
        $ts = (string) time();
        $this->call('POST', "/api/integrations/smartdcm/{$uuid}/webhook", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ROVEXY_TIMESTAMP' => $ts, 'HTTP_X_ROVEXY_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->call('POST', "/api/integrations/smartdcm/{$uuid}/webhook", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ROVEXY_TIMESTAMP' => $ts,
            'HTTP_X_ROVEXY_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $body, $secret)], $body)->assertStatus(202);
        $this->call('POST', '/api/integrations/smartdcm/00000000-0000-0000-0000-000000000000/webhook')->assertNotFound();

        $call = $this->in(fn () => Call::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'click2call', 'user_id' => $this->u['emp']->id,
            'destination' => '+919876543210', 'which_number' => 'primary', 'agent_leg' => 'pstn_callback', 'state' => 'completed', 'initiated_at' => now(), 'recording_status' => 'pending']));
        $this->in(fn () => \App\Models\Recording::query()->create(['call_id' => $call->id, 'status' => 'pending']));
        $this->getJson("/api/integrations/smartdcm/{$uuid}/recordings/{$call->uuid}", ['X-Api-Key' => 'wrong'])->assertStatus(401);
        $this->getJson("/api/integrations/smartdcm/{$uuid}/recordings/{$call->uuid}", ['X-Api-Key' => $secret])->assertNotFound()->assertJsonPath('status', 'pending');
    }

    public function test_bad_data_never_blocks_the_sync_and_admins_are_never_changed(): void
    {
        // A duplicate number (two spellings), a hand-added Smart31 number SmartDCM also sends, an over-long account no., a bad date,
        // and a SmartDCM user with the ROVEXY admin's e-mail and a password of their own.
        $this->in(fn () => Smart31Number::query()->create(['number' => '8040002222', 'source' => 'admin', 'active' => true]));
        $this->dcm['telephony']['numbers'][] = ['number' => '8040001111', 'click2call' => true];
        $this->dcm['telephony']['smart31']['numbers'][] = ['number' => '8040009999', 'group' => 'Pool A', 'team_ids' => [7]];
        $this->dcm['users']['data'][] = ['id' => 104, 'employee_code' => 'X9', 'name' => 'Imposter', 'email' => 'admin@alpha.test', 'role' => 'manager', 'active' => false,
            'password_hash' => Hash::make('Taken-over-1'), 'updated_at' => '2026-11-20T10:00:00+05:30'];
        $this->dcm['cases']['data'][] = ['id' => 9003, 'account_no' => str_repeat('A', 90), 'customer_name' => 'Long', 'mobile' => '9876543212', 'due_date' => 'tomorrow',
            'city' => str_repeat('C', 300), 'updated_at' => '2026-11-22T09:02:00+05:30'];
        $this->connect();
        $this->in(function () {
            $this->assertSame(1, Vmn::query()->count());
            $this->assertSame('admin', Smart31Number::query()->where('number', '8040002222')->value('source'));   // the admin's own number stays
            $admin = $this->u['admin']->fresh();
            $this->assertSame(['admin', 'active'], [$admin->name, $admin->status]);
            $this->assertTrue(Hash::check('Secret-123456', $admin->password));
            $this->assertSame(3, Customer::query()->count());   // the long account no. is cut, not a failure
            $this->assertNull(Customer::query()->where('customer_ref', str_repeat('A', 64))->value('due_date'));
            // Smart31 rotation survives the next sync
            Smart31Number::query()->where('source', 'smartdcm')->update(['calls' => 7]);
        });
        $this->as('admin')->postJson('/api/admin/integrations/smartdcm/sync')->assertOk();
        $this->in(fn () => $this->assertSame(7, (int) Smart31Number::query()->where('number', '8040009999')->value('calls')));
    }

    /** 25 Nov 2026: a case ROVEXY gave an agent keeps that agent when SmartDCM sends the same account no. unallocated. */
    public function test_unallocated_smartdcm_case_keeps_the_rovexy_assignment(): void
    {
        $id = $this->in(fn () => Customer::query()->create(['customer_ref' => 'RAR318', 'name' => 'Alams', 'phone' => '+919876543299', 'assigned_user_id' => $this->u['emp']->id])->id);
        $this->dcm['cases']['data'][] = ['id' => 9010, 'account_no' => 'RAR318', 'customer_name' => 'Alams Paiker', 'mobile' => '9876543299', 'updated_at' => '2026-11-22T09:03:00+05:30'];
        $this->connect();
        $this->in(function () use ($id) {
            $c = Customer::query()->findOrFail($id);
            $this->assertSame([$this->u['emp']->id, 'Alams Paiker'], [$c->assigned_user_id, $c->name]);   // data updated, agent kept
            $this->assertSame(2, (int) Customer::query()->whereNotNull('assigned_user_id')->whereIn('customer_ref', ['LN001', 'LN002'])->count());   // SmartDCM allocations still apply
        });
    }

    /** 27 Nov 2026: SmartDCM's numbers are LC-checked by ROVEXY itself — a voucher revoked in the LC Manager stops them at once. */
    /** 27 Nov 2026: SmartDCM's voucher is used as it is (nothing typed again); its status and the LC Manager both stop it. */
    public function test_smartdcm_numbers_follow_the_lc_manager(): void
    {
        $this->dcm['telephony']['c2c']['lc_secret'] = 'lc-secret';   // SmartDCM's CRM key: ROVEXY asks the LC Manager itself
        $this->dcm['telephony']['numbers'] = [
            ['number' => '918040001111', 'click2call' => true, 'team_ids' => [7], 'voucher_code' => 'DCMV1', 'voucher_status' => 'active'],
            ['number' => '918040003333', 'click2call' => true, 'team_ids' => [7]],   // no voucher code: not usable
        ];
        $this->dcm['telephony']['smart31']['numbers'] = [['number' => '8040002222', 'group' => 'Pool A', 'team_ids' => [7]]];
        $this->lc['vouchers']['DCMV1'] = ['status' => 200, 'deleted' => []];
        $this->lc['smart31'] = ['status' => 200, 'vmn_details' => [['vmn' => '8040002222', 'usage_date' => now()->toDateString(), 'c2c' => 1, 'webrtc' => 1, 'group_name' => 'Pool A']]];
        $this->connect();
        $usable = fn () => $this->in(function () {
            \Illuminate\Support\Facades\Cache::flush();
            $emp = User::query()->where('employee_code', 'EMP1')->firstOrFail();
            return [collect(\App\Telephony\Click2CallSettings::numbersFor($emp))->where('available', true)->pluck('number')->all(),
                \App\Telephony\Click2CallSettings::smart31Available($emp)];
        });
        $this->in(fn () => \App\Telephony\LcGuard::refresh(true));
        $this->assertSame([['8040001111'], true], $usable());
        $page = $this->as('admin')->getJson('/api/admin/calling/numbers')->assertOk();
        $this->assertSame(['smartdcm', true, '1133', true], [$page->json('licence_source'), $page->json('smartdcm_linked'), $page->json('smartdcm_licence.crm_id'), $page->json('smartdcm_licence.key_from_smartdcm')]);
        $this->assertSame(['DCMV1'], collect($page->json('vouchers'))->pluck('voucher_code')->all());
        $this->as('admin')->postJson('/api/admin/calling/vouchers', ['voucher_code' => 'OWN1'])->assertStatus(422);   // no second copy while SmartDCM's licence is used

        // ROVEXY licence chosen: SmartDCM's numbers stop; the client's own CRM id / key / voucher are used instead.
        $this->as('admin')->putJson('/api/admin/calling/licence-source', ['source' => 'rovexy'])->assertOk()->assertJsonPath('licence_source', 'rovexy');
        $this->assertSame([[], false], $usable());   // no own voucher yet, no own CRM id
        $this->as('admin')->putJson('/api/admin/calling/licence-source', ['source' => 'smartdcm'])->assertOk();
        $this->assertSame([['8040001111'], true], $usable());

        // Revoked in the LC Manager: gone at the next check, and SmartDCM's next sync ("active") does not bring it back.
        $this->lc['vouchers']['DCMV1'] = ['status' => 400, 'message' => 'Voucher revoked'];
        $this->lc['smart31'] = ['status' => 200, 'vmn_details' => []];
        $this->in(fn () => \App\Telephony\LcGuard::refresh(true));
        $this->as('admin')->postJson('/api/admin/integrations/smartdcm/sync')->assertOk();
        $this->in(fn () => \App\Telephony\LcGuard::refresh(true));
        $this->assertSame([[], false], $usable());
        $this->assertSame('revoked', $this->in(fn () => \App\Models\C2c\Voucher::query()->where('voucher_code', 'DCMV1')->value('status')));
        $this->assertSame(['8040003333'], $this->in(fn () => \App\Models\C2c\Vmn::query()->pluck('virtual_number')->all()));   // kept, but not licensed

        // A new voucher in SmartDCM, no CRM key sent: SmartDCM's status decides. "expired" there stops it at once.
        unset($this->dcm['telephony']['c2c']['lc_secret']);
        $this->dcm['telephony']['numbers'] = [['number' => '918040004444', 'click2call' => true, 'team_ids' => [7], 'voucher_code' => 'DCMV2', 'voucher_status' => 'active']];
        $this->as('admin')->postJson('/api/admin/integrations/smartdcm/sync')->assertOk();
        $this->assertSame([['8040004444'], false], $usable());
        $this->dcm['telephony']['numbers'][0]['voucher_status'] = 'expired';
        $this->as('admin')->postJson('/api/admin/integrations/smartdcm/sync')->assertOk();
        $this->assertSame([[], false], $usable());
        $this->assertSame('expired', $this->in(fn () => \App\Models\C2c\Voucher::query()->where('voucher_code', 'DCMV2')->value('status')));
    }
}
