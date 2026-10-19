<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ManagedDevice;
use App\Models\PolicyAcknowledgement;
use App\Models\Recording;
use App\Models\Role;
use App\Models\User;
use App\Domain\Policy\PolicyCatalog;
use App\Services\CallService;
use App\Support\CurrentCompany;
use App\Telephony\TelephonyManager;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelephony;
use Tests\Support\MobileClient;
use Tests\TestCase;

/**
 * Server-side acceptance criteria (numbers refer to the brief's §20). NOTE: written while composer could not run in the
 * build environment — execute with `composer test` after `composer install`.
 */
final class AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private array $users = [];
    private FakeTelephony $tel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tel = new FakeTelephony();
        app(TelephonyManager::class)->swap($this->tel);
        $this->a = $this->company('alpha');
        $this->b = $this->company('bravo');
    }

    private function company(string $slug): Company
    {
        $c = Company::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'timezone' => 'UTC', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($c, function () use ($c, $slug) {
            CompanyDefaults::apply($c);
            $mk = function (string $code, string $role, ?string $email = null) use ($c) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $email, 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $c->id]);
                return $u;
            };
            $this->users[$slug] = ['admin' => $mk('admin', 'company_admin', "admin@{$slug}.test"), 'emp' => $mk('EMP1', 'employee'), 'emp2' => $mk('EMP2', 'employee')];
            Customer::query()->create(['customer_ref' => 'C1', 'name' => 'Cust', 'phone' => '+919876543210', 'status' => 'new', 'assigned_user_id' => $this->users[$slug]['emp']->id]);
            Customer::query()->create(['customer_ref' => 'C2', 'name' => 'Other', 'phone' => '+919876500000', 'status' => 'new', 'assigned_user_id' => $this->users[$slug]['emp2']->id]);
        });
        return $c;
    }

    private function admin(string $slug)
    {
        return $this->actingAs($this->users[$slug]['admin'], 'web')
            ->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => $slug, 'Referer' => 'http://localhost/']); // Sanctum treats it as the SPA
    }

    private function approvedPhone(string $slug = 'alpha', string $emp = 'emp'): MobileClient
    {
        $m = new MobileClient($this, $slug);
        $code = $m->register()->assertCreated()->json('approval_code');
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail());
        $this->admin($slug)->postJson("/api/admin/devices/{$device->id}/approve", ['code' => $code, 'user_id' => $this->users[$slug][$emp]->id])->assertOk();
        $m->deviceToken = $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->json('device_token');
        return $m;
    }

    private function login(MobileClient $m, string $code = 'EMP1'): void
    {
        $r = $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => $code, 'password' => 'Secret-123456'])->assertOk();
        [$m->accessToken, $m->refreshToken] = [$r->json('access_token'), $r->json('refresh_token')];
    }

    private function ackAll(MobileClient $m, bool $verified = true): void
    {
        $fp = $m->deviceCall('GET', '/api/mobile/v1/policy')->assertOk()->json('fingerprint');
        $results = array_fill_keys(array_keys(PolicyCatalog::all()), ['status' => 'applied', 'verified' => $verified]);
        $m->deviceCall('POST', '/api/mobile/v1/policy/ack', ['fingerprint' => $fp, 'results' => $results])->assertOk();
    }

    /** Device-level controls report requires_setup in app_only mode, so for these server tests mandatory = app-level only. */
    private function appOnlyMandatory(): void
    {
        app(CurrentCompany::class)->run($this->a, function () {
            $p = \App\Models\PolicyProfile::query()->first();
            $v = \App\Models\PolicyVersion::query()->where('profile_id', $p->id)->first();
            $v->forceFill(['settings' => $v->settings + ['compliance.mandatory' => ['calling.enabled', 'recording.mode'],
                'calling.hours' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '23:59']]])->save();
        });
    }

    public function test_1_and_2_unapproved_phone_and_device_id_alone_get_nothing(): void
    {
        $m = new MobileClient($this, 'alpha');
        $m->register()->assertCreated()->assertJsonPath('state', 'pending')->assertJsonMissingPath('device_token');
        $m->signed('POST', '/api/mobile/v1/device/session')->assertStatus(403);
        // Device ID / installation ID without the private key:
        $this->withHeaders(['X-Workspace' => 'alpha', 'X-Installation-Id' => $m->installationId, 'X-Timestamp' => (string) time(), 'X-Nonce' => str_repeat('a', 20), 'X-Signature' => base64_encode('x')])
            ->postJson('/api/mobile/v1/device/session')->assertStatus(401);
        $this->withHeaders(['X-Workspace' => 'alpha'])->getJson('/api/mobile/v1/customers')->assertStatus(401);
        $this->withHeaders(['X-Workspace' => 'alpha'])->getJson('/api/mobile/v1/me?device=1&app=1')->assertStatus(401);
    }

    public function test_3_approved_device_with_unassigned_employee_cannot_work(): void
    {
        $m = $this->approvedPhone();
        $m->deviceCall('POST', '/api/mobile/v1/auth/login', ['employee_code' => 'EMP2', 'password' => 'Secret-123456'])->assertStatus(403)->assertJsonPath('error', 'not_assigned');
    }

    public function test_4_company_isolation(): void
    {
        $m = $this->approvedPhone('alpha');
        // Same token presented against company B's workspace: rejected.
        $m2 = new MobileClient($this, 'bravo');
        $m2->deviceToken = $m->deviceToken;
        $m2->deviceCall('GET', '/api/mobile/v1/policy')->assertStatus(401);
        // Admin of B cannot see or act on A's device/calls.
        $deviceA = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->first());
        $this->admin('bravo')->getJson("/api/admin/devices/{$deviceA->id}")->assertNotFound();
        $this->admin('bravo')->postJson("/api/admin/devices/{$deviceA->id}/revoke", ['reason' => 'x'])->assertNotFound();
        // Admin session of A presented with B workspace: rejected by MFA/company guard.
        $this->admin('alpha')->withHeaders(['X-Workspace' => 'bravo'])->getJson('/api/admin/overview')->assertStatus(403);
        // Unknown and conflicting workspace: rejected, never defaulted.
        $this->withHeaders(['X-Workspace' => 'nope'])->getJson('/api/mobile/v1/bootstrap')->assertNotFound();
    }

    public function test_5_and_6_revocation_is_immediate_and_reapproval_issues_fresh_credentials(): void
    {
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $m->work('GET', '/api/mobile/v1/me')->assertOk();
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->first());
        $this->admin('alpha')->postJson("/api/admin/devices/{$device->id}/revoke", ['reason' => 'lost'])->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertStatus(401);           // next request: blocked (bound = 0 s online)
        $m->deviceCall('GET', '/api/mobile/v1/policy')->assertStatus(401);
        $m->signed('GET', '/api/mobile/v1/device/status')->assertOk()->assertJsonPath('state', 'revoked');
        $m->signed('POST', '/api/mobile/v1/auth/refresh', ['refresh_token' => $m->refreshToken])->assertStatus(401);
        // Re-approval requires a new registration (new key); the old key can never come back.
        $m->register()->assertStatus(200)->assertJsonPath('state', 'revoked');
        $fresh = $this->approvedPhone();
        $this->assertNotSame($fresh->deviceToken, $m->deviceToken);
        $m->deviceCall('GET', '/api/mobile/v1/policy')->assertStatus(401);
    }

    public function test_7_duplicate_registration_is_idempotent_and_key_conflict_rejected(): void
    {
        $m = new MobileClient($this, 'alpha');
        $c1 = $m->register()->assertCreated()->json('approval_code');
        $c2 = $m->register()->assertOk()->json('approval_code');
        $this->assertSame($c1, $c2);
        $this->assertSame(1, app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->count()));
        $evil = new MobileClient($this, 'alpha');
        $evil->installationId = $m->installationId;
        $evil->register()->assertStatus(422)->assertJsonPath('error', 'installation_conflict');
    }

    public function test_8_and_9_unsupported_controls_reported_and_noncompliance_blocks_work(): void
    {
        app(CurrentCompany::class)->run($this->a, function () {   // a fully-managed requirement on an app-only phone
            $v = \App\Models\PolicyVersion::query()->first();
            $v->forceFill(['settings' => $v->settings + ['compliance.mandatory' => ['apps.mode']]])->save();
        });
        $m = $this->approvedPhone();
        $this->login($m);
        $policy = $m->deviceCall('GET', '/api/mobile/v1/policy')->json();
        $this->assertSame('requires_setup', $policy['controls']['device.camera']['status']);   // app_only: honest
        $this->ackAll($m);   // app CLAIMS all applied…
        $m->work('GET', '/api/mobile/v1/customers')->assertStatus(403)->assertJsonPath('error', 'device_not_compliant'); // …server disagrees
    }

    public function test_10_call_restrictions_enforced_server_side(): void
    {
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $ids = $m->work('GET', '/api/mobile/v1/customers')->assertOk()->json('visible_ids');
        $this->assertCount(1, $ids);
        $other = app(CurrentCompany::class)->withoutScope(fn () => Customer::query()->where('company_id', $this->a->id)->where('customer_ref', 'C2')->value('id'));
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $other, 'which' => 'primary'])->assertStatus(422)->assertJsonPath('error', 'customer_not_assigned');
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $ids[0], 'which' => 'primary', 'number' => '+911800000000'])->assertJsonPath('error', 'destination_mismatch');
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => null, 'which' => 'primary', 'number' => '+911800000000'])->assertJsonPath('error', 'manual_dial_disabled');
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $ids[0], 'which' => 'primary'])->assertCreated();
        $this->assertSame('919876543210', ltrim($this->tel->originated[0]['customer_e164'], '+'));
    }

    public function test_sim_route_call_result_and_phone_recording_upload(): void
    {
        config(['ammp.telephony.route' => 'sim']);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $r = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->assertCreated()
            ->assertJsonPath('route', 'sim')->assertJsonPath('dial', '+919876543210');
        $uuid = $r->json('call_id');
        $this->assertSame([], $this->tel->originated);                                 // nothing sent to telephony
        // Upload before the result is rejected; the result comes from the phone's call log.
        $wav = \Illuminate\Http\UploadedFile::fake()->createWithContent('Call_+919876543210.m4a', str_repeat("\0", 2000));
        $m->upload("/api/mobile/v1/calls/{$uuid}/recording", [], ['file' => $wav])->assertStatus(422)->assertJsonPath('error', 'call_not_ended');
        $m->work('POST', "/api/mobile/v1/calls/{$uuid}/sim-result", ['talk_seconds' => 42, 'ring_seconds' => 8])->assertOk()
            ->assertJsonPath('state', 'completed')->assertJsonPath('talk_seconds', 42)->assertJsonPath('recording_status', 'pending')->assertJsonPath('dial', null);
        $m->work('POST', "/api/mobile/v1/calls/{$uuid}/sim-result", ['talk_seconds' => 1])->assertJsonPath('talk_seconds', 42);  // idempotent
        $bad = \Illuminate\Http\UploadedFile::fake()->createWithContent('notes.txt', 'hello');
        $m->upload("/api/mobile/v1/calls/{$uuid}/recording", [], ['file' => $bad])->assertStatus(422);
        $m->upload("/api/mobile/v1/calls/{$uuid}/recording", ['duration_seconds' => 41], ['file' => $wav])->assertOk()->assertJsonPath('recording_status', 'available');
        $call = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->first());
        $this->admin('alpha')->postJson("/api/admin/calls/{$call->id}/recording/playback")->assertOk();
        // Unanswered call: no recording expected; a later call is not blocked by an unreported one.
        $u2 = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->json('call_id');
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->assertCreated();   // u2 never reported → auto-closed
        $m->work('POST', "/api/mobile/v1/calls/{$u2}/sim-result", ['talk_seconds' => 0])->assertJsonPath('state', 'no_answer')->assertJsonPath('recording_status', 'not_required');
        // Unanswered but the ringing was recorded: kept and uploadable.
        $u3 = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->json('call_id');
        $m->work('POST', "/api/mobile/v1/calls/{$u3}/sim-result", ['talk_seconds' => 0, 'recording_found' => true])->assertJsonPath('state', 'no_answer')->assertJsonPath('recording_status', 'pending');
        $m->upload("/api/mobile/v1/calls/{$u3}/recording", [], ['file' => $wav])->assertOk()->assertJsonPath('recording_status', 'available');
        // Admin dashboard + customer page reflect it.
        $this->admin('alpha')->getJson('/api/admin/overview')->assertOk()->assertJsonPath('employees_today.0.calls', 4)
            ->assertJsonPath('employees_today.0.connected', 1)->assertJsonPath('employees_today.0.recorded', 2)->assertJsonCount(7, 'trend_7d')
            ->assertJsonPath('kpis.calls', 4)->assertJsonPath('status.total', 1)->assertJsonPath('status.online', 1)->assertJsonCount(24, 'hourly')->assertJsonCount(7, 'trend');
        $this->admin('alpha')->getJson('/api/admin/overview?days=30')->assertOk()->assertJsonCount(30, 'trend')->assertJsonPath('range.days', 30);
        $this->admin('alpha')->getJson("/api/admin/customers/{$cid}")->assertOk()->assertJsonPath('stats.calls', 4)->assertJsonPath('stats.talk_seconds', 42);
        $this->admin('bravo')->getJson("/api/admin/customers/{$cid}")->assertNotFound();      // other company        // Delete: other company cannot; admin removes the call, its recording file and events.
        $this->admin('bravo')->deleteJson("/api/admin/calls/{$call->id}")->assertNotFound();
        $key = app(CurrentCompany::class)->withoutScope(fn () => $call->recording()->value('object_key'));
        $this->admin('alpha')->deleteJson("/api/admin/calls/{$call->id}")->assertOk();
        $this->admin('alpha')->getJson("/api/admin/calls/{$call->id}")->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Storage::disk(config('ammp.recordings.disk'))->exists($key));
    }

    /** Call feedback (9 Oct 2026): promise amount, payment with slip, admin check + e-mail, company isolation. */
    public function test_payment_feedback_slip_review_and_email(): void
    {
        config(['ammp.telephony.route' => 'sim', 'mail.default' => 'array']);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $codes = $m->work('GET', '/api/mobile/v1/dispositions?full=1')->assertOk();
        $this->assertSame('payment', collect($codes->json('codes'))->firstWhere('code', 'PAID')['captures']);
        $this->assertArrayHasKey('UPI', $codes->json('payment_modes'));
        $m->work('GET', '/api/mobile/v1/dispositions')->assertOk()->assertJsonPath('0.code', 'PAID');   // old app: plain list still works
        $call = function () use ($m, $cid) {
            $u = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->assertCreated()->json('call_id');
            $m->work('POST', "/api/mobile/v1/calls/{$u}/sim-result", ['talk_seconds' => 30])->assertOk();
            return $u;
        };
        // Payment: amount and mode are required; slip is uploaded with the feedback.
        $u1 = $call();
        $m->work('POST', "/api/mobile/v1/calls/{$u1}/disposition", ['code' => 'PAID'])->assertStatus(422)->assertJsonPath('error', 'amount_required');
        $m->work('POST', "/api/mobile/v1/calls/{$u1}/disposition", ['code' => 'PAID', 'amount' => 100])->assertStatus(422)->assertJsonPath('error', 'payment_mode_required');
        $slip = \Illuminate\Http\UploadedFile::fake()->image('slip.jpg', 400, 600);
        $m->upload("/api/mobile/v1/calls/{$u1}/disposition", ['code' => 'PAID', 'amount' => '1500.50', 'payment_mode' => 'UPI', 'payment_ref' => 'UTR123',
            'paid_on' => now()->toDateString(), 'notes' => 'paid by GPay'], ['slip' => $slip])->assertOk();
        // Promise via the offline queue: amount kept, not a payment.
        $u2 = $call();
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'disposition', 'occurred_at' => now()->toAtomString(),
            'payload' => ['call_id' => $u2, 'code' => 'CONNECTED_PTP', 'amount' => '5000', 'follow_up_at' => now()->addDays(3)->toAtomString()]]]])
            ->assertOk()->assertJsonPath('results.0.result', 'accepted');
        $ptp = app(CurrentCompany::class)->withoutScope(fn () => \App\Models\CallDisposition::query()->where('code', 'CONNECTED_PTP')->first());
        $this->assertSame('5000.00', $ptp->amount);
        $this->assertNull($ptp->payment_mode);
        // Admin: list (only the payment), slip, verify, e-mail.
        $list = $this->admin('alpha')->getJson('/api/admin/payments?status=pending')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '1500.50')->assertJsonPath('data.0.payment_mode', 'UPI')->assertJsonPath('data.0.has_slip', true)
            ->assertJsonPath('totals.pending.count', 1)->assertJsonMissingPath('data.0.slip_key');
        $id = $list->json('data.0.id');
        $this->assertStringStartsWith('image/', $this->admin('alpha')->get("/api/admin/payments/{$id}/slip")->assertOk()->headers->get('Content-Type'));
        $this->admin('bravo')->getJson('/api/admin/payments')->assertOk()->assertJsonCount(0, 'data');       // other company sees nothing
        $this->admin('bravo')->get("/api/admin/payments/{$id}/slip")->assertNotFound();
        $this->admin('alpha')->postJson("/api/admin/payments/{$id}/review", ['status' => 'verified', 'note' => 'matched bank'])->assertOk();
        $this->admin('alpha')->getJson('/api/admin/payments?status=verified')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reviewer.name', 'admin');
        $this->admin('alpha')->postJson("/api/admin/payments/{$id}/email", ['to' => ['not-an-email']])->assertStatus(422);
        $this->admin('alpha')->postJson("/api/admin/payments/{$id}/email", ['to' => ['accounts@alpha.test'], 'message' => 'Please book'])->assertOk();
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $msg = $sent[0]->getOriginalMessage();
        $this->assertStringContainsString('1,500.50', $msg->getHtmlBody());
        $this->assertCount(1, $msg->getAttachments());
        $this->admin('alpha')->getJson("/api/admin/calls/{$ptp->call_id}")->assertOk()->assertJsonPath('disposition.amount', '5000.00');
    }

    /** SmartDCM-style groups: bulk upload creates logins + group + manager mapping; a manager sees only their group. */
    public function test_bulk_upload_groups_and_manager_isolation(): void
    {
        $csv = "\xEF\xBB\xBFName,Group,Role,Phone\nAkshitha,Akshitha,manager,\nGulab,Gulab,manager,\nTele One,Akshitha,telecaller,9876501234\nTele Two,Akshitha,,\nTele Three,Gulab,telecaller,\nTele Four,Altaf,telecaller,\n";
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('users.csv', $csv);
        $bad = \Illuminate\Http\UploadedFile::fake()->createWithContent('bad.csv', "name,group,role\nX,,boss\n");
        $this->admin('alpha')->post('/api/admin/employees/bulk', ['file' => $bad, 'email_domain' => 'alpha.test'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('rows.0.line', 2);
        $r = $this->admin('alpha')->post('/api/admin/employees/bulk', ['file' => $file(), 'email_domain' => 'alpha.test'], ['Accept' => 'application/json'])->assertCreated();
        $rows = collect($r->json('created'));
        $this->assertCount(6, $rows);
        $t1 = $rows->firstWhere('name', 'Tele One');
        $this->assertSame('TC0001', $t1['employee_code']);
        $this->assertSame('tc0001@alpha.test', $t1['email']);
        $this->assertSame('Akshitha', $t1['group']);
        $this->assertSame('Akshitha', $t1['manager']);
        $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*\d).{10}$/', $t1['password']);
        $this->assertSame(['Altaf'], $r->json('groups_without_manager'));
        // Same names again → duplicate codes are generated fresh, not clashing.
        $this->admin('alpha')->post('/api/admin/employees/bulk', ['file' => $file(), 'email_domain' => 'alpha.test'], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('created.2.employee_code', 'TC0005');
        // The generated password is the one stored (hashed) for the telecaller.
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($t1['password'], app(CurrentCompany::class)->run($this->a, fn () => User::query()->where('employee_code', 'TC0001')->value('password'))));
        // Manager of Akshitha sees only Akshitha people, customers and teams; admin sees everyone.
        [$mgr, $teleGulab] = app(CurrentCompany::class)->run($this->a, fn () => [User::query()->where('employee_code', 'MGR0001')->first(), User::query()->where('name', 'Tele Three')->first()]);
        app(CurrentCompany::class)->run($this->a, fn () => Customer::query()->create(['customer_ref' => 'G1', 'name' => 'Gulab cust', 'phone' => '+919876511111', 'status' => 'new', 'assigned_user_id' => $teleGulab->id]));
        config(['ammp.sessions.admin_mfa_required' => false]);   // bulk-created managers have no authenticator
        $asMgr = $this->actingAs($mgr, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
        $names = collect($asMgr->getJson('/api/admin/employees')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Akshitha', 'Akshitha', 'Tele One', 'Tele One', 'Tele Two', 'Tele Two'], $names);
        $this->assertSame(['Akshitha'], collect($asMgr->getJson('/api/admin/teams')->json())->pluck('name')->all());
        $this->assertNotContains('Gulab cust', collect($asMgr->getJson('/api/admin/customers')->json('data'))->pluck('name')->all());
        $asMgr->getJson('/api/admin/locations?user_id=' . $teleGulab->id)->assertForbidden();
        $asMgr->post('/api/admin/employees/bulk', ['file' => $file(), 'email_domain' => 'alpha.test'], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertContains('Gulab cust', collect($this->admin('alpha')->getJson('/api/admin/customers')->json('data'))->pluck('name')->all());
        $teams = collect($this->admin('alpha')->getJson('/api/admin/teams')->json());
        $this->assertSame(['Akshitha', 'Altaf', 'Gulab'], $teams->pluck('name')->sort()->values()->all());
        $this->assertSame('Akshitha', $teams->firstWhere('name', 'Akshitha')['managers'][0]['name']);
    }

    public function test_exotel_click_to_call_result_and_recording(): void
    {
        config(['ammp.telephony.route' => 'server', 'ammp.telephony.driver' => 'exotel', 'ammp.telephony.exotel' => [
            'sid' => 'acme1', 'api_key' => 'k', 'api_token' => 't', 'subdomain' => 'api.in.exotel.com', 'caller_id' => '08047000000', 'call_type' => 'trans',
            'recording_channels' => 'dual', 'ring_timeout' => 30, 'time_limit' => 3600, 'timezone' => 'Asia/Kolkata']]);
        app(TelephonyManager::class)->swap(new \App\Telephony\ExotelAdapter(config('ammp.telephony.exotel')));
        $status = 'in-progress';
        \Illuminate\Support\Facades\Http::fake([
            'api.in.exotel.com/v1/Accounts/acme1/Calls/connect.json' => \Illuminate\Support\Facades\Http::response(['Call' => ['Sid' => 'EXO123', 'Status' => 'queued']]),
            'api.in.exotel.com/v1/Accounts/acme1/Calls/EXO123.json*' => function () use (&$status) {
                return \Illuminate\Support\Facades\Http::response(['Call' => ['Sid' => 'EXO123', 'Status' => $status, 'Duration' => 50, 'StartTime' => '2026-10-01 17:00:00', 'EndTime' => '2026-10-01 17:00:50',
                    'RecordingUrl' => $status === 'completed' ? 'https://recordings.exotel.com/acme1/EXO123.mp3' : null, 'Details' => ['ConversationDuration' => 38, 'Leg1Status' => 'completed', 'Leg2Status' => 'completed']]]);
            },
            'recordings.exotel.com/*' => \Illuminate\Support\Facades\Http::response('ID3' . str_repeat("\x00", 4000)),
        ]);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $uuid = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->assertCreated()->assertJsonPath('route', 'server')->json('call_id');
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_ends_with($req->url(), '/Calls/connect.json') && $req['From'] === '+919811111111'
            && $req['To'] === '+919876543210' && $req['CallerId'] === '08047000000' && $req['Record'] === 'true');
        $m->work('GET', "/api/mobile/v1/calls/{$uuid}")->assertJsonPath('state', 'answered');
        $status = 'completed';
        \Illuminate\Support\Facades\Cache::flush();   // per-call sync throttle
        $m->work('GET', "/api/mobile/v1/calls/{$uuid}")->assertJsonPath('state', 'completed')->assertJsonPath('talk_seconds', 38)
            ->assertJsonPath('ring_seconds', 12)->assertJsonPath('recording_status', 'available');
        $call = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->first());
        $this->assertSame('mp3', app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->where('call_id', $call->id)->value('format')));
        $this->admin('alpha')->postJson("/api/admin/calls/{$call->id}/recording/playback")->assertOk();
    }

    public function test_11_and_12_duplicate_events_and_recording_failure_visible(): void
    {
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $uuid = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->json('call_id');
        $svc = app(CallService::class);
        $ev = fn ($type, $id, $at, $extra = []) => $svc->ingest(['call_id' => $uuid, 'company_id' => $this->a->id, 'provider_event_id' => $id, 'type' => $type, 'at' => $at] + $extra);
        $this->assertSame('applied', $ev('agent_answered', 'e1', '2026-10-01T06:00:05Z'));
        $this->assertSame('duplicate', $ev('agent_answered', 'e1', '2026-10-01T06:00:05Z'));
        $ev('customer_answered', 'e2', '2026-10-01T06:00:10Z');
        $ev('hangup', 'e3', '2026-10-01T06:01:10Z', ['cause' => 'NORMAL_CLEARING']);
        $this->assertSame('duplicate', $ev('hangup', 'e3', '2026-10-01T06:01:10Z', ['cause' => 'NORMAL_CLEARING']));
        $call = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->first());
        $this->assertSame('completed', $call->state);
        $this->assertSame(60, $call->talk_seconds);
        $this->assertSame(1, app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->count()));
        // No recording file exists → FetchRecording (sync queue) ends Failed, never Available.
        $rec = app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->where('call_id', $call->id)->first());
        $this->assertNotSame('available', $rec->status);
        // Wrong company tag on an event is ignored.
        $this->assertSame('ignored', $svc->ingest(['call_id' => $uuid, 'company_id' => $this->b->id, 'provider_event_id' => 'x', 'type' => 'hangup', 'at' => '2026-10-01T06:02:00Z']));
    }

    public function test_13_and_14_offline_sync_idempotent_and_lease_enforced(): void
    {
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $evt = ['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'activity', 'occurred_at' => now()->subMinute()->toAtomString(), 'payload' => ['type' => 'idle_start']];
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [$evt]])->assertJsonPath('results.0.result', 'accepted');
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [$evt]])->assertJsonPath('results.0.result', 'duplicate');
        $late = ['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'activity', 'occurred_at' => now()->addMinutes(2)->toAtomString(), 'payload' => ['type' => 'idle_end']];
        app(CurrentCompany::class)->withoutScope(fn () => \App\Models\WorkSession::query()->whereNull('ended_at')->update(['lease_expires_at' => now()->subMinute()]));
        $this->travel(2)->minutes();
        // Lease expired before the event happened → rejected.
        $late['occurred_at'] = now()->toAtomString();
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [$late]])->assertJsonPath('results.0.reason', 'event_after_lease_expiry');
    }

    public function test_15_media_session_requires_employee_acceptance_and_ends_on_revoke(): void
    {
        app(CurrentCompany::class)->run($this->a, function () {
            $v = \App\Models\PolicyVersion::query()->first();
            $v->forceFill(['settings' => $v->settings + ['live_view.enabled' => true]])->save();
        });
        $m = $this->approvedPhone();
        $this->login($m);
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->first());
        app(CurrentCompany::class)->run($this->a, fn () => app(\App\Services\PolicyService::class)->refreshDevice($device));
        $uuid = $this->admin('alpha')->postJson('/api/admin/media-sessions', ['device_id' => $device->id, 'kind' => 'screen', 'purpose' => 'Help with app'])->assertCreated()->json('uuid');
        $m->work('POST', "/api/mobile/v1/media/{$uuid}/start")->assertStatus(422);   // cannot start before accepting
        $m->work('POST', "/api/mobile/v1/media/{$uuid}/accept")->assertOk();
        $m->work('POST', "/api/mobile/v1/media/{$uuid}/start")->assertOk()->assertJsonPath('state', 'active');
        $this->admin('alpha')->postJson("/api/admin/devices/{$device->id}/revoke", ['reason' => 'test'])->assertOk();
        $this->assertSame('revoked', app(CurrentCompany::class)->withoutScope(fn () => \App\Models\MediaSession::query()->where('uuid', $uuid)->value('end_reason')));
    }

    public function test_16_policy_available_without_push(): void
    {
        $m = $this->approvedPhone();   // no push token ever registered
        $m->deviceCall('GET', '/api/mobile/v1/policy')->assertOk()->assertJsonStructure(['fingerprint', 'values', 'controls']);
    }

    public function test_17_logout_keeps_device_approval_and_policy(): void
    {
        $m = $this->approvedPhone();
        $this->login($m);
        $m->work('POST', '/api/mobile/v1/auth/logout')->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertStatus(401);
        $m->deviceCall('GET', '/api/mobile/v1/policy')->assertOk();
        $this->assertSame('approved', app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->first()->approval_state->value));
    }

    public function test_18_import_validation_and_reassignment(): void
    {
        $csv = "Customer ID,Customer Name,Phone Number,Assigned Employee\nN1,New One,9000011111,EMP1\nN2,,bad,EMP1\nC1,Cust,9000022222,EMP2\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('import.csv', $csv);
        $job = $this->admin('alpha')->post('/api/admin/imports', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk()
            ->assertJsonCount(2, 'result.valid');
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        $status = $this->admin('alpha')->getJson("/api/admin/imports/{$job['id']}")->assertOk()->json();
        $this->assertSame('completed', $status['status']);
        $this->assertSame(1, $status['created_count']);
        $this->assertSame(1, $status['updated_count']);
        $this->assertSame(1, $status['error_rows']);
        $c1 = app(CurrentCompany::class)->withoutScope(fn () => Customer::query()->where('company_id', $this->a->id)->where('customer_ref', 'C1')->first());
        $this->assertSame($this->users['alpha']['emp2']->id, $c1->assigned_user_id);
        $this->admin('alpha')->postJson('/api/admin/customers/withdraw', ['customer_ids' => [$c1->id]])->assertOk()->assertJsonPath('withdrawn', 1);
    }

    /** SmartDCM lead upload: random allocation inside a group, Caller ID-wise allocation, managers limited to their group. */
    public function test_lead_upload_random_and_caller_id_allocation_with_manager_scope(): void
    {
        config(['ammp.sessions.admin_mfa_required' => false]);
        $people = "name,group,role,employee_code\nMgr A,Akshitha,manager,MA\nMgr B,Gulab,manager,MB\nTele A1,Akshitha,telecaller,TA1\nTele A2,Akshitha,telecaller,TA2\nTele B1,Gulab,telecaller,TB1\n";
        $this->admin('alpha')->post('/api/admin/employees/bulk', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('p.csv', $people), 'email_domain' => 'alpha.test'], ['Accept' => 'application/json'])->assertCreated();
        $ctx = app(CurrentCompany::class);
        [$teamA, $teamB, $ids] = $ctx->run($this->a, fn () => [\App\Models\Team::query()->where('name', 'Akshitha')->value('id'), \App\Models\Team::query()->where('name', 'Gulab')->value('id'),
            User::query()->pluck('id', 'employee_code')]);
        $run = function ($as, string $csv, array $opts) {
            $job = $as->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('leads.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
            $prev = $as->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']] + $opts);
            if ($prev->status() !== 200) {
                return [$job, $prev];
            }
            $as->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
            $as->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
            return [$job, $as->getJson("/api/admin/imports/{$job['id']}")->assertOk()];
        };
        $leads = "Customer ID,Customer Name,Phone Number\n" . implode("\n", array_map(fn ($i) => "R{$i},Lead {$i},90000300{$i}0", range(1, 5))) . "\n";
        // 1. Admin, random allocation to all telecallers of Akshitha → even split, all in that group.
        [, $st] = $run($this->admin('alpha'), $leads, ['allocation' => 'random', 'team_id' => $teamA]);
        $st->assertJsonPath('status', 'completed')->assertJsonPath('created_count', 5);
        $counts = $ctx->run($this->a, fn () => Customer::query()->where('customer_ref', 'like', 'R%')->get()->countBy('assigned_user_id')->all());
        ksort($counts);
        $this->assertEqualsCanonicalizing([$ids['TA1'], $ids['TA2']], array_keys($counts));
        $this->assertLessThanOrEqual(1, abs(array_values($counts)[0] - array_values($counts)[1]));
        $this->assertSame(5, $ctx->run($this->a, fn () => Customer::query()->where('customer_ref', 'like', 'R%')->where('assigned_team_id', $teamA)->count()));
        // A caller from another group cannot be picked for a group.
        [, $bad] = $run($this->admin('alpha'), $leads, ['allocation' => 'random', 'team_id' => $teamA, 'user_ids' => [$ids['TB1']]]);
        $bad->assertStatus(422)->assertJsonPath('error', 'caller_not_in_group');
        // 2. Manager A: random into group B is refused; Caller ID-wise rows for group B callers / customers are rejected.
        $mgr = $ctx->run($this->a, fn () => User::query()->where('employee_code', 'MA')->first());
        $asMgr = $this->actingAs($mgr, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
        [, $no] = $run($asMgr, $leads, ['allocation' => 'random', 'team_id' => $teamB]);
        $no->assertForbidden();
        $ctx->run($this->a, fn () => Customer::query()->create(['customer_ref' => 'GB1', 'name' => 'B lead', 'phone' => '+919000040001', 'status' => 'new', 'assigned_user_id' => $ids['TB1'], 'assigned_team_id' => $teamB]));
        $csv = "Customer ID,Customer Name,Phone Number,Caller ID\nK1,Mine,9000050001,TA2\nK2,Theirs,9000050002,TB1\nGB1,Steal,9000040001,TA1\nK3,Nobody,9000050003,\n";
        [$job, $st] = $run($asMgr, $csv, ['allocation' => 'column']);
        $st->assertJsonPath('created_count', 1)->assertJsonPath('updated_count', 0)->assertJsonPath('error_rows', 3);
        $codes = collect($st->json('sample_errors'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['caller_outside_group', 'caller_required', 'customer_other_group'], $codes);
        $this->assertSame($ids['TB1'], $ctx->run($this->a, fn () => Customer::query()->where('customer_ref', 'GB1')->value('assigned_user_id')));
        $this->assertSame($ids['TA2'], $ctx->run($this->a, fn () => Customer::query()->where('customer_ref', 'K1')->value('assigned_user_id')));
        // Managers see only their own uploads; the admin sees all.
        $this->assertSame([$mgr->id], collect($asMgr->getJson('/api/admin/imports')->json('data'))->pluck('uploaded_by')->unique()->values()->all());
        $this->assertGreaterThanOrEqual(3, count($this->admin('alpha')->getJson('/api/admin/imports')->json('data')));
        // Customers page: random re-assignment of selected customers to chosen callers of a group, and list filters.
        $rIds = $ctx->run($this->a, fn () => Customer::query()->where('customer_ref', 'like', 'R%')->pluck('id')->all());
        $this->admin('alpha')->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA, 'user_ids' => [$ids['TA1']]])
            ->assertOk()->assertJsonPath('updated', 5)->assertJsonPath('per_caller.' . $ids['TA1'], 5);
        $this->admin('alpha')->getJson('/api/admin/customers?assigned=' . $ids['TA1'] . '&team_id=' . $teamA)->assertOk()->assertJsonCount(5, 'data');
        $this->admin('alpha')->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA, 'user_ids' => [$ids['TB1']]])->assertStatus(422);
        $asMgr = $this->actingAs($mgr, 'web');   // admin() above switched the signed-in user
        $asMgr->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamB])->assertForbidden();
        $asMgr->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA])->assertOk()->assertJsonPath('updated', 5);
        // Transfer cases: a manager cannot move them to another group; the admin can, with follow-ups and group.
        $ctx->run($this->a, fn () => \App\Models\FollowUp::query()->create(['customer_id' => $rIds[0], 'user_id' => Customer::query()->whereKey($rIds[0])->value('assigned_user_id'), 'due_at' => now()->addDay(), 'status' => 'open']));
        $asMgr->postJson('/api/admin/customers/transfer', ['from_user_id' => $ids['TA1'], 'user_id' => $ids['TB1']])->assertForbidden();
        $this->admin('alpha')->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'user_id' => $ids['TA1']])->assertOk();
        $this->admin('alpha')->postJson('/api/admin/customers/transfer', ['from_user_id' => $ids['TA1'], 'user_id' => $ids['TB1']])->assertOk()->assertJsonPath('updated', 5);
        $moved = $ctx->run($this->a, fn () => Customer::query()->whereIn('id', $rIds)->get());
        $this->assertSame([$ids['TB1']], $moved->pluck('assigned_user_id')->unique()->values()->all());
        $this->assertSame([$teamB], $moved->pluck('assigned_team_id')->unique()->values()->all());
        $this->assertSame($ids['TB1'], $ctx->run($this->a, fn () => \App\Models\FollowUp::query()->where('customer_id', $rIds[0])->value('user_id')));
        $this->admin('alpha')->postJson('/api/admin/customers/transfer', ['from_user_id' => $ids['TB1'], 'mode' => 'random', 'team_id' => $teamA])->assertOk()->assertJsonPath('updated', 6);
    }

    /** Team page: groups with a manager, people of every type (generated password), custom roles with chosen permissions. */
    public function test_team_setup_roles_permissions_and_groups(): void
    {
        config(['ammp.sessions.admin_mfa_required' => false]);
        $adm = fn () => $this->admin('alpha');
        $mgr = $adm()->postJson('/api/admin/employees', ['employee_code' => 'M1', 'name' => 'Manager One', 'roles' => ['supervisor']])->assertStatus(422)->assertJsonPath('error', 'email_required');
        $mgr = $adm()->postJson('/api/admin/employees', ['employee_code' => 'M1', 'name' => 'Manager One', 'email' => 'm1@alpha.test', 'roles' => ['supervisor']])->assertCreated();
        $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*\d).{10}$/', $mgr->json('password'));
        $g = $adm()->postJson('/api/admin/teams', ['name' => 'Altaf Branch', 'manager_ids' => [$mgr->json('id')]])->assertCreated()->json('id');
        $tc = $adm()->postJson('/api/admin/employees', ['employee_code' => 'T9', 'name' => 'Tele Nine', 'roles' => ['employee'], 'teams' => [['id' => $g, 'membership' => 'member']]])->assertCreated();
        $teams = collect($adm()->getJson('/api/admin/teams')->json());
        $this->assertSame('ALTAFBRANCH', $teams->firstWhere('id', $g)['code']);
        $this->assertSame('Manager One', $teams->firstWhere('id', $g)['managers'][0]['name']);
        $this->assertSame(['T9'], collect($adm()->getJson("/api/admin/employees?team_id={$g}&role=employee")->json('data'))->pluck('employee_code')->all());
        // Custom role: reports only. The holder can sign in to the console; a permission you lack cannot be granted.
        $roles = $adm()->getJson('/api/admin/roles')->assertOk();
        $this->assertGreaterThan(20, count($roles->json('permissions')));
        $rid = $adm()->postJson('/api/admin/roles', ['name' => 'Report viewer', 'permissions' => ['calls.view', 'reports.export']])->assertCreated()->json('id');
        $adm()->putJson("/api/admin/roles/{$rid}", ['name' => 'Report viewer', 'permissions' => ['nope']])->assertStatus(422);
        $key = collect($adm()->getJson('/api/admin/roles')->json('roles'))->firstWhere('id', $rid)['key'];
        $adm()->postJson('/api/admin/employees', ['employee_code' => 'R1', 'name' => 'Reporter', 'email' => 'r1@alpha.test', 'password' => 'Secret-123456', 'roles' => [$key]])->assertCreated();
        $this->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/'])->postJson('/api/admin/auth/login', ['email' => 'r1@alpha.test', 'password' => 'Secret-123456'])->assertOk();
        $sys = collect($adm()->getJson('/api/admin/roles')->json('roles'))->firstWhere('key', 'supervisor')['id'];
        $adm()->putJson("/api/admin/roles/{$sys}", ['name' => 'X', 'permissions' => ['calls.view']])->assertStatus(422)->assertJsonPath('error', 'system_role');
        $adm()->deleteJson("/api/admin/roles/{$rid}")->assertStatus(422);                       // still in use
        // Edit person: move the telecaller to manager of the group with an e-mail.
        $adm()->patchJson('/api/admin/employees/' . $tc->json('id'), ['roles' => ['supervisor'], 'email' => 't9@alpha.test', 'teams' => [['id' => $g, 'membership' => 'supervisor']]])->assertOk();
        $this->assertCount(2, collect($adm()->getJson('/api/admin/teams')->json())->firstWhere('id', $g)['managers']);
    }

    /** Customers → Allocation: plain import (no caller), location-wise filters, all or part of the cases, group pool, manager limits. */
    public function test_allocation_by_filter_location_and_partial(): void
    {
        config(['ammp.sessions.admin_mfa_required' => false]);
        $people = "name,group,role,employee_code\nMgr A,Akshitha,manager,MA\nMgr B,Gulab,manager,MB\nTele A1,Akshitha,telecaller,TA1\nTele A2,Akshitha,telecaller,TA2\nTele B1,Gulab,telecaller,TB1\n";
        $this->admin('alpha')->post('/api/admin/employees/bulk', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('p.csv', $people), 'email_domain' => 'alpha.test'], ['Accept' => 'application/json'])->assertCreated();
        $ctx = app(CurrentCompany::class);
        [$teamA, $teamB, $ids] = $ctx->run($this->a, fn () => [\App\Models\Team::query()->where('name', 'Akshitha')->value('id'), \App\Models\Team::query()->where('name', 'Gulab')->value('id'),
            User::query()->pluck('id', 'employee_code')]);
        // Plain import: no allocation chosen, no Caller ID column → unassigned, with city / pincode.
        $csv = "Customer ID,Customer Name,Phone Number,City,Pincode\n" . implode("\n", array_map(fn ($i) => "L{$i},Lead {$i},90000700{$i}0," . ($i <= 6 ? 'Hyderabad,500081' : 'Warangal,506002'), range(1, 9))) . "\n";
        $job = $this->admin('alpha')->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('l.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk()->assertJsonCount(9, 'result.valid');
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        $this->admin('alpha')->getJson('/api/admin/customers/facets')->assertOk()->assertJsonPath('city', ['Hyderabad', 'Warangal']);
        $this->admin('alpha')->patchJson("/api/admin/imports/{$job['id']}", ['portfolio' => 'HDFC Oct'])->assertOk();
        $pf = collect($this->admin('alpha')->getJson('/api/admin/portfolios')->assertOk()->json())->firstWhere('id', $job['id']);
        $this->assertSame(['HDFC Oct', 9, 9], [$pf['name'], $pf['total'], $pf['unassigned']]);
        $hyd = ['city' => 'Hyderabad', 'assigned' => 'none', 'import_id' => $job['id']];
        $this->admin('alpha')->postJson('/api/admin/customers/allocate', ['filter' => $hyd, 'dry_run' => true])->assertOk()->assertJsonPath('matching', 6);
        // Partial: 4 of the 6 Hyderabad cases, random between Akshitha callers → 2 each.
        $this->admin('alpha')->postJson('/api/admin/customers/allocate', ['filter' => $hyd, 'limit' => 4, 'pick' => 'random', 'target' => 'random', 'team_id' => $teamA])
            ->assertOk()->assertJsonPath('updated', 4)->assertJsonPath('per_caller.' . $ids['TA1'], 2);
        // All remaining Warangal cases → Gulab group pool (its manager shares them out).
        $this->admin('alpha')->postJson('/api/admin/customers/allocate', ['filter' => ['city' => 'Warangal'], 'target' => 'group', 'team_id' => $teamB])->assertOk()->assertJsonPath('updated', 3);
        $mgrB = $ctx->run($this->a, fn () => User::query()->where('employee_code', 'MB')->first());
        $asB = fn () => $this->actingAs($mgrB, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
        $asB()->postJson('/api/admin/customers/allocate', ['filter' => ['team_id' => $teamB, 'assigned' => 'none'], 'dry_run' => true])->assertJsonPath('matching', 3);
        $asB()->postJson('/api/admin/customers/allocate', ['filter' => ['city' => 'Hyderabad'], 'dry_run' => true])->assertJsonPath('matching', 0);   // not his group
        $asB()->postJson('/api/admin/customers/allocate', ['filter' => ['team_id' => $teamB, 'assigned' => 'none'], 'target' => 'caller', 'user_id' => $ids['TA1']])->assertForbidden();
        $asB()->postJson('/api/admin/customers/allocate', ['filter' => ['team_id' => $teamB, 'assigned' => 'none'], 'target' => 'caller', 'user_id' => $ids['TB1']])->assertOk()->assertJsonPath('updated', 3);
        // Transfer = allocate the cases of one caller to another.
        $this->admin('alpha')->postJson('/api/admin/customers/allocate', ['filter' => ['assigned' => (string) $ids['TA1']], 'target' => 'caller', 'user_id' => $ids['TB1']])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(5, $ctx->run($this->a, fn () => Customer::query()->where('assigned_user_id', $ids['TB1'])->where('assigned_team_id', $teamB)->count()));
    }

    /** Portfolio reports: numbers come from real cases, calls, feedback, follow-ups and payments, without double counting. */
    public function test_portfolio_report_numbers(): void
    {
        config(['ammp.telephony.route' => 'sim']);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $ctx = app(CurrentCompany::class);
        $csv = "Customer ID,Customer Name,Phone Number,Caller ID\nP1,One,9000081001,EMP1\nP2,Two,9000081002,EMP1\nP3,Three,9000081003,EMP1\nP4,Four,9000081004,\n";
        $job = $this->admin('alpha')->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('pf.csv', $csv), 'portfolio' => 'Axis Q3'], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk();
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        $cid = fn ($ref) => $ctx->run($this->a, fn () => Customer::query()->where('customer_ref', $ref)->value('id'));
        $call = function (string $ref, int $talk) use ($m, $cid) {
            $u = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid($ref), 'which' => 'primary'])->assertCreated()->json('call_id');
            $m->work('POST', "/api/mobile/v1/calls/{$u}/sim-result", ['talk_seconds' => $talk])->assertOk();
            return $u;
        };
        $u1 = $call('P1', 40);                                                       // connected, paid 1000
        $m->work('POST', "/api/mobile/v1/calls/{$u1}/disposition", ['code' => 'PAID', 'amount' => 1000, 'payment_mode' => 'UPI'])->assertOk();
        $u2 = $call('P2', 0);                                                        // not connected → callback
        $m->work('POST', "/api/mobile/v1/calls/{$u2}/disposition", ['code' => 'CALLBACK', 'follow_up_at' => now()->addDay()->toAtomString()])->assertOk();
        $call('P2', 0);                                                              // second try, still not connected
        // P3 assigned, never called; P4 not assigned.
        $r = $this->admin('alpha')->getJson("/api/admin/reports/portfolio?portfolio={$job['id']}")->assertOk();
        $s = $r->json('summary');
        $this->assertSame([4, 1, 3, 1, 1, 1, 1, 1000.0, 1], [$s['cases'], $s['unassigned'], $s['calls'], $s['connected'], $s['not_made'], $s['not_connected_cases'], $s['callbacks'], (float) $s['collected'], $s['employees']]);
        $this->assertSame('Axis Q3', $r->json('portfolio.name'));
        $e = collect($r->json('employees'))->firstWhere('employee_code', 'EMP1');
        $this->assertSame([3, 3, 1, 1, 1, 1000.0], [$e['cases'], $e['calls'], $e['connected'], $e['not_made'], $e['callbacks'], (float) $e['collected']]);
        $this->assertSame(1000.0, (float) collect($r->json('trend'))->sum('collected'));
        $this->assertSame(3, collect($r->json('trend'))->sum('calls'));
        // Filters + drill-down.
        $this->admin('alpha')->getJson("/api/admin/reports/portfolio?portfolio={$job['id']}&collection=paid")->assertJsonPath('summary.cases', 1);
        $this->admin('alpha')->getJson("/api/admin/reports/portfolio?portfolio={$job['id']}&callback=pending")->assertJsonPath('summary.cases', 1);
        $this->admin('alpha')->getJson("/api/admin/reports/portfolio?portfolio={$job['id']}&call_status=not_called&assigned=assigned")->assertJsonPath('summary.cases', 1);
        $this->admin('alpha')->getJson("/api/admin/reports/portfolio?portfolio={$job['id']}&from=2020-01-01&to=2020-01-31")->assertJsonPath('summary.calls', 0)->assertJsonPath('summary.collected', 0);
        $rows = collect($this->admin('alpha')->getJson("/api/admin/reports/portfolio/cases?portfolio={$job['id']}&employee=" . $this->users['alpha']['emp']->id)->assertOk()->json('data'));
        $this->assertSame(3, $rows->count());
        $p1 = $rows->firstWhere('customer_ref', 'P1');
        $this->assertSame(['PAID', 1000.0, 1, 'Axis Q3'], [$p1['last_outcome'], (float) $p1['collected'], $p1['calls'], $p1['portfolio']]);
        $this->admin('bravo')->getJson("/api/admin/reports/portfolio?portfolio={$job['id']}")->assertJsonPath('summary.cases', 0);   // other company
        // Exports: portfolio-wise (one row per portfolio + total), employee-wise, call-by-call with portfolio / employee filters.
        $pw = array_map('str_getcsv', explode("\n", trim($this->admin('alpha')->get('/api/admin/reports/portfolio.csv?by=portfolio')->assertOk()->streamedContent())));
        $axis = collect($pw)->firstWhere(0, 'Axis Q3');
        $this->assertSame(['Axis Q3', '4', '1', '4', '3', '1', '33%'], array_slice($axis, 0, 7));
        $this->assertSame('All portfolios', end($pw)[0]);
        $this->assertSame((int) end($pw)[1], collect($pw)->slice(1, -1)->sum(fn ($x) => (int) $x[1]));   // total = sum of portfolios
        $only = $this->admin('alpha')->get("/api/admin/reports/portfolio.csv?by=portfolio&portfolio={$job['id']}")->streamedContent();
        $this->assertSame(2, count(explode("\n", trim($only))));
        $ew = $this->admin('alpha')->get("/api/admin/reports/portfolio.csv?by=employee&portfolio={$job['id']}")->assertOk()->streamedContent();
        $this->assertStringContainsString(',EMP1,', $ew);
        $calls = fn (string $extra) => count(explode("\n", trim($this->admin('alpha')->get('/api/admin/reports/calls.csv?from=2020-01-01&to=' . now()->addDay()->toDateString() . $extra)->assertOk()->streamedContent()))) - 1;
        $this->assertSame(3, $calls("&portfolio={$job['id']}"));
        $this->assertSame(0, $calls('&portfolio=manual'));
        $this->assertSame(3, $calls("&portfolio={$job['id']}&employee=" . $this->users['alpha']['emp']->id));
        $this->assertStringContainsString('Axis Q3', $this->admin('alpha')->get("/api/admin/reports/calls.csv?from=2020-01-01&to=" . now()->addDay()->toDateString() . "&portfolio={$job['id']}")->streamedContent());
    }

    public function test_local_loopback_workspace_only_in_local_env(): void
    {
        config(['ammp.tenancy.local_workspace' => 'bravo']);
        $this->getJson('http://127.0.0.1/api/mobile/v1/bootstrap')->assertNotFound();            // testing env: no default
        $this->app['env'] = 'local';
        $this->getJson('http://127.0.0.1/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('company.slug', 'bravo');
        $this->withHeaders(['X-Workspace' => 'alpha'])->getJson('http://127.0.0.1/api/mobile/v1/bootstrap')->assertJsonPath('company.slug', 'alpha');
        $this->flushHeaders();   // withHeaders() sticks to later requests in the same test; the next ones send no workspace
        $this->getJson('http://192.168.0.100/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('company.slug', 'bravo'); // phone on same Wi-Fi
        $this->getJson('http://8.8.8.8/api/mobile/v1/bootstrap')->assertNotFound();                // public IP: no default
        $this->getJson('http://mdm.example.com/api/mobile/v1/bootstrap')->assertNotFound();        // non-loopback: still rejected
    }

    public function test_admin_password_only_when_mfa_switched_off(): void
    {
        $h = ['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/'];
        $body = ['email' => 'admin@alpha.test', 'password' => 'Secret-123456'];
        $this->withHeaders($h)->postJson('/api/admin/auth/login', $body)->assertOk()->assertJsonPath('mfa', 'verify');
        $this->users['alpha']['admin']->forceFill(['mfa_enabled_at' => null])->save();
        $this->admin('alpha')->getJson('/api/admin/employees')->assertForbidden();          // required: unenrolled admin blocked
        config(['ammp.sessions.admin_mfa_required' => false]);
        $this->withHeaders($h)->postJson('/api/admin/auth/login', $body)->assertOk()->assertJsonPath('mfa', 'none');
        $this->assertNotNull(session('mfa_verified_at'));
        $this->admin('alpha')->getJson('/api/admin/employees')->assertOk();                 // switched off: password session is enough
    }

    public function test_20_simulator_refused_outside_development(): void
    {
        $this->app['env'] = 'production';
        config(['ammp.telephony.driver' => 'simulator']);
        $this->expectException(\RuntimeException::class);
        (new TelephonyManager())->adapter();
    }

    /** Assigned case → list with dues → profile → promise → payment (duplicate blocked) → verify → balance, timeline, notes, status, scoping. */
    public function test_field_map_distances_route_geocode_and_pin_update(): void
    {
        $this->appOnlyMandatory();
        config(['ammp.maps.traffic_aware' => false]);
        $ids = app(CurrentCompany::class)->run($this->a, function () {
            $emp = $this->users['alpha']['emp'];
            Customer::query()->where('customer_ref', 'C1')->update(['lat' => 17.4612, 'lng' => 78.3549]);
            $near = Customer::query()->create(['customer_ref' => 'N1', 'name' => 'Near', 'phone' => '+919876500001', 'status' => 'new', 'assigned_user_id' => $emp->id, 'lat' => 17.45, 'lng' => 78.36]);
            $zero = Customer::query()->create(['customer_ref' => 'Z1', 'name' => 'Zero', 'phone' => '+919876500002', 'status' => 'new', 'assigned_user_id' => $emp->id, 'lat' => 0, 'lng' => 0, 'address' => '12-3 MG Road', 'city' => 'Hyderabad', 'pincode' => '500084']);
            return ['c1' => Customer::query()->where('customer_ref', 'C1')->value('id'), 'near' => $near->id, 'zero' => $zero->id, 'other' => Customer::query()->where('customer_ref', 'C2')->value('id')];
        });
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $m->work('GET', '/api/mobile/v1/field/config')->assertOk()->assertJsonPath('map_key', null)->assertJsonPath('routing', false);

        // Keys are set only on the admin page (stored encrypted; the page shows the last 4 characters; save runs a test).
        \Illuminate\Support\Facades\Http::fake(['maps.googleapis.com/maps/api/geocode/*' => \Illuminate\Support\Facades\Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'API key not valid']),
            'routes.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Routes API has not been used in project 1 before or it is disabled.']], 403)]);
        $this->admin('alpha')->putJson('/api/admin/integrations/maps', [])->assertStatus(422);
        $bad = $this->admin('alpha')->putJson('/api/admin/integrations/maps', ['api_key' => 'bad-key'])->assertOk()->assertJsonPath('ok', false)->json('detail');
        $this->assertStringContainsString('Routes API ✗ 403 PERMISSION_DENIED Routes API has not been used', $bad);   // admin sees Google's reason per API
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(['maps.googleapis.com/maps/api/geocode/*' => \Illuminate\Support\Facades\Http::response(['status' => 'OK', 'results' => []]),
            'routes.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['routes' => [['distanceMeters' => 12000]]])]);
        $this->admin('alpha')->putJson('/api/admin/integrations/maps', ['api_key' => 'test-key', 'display_key' => 'web-key'])->assertOk()->assertJsonPath('ok', true);
        $this->admin('alpha')->getJson('/api/admin/integrations/maps')->assertOk()->assertJsonPath('key_hint', '••••-key')->assertJsonPath('display_key_hint', '••••-key');
        $this->assertNotSame('test-key', \Illuminate\Support\Facades\DB::table('integration_connections')->where('system', 'gmaps')->value('api_key'));   // encrypted at rest
        $m->work('GET', '/api/mobile/v1/field/config')->assertOk()->assertJsonPath('map_key', 'web-key')->assertJsonPath('routing', true);
        $this->admin('alpha')->putJson('/api/admin/integrations/maps', ['remove_display_key' => true])->assertOk();
        $m->work('GET', '/api/mobile/v1/field/config')->assertJsonPath('map_key', 'test-key');
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());

        // Case list carries the pin only when it is usable, plus why it is missing.
        $items = collect($m->work('GET', '/api/mobile/v1/customers')->assertOk()->json('items'))->keyBy('customer_ref');
        $this->assertEquals(17.4612, $items['C1']['lat']);
        $this->assertNull($items['C1']['location_issue']);
        $this->assertNull($items['Z1']['lat']);
        $this->assertSame('invalid', $items['Z1']['location_issue']);

        \Illuminate\Support\Facades\Http::fake([
            'routes.googleapis.com/distanceMatrix/*' => \Illuminate\Support\Facades\Http::response([
                ['originIndex' => 0, 'destinationIndex' => 1, 'distanceMeters' => 1400, 'duration' => '420s', 'condition' => 'ROUTE_EXISTS'],
                ['originIndex' => 0, 'distanceMeters' => 2300, 'duration' => '600s', 'condition' => 'ROUTE_EXISTS'],   // index 0 is omitted by Google
            ]),
            'routes.googleapis.com/directions/*' => \Illuminate\Support\Facades\Http::response(['routes' => [[
                'distanceMeters' => 3700, 'duration' => '1020s', 'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                'legs' => [['distanceMeters' => 1400, 'duration' => '420s'], ['distanceMeters' => 2300, 'duration' => '600s']],
            ]]]),
            'maps.googleapis.com/maps/api/geocode/*' => \Illuminate\Support\Facades\Http::response(['status' => 'OK', 'results' => [[
                'formatted_address' => 'MG Road, Hyderabad 500084', 'geometry' => ['location' => ['lat' => 17.44, 'lng' => 78.35], 'location_type' => 'ROOFTOP']]]]),
        ]);

        // Road distances: own cases with a usable pin only (Z1 has 0,0; C2 belongs to EMP2), in the order asked.
        $d = $m->work('POST', '/api/mobile/v1/field/distances', ['lat' => 17.44, 'lng' => 78.35, 'customer_ids' => [$ids['c1'], $ids['near'], $ids['zero'], $ids['other']]])->assertOk();
        $this->assertSame([[$ids['c1'], 2300, 600], [$ids['near'], 1400, 420]], array_map(fn ($i) => [$i['id'], $i['distance_m'], $i['duration_s']], $d->json('items')));
        // Second ask from ~the same spot is served from the cache (no new Google call).
        $m->work('POST', '/api/mobile/v1/field/distances', ['lat' => 17.4401, 'lng' => 78.3501, 'customer_ids' => [$ids['c1']]])->assertOk()->assertJsonPath('items.0.distance_m', 2300);
        \Illuminate\Support\Facades\Http::assertSentCount(1);   // (the admin key tests above used a separate fake)
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => $req->hasHeader('X-Goog-Api-Key', 'test-key') && $req['routingPreference'] === 'TRAFFIC_UNAWARE');

        // Multi-stop route: farthest stop (C1) is the end, the polyline is decoded server-side, skipped cases reported.
        $rt = $m->work('POST', '/api/mobile/v1/field/route', ['lat' => 17.44, 'lng' => 78.35, 'customer_ids' => [$ids['c1'], $ids['near'], $ids['zero']], 'optimize' => true])->assertOk();
        $this->assertSame([$ids['near'], $ids['c1']], $rt->json('order'));
        $this->assertSame([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]], $rt->json('points'));
        $this->assertSame(1020, $rt->json('duration_s'));
        $this->assertSame([$ids['zero']], $rt->json('skipped'));

        // Address → suggested pin (not saved), then the employee saves it; someone else's case is refused.
        $m->work('POST', "/api/mobile/v1/customers/{$ids['zero']}/geocode")->assertOk()->assertJsonPath('lat', 17.44)->assertJsonPath('precise', true);
        $this->assertNull($m->work('POST', "/api/mobile/v1/customers/{$ids['other']}/geocode")->assertNotFound()->json('lat'));
        $m->work('POST', "/api/mobile/v1/customers/{$ids['zero']}/location", ['lat' => 0, 'lng' => 0, 'source' => 'gps'])->assertStatus(422);
        $m->work('POST', "/api/mobile/v1/customers/{$ids['zero']}/location", ['lat' => 17.4405, 'lng' => 78.3507, 'source' => 'gps', 'accuracy_m' => 8])->assertOk();
        $m->work('GET', "/api/mobile/v1/customers/{$ids['zero']}")->assertOk()->assertJsonPath('location.lat', 17.4405)->assertJsonPath('timeline.0.type', 'location');
        // Check-in at the customer: only within 100 m (+ GPS drift, max 50 m) of the pin; logged on the timeline.
        $m->work('POST', "/api/mobile/v1/customers/{$ids['zero']}/visit", ['lat' => 17.4505, 'lng' => 78.3507])->assertStatus(422);
        $m->work('POST', "/api/mobile/v1/customers/{$ids['zero']}/visit", ['lat' => 17.4413, 'lng' => 78.3507, 'accuracy_m' => 30])->assertOk();   // ~89 m
        $m->work('POST', "/api/mobile/v1/customers/{$ids['zero']}/visit", ['lat' => 17.4406, 'lng' => 78.3507, 'accuracy_m' => 5])->assertOk()->assertJsonPath('distance_m', 11);
        $m->work('GET', "/api/mobile/v1/customers/{$ids['zero']}")->assertJsonPath('timeline.0.type', 'visit');

        // Key problems never reach the employee as raw Google errors.
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());   // drop the stubs above
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid']], 403)]);
        $err = $m->work('POST', '/api/mobile/v1/field/distances', ['lat' => 17.44, 'lng' => 78.35, 'customer_ids' => [$ids['c1']]])->assertStatus(503);
        $this->assertSame('maps_unavailable', $err->json('error'));
        $this->assertStringContainsString('Google refused the key', $err->json('message'));
        $this->assertStringNotContainsString('API key', $err->json('message'));
        app(CurrentCompany::class)->run($this->a, fn () => \App\Models\IntegrationConnection::query()->where('system', 'gmaps')->update(['enabled' => false]));
        $m->work('POST', '/api/mobile/v1/field/distances', ['lat' => 17.44, 'lng' => 78.35, 'customer_ids' => [$ids['c1']]])->assertStatus(503)->assertJsonPath('error', 'maps_not_configured');
    }

    public function test_bulk_geocode_saves_precise_pins_only(): void
    {
        app(CurrentCompany::class)->run($this->a, function () {
            \App\Models\IntegrationConnection::query()->create(['system' => 'gmaps', 'base_url' => 'https://maps.googleapis.com', 'api_key' => 'test-key', 'enabled' => true]);
            Customer::query()->where('customer_ref', 'C1')->update(['address' => '12-3 MG Road', 'city' => 'Hyderabad']);
            Customer::query()->where('customer_ref', 'C2')->update(['pincode' => '500084']);
        });
        \Illuminate\Support\Facades\Http::fake(['maps.googleapis.com/*' => fn ($req) => \Illuminate\Support\Facades\Http::response(['status' => 'OK', 'results' => [[
            'formatted_address' => 'x', 'geometry' => ['location' => ['lat' => 17.4, 'lng' => 78.3], 'location_type' => str_contains($req['address'], 'MG Road') ? 'ROOFTOP' : 'APPROXIMATE']]]])]);
        $this->artisan('ammp:geocode-customers')->expectsOutputToContain('Saved 1 · area-only match, skipped 1')->assertSuccessful();
        app(CurrentCompany::class)->run($this->a, function () {
            $this->assertEquals(17.4, Customer::query()->where('customer_ref', 'C1')->value('lat'));
            $this->assertNull(Customer::query()->where('customer_ref', 'C2')->value('lat'));
        });
    }

    public function test_address_changes_history_and_report(): void
    {
        $this->appOnlyMandatory();
        $upload = function (string $addr, string $lat) {
            $csv = "Customer ID,Customer Name,Phone Number,Caller ID,Address,City,Latitude,Longitude\nA-1,Asha Rao,9876544444,EMP1,\"{$addr}\",Hyderabad,{$lat},78.4\n";
            $job = $this->admin('alpha')->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('a.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
            $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk();
            $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
            $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        };
        $upload('1 Old Street', '17.40');   // new case: its first address is not a "change"
        $upload('9 New Road', '17.41');     // address + pin changed by the file
        $upload('9 New Road', '17.41');     // same again: nothing logged
        $id = app(CurrentCompany::class)->run($this->a, fn () => Customer::query()->where('customer_ref', 'A-1')->value('id'));

        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $m->work('POST', "/api/mobile/v1/customers/{$id}/location", ['lat' => 17.4101, 'lng' => 78.4, 'source' => 'gps', 'accuracy_m' => 6])->assertOk();
        $m->work('POST', "/api/mobile/v1/customers/{$id}/visit", ['lat' => 17.4102, 'lng' => 78.4, 'accuracy_m' => 5])->assertOk();

        // Case profile (phone + console): newest first, before / after, who and how.
        $h = $m->work('GET', "/api/mobile/v1/customers/{$id}")->assertOk()->json('address_history');
        $this->assertCount(2, $h);
        $this->assertSame(['phone_gps', 'pin', 11, 'GPS ±6 m.'], [$h[0]['source'], $h[0]['what'], $h[0]['moved_m'], $h[0]['note']]);
        $this->assertSame(['upload', 'both', '1 Old Street, Hyderabad', '9 New Road, Hyderabad'], [$h[1]['source'], $h[1]['what'], $h[1]['old_address'], $h[1]['new_address']]);
        $this->assertEqualsWithDelta(1112, $h[1]['moved_m'], 3);
        $this->assertEquals(['lat' => 17.4, 'lng' => 78.4], $h[1]['old_pin']);
        $this->admin('alpha')->getJson("/api/admin/customers/{$id}")->assertOk()->assertJsonCount(2, 'address_history');

        // Admin report: filters, status (verified = checked in at the door after the change; replaced = a newer change exists).
        $r = $this->admin('alpha')->getJson('/api/admin/address-changes')->assertOk();
        $r->assertJsonPath('summary.changes', 2)->assertJsonPath('summary.verified', 2)->assertJsonPath('summary.pins_moved', 2)->assertJsonPath('summary.big_moves', 1)
            ->assertJsonPath('page.data.0.status', 'verified')->assertJsonPath('page.data.1.status', 'replaced')->assertJsonPath('page.data.0.customer.customer_ref', 'A-1');
        $this->admin('alpha')->getJson('/api/admin/address-changes?status=replaced')->assertJsonPath('page.total', 1);
        $this->admin('alpha')->getJson('/api/admin/address-changes?status=pending')->assertJsonPath('page.total', 0);
        $this->admin('alpha')->getJson('/api/admin/address-changes?q=Old%20Street')->assertJsonPath('page.total', 1);
        $this->admin('alpha')->getJson('/api/admin/address-changes?q=Asha')->assertJsonPath('page.total', 2);
        $this->admin('alpha')->getJson('/api/admin/address-changes?source=upload&what=both')->assertJsonPath('page.total', 1);
        $this->admin('alpha')->getJson('/api/admin/address-changes?from=' . now()->addDay()->toDateString())->assertJsonPath('page.total', 0);

        $rc = $this->admin('alpha')->getJson('/api/admin/reachability?q=New%20Road')->assertOk();
        $rc->assertJsonPath('page.total', 1)->assertJsonPath('page.data.0.reach', 'visited')->assertJsonPath('page.data.0.visits', 1)
            ->assertJsonPath('page.data.0.changes', 2)->assertJsonPath('page.data.0.pin_status', 'ok');
        $all = $this->admin('alpha')->getJson('/api/admin/reachability')->assertOk()->json('summary');
        $this->assertSame($all['cases'], $all['pin_ok'] + $all['pin_missing'] + $all['pin_invalid']);
        $this->assertSame(1, $all['visited']);
        $this->admin('alpha')->getJson('/api/admin/reachability?pin=invalid')->assertJsonPath('page.total', $all['pin_invalid']);
        $this->admin('alpha')->getJson('/api/admin/reachability?reach=not_reached')->assertJsonPath('page.total', $all['not_reached']);
    }

    /** Live map: every employee with an approved phone, their latest fix (live / stale / none) and today's work. */
    public function test_live_locations_all_employees(): void
    {
        $m = $this->approvedPhone();
        $this->login($m);
        $emp = $this->users['alpha']['emp'];
        app(CurrentCompany::class)->run($this->a, function () use ($emp) {
            $dev = \App\Models\ManagedDevice::query()->where('assigned_user_id', $emp->id)->firstOrFail();
            foreach ([[17.40, now()->subHours(3)], [17.45, now()->subMinutes(2)]] as [$lat, $at]) {
                \App\Models\LocationEvent::query()->create(['device_id' => $dev->id, 'user_id' => $emp->id, 'lat' => $lat, 'lng' => 78.35, 'accuracy_m' => 9, 'battery_pct' => 64, 'captured_at' => $at]);
            }
        });
        $e = collect($this->admin('alpha')->getJson('/api/admin/live-locations')->assertOk()->json('employees'))->firstWhere('user_id', $emp->id);
        $this->assertSame(['live', 17.45, 64, 0], [$e['status'], $e['location']['lat'], $e['battery_pct'], $e['visits_today']]);
        $this->travel(20)->minutes();
        $this->admin('alpha')->getJson('/api/admin/live-locations?refresh=1')->assertOk()->assertJsonPath('employees.0.status', 'stale');
        $this->travel(2)->days();
        $this->admin('alpha')->getJson('/api/admin/live-locations?refresh=1')->assertJsonPath('employees.0.status', 'none')->assertJsonPath('employees.0.location', null);
        $this->admin('bravo')->getJson('/api/admin/live-locations')->assertOk()->assertJsonCount(0, 'employees');   // other company

        // Day route: trail, customers checked in at (in order) and totals; GPS jumps are not counted as distance.
        $this->travelBack();
        $cust = app(CurrentCompany::class)->run($this->a, function () use ($emp) {
            $dev = \App\Models\ManagedDevice::query()->where('assigned_user_id', $emp->id)->firstOrFail();
            \App\Models\LocationEvent::query()->create(['device_id' => $dev->id, 'user_id' => $emp->id, 'lat' => 17.46, 'lng' => 78.35, 'accuracy_m' => 5, 'captured_at' => now()->subMinute()]);   // 1.1 km a minute after the 17.45 fix
            \App\Models\LocationEvent::query()->create(['device_id' => $dev->id, 'user_id' => $emp->id, 'lat' => 18.50, 'lng' => 78.35, 'accuracy_m' => 5, 'captured_at' => now()->subSeconds(30)]);  // jump: 115 km in 30 s
            $c = Customer::query()->where('customer_ref', 'C1')->firstOrFail();
            \App\Models\CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $emp->id, 'type' => 'visit', 'body' => 'x', 'meta' => ['lat' => 17.4613, 'lng' => 78.355, 'distance_m' => 12]]);
            return $c;
        });
        $d = $this->admin('alpha')->getJson("/api/admin/day-route?user_id={$emp->id}")->assertOk();
        $d->assertJsonPath('summary.visits', 1)->assertJsonPath('visits.0.name', $cust->name)->assertJsonPath('visits.0.distance_m', 12)->assertJsonPath('visits.0.n', 1);
        $this->assertCount(4, $d->json('fixes'));
        $this->assertEqualsWithDelta(6672, $d->json('summary.distance_m'), 50);   // 17.40→17.45 (5.6 km) + 17.45→17.46 (1.1 km); the jump to 18.50 is skipped
        $this->admin('alpha')->getJson("/api/admin/day-route?user_id={$emp->id}&date=2020-01-01")->assertOk()->assertJsonPath('summary.fixes', 0);
        // Filters: past date (last fix of that day, never "live"), employee, group, portfolio.
        $this->admin('alpha')->getJson('/api/admin/live-locations?date=' . now()->subDays(5)->toDateString())->assertOk()->assertJsonPath('employees.0.status', 'none')->assertJsonPath('today', false);
        $this->admin('alpha')->getJson("/api/admin/live-locations?employee={$emp->id}")->assertJsonCount(1, 'employees')->assertJsonPath('employees.0.visits_today', 1);
        $this->admin('alpha')->getJson('/api/admin/live-locations?employee=999999')->assertJsonCount(0, 'employees');
        $this->admin('alpha')->getJson('/api/admin/live-locations?team=999999')->assertJsonCount(0, 'employees');
        $this->admin('alpha')->getJson('/api/admin/live-locations?portfolio=manual')->assertJsonPath('employees.0.user_id', $emp->id)->assertJsonPath('employees.0.visits_today', 1);   // C1 was added by hand
        $this->admin('alpha')->getJson('/api/admin/live-locations?portfolio=999999')->assertJsonCount(0, 'employees');
        $this->admin('alpha')->getJson("/api/admin/day-route?user_id={$emp->id}&portfolio=999999")->assertJsonPath('summary.visits', 0)->assertJsonPath('summary.fixes', 4);
    }

    public function test_case_profile_dues_promise_payment_and_timeline(): void
    {
        config(['ammp.telephony.route' => 'sim']);
        $this->appOnlyMandatory();
        $csv = "Customer ID,Customer Name,Phone Number,Caller ID,Address,City,State,Pincode,Latitude,Longitude,Total Outstanding,Overdue Amount,EMI Amount,Due Date,DPD,Overdue EMIs,Loan Amount\n"
            . "L-1,Ravi Kumar,9876511111,EMP1,\"12-3, MG Road\",Hyderabad,Telangana,500084,17.4612,78.3549,\"1,20,000\",9000,4500," . now()->addDays(5)->toDateString() . ",40,2,250000\n"
            . "L-2,No Map,9876522222,EMP1,,,,,,,50000,,,,,,\n"
            . "L-3,Bad Money,9876533333,EMP1,,,,,,,abc,,,,,,\n";
        $job = $this->admin('alpha')->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('dues.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertNotNull($job['mapping']['outstanding']);
        $this->assertNotNull($job['mapping']['dpd']);
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk()
            ->assertJsonPath('result.errors.0.field', 'outstanding');   // "abc" is refused, never stored as 0
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->admin('alpha')->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);

        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $items = collect($m->work('GET', '/api/mobile/v1/customers')->assertOk()->json('items'))->keyBy('customer_ref');
        $this->assertSame(['C1', 'L-1', 'L-2'], $items->keys()->sort()->values()->all());   // own cases only; L-3 rejected; C2 is EMP2's
        $l1 = $items['L-1'];
        $this->assertEquals(120000, $l1['outstanding']);
        $this->assertEquals(9000, $l1['overdue_amount']);
        $this->assertSame(now()->subDays(40)->toDateString(), $l1['oldest_due_date']);
        $this->assertSame('dues', $l1['portfolio']);
        $this->assertSame(0, $l1['calls']);
        $this->assertNull($l1['last_call_at']);

        // Other employee's case is not reachable.
        $c2 = app(CurrentCompany::class)->run($this->a, fn () => Customer::query()->where('customer_ref', 'C2')->value('id'));
        $m->work('GET', "/api/mobile/v1/customers/{$c2}")->assertNotFound();

        $p = $m->work('GET', "/api/mobile/v1/customers/{$l1['id']}")->assertOk();
        $p->assertJsonPath('dues.days_overdue', 40)->assertJsonPath('dues.overdue_installments', 2)->assertJsonPath('location.lat', 17.4612)
            ->assertJsonPath('address', '12-3, MG Road')->assertJsonPath('phone', '+919876511111')->assertJsonPath('assigned_user.employee_code', 'EMP1')
            ->assertJsonPath('timeline.0.type', 'assignment')->assertJsonPath('installments', null);
        $m->work('GET', "/api/mobile/v1/customers/{$items['L-2']['id']}")->assertOk()->assertJsonPath('location', null)->assertJsonPath('dues.days_overdue', null);

        // Promise from the profile (no call), then a payment, then the same payment again.
        $m->work('POST', "/api/mobile/v1/customers/{$l1['id']}/entry", ['code' => 'CALLBACK'])->assertStatus(422);
        $m->work('POST', "/api/mobile/v1/customers/{$l1['id']}/entry", ['code' => 'CONNECTED_PTP', 'amount' => 9000, 'follow_up_at' => now()->subDay()->toAtomString()])->assertCreated();
        $l1b = collect($m->work('GET', '/api/mobile/v1/customers')->json('items'))->firstWhere('customer_ref', 'L-1');
        $this->assertSame('broken', $l1b['promise_status']);
        $this->assertSame(0, $l1b['calls']);    // a field entry is not a call
        $pay = ['code' => 'PART_PAID', 'amount' => 5000, 'payment_mode' => 'UPI', 'payment_ref' => 'UTR9', 'paid_on' => now()->toDateString()];
        $m->work('POST', "/api/mobile/v1/customers/{$l1['id']}/entry", $pay)->assertCreated();
        $m->work('POST', "/api/mobile/v1/customers/{$l1['id']}/entry", $pay)->assertStatus(422)->assertJsonPath('error', 'duplicate_payment');
        $p = $m->work('GET', "/api/mobile/v1/customers/{$l1['id']}")->assertOk();
        $p->assertJsonPath('promises.0.status', 'kept')->assertJsonPath('payments.0.status', 'pending')->assertJsonPath('dues.remaining_balance', 120000);   // pending ≠ collected
        // Office verifies → balance and overdue come down.
        $pid = $this->admin('alpha')->getJson('/api/admin/payments?status=pending')->assertOk()->json('data.0.id');
        $this->admin('alpha')->postJson("/api/admin/payments/{$pid}/review", ['status' => 'verified'])->assertOk();
        $p = $m->work('GET', "/api/mobile/v1/customers/{$l1['id']}")->assertOk();
        $p->assertJsonPath('dues.remaining_balance', 115000)->assertJsonPath('dues.overdue_now', 4000)->assertJsonPath('activity.collected_verified', 5000);

        // Callback, note and status from the phone (offline queue), then the call itself.
        $ev = fn (string $type, array $payload) => ['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => $type, 'occurred_at' => now()->toAtomString(), 'payload' => $payload];
        $m->work('POST', '/api/mobile/v1/sync', ['events' => [
            $ev('follow_up', ['customer_id' => $l1['id'], 'due_at' => now()->addDay()->toAtomString(), 'notes' => 'call after salary']),
            $ev('note', ['customer_id' => $l1['id'], 'text' => 'Wife answered, he is travelling']),
            $ev('status', ['customer_id' => $l1['id'], 'status' => 'in_progress']),
            $ev('status', ['customer_id' => $c2, 'status' => 'closed']),
        ]])->assertOk()->assertJsonPath('results.0.result', 'accepted')->assertJsonPath('results.2.result', 'accepted')->assertJsonPath('results.3.reason', 'customer_not_assigned');
        $u = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $l1['id'], 'which' => 'primary'])->assertCreated()->json('call_id');
        $m->work('POST', "/api/mobile/v1/calls/{$u}/sim-result", ['talk_seconds' => 20])->assertOk();
        $m->work('POST', "/api/mobile/v1/calls/{$u}/disposition", ['code' => 'BUSY'])->assertOk();
        $p = $m->work('GET', "/api/mobile/v1/customers/{$l1['id']}")->assertOk()->json();
        $types = collect($p['timeline'])->pluck('type');
        foreach (['call', 'entry', 'callback', 'note', 'status', 'assignment'] as $t) {
            $this->assertContains($t, $types, "timeline has {$t}");
        }
        $this->assertSame('BUSY', $p['activity']['last_outcome']);
        $this->assertSame(1, $p['activity']['calls']);
        $this->assertNull($p['next_action']);    // new feedback settled the open callback
        $this->assertSame('in_progress', $p['status']);

        // Console: profile with masked numbers; manager-scoped; note + status from the console.
        $this->admin('alpha')->getJson("/api/admin/customers/{$l1['id']}")->assertOk()->assertJsonPath('phone', '+91••••••1111')
            ->assertJsonPath('dues.outstanding', 120000)->assertJsonPath('stats.calls', 1);
        $this->admin('alpha')->postJson("/api/admin/customers/{$l1['id']}/note", ['text' => 'Escalate'])->assertOk();
        $this->admin('alpha')->postJson("/api/admin/customers/{$l1['id']}/status", ['status' => 'closed'])->assertOk();
        $this->admin('bravo')->getJson("/api/admin/customers/{$l1['id']}")->assertNotFound();
        $this->assertSame('closed', $m->work('GET', "/api/mobile/v1/customers/{$l1['id']}")->json('status'));
    }

    public function test_auto_approve_employee_setting_approves_new_phones(): void
    {
        $this->a->forceFill(['settings' => ['devices' => ['auto_approve_employee' => 'EMP1']]])->save();
        $m = new MobileClient($this, 'alpha');
        $m->register()->assertCreated()->assertJsonPath('state', 'approved')->assertJsonPath('approval_code', null);
        $m->signed('POST', '/api/mobile/v1/device/session')->assertOk()->assertJsonStructure(['device_token']);
        (new MobileClient($this, 'bravo'))->register()->assertCreated()->assertJsonPath('state', 'pending');   // other companies unchanged
    }
}
