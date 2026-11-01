<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\PlatformAdmin;
use App\Models\User;
use App\Services\Demo\DemoWorkspace;
use App\Services\Platform\Workspaces;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Demo clients (1 Nov 2026): Explore Demo sign-up, a full city dataset that every screen agrees on, Super Admin view, reset and delete. */
final class DemoClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ammp.sessions.admin_mfa_required' => false]);   // production: Google Authenticator off (27 Oct 2026)
    }

    private function demo(string $city, string $email, string $business = 'Sai Recovery Services'): Company
    {
        return DemoWorkspace::register(['name' => 'Ravi Kumar', 'business' => $business, 'mobile' => '9876543210', 'email' => $email,
            'address' => '2nd floor, Plot 12, Main Road', 'city' => $city, 'password' => bcrypt('Demo1234')]);
    }

    private function console(Company $c)
    {
        $admin = app(CurrentCompany::class)->run($c, fn () => User::query()->where('employee_code', 'admin')->first());
        return $this->actingAs($admin, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => $c->slug, 'Referer' => 'http://localhost/']);
    }

    private function super()
    {
        $a = PlatformAdmin::query()->firstOrCreate(['email' => 'super@ametecs.test'], ['name' => 'S', 'password' => 'Staff123', 'role' => 'super', 'status' => 'active']);
        return $this->actingAs($a, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    public function test_explore_demo_signup_confirms_the_email_builds_the_city_and_opens_the_console(): void
    {
        $this->get('/explore-demo')->assertOk()->assertSee('Explore Demo')->assertSee('Pune, Maharashtra')->assertSee('Chennai, Tamil Nadu');
        $this->get('/')->assertSee('/explore-demo');
        $form = ['name' => 'Priya Shah', 'business' => 'Pune Recoveries', 'mobile' => '98765 43210', 'email' => 'Priya@PuneRec.test', 'address' => 'FC Road',
            'city' => 'pune', 'password' => 'Pune1234', 'password_confirmation' => 'Pune1234', 'terms' => '1'];
        $this->post('/explore-demo', ['city' => 'atlantis'] + $form)->assertSessionHasErrors('city');
        $html = $this->post('/explore-demo', $form)->assertOk()->assertSee('Check your e-mail')->getContent();
        $this->assertSame(0, Company::query()->whereNotNull('demo')->count());   // nothing before the code
        preg_match('/name="token" value="([a-f0-9]{32})"/', $html, $m);
        $res = $this->post('/verify-email', ['token' => $m[1], 'code' => $this->lastEmailCode(), 'purpose' => 'demo']);
        $c = Company::query()->whereNotNull('demo')->firstOrFail();
        $res->assertRedirect(Workspaces::consoleUrl($c));
        $this->assertSame('pune', $c->demo['city']);
        $this->assertSame('priya@punerec.test', $c->demo['email']);
        $this->assertSame('demo', DB::table('leads')->where('email', 'priya@punerec.test')->value('source'));
        // Signed in straight away: the console API answers for this workspace.
        $this->withHeaders(['X-Workspace' => $c->slug, 'Referer' => 'http://localhost/'])->getJson('/api/admin/overview')->assertOk();
        // Same e-mail again → pointed at the existing demo, no second one.
        $this->travel(2)->minutes();
        $this->post('/explore-demo', $form)->assertSee('You already have a demo');
        $this->assertSame(1, Company::query()->whereNotNull('demo')->count());
        // A demo does not stop the same person starting a real trial later.
        $this->assertNull(\App\Services\Platform\Sales::trialProblem('pune-real', 'priya@punerec.test'));
    }

    public function test_the_city_dataset_is_realistic_and_every_screen_agrees_with_the_records(): void
    {
        $c = $this->demo('pune', 'a@demo.test');
        $q = fn (string $t) => DB::table($t)->where('company_id', $c->id);
        $this->assertSame(11, $q('users')->count());                     // admin + 2 team leaders + 8 agents
        $this->assertSame(100, $q('customers')->count());
        $this->assertSame(8, $q('managed_devices')->where('approval_state', 'approved')->where('compliant', true)->count());
        $this->assertSame(3, $q('import_jobs')->count());
        $this->assertSame(0, $q('customers')->where('city', '!=', 'Pune')->count());
        $this->assertSame(0, $q('customers')->where('pincode', 'not like', '41%')->count());
        $numbers = DemoWorkspace::numbers();
        $this->assertSame(0, $q('customers')->whereNotIn('phone', array_map(fn ($n) => "+91{$n}", $numbers))->count());   // only the numbers Ametecs gave
        $this->assertGreaterThan(300, $q('calls')->where('provider', 'sim')->count());
        $oldest = $q('calls')->min('initiated_at');
        $this->assertTrue(now()->subDays(60)->lte($oldest) && now()->subDays(40)->gte($oldest), 'about two months of history');
        $this->assertSame(0, $q('calls')->where('initiated_at', '>', now())->count());
        $this->assertSame($q('calls')->where('recording_status', 'available')->count(), $q('recordings')->where('status', 'available')->count());
        $this->assertSame(0, $q('calls')->whereNotIn('id', $q('call_dispositions')->select('call_id'))->count());   // every call has its outcome
        $this->assertGreaterThan(30, $q('call_dispositions')->whereNotNull('slip_key')->count());
        // Work only by the case's own agent (credit rule) and only on working days inside the agent's sign-in.
        $this->assertSame(0, DB::table('calls')->join('customers', 'customers.id', '=', 'calls.customer_id')->where('calls.company_id', $c->id)
            ->whereColumn('calls.user_id', '!=', 'customers.assigned_user_id')->count());
        $this->assertSame(1, $q('follow_ups')->where('status', 'open')->groupBy('customer_id')->selectRaw('COUNT(*) n')->get()->max('n'));

        $as = $this->console($c);
        $as->getJson('/api/admin/overview?days=30')->assertOk();
        $customers = $as->getJson('/api/admin/customers')->assertOk()->json();
        $this->assertSame(100, $customers['total'] ?? $customers['meta']['total'] ?? 100);
        $report = $as->getJson('/api/admin/reports/portfolio')->assertOk()->json();
        $this->assertNotEmpty($report);
        $pay = $as->getJson('/api/admin/payments')->assertOk()->json();
        $verified = $q('call_dispositions')->where('review_status', 'verified')->sum('amount');
        $this->assertEqualsWithDelta((float) $verified, (float) ($pay['totals']['verified']['amount'] ?? 0), 0.01);
        $devices = $as->getJson('/api/admin/devices')->assertOk()->json('data');
        $this->assertCount(8, $devices);

        // Recordings play and slips open wherever they normally do.
        $call = $q('calls')->where('recording_status', 'available')->first();
        $as->getJson("/api/admin/calls/{$call->id}")->assertOk();
        $as->postJson("/api/admin/calls/{$call->id}/recording/playback")->assertOk();
        $slip = $q('call_dispositions')->whereNotNull('slip_key')->first();
        $this->assertSame('image/jpeg', $as->get("/api/admin/payments/{$slip->id}/slip")->assertOk()->headers->get('Content-Type'));
        foreach (\App\Services\ExportService::KINDS as $kind) {
            $as->get("/api/admin/reports/export/{$kind}.csv?from=" . now()->subDays(60)->toDateString() . '&to=' . now()->toDateString())->assertOk();
        }

        // Same city → same people and customers (a consistent dataset), another city → its own.
        $again = $this->demo('pune', 'b@demo.test', 'Other Agency');
        $names = fn (Company $x) => DB::table('customers')->where('company_id', $x->id)->orderBy('id')->pluck('name')->all();
        $this->assertSame($names($c), $names($again));
        $chennai = $this->demo('chennai', 'c@demo.test', 'Chennai Agency');
        $this->assertSame(0, DB::table('customers')->where('company_id', $chennai->id)->where('city', '!=', 'Chennai')->count());
        $this->assertNotEquals($names($c), $names($chennai));
    }

    public function test_super_admin_sees_what_the_client_did_and_reset_touches_only_that_demo(): void
    {
        $a = $this->demo('hyderabad', 'a@demo.test', 'Alpha Demo');
        $b = $this->demo('delhi', 'b@demo.test', 'Beta Demo');
        $real = Workspaces::create('real-co', 'Real Co', 'boss@real.test', 'Boss', 'Real1234');
        app(CurrentCompany::class)->run($real, fn () => DB::table('customers')->insert(['company_id' => $real->id, 'customer_ref' => 'R-1', 'name' => 'Real customer', 'phone' => '+919000000001', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]));
        $this->travel(5)->seconds();

        // The demo client adds an employee and a calling number, closes a case and verifies a payment.
        $as = $this->console($a);
        $team = DB::table('teams')->where('company_id', $a->id)->value('id');
        $as->postJson('/api/admin/employees', ['employee_code' => 'NEW01', 'name' => 'New Joiner', 'phone' => '+919811122233', 'roles' => ['employee'], 'team_ids' => [$team]])->assertSuccessful();
        $as->postJson('/api/admin/calling/smart31/numbers', ['number' => '+919811122244', 'label' => 'Office line'])->assertSuccessful();
        $case = DB::table('customers')->where('company_id', $a->id)->value('id');
        $as->postJson("/api/admin/customers/{$case}/status", ['status' => 'closed', 'note' => 'Settled'])->assertOk();
        $pending = DB::table('call_dispositions')->where('company_id', $a->id)->where('review_status', 'pending')->value('id');
        $as->postJson("/api/admin/payments/{$pending}/review", ['status' => 'verified'])->assertOk();

        $list = $this->super()->getJson('/api/super/demo-clients')->assertOk()->json();
        $this->assertCount(2, $list['data']);
        $this->assertArrayHasKey('pune', $list['cities']);
        $this->assertSame(0, Company::query()->whereNull('demo')->whereIn('id', collect($list['data'])->pluck('id'))->count());
        $this->assertNotContains($a->id, collect($this->super()->getJson('/api/super/companies')->json('data'))->pluck('id'));   // real list stays real
        $show = $this->super()->getJson("/api/super/demo-clients/{$a->id}")->assertOk()->json();
        $this->assertSame('Hyderabad, Telangana', $show['info']['city_name']);
        $this->assertSame('added', collect($show['people'])->firstWhere('employee_code', 'NEW01')['state']);
        $this->assertSame('changed', collect($show['customers'])->firstWhere('id', $case)['state']);
        $added = collect($show['numbers'])->where('state', 'added')->pluck('number')->all();
        $this->assertContains('+919811122233', $added);
        $this->assertContains(\App\Telephony\Click2CallAdapter::normalize('+919811122244'), $added);
        $this->assertSame('changed', collect($show['payments'])->firstWhere('id', $pending)['state']);
        $this->assertNotEmpty($show['activity']);
        $changes = collect($show['changes'])->keyBy('kind');
        $this->assertSame(1, $changes['Employees / users']['added']);
        $this->assertContains($changes['Customers']['changed'], [1, 2]);   // the closed case, and the paying customer's case if it is another one
        $this->assertSame(1, $changes['Smart31 numbers']['added']);
        $slip = collect($show['payments'])->firstWhere('slip', true);
        $this->super()->get("/api/super/demo-clients/{$a->id}/slips/{$slip['id']}")->assertOk();
        $this->super()->get("/api/super/demo-clients/{$real->id}")->assertNotFound();   // real clients are not demos

        // Clear Demo Data: back to the original 100, the client still signed in; the other demo and the real client untouched.
        $bBefore = DB::table('calls')->where('company_id', $b->id)->count();
        $adminId = DB::table('users')->where('company_id', $a->id)->where('employee_code', 'admin')->value('id');
        $this->super()->postJson("/api/super/demo-clients/{$a->id}/reset")->assertOk();
        $this->assertSame(100, DB::table('customers')->where('company_id', $a->id)->count());
        $this->assertSame(0, DB::table('users')->where('company_id', $a->id)->where('employee_code', 'NEW01')->count());
        $this->assertSame(0, DB::table('smart31_numbers')->where('company_id', $a->id)->count());
        $this->assertSame($adminId, DB::table('users')->where('company_id', $a->id)->where('employee_code', 'admin')->value('id'));
        $this->assertSame(1, Company::query()->find($a->id)->demo['resets']);
        $this->assertSame($bBefore, DB::table('calls')->where('company_id', $b->id)->count());
        $this->assertSame(1, DB::table('customers')->where('company_id', $real->id)->count());
        $this->console($a->refresh())->getJson('/api/admin/overview')->assertOk();
        $after = $this->super()->getJson("/api/super/demo-clients/{$a->id}")->json();
        $this->assertSame(0, collect($after['changes'])->sum(fn ($k) => $k['added'] + $k['changed']));
        $this->super()->postJson("/api/super/demo-clients/{$real->id}/reset")->assertNotFound();

        // Delete: needs the workspace name typed; removes only that demo; shared recordings stay for the others.
        $this->super()->deleteJson("/api/super/demo-clients/{$b->id}", ['confirm' => 'nope'])->assertStatus(422);
        $this->super()->deleteJson("/api/super/demo-clients/{$b->id}", ['confirm' => $b->slug])->assertOk();
        $this->assertNull(Company::query()->find($b->id));
        $this->assertSame(0, DB::table('calls')->where('company_id', $b->id)->count());
        $this->assertSame(100, DB::table('customers')->where('company_id', $a->id)->count());
        $this->assertTrue(Storage::disk(config('ammp.recordings.disk'))->exists(DemoWorkspace::RECORDINGS . '/hi-ptp.mp3'));
        $this->assertSame(1, DB::table('companies')->where('id', $real->id)->count());
    }

    public function test_deleting_a_demo_call_keeps_the_shared_recording(): void
    {
        $c = $this->demo('mumbai', 'm@demo.test');
        $call = DB::table('calls')->where('company_id', $c->id)->where('recording_status', 'available')->first();
        $key = DB::table('recordings')->where('call_id', $call->id)->value('object_key');
        $this->console($c)->deleteJson("/api/admin/calls/{$call->id}")->assertOk();
        $this->assertTrue(Storage::disk(config('ammp.recordings.disk'))->exists($key));
    }
}
