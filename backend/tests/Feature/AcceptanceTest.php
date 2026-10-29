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

    public function test_download_links_carry_the_workspace(): void
    {
        // Plain <a href> downloads can't send X-Workspace: they carry ?workspace=. Own workspace works, another company's is refused.
        $link = $this->actingAs($this->users['alpha']['admin'], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])->withHeaders(['Referer' => 'http://localhost/']);
        $link->get('/api/admin/imports/template?workspace=alpha')->assertOk();
        $link->get('/api/admin/imports/template?workspace=bravo')->assertStatus(403);
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
        $url = $this->admin('alpha')->postJson("/api/admin/calls/{$call->id}/recording/playback")->assertOk()->json('url');
        // The <audio> player sends no X-Workspace header: the signed URL itself must name the workspace.
        $this->flushHeaders();
        $this->get(str_replace(config('app.url'), '', $url))->assertOk();
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

    public function test_click2call_employee_chooses_route_result_and_recording(): void
    {
        config(['ammp.telephony.routes' => ['sim', 'server'], 'ammp.telephony.driver' => 'click2call', 'ammp.telephony.click2call' => [
            'start_url' => 'https://sip.telozar.test/api/webrtc/c2c_call', 'cdr_url' => 'https://obd.gateway.test/admin/click2call_data',
            'gateway_secret' => 'gs', 'did' => '918047000000', 'agent_caller_id' => null, 'webhook_secret' => 'wh', 'timeout' => 15, 'timezone' => 'Asia/Kolkata']]);
        app(TelephonyManager::class)->swap(new \App\Telephony\Click2CallAdapter(config('ammp.telephony.click2call')));
        $cdr = ['A_DIAL_STATUS' => 'ANSWERED'];
        $url = null;
        \Illuminate\Support\Facades\Http::fake([
            'sip.telozar.test/*' => \Illuminate\Support\Facades\Http::response(['call_id' => 'TZ1']),
            'obd.gateway.test/*' => function ($req) use (&$cdr, &$url) {
                return $req->header('Secret-Key')[0] === 'gs'
                    ? \Illuminate\Support\Facades\Http::response(['status' => 200, 'data' => ['response' => json_encode($cdr), 'recording_url' => $url, 'duration' => 82]])
                    : \Illuminate\Support\Facades\Http::response(['status' => 401], 401);
            },
            'rec.gateway.test/*' => \Illuminate\Support\Facades\Http::response('RIFF' . str_repeat("\x00", 4000)),
        ]);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim', 'server'])->assertJsonPath('calling_route', 'sim');
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];

        // Employee picks Click2Call: rings the work number, then the customer, from the VMN; ref_id = our call uuid.
        $uuid = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()
            ->assertJsonPath('route', 'server')->assertJsonPath('callback_from', '8047000000')->json('call_id');
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'c2c_call') && $req['did'] === '8047000000'
            && $req['a_party_no'] === '9811111111' && $req['b_party_no'] === '9876543210' && $req['ref_id'] === $uuid && $req['record'] === 1);
        $m->work('GET', "/api/mobile/v1/calls/{$uuid}")->assertJsonPath('state', 'ringing');   // employee answered, customer ringing

        $cdr = ['A_DIAL_STATUS' => 'ANSWERED', 'B_DIAL_STATUS' => 'ANSWERED', 'B_PARTY_CONNECTED_TIME' => '21102026103015',
            'B_PARTY_END_TIME' => '21102026103130', 'EVENT_TYPE' => 'call_end', 'DURATION' => 82];
        $url = 'https://rec.gateway.test/r/TZ1.wav';
        $this->postJson('/api/telephony/v1/click2call', ['ref_id' => $uuid])->assertStatus(401);   // secret required when set
        \Illuminate\Support\Facades\Cache::flush();   // per-call sync throttle
        // With the secret, the provider's posted end-of-call result is applied (kit); the gateway read gives the same answer.
        $this->postJson('/api/telephony/v1/click2call?secret=wh', ['ref_id' => $uuid] + $cdr + ['recording_url' => $url])->assertJsonPath('status', 'success');
        $m->work('GET', "/api/mobile/v1/calls/{$uuid}")->assertJsonPath('state', 'completed')->assertJsonPath('talk_seconds', 75)
            ->assertJsonPath('ring_seconds', 7)->assertJsonPath('recording_status', 'available')->assertJsonPath('callback_from', null);
        $call = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->first());
        $this->assertSame('2026-10-21 05:01:30', $call->ended_at->utc()->format('Y-m-d H:i:s'));   // 10:31:30 IST
        $this->admin('alpha')->postJson("/api/admin/calls/{$call->id}/recording/playback")->assertOk();

        // Next call, employee picks SIM: the phone dials itself.
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'sim'])->assertCreated()
            ->assertJsonPath('route', 'sim')->assertJsonPath('dial', '+919876543210');

        // A route the company does not offer is refused.
        config(['ammp.telephony.routes' => ['sim']]);
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertStatus(422)->assertJsonPath('error', 'route_not_allowed');
    }

    public function test_click2call_recording_late_link_long_url_checks_and_retry_sweep(): void
    {
        config(['ammp.telephony.routes' => ['sim', 'server'], 'ammp.telephony.driver' => 'click2call', 'ammp.telephony.click2call' => [
            'start_url' => 'https://sip.telozar.test/api/webrtc/c2c_call', 'cdr_url' => 'https://obd.gateway.test/admin/click2call_data',
            'gateway_secret' => 'gs', 'did' => '918047000000', 'agent_caller_id' => null, 'webhook_secret' => 'wh', 'timeout' => 15,
            'timezone' => 'Asia/Kolkata', 'recording_hosts' => ['rec.gateway.test'], 'recording_wait_minutes' => 60]]);
        $adapter = new \App\Telephony\Click2CallAdapter(config('ammp.telephony.click2call'));
        app(TelephonyManager::class)->swap($adapter);
        $audio = 'RIFF' . str_repeat("\x00", 4000);
        $ist = now('Asia/Kolkata');
        $cdr = ['A_DIAL_STATUS' => 'ANSWERED', 'B_DIAL_STATUS' => 'ANSWERED', 'B_PARTY_CONNECTED_TIME' => $ist->copy()->subSeconds(75)->format('dmYHis'),
            'B_PARTY_END_TIME' => $ist->format('dmYHis'), 'EVENT_TYPE' => 'call_end', 'DURATION' => 82];
        \Illuminate\Support\Facades\Http::fake([
            'sip.telozar.test/*' => \Illuminate\Support\Facades\Http::response(['call_id' => 'TZ2']),
            'obd.gateway.test/*' => \Illuminate\Support\Facades\Http::response(['status' => 200, 'data' => ['response' => json_encode($cdr), 'recording_url' => null]]),
            'rec.gateway.test/html*' => \Illuminate\Support\Facades\Http::response('<html>expired</html>', 200, ['Content-Type' => 'text/html']),
            'rec.gateway.test/*' => \Illuminate\Support\Facades\Http::response($audio, 200, ['Content-Type' => 'audio/wav']),
            'evil.test/*' => \Illuminate\Support\Facades\Http::response($audio),
        ]);
        // Download checks: allowed host + https + audio only.
        $this->assertSame($audio, $adapter->fetchRecording('https://rec.gateway.test/r/a.wav'));
        $this->assertNull($adapter->fetchRecording('https://rec.gateway.test/html/a.wav'));   // provider error page
        $this->assertNull($adapter->fetchRecording('https://evil.test/a.wav'));               // host not allowed
        $this->assertNull($adapter->fetchRecording('http://rec.gateway.test/a.wav'));         // not https

        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $uuid = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()->json('call_id');

        // Result arrives without a link → call completed, recording still waiting.
        $this->postJson('/api/click2call/webhook', ['ref_id' => $uuid] + $cdr, ['Secret-Key' => 'wh'])->assertJsonPath('status', 'success');
        $m->work('GET', "/api/mobile/v1/calls/{$uuid}")->assertJsonPath('state', 'completed')->assertJsonPath('recording_status', 'pending');
        $call = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->first());
        // 60-minute wait ran out: recording failed (no link).
        app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->where('call_id', $call->id)->update(['status' => 'failed', 'last_error' => 'no recording']));
        app(CurrentCompany::class)->withoutScope(fn () => Call::query()->whereKey($call->id)->update(['recording_status' => 'failed']));

        // A late callback brings a long signed link (> 255 chars): it is stored whole and the recording becomes available.
        $long = 'https://rec.gateway.test/r/TZ2.wav?X-Amz-Signature=' . str_repeat('a', 400);
        \Illuminate\Support\Facades\Cache::flush();
        $this->postJson('/api/click2call/webhook', ['ref_id' => $uuid] + $cdr + ['recording_url' => $long], ['Secret-Key' => 'wh'])->assertJsonPath('status', 'success');
        $rec = app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->where('call_id', $call->id)->first());
        $this->assertSame('available', $rec->status);
        $this->assertSame($long, $rec->source_path);

        // Retry sweep: a failed provider download older than 10 minutes is queued again.
        app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->whereKey($rec->id)->update(['status' => 'failed', 'attempts' => 3, 'updated_at' => now()->subMinutes(11)]));
        \Illuminate\Support\Facades\Queue::fake();
        collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'recordings:retry')->run(app());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\FetchRecording::class, fn ($j) => $j->recordingId === $rec->id);
        $this->assertSame('processing', app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->whereKey($rec->id)->value('status')));
    }

    public function test_admin_test_call_from_calling_options(): void
    {
        $cdr = ['A_DIAL_STATUS' => 'ANSWERED'];
        $url = null;
        $gwStatus = 200;
        \Illuminate\Support\Facades\Http::fake([
            'sip.telozar.com/*' => \Illuminate\Support\Facades\Http::response(['call_id' => 'TZ9']),
            'obd.ametecsindia.com/*' => function () use (&$cdr, &$url, &$gwStatus) {
                return $gwStatus === 400 ? \Illuminate\Support\Facades\Http::response(['status' => 400, 'message' => 'Secret Key not matched'])
                    : ($gwStatus === 401 ? \Illuminate\Support\Facades\Http::response(['status' => 401, 'message' => 'Unauthorized'], 401)
                    : \Illuminate\Support\Facades\Http::response(['status' => 200, 'data' => ['response' => json_encode($cdr), 'recording_url' => $url]]));
            },
            'rec.gateway.test/*' => \Illuminate\Support\Facades\Http::response('RIFF' . str_repeat("\x00", 4000), 200, ['Content-Type' => 'audio/wav']),
        ]);
        app(TelephonyManager::class)->swap(null);   // the company's Click2Call setting picks the adapter
        $adm = $this->admin('alpha');
        $adm->postJson('/api/admin/calling/click2call/test-call', ['agent_number' => '9811111111', 'customer_number' => '9876543210'])->assertStatus(422);   // Click2Call off
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '08047000001', 'gateway_secret' => 'gw'])->assertOk();
        $adm->postJson('/api/admin/calling/click2call/test-call', ['agent_number' => 'abc', 'customer_number' => '9876543210'])->assertStatus(422);

        $adm->getJson('/api/admin/calling/click2call/test-call/numbers')->assertJsonPath('numbers.0.number', '08047000001')->assertJsonPath('numbers.0.source', 'company');
        $adm->postJson('/api/admin/calling/click2call/test-call', ['agent_number' => '9811111111', 'customer_number' => '9876543210', 'from' => '8047999999'])
            ->assertStatus(422)->assertJsonPath('message', 'That number is not one of your Click2Call numbers (or it has expired).');
        // A voucher number is offered first and is the default "call from".
        \App\Models\C2c\Vmn::query()->create(['virtual_number' => '8047000101', 'click2call' => true, 'expires_at' => now()->addMonth()]);
        $adm->getJson('/api/admin/calling/click2call/test-call/numbers')->assertJsonPath('numbers.0.number', '8047000101')->assertJsonPath('numbers.0.source', 'voucher')
            ->assertJsonPath('numbers.1.number', '08047000001');
        $id = $adm->postJson('/api/admin/calling/click2call/test-call', ['agent_number' => '9811111111', 'customer_number' => '+91 98765 43210'])
            ->assertOk()->assertJsonPath('ok', true)->json('call_id');
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'c2c_call') && $req['a_party_no'] === '9811111111'
            && $req['b_party_no'] === '9876543210' && $req['did'] === '8047000101' && $req['record'] === 1);
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id}")->assertJsonPath('state', 'ringing')->assertJsonPath('terminal', false)->assertJsonPath('gateway', null);
        // Gateway refuses the key: the page says so at once instead of "ringing" for 15 minutes.
        $gwStatus = 401;
        \Illuminate\Support\Facades\Cache::flush();
        $id2 = $adm->postJson('/api/admin/calling/click2call/test-call', ['agent_number' => '9811111111', 'customer_number' => '9876543211'])->assertOk()->json('call_id');
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id2}")->assertJsonPath('state', 'initiated')->assertJsonPath('gateway.code', 'KEY_REFUSED');
        // The real gateway answers HTTP 200 + status 400 "Secret Key not matched" (24 Oct): also treated as a refused key.
        $gwStatus = 400;
        \Illuminate\Support\Facades\Cache::flush();
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id2}")->assertJsonPath('gateway.code', 'KEY_REFUSED');
        $adm->postJson('/api/admin/calling/click2call/test')->assertJsonPath('ok', false);
        // Hang up before the customer is dialled → cancelled at once; once the customer rings → 409 (cut on the phone).
        $adm->postJson("/api/admin/calling/click2call/test-call/{$id2}/hangup")->assertOk()->assertJsonPath('state', 'cancelled');
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id2}")->assertJsonPath('state', 'cancelled')->assertJsonPath('terminal', true);
        $adm->postJson("/api/admin/calling/click2call/test-call/{$id}/hangup")->assertStatus(409);   // $id: customer ringing
        $gwStatus = 200;

        $ist = now('Asia/Kolkata');
        $cdr = ['A_DIAL_STATUS' => 'ANSWERED', 'B_DIAL_STATUS' => 'ANSWERED', 'B_PARTY_CONNECTED_TIME' => $ist->copy()->subSeconds(30)->format('dmYHis'),
            'B_PARTY_END_TIME' => $ist->format('dmYHis'), 'EVENT_TYPE' => 'call_end', 'DURATION' => 40];
        $url = 'https://rec.gateway.test/r/TZ9.wav';
        \Illuminate\Support\Facades\Cache::flush();
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id}")->assertJsonPath('state', 'completed')->assertJsonPath('talk_seconds', 30)
            ->assertJsonPath('recording_status', 'available');
        $adm->postJson("/api/admin/calls/{$id}/recording/playback")->assertOk();   // the recording still plays
        // Kept apart: Test calls lists them; Calls, Dashboard and the export do not.
        $adm->getJson('/api/admin/calling/click2call/test-calls')->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $id2)
            ->assertJsonPath('data.1.recording_status', 'available')->assertJsonPath('data.1.caller_number', '8047000101');
        $this->assertSame([], collect($adm->getJson('/api/admin/calls')->json('data'))->whereIn('id', [$id, $id2])->all());
        $adm->getJson('/api/admin/overview')->assertOk()->assertJsonPath('calls_today.total', 0);
    }

    public function test_obd_gateway_guide_payloads_webhook_and_ringing_recording(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'sip.telozar.com/*' => \Illuminate\Support\Facades\Http::response(['status' => 'success']),
            'obd.ametecsindia.com/*' => \Illuminate\Support\Facades\Http::response(['status' => 404, 'message' => 'Record not found']),
            'rec.obd.test/*' => \Illuminate\Support\Facades\Http::response('RIFF' . str_repeat("\x00", 4000), 200, ['Content-Type' => 'audio/wav']),
        ]);
        app(TelephonyManager::class)->swap(null);
        $adm = $this->admin('alpha');
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '6744123493', 'gateway_secret' => " 'tel-secret', ", 'crm_id' => '776'])->assertOk();   // pasted from PHP: quotes dropped
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'click2call_data/connection-test_d_776_0_1/') && $req->header('Secret-Key')[0] === 'tel-secret');
        $ist = now('Asia/Kolkata');
        $place = fn (string $to) => $adm->postJson('/api/admin/calling/click2call/test-call', ['agent_number' => '7322822761', 'customer_number' => $to])->assertOk()->json('call_id');

        // 1) Connected (guide 3.3 spellings), http RecordVoice → stored over https; LeadGER webhook URL + Secret-Key = Gateway secret.
        $id = $place('9640875757');
        $call = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->find($id));
        $ref = "{$call->uuid}_d_776_{$call->user_id}_1";
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'c2c_call') && $req['ref_id'] === $ref);
        // The reference sent is kept: changing the CRM id later must not break the lookup of this call.
        $this->assertSame($ref, app(CurrentCompany::class)->withoutScope(fn () => Call::query()->find($id))->provider_call_id);
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '6744123493', 'crm_id' => '7761'])->assertOk();
        \Illuminate\Support\Facades\Cache::flush();
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id}")->assertJsonPath('gateway.tone', 'info');   // "Record not found" while ringing
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'click2call_data/' . rawurlencode($ref) . '/'));
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '6744123493', 'crm_id' => '776'])->assertOk();
        $connected = ['SERVICE_TYPE' => 'CLICK_TO_CALL', 'EVENT_TYPE' => 'Call End', 'REF_ID' => $ref, 'A_DIAL_STATUS' => 'Connected', 'B_DIAL_STATUS' => 'Connected',
            'B_PARTY_CONNECTED_TIME' => $ist->copy()->subSeconds(35)->format('dmYHis'), 'B_PARTY_END_TIME' => $ist->format('dmYHis'),
            'DISCONNECTED_BY' => 'Customer', 'RecordVoice' => 'http://rec.obd.test/recordings/c2c/a.wav'];
        $this->postJson('/api/telephony/webhook', ['ref_id' => $ref] + $connected, ['Secret-Key' => 'wrong'])->assertJsonPath('status', 'success');
        $this->assertSame('initiated', app(CurrentCompany::class)->withoutScope(fn () => Call::query()->find($id))->state);   // wrong key: body not trusted (gateway read finds nothing yet)
        \Illuminate\Support\Facades\Cache::flush();
        $this->postJson('/api/telephony/webhook', ['ref_id' => $ref] + $connected, ['Secret-Key' => 'tel-secret'])->assertJsonPath('call_state', 'completed');
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id}")->assertJsonPath('talk_seconds', 35)->assertJsonPath('recording_status', 'available');
        $this->assertSame('https://rec.obd.test/recordings/c2c/a.wav', app(CurrentCompany::class)->withoutScope(fn () => Recording::query()->where('call_id', $id)->value('source_path')));

        // 2) Agent answered, customer "No Answer": no talk time, but the provider's ringing recording is kept (guide 10).
        $id2 = $place('9777436222');
        $c2 = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->find($id2));
        $ref2 = "{$c2->uuid}_d_776_{$c2->user_id}_1";
        $this->postJson('/api/telephony/webhook', ['ref_id' => $ref2, 'EVENT_TYPE' => 'Call End', 'A_DIAL_STATUS' => 'Connected', 'B_DIAL_STATUS' => 'No Answer',
            'A_PARTY_END_TIME' => $ist->format('dmYHis'), 'RecordVoice' => 'https://rec.obd.test/recordings/c2c/b.wav'], ['Secret-Key' => 'tel-secret'])
            ->assertJsonPath('call_state', 'no_answer');
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id2}")->assertJsonPath('talk_seconds', 0)->assertJsonPath('recording_status', 'available');

        // 3) Agent did not answer and no recording: nothing to keep.
        $id3 = $place('9000186925');
        $c3 = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->find($id3));
        $this->postJson('/api/telephony/webhook', ['ref_id' => "{$c3->uuid}_d_776_{$c3->user_id}_1", 'EVENT_TYPE' => 'Call End', 'A_DIAL_STATUS' => 'No Answer',
            'RecordVoice' => 'No Voice'], ['Secret-Key' => 'tel-secret'])->assertJsonPath('call_state', 'agent_unanswered');
        $adm->getJson("/api/admin/calling/click2call/test-call/{$id3}")->assertJsonPath('recording_status', 'not_required');
    }

    public function test_click2call_stuck_call_does_not_block_the_next_call(): void
    {
        config(['ammp.telephony.routes' => ['sim', 'server'], 'ammp.telephony.driver' => 'click2call', 'ammp.telephony.click2call' => [
            'start_url' => 'https://sip.telozar.test/api/webrtc/c2c_call', 'cdr_url' => 'https://obd.gateway.test/admin/click2call_data',
            'gateway_secret' => 'gs', 'did' => '918047000000', 'agent_caller_id' => null, 'webhook_secret' => null, 'timeout' => 15, 'timezone' => 'Asia/Kolkata', 'daily_limit' => 100]]);
        app(TelephonyManager::class)->swap(new \App\Telephony\Click2CallAdapter(config('ammp.telephony.click2call')));
        $cdr = ['A_DIAL_STATUS' => 'Connected'];   // employee answered, customer ringing — and the gateway never gets the call end
        $url = null;
        \Illuminate\Support\Facades\Http::fake([
            'sip.telozar.test/*' => \Illuminate\Support\Facades\Http::response(['status' => 'success', 'message' => 'C2C Call initiated']),
            'obd.gateway.test/*' => function () use (&$cdr, &$url) {
                return \Illuminate\Support\Facades\Http::response(['status' => 200, 'data' => ['response' => json_encode($cdr), 'recording_url' => $url]]);
            },
            'rec.gateway.test/*' => \Illuminate\Support\Facades\Http::response('RIFF' . str_repeat("\x00", 4000), 200, ['Content-Type' => 'audio/wav']),
        ]);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $first = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()->json('call_id');
        $m->work('GET', "/api/mobile/v1/calls/{$first}")->assertJsonPath('state', 'ringing');
        // Right away a second call is still refused (the first one may really be in progress)…
        \Illuminate\Support\Facades\Cache::flush();
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertStatus(422)->assertJsonPath('error', 'call_in_progress');
        // …but 3 minutes later the stuck call no longer blocks: it is closed as superseded and the new call starts.
        $this->travel(3)->minutes();
        \Illuminate\Support\Facades\Cache::flush();
        $second = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()->json('call_id');
        $this->assertNotSame($first, $second);
        $old = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $first)->first());
        $this->assertSame(['disconnected', 'superseded', 'pending'], [$old->state, $old->failure_reason, $old->recording_status]);
        // The gateway's final record arrives later: the old call still gets its real result and recording.
        $ist = now('Asia/Kolkata');
        $cdr = ['A_DIAL_STATUS' => 'Connected', 'B_DIAL_STATUS' => 'Connected', 'EVENT_TYPE' => 'Call End',
            'B_PARTY_CONNECTED_TIME' => $ist->copy()->subSeconds(40)->format('dmYHis'), 'B_PARTY_END_TIME' => $ist->format('dmYHis')];
        $url = 'https://rec.gateway.test/r/old.wav';
        \Illuminate\Support\Facades\Cache::flush();
        app(CurrentCompany::class)->run(\App\Models\Company::query()->findOrFail($old->company_id), fn () => app(\App\Services\CallService::class)->syncProvider($old));
        $old = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->find($old->id));
        $this->assertSame(['completed', null, 40, 'available'], [$old->state, $old->failure_reason, $old->talk_seconds, $old->recording_status]);
    }

    public function test_app_crash_note_lands_in_audit_log(): void
    {
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $m->work('POST', '/api/mobile/v1/diagnostics/crash', ['report' => "1.0.3 OPPO CPH2359\njava.lang.SecurityException: x"])->assertOk();
        $this->assertStringContainsString('SecurityException', $this->admin('alpha')->getJson('/api/admin/audit?action=app.crash')->json('data.0.meta'));
    }

    public function test_click2call_set_up_in_console_per_company_and_per_employee(): void
    {
        config(['ammp.telephony.routes' => [], 'ammp.telephony.route' => 'sim', 'ammp.telephony.driver' => 'freeswitch']);
        app(TelephonyManager::class)->swap(null);   // no test double: the company's Click2Call setting must pick the adapter
        \Illuminate\Support\Facades\Http::fake([
            'sip.telozar.com/*' => \Illuminate\Support\Facades\Http::response(['call_id' => 'TZ9']),
            'obd.ametecsindia.com/*' => fn ($req) => $req->header('Secret-Key')[0] === 'co-secret'
                ? \Illuminate\Support\Facades\Http::response(['status' => 404], 404) : \Illuminate\Support\Facades\Http::response(['status' => 401], 401),
        ]);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim']);
        $adm = $this->admin('alpha');
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '080 4700 0001'])->assertStatus(422);   // secret needed first time
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '080 4700 0001', 'gateway_secret' => 'co-secret'])
            ->assertOk()->assertJsonPath('ok', true);
        $adm->getJson('/api/admin/calling/click2call')->assertJsonPath('mode', 'choice')->assertJsonPath('did', '08047000001')
            ->assertJsonPath('secret_hint', '••••cret')->assertJsonMissingPath('secret');
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim', 'server']);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()
            ->assertJsonPath('callback_from', '08047000001');
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'c2c_call') && $req['did'] === '08047000001');
        app(CurrentCompany::class)->withoutScope(fn () => Call::query()->update(['state' => 'completed']));

        // Access: only selected people/groups. EMP1 is in no grant → SIM only, Click2Call refused.
        $emp = $this->users['alpha']['emp'];
        $adm->putJson('/api/admin/calling/access/click2call', ['mode' => 'selected'])->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim']);
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertStatus(422);
        // Grant his group → allowed again; remove the grant → refused.
        $team = app(CurrentCompany::class)->run($this->a, function () use ($emp) {
            $t = \App\Models\Team::query()->create(['code' => 'G1', 'name' => 'Gulab Group']);
            $emp->teams()->attach($t->id, ['company_id' => $this->a->id, 'membership' => 'member']);
            return $t;
        });
        $gid = collect($adm->postJson('/api/admin/calling/access/click2call', ['team_ids' => [$team->id]])->assertOk()->json('click2call.grants'))->firstWhere('type', 'group')['id'];
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim', 'server']);

        // Smart31: two numbers for his group, one for nobody's group → round-robin over his two.
        $adm->postJson('/api/admin/calling/smart31/numbers', ['number' => '+91 1600 111 001', 'team_ids' => [$team->id]])->assertOk();
        $adm->postJson('/api/admin/calling/smart31/numbers', ['number' => '1600111002', 'team_ids' => [$team->id]])->assertOk();
        $adm->postJson('/api/admin/calling/smart31/numbers', ['number' => '1600111003', 'team_ids' => [999999]])->assertOk();   // unknown group dropped = every group
        $adm->postJson('/api/admin/calling/smart31/numbers', ['number' => '1600111001'])->assertStatus(422);   // duplicate
        $adm->putJson('/api/admin/calling/smart31', ['enabled' => true])->assertOk()->assertJsonCount(3, 'numbers');
        $used = [];
        foreach (range(1, 3) as $i) {
            $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated();
            $used[] = app(CurrentCompany::class)->withoutScope(fn () => Call::query()->latest('id')->value('caller_number'));
            app(CurrentCompany::class)->withoutScope(fn () => Call::query()->update(['state' => 'completed']));
        }
        $this->assertSame(['1600111001', '1600111002', '1600111003'], $used);   // each number once, in turn
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'c2c_call') && $req['did'] === '1600111002');

        // Smart31 for selected people only, and he isn't one → company VMN.
        $adm->putJson('/api/admin/calling/access/smart31', ['mode' => 'selected'])->assertOk();
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()->assertJsonPath('callback_from', '08047000001');
        app(CurrentCompany::class)->withoutScope(fn () => Call::query()->update(['state' => 'completed']));

        $adm->deleteJson("/api/admin/calling/access/click2call/{$gid}")->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim']);

        // Plan (LC): a licence whose plan lacks Smart31 / Click2Call switches them off whatever the settings say.
        app(CurrentCompany::class)->run($this->a, fn () => \App\Models\Platform\Licence::query()->create(['key' => \App\Services\Platform\LicenceIssuer::newKey(),
            'company_id' => $this->a->id, 'holder_name' => 'Alpha', 'kind' => 'subscription', 'max_users' => 50, 'features' => ['calling', 'recording', 'customers'],
            'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addYear(), 'grace_days' => 7]));
        $adm->putJson('/api/admin/calling/access/click2call', ['mode' => 'everyone'])->assertOk();
        $adm->getJson('/api/admin/calling/access')->assertJsonPath('smart31.in_plan', false)->assertJsonPath('click2call.in_plan', false);
        $adm->postJson('/api/admin/calling/smart31/numbers', ['number' => '1600111009'])->assertForbidden();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim']);
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '08047000001'])->assertForbidden();

        // Wrong secret is reported; switching off is always allowed.
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'off'])->assertOk();
    }

    public function test_click2call_kit_vouchers_numbers_limits_webhook_and_sim_access(): void
    {
        config(['ammp.telephony.routes' => [], 'ammp.telephony.route' => 'sim', 'ammp.telephony.driver' => 'freeswitch',
            'ammp.telephony.click2call.webhook_secret' => 'wh-secret',
            'ammp.telephony.click2call.max_users' => 0]);
        app(TelephonyManager::class)->swap(null);
        $today = now()->toDateString();
        $voucherAnswer = ['status' => 200, 'data' => ['click2call_did' => '918047000101,8047000102', 'obd_did' => '8047000103', 'tc_verified' => '8047000101',
            'expiry' => now()->addMonth()->toDateString(), 'smart_groups' => [
                ['vmn' => '1600222001', 'usage_date' => $today, 'c2c' => 1, 'type' => '1600', 'group_name' => 'S31-A'],
                ['vmn' => '1600222009', 'usage_date' => '2020-01-01', 'c2c' => 1],   // not today: ignored
            ]]];
        $started = [];
        $cdr = null;
        \Illuminate\Support\Facades\Http::fake([
            'lc.ametecsindia.com/api/did_voucher/GOOD1/CRM77' => fn ($req) => $req->header('Secret-Key')[0] === 'lic-secret' ? \Illuminate\Support\Facades\Http::response($voucherAnswer) : \Illuminate\Support\Facades\Http::response([], 401),
            'lc.ametecsindia.com/api/did_voucher/BAD12/*' => \Illuminate\Support\Facades\Http::response(['status' => 404, 'message' => 'Voucher already used.']),
            'lc.ametecsindia.com/api/did_voucher_used/*' => \Illuminate\Support\Facades\Http::response(['status' => 200]),
            'sip.telozar.com/*' => function ($req) use (&$started) {
                $started[] = $req->data();
                return $req['b_party_no'] === '9876500000' ? \Illuminate\Support\Facades\Http::response(['error' => 'down'], 500) : \Illuminate\Support\Facades\Http::response(['call_id' => 'TZ' . count($started)]);
            },
            'obd.ametecsindia.com/*' => fn () => $cdr ? \Illuminate\Support\Facades\Http::response(['status' => 200, 'data' => ['response' => $cdr]]) : \Illuminate\Support\Facades\Http::response(['status' => 404]),
        ]);
        $this->appOnlyMandatory();
        $m = $this->approvedPhone();
        $this->login($m);
        $this->ackAll($m);
        $adm = $this->admin('alpha');
        $emp = $this->users['alpha']['emp'];
        $team = app(CurrentCompany::class)->run($this->a, function () use ($emp) {
            $t = \App\Models\Team::query()->create(['code' => 'G9', 'name' => 'Field team']);
            $emp->teams()->attach($t->id, ['company_id' => $this->a->id, 'membership' => 'member']);
            return $t;
        });

        // Click2Call on, no company number typed: CRM id set for vouchers.
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'gateway_secret' => 'gw', 'crm_id' => 'CRM77', 'daily_limit' => 2])->assertStatus(422);   // needs a number or vouchers
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '8047000999', 'gateway_secret' => 'gw', 'crm_id' => 'CRM77', 'daily_limit' => 2])->assertOk();
        $adm->postJson('/api/admin/calling/vouchers', ['voucher_code' => 'GOOD1'])->assertStatus(422)->assertJsonPath('message', 'Enter the CRM secret key first (Calling configuration → Calling options → Click2Call connection).');
        $adm->putJson('/api/admin/calling/click2call', ['mode' => 'choice', 'did' => '8047000999', 'lc_secret' => 'lic-secret'])->assertOk();   // secret typed in the console, not .env
        $adm->getJson('/api/admin/calling/click2call')->assertJsonPath('lc_secret_set', true)->assertJsonMissing(['lic-secret']);

        // Add Voucher: bad code refused with the server's message; good code → numbers + today's Smart31 number.
        $adm->postJson('/api/admin/calling/vouchers', ['voucher_code' => 'BAD12'])->assertStatus(422)->assertJsonPath('message', 'Voucher already used.');
        $res = $adm->postJson('/api/admin/calling/vouchers', ['voucher_code' => 'GOOD1'])->assertOk()->json();
        $this->assertSame(['8047000101', '8047000102', '8047000103'], collect($res['numbers'])->pluck('virtual_number')->all());
        $this->assertSame([true, true, false], collect($res['numbers'])->pluck('click2call')->all());
        $this->assertSame(1, $res['smart31_from_voucher']);
        // Numbers start with no group → the employee has none (company number no longer used once vouchers exist).
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('click2call.numbers', []);
        $cid = $m->work('GET', '/api/mobile/v1/customers')->json('visible_ids')[0];
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertStatus(422)->assertJsonPath('error', 'c2c_no_number');
        $ids = collect($res['numbers'])->pluck('id', 'virtual_number');
        $adm->postJson('/api/admin/calling/numbers/bulk', ['ids' => [$ids['8047000101'], $ids['8047000102']], 'action' => 'update', 'team_ids' => [$team->id]])->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('click2call.numbers.*.number', ['8047000101', '8047000102']);

        // Agent number override + default number; picking a number not his is refused.
        $adm->patchJson("/api/admin/calling/users/{$emp->id}", ['click_agent_number' => '+91 99999 11111', 'c2c_default_number' => '8047000102'])->assertOk();
        $adm->patchJson("/api/admin/calling/users/{$emp->id}", ['c2c_default_number' => '8047000103'])->assertStatus(422);
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server', 'vmn' => '8047000103'])->assertStatus(422)->assertJsonPath('error', 'c2c_number_not_yours');
        $uuid = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertCreated()->json('call_id');
        $this->assertSame($uuid, \App\Telephony\Click2CallAdapter::uuidFromRef(strtoupper($uuid) . "_d_CRM77_{$emp->id}_1"));   // webhook matching
        // CRM id set → LeadGER reference format {uuid}_d_{crm_id}_{user_id}_ (the OBD gateway answers 400 to a bare uuid).
        $this->assertSame(['8047000102', '9999911111', '9876543210', "{$uuid}_d_CRM77_{$emp->id}_1"], [end($started)['did'], end($started)['a_party_no'], end($started)['b_party_no'], end($started)['ref_id']]);

        // Webhook with the secret: missing → 401; correct → the posted result is applied as final.
        $this->postJson('/api/click2call/webhook', ['ref_id' => $uuid])->assertStatus(401);
        $this->postJson('/api/click2call/webhook', ['ref_id' => $uuid, 'A_DIAL_STATUS' => 'ANSWERED', 'B_DIAL_STATUS' => 'ANSWERED',
            'B_PARTY_CONNECTED_TIME' => '21102026103015', 'B_PARTY_END_TIME' => '21102026103045'], ['Secret-Key' => 'wh-secret'])
            ->assertOk()->assertJsonPath('call_state', 'completed');
        $this->assertSame(30, app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $uuid)->value('talk_seconds')));
        $this->postJson('/api/click2call/webhook', ['ref_id' => 'unknown'], ['Secret-Key' => 'wh-secret'])->assertOk()->assertJsonPath('status', 'ignored');

        // Daily limit per customer number (2): this was attempt 1; attempt 2 ok; attempt 3 refused.
        $open = $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server', 'vmn' => '8047000101'])->assertCreated()->json('call_id');
        // Click2Call keeps the provider's recording even when the policy does not require one.
        $this->assertSame('pending', app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $open)->value('recording_status')));
        // "Hang up" before the employee's phone answered: Click2Call has no hang-up API, so the call is closed here.
        $m->work('POST', "/api/mobile/v1/calls/{$open}/hangup")->assertSuccessful();
        $this->assertSame('cancelled', app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $open)->value('state')));
        // The employee's phone never answered: after 3 minutes that call no longer blocks calling ("Finish the current call first").
        app(CurrentCompany::class)->withoutScope(fn () => Call::query()->where('uuid', $open)->update(['state' => 'initiated', 'failure_reason' => null, 'initiated_at' => now()->subMinutes(4)]));
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary', 'route' => 'server'])->assertStatus(422)->assertJsonPath('error', 'retry_limit');
        $this->assertSame(1, app(CurrentCompany::class)->withoutScope(fn () => \Illuminate\Support\Facades\DB::table('audit_events')->where('action', 'call.blocked')->count()));
        $this->assertSame(['failed', 'agent_not_answered'], app(CurrentCompany::class)->withoutScope(fn () => array_values(Call::query()->where('uuid', $open)->first(['state', 'failure_reason'])->toArray())));

        // SIM calling: switched off → Click2Call only; SIM for selected people only (none) → same; Click2Call off too → no option.
        $adm->putJson('/api/admin/calling/sim', ['enabled' => false])->assertOk()->assertJsonPath('sim.on', false);
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['server']);
        $adm->putJson('/api/admin/calling/sim', ['enabled' => true])->assertOk();
        $adm->putJson('/api/admin/calling/access/sim', ['mode' => 'selected'])->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['server']);
        $adm->postJson('/api/admin/calling/access/sim', ['team_ids' => [$team->id]])->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['sim', 'server']);
        $adm->putJson('/api/admin/calling/access/click2call', ['mode' => 'selected'])->assertOk();
        $adm->putJson('/api/admin/calling/access/sim', ['mode' => 'selected'])->assertOk();
        $gid = collect($adm->getJson('/api/admin/calling/access')->json('sim.grants'))->first()['id'];
        $adm->deleteJson("/api/admin/calling/access/sim/{$gid}")->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', [])->assertJsonPath('click2call', null);
        $m->work('POST', '/api/mobile/v1/calls', ['customer_id' => $cid, 'which' => 'primary'])->assertStatus(422)->assertJsonPath('error', 'no_calling_option');

        // Click2Call users page: switch him on personally; licensed limit 1 blocks a second person.
        config(['ammp.telephony.click2call.max_users' => 1]);
        $adm->patchJson("/api/admin/calling/users/{$emp->id}", ['click2call' => true])->assertOk();
        $m->work('GET', '/api/mobile/v1/me')->assertJsonPath('calling_routes', ['server']);
        $adm->patchJson('/api/admin/calling/users/' . $this->users['alpha']['emp2']->id, ['click2call' => true])->assertStatus(422);
        $adm->getJson('/api/admin/calling/users')->assertJsonPath('limit.in_use', 1)->assertJsonPath('users.0.access.click2call.via', 'person');

        // A second voucher replaces the numbers (history kept).
        $adm->postJson('/api/admin/calling/vouchers', ['voucher_code' => 'GOOD1'])->assertOk();
        $this->assertSame(3, app(CurrentCompany::class)->withoutScope(fn () => \Illuminate\Support\Facades\DB::table('c2c_vmn_history')->count()));
    }

    public function test_click2call_leg_results(): void
    {
        $s = fn (array $cdr) => [\App\Telephony\Click2CallAdapter::legState($cdr['A'] ?? null), \App\Telephony\Click2CallAdapter::legState($cdr['B'] ?? null)];
        $this->assertSame(['answered', 'busy'], $s(['A' => 'ANSWERED', 'B' => 'BUSY']));
        $this->assertSame(['no_answer', null], $s(['A' => 'NO ANSWER']));
        $this->assertSame([null, 'failed'], $s(['B' => 'CHANUNAVAIL']));
        $this->assertSame([null, 'no_answer'], $s(['B' => 'not_answered']));
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

    /** The downloaded sample re-imports with no problem rows; bad files leave no half-made upload; groups by name; Excel dates. */
    public function test_sample_file_round_trip_and_upload_errors(): void
    {
        app(CurrentCompany::class)->withoutScope(fn () => Customer::query()->delete());   // fixture customers would share the sample's phone numbers
        $as = $this->admin('alpha');
        $sample = $as->get('/api/admin/imports/template')->assertOk()->streamedContent();
        $this->assertGreaterThanOrEqual(4, substr_count(trim($sample), "\n") + 1);   // header + at least 3 rows
        $job = $as->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('customer-import-template.csv', $sample), 'portfolio' => 'Sample'],
            ['Accept' => 'application/json'])->assertCreated()->json();
        $prev = $as->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk()->json('result');
        $this->assertSame([], $prev['errors']);
        $this->assertGreaterThanOrEqual(3, count($prev['valid']));
        $as->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $as->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        $st = $as->getJson("/api/admin/imports/{$job['id']}")->json();
        $this->assertSame(['completed', 0, count($prev['valid'])], [$st['status'], $st['error_rows'], $st['created_count']]);
        $as->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(409)->assertJsonPath('message', 'This file is already imported (or importing). Refresh the page.');

        $before = $as->getJson('/api/admin/imports')->json('total');
        $as->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('blank.csv', "\n\n")], ['Accept' => 'application/json'])->assertStatus(422);
        $as->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('leads.pdf', '%PDF-1.4')], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('message', 'Upload a .csv or .xlsx file.');
        $this->assertSame($before, $as->getJson('/api/admin/imports')->json('total'));   // nothing half-made left in Upload history

        app(CurrentCompany::class)->run($this->a, fn () => \App\Models\Team::query()->create(['code' => 'G1', 'name' => 'Recovery East']));
        $csv = "Customer ID,Customer Name,Phone Number,Assigned Team,Caller ID,Due Date\nX1,Ex One,9000033333,recovery east,,5/1/2026\nX2,Ex Two,9000044444,Nowhere,GHOST,05/01/2026\n";
        $j2 = $as->post('/api/admin/imports', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('g.csv', $csv)], ['Accept' => 'application/json'])->assertCreated()->json();
        $r = $as->postJson("/api/admin/imports/{$j2['id']}/preview", ['mapping' => $j2['mapping']])->assertOk()->json('result');
        $this->assertCount(1, $r['valid']);
        $this->assertSame('2026-01-05', $r['valid'][0]['account']['due_date']);
        $msgs = collect($r['errors'])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Group "Nowhere" does not exist here', $msgs);
        $this->assertStringContainsString('Caller ID "GHOST" is not an active employee', $msgs);
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
        $moving = $ctx->run($this->a, fn () => Customer::query()->whereIn('id', $rIds)->where(fn ($q) => $q->where('assigned_user_id', '!=', $ids['TA1'])->orWhereNull('assigned_user_id'))->count());
        $this->admin('alpha')->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA, 'user_ids' => [$ids['TA1']]])
            ->assertOk()->assertJsonPath('updated', $moving)->assertJsonPath('per_caller.' . $ids['TA1'], $moving);   // cases already with TA1 are not re-written
        $this->admin('alpha')->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA, 'user_ids' => [$ids['TA1']]])
            ->assertOk()->assertJsonPath('updated', 0);                                                              // same again: no duplicate assignment
        $this->admin('alpha')->getJson('/api/admin/customers?assigned=' . $ids['TA1'] . '&team_id=' . $teamA)->assertOk()->assertJsonCount(5, 'data');
        $this->admin('alpha')->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA, 'user_ids' => [$ids['TB1']]])->assertStatus(422);
        $asMgr = $this->actingAs($mgr, 'web');   // admin() above switched the signed-in user
        $asMgr->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamB])->assertForbidden();
        $upd = $asMgr->postJson('/api/admin/customers/assign', ['customer_ids' => $rIds, 'mode' => 'random', 'team_id' => $teamA])->assertOk()->json('updated');
        $this->assertLessThanOrEqual(5, $upd);   // random share: cases that land on the employee who already had them are left as they are
        $this->assertSame(5, $ctx->run($this->a, fn () => Customer::query()->whereIn('id', $rIds)->where('assigned_team_id', $teamA)->whereIn('assigned_user_id', [$ids['TA1'], $ids['TA2']])->count()));
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
        // G13: no display key → the phone gets NO key (never the server key, which can call Routes/Geocoding).
        $m->work('GET', '/api/mobile/v1/field/config')->assertJsonPath('map_key', null)->assertJsonPath('routing', true);
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
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => $req->hasHeader('X-Goog-Api-Key', 'test-key') && $req['routingPreference'] === 'TRAFFIC_UNAWARE' && $req['travelMode'] === 'DRIVE');

        // Multi-stop route: farthest stop (C1) is the end, the polyline is decoded server-side, skipped cases reported.
        $rt = $m->work('POST', '/api/mobile/v1/field/route', ['lat' => 17.44, 'lng' => 78.35, 'customer_ids' => [$ids['c1'], $ids['near'], $ids['zero']], 'optimize' => true])->assertOk();
        $this->assertSame([$ids['near'], $ids['c1']], $rt->json('order'));
        $this->assertSame([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]], $rt->json('points'));
        $this->assertSame(1020, $rt->json('duration_s'));
        $this->assertSame([$ids['zero']], $rt->json('skipped'));
        // G4: field-map routes are basic (Routes Essentials): DRIVE, no live traffic — never two-wheeler.
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_contains($req->url(), 'directions') && $req['travelMode'] === 'DRIVE' && $req['routingPreference'] === 'TRAFFIC_UNAWARE');
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($req) => str_contains($req->url(), 'routes.googleapis.com') && ($req['travelMode'] ?? null) === 'TWO_WHEELER');

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

    /** Approval: the code is accepted without its dash / in lower case, and a repeated Approve is a success, not an error. */
    public function test_approval_tolerates_code_format_and_double_click(): void
    {
        $m = new MobileClient($this, 'alpha');
        $code = $m->register()->assertCreated()->json('approval_code');
        $device = app(CurrentCompany::class)->withoutScope(fn () => ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail());
        $body = ['code' => strtolower(str_replace('-', ' ', $code)), 'user_id' => $this->users['alpha']['emp']->id];
        $this->admin('alpha')->postJson("/api/admin/devices/{$device->id}/approve", $body)->assertOk()->assertJsonPath('state', 'approved');
        $this->admin('alpha')->postJson("/api/admin/devices/{$device->id}/approve", $body)->assertOk()->assertJsonPath('state', 'approved');
        $m->signed('GET', '/api/mobile/v1/device/status')->assertOk()->assertJsonPath('state', 'approved');
        $this->admin('alpha')->postJson("/api/admin/devices/{$device->id}/approve", ['code' => 'ZZZZ-ZZZZ', 'user_id' => $this->users['alpha']['admin']->id])->assertStatus(422);
    }

    /** Every company gets "ROVEXY Standard", live and ready to apply; it turns on live view + camera sessions. */
    public function test_recommended_policy_is_ready_to_apply(): void
    {
        $list = $this->admin('alpha')->getJson('/api/admin/policies')->assertOk()->json();
        $std = collect($list)->firstWhere('recommended', true);
        self::assertNotNull($std);
        self::assertSame('published', $std['versions'][0]['status']);
        self::assertCount(1, collect($this->admin('alpha')->getJson('/api/admin/policies')->json())->where('recommended', true));   // not duplicated
        $this->admin('alpha')->putJson('/api/admin/policy-assignments', ['profile_id' => $std['id'], 'target_type' => 'company'])->assertOk();
        $m = $this->approvedPhone();
        app(CurrentCompany::class)->run($this->a, function () use ($m) {
            $d = ManagedDevice::query()->where('installation_id', $m->installationId)->firstOrFail();
            $v = app(\App\Services\PolicyService::class)->currentSnapshot($d)->values;
            self::assertTrue($v['live_view.enabled']);
            self::assertTrue($v['camera_session.enabled']);
        });
    }
}
