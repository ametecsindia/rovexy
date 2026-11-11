<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Models\DailyActivity;
use App\Models\LocationEvent;
use App\Models\ManagedDevice;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\DayBuilder;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Reports → Employee activity: one known day is classified minute by minute and reported through every endpoint. */
final class EmployeeActivityTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'Asia/Kolkata'));
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        $this->in(function () {
            CompanyDefaults::apply($this->a);
            $mk = function (string $code, string $role, ?string $email = null) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => $email, 'phone' => '+919811111111', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                return $u;
            };
            $this->u = ['admin' => $mk('admin', 'company_admin', 'admin@alpha.test'), 'emp' => $mk('FOS1', 'employee'), 'idle' => $mk('FOS2', 'employee')];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->run($this->a, $fn);
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    /**
     * Monday 9 Nov 2026 (IST): signs in 09:12 at the office, call 09:15–09:20 (outcome 09:22), drives 09:40–10:25 to a
     * customer, visit at 10:30, stays there; phone silent 14:00–14:35; break 12:40–13:20; signs out 17:48.
     */
    private function seedDay(): void
    {
        $this->in(function () {
            $u = $this->u['emp'];
            $dev = ManagedDevice::query()->create(['installation_id' => (string) Str::uuid(), 'approval_code' => 'ABC123456', 'approval_state' => 'approved', 'assigned_user_id' => $u->id]);
            $at = fn (string $hm) => Carbon::parse("2026-11-09 $hm", 'Asia/Kolkata')->utc();
            $office = [17.4400, 78.3800];
            $cust = [17.5600, 78.3800];   // ≈ 13.3 km north
            Branch::query()->create(['code' => 'HYD', 'name' => 'Hyderabad'])->forceFill(['lat' => $office[0], 'lng' => $office[1], 'radius_m' => 150])->save();
            $ev = fn (string $type, Carbon $t, array $meta = []) => ActivityEvent::query()->create(['device_id' => $dev->id, 'user_id' => $u->id, 'type' => $type, 'occurred_at' => $t, 'meta' => $meta ?: null]);
            $ev('signed_in', $at('09:12'));
            for ($t = $at('09:12'); $t->lt($at('17:48')); $t = $t->copy()->addMinutes(2)) {
                if ($t->gt($at('14:00')) && $t->lt($at('14:35'))) {
                    continue;   // no signal
                }
                $m = $t->diffInMinutes($at('09:40'), false);
                $pos = $t->lt($at('09:40')) ? $office : ($t->lt($at('10:25')) ? [$office[0] + ($cust[0] - $office[0]) * ($t->diffInMinutes($at('09:40')) / 45), $office[1]] : $cust);
                $ev('heartbeat', $t);
                LocationEvent::query()->create(['device_id' => $dev->id, 'user_id' => $u->id, 'lat' => $pos[0], 'lng' => $pos[1], 'accuracy_m' => 12, 'captured_at' => $t]);
            }
            $ev('break_start', $at('12:40'), ['reason' => 'lunch']);
            $ev('break_end', $at('13:20'));
            $ev('signed_out', $at('17:48'));
            $call = Call::query()->create(['uuid' => (string) Str::uuid(), 'provider' => 'sim', 'user_id' => $u->id, 'device_id' => $dev->id, 'destination' => '+919800000001',
                'agent_leg' => 'pstn_callback', 'state' => 'completed', 'initiated_at' => $at('09:15'), 'answered_at' => $at('09:16'), 'ended_at' => $at('09:20'), 'talk_seconds' => 240]);
            DB::table('call_dispositions')->insert(['company_id' => $this->a->id, 'call_id' => $call->id, 'user_id' => $u->id, 'code' => 'PTP', 'submitted_at' => $at('09:22')]);
            $c = Customer::query()->create(['customer_ref' => 'C1', 'name' => 'Ravi Kumar', 'phone' => '+919800000001', 'assigned_user_id' => $u->id]);
            CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $u->id, 'type' => 'visit', 'meta' => ['lat' => $cust[0], 'lng' => $cust[1], 'distance_m' => 8], 'created_at' => $at('10:30')]);
        });
    }

    public function test_a_day_is_classified_minute_by_minute_and_adds_up(): void
    {
        $this->seedDay();
        $d = $this->in(fn () => app(DayBuilder::class)->build($this->u['emp'], '2026-11-09'));
        $s = $d['summary'];

        $this->assertSame(516 * 60, $s['signed_in_s']);   // 09:12 → 17:48
        $parts = $s['active_s'] + $s['customer_s'] + $s['travel_s'] + $s['break_s'] + $s['idle_s'] + $s['offline_s'];
        $this->assertSame($s['signed_in_s'], $parts, 'every signed-in minute is in exactly one state');
        $this->assertSame(40 * 60, $s['break_s']);
        $this->assertEqualsWithDelta(34 * 60, $s['offline_s'], 120);   // 14:00 → 14:35 without a heartbeat
        $this->assertEqualsWithDelta(45 * 60, $s['travel_s'], 300);
        $this->assertGreaterThanOrEqual(5 * 60, $s['active_s']);       // call + outcome
        $this->assertGreaterThan(3 * 3600, $s['customer_s']);         // stayed within 150 m of the visit all afternoon
        $this->assertEqualsWithDelta(13300, $s['distance_m'], 400);
        $this->assertGreaterThanOrEqual(26 * 60, $s['office_s']);
        $this->assertSame(2, $s['late_min']);    // 09:12 vs 09:00 + 10 min grace
        $this->assertSame(2, $s['early_min']);   // 17:48 vs 18:00 − 10 min grace
        $this->assertSame('half_day', $s['attendance']);   // worked 7 h 22 m < 8 h
        $this->assertTrue($s['final']);
        $this->assertSame(1, $s['visits']);
        $this->assertSame(1, $s['calls']);
        $this->assertSame([['type' => 'sign_in'], ['type' => 'sign_out']], array_map(fn ($l) => ['type' => $l['type']], $d['logins']));
        $this->assertSame('lunch', $d['breaks'][0]['reason']);
        $this->assertSame(['Hyderabad'], array_keys($d['offices']));
        $this->assertSame(['active', 'customer', 'travel', 'break', 'idle', 'offline'], array_values(array_intersect(DayBuilder::STATES, array_column($d['segments'], 'state'))));
        $this->assertSame('active', $d['segments'][0]['state']);   // the day opens with the call
    }

    public function test_reports_endpoints_scoping_and_exports(): void
    {
        $this->seedDay();
        $emp = $this->u['emp'];

        $day = $this->as($this->u['admin'])->getJson("/api/admin/activity/day?user_id={$emp->id}&date=2026-11-09")->assertOk();
        $day->assertJsonPath('employee.employee_code', 'FOS1')->assertJsonPath('summary.attendance', 'half_day')->assertJsonPath('visits.0.name', 'Ravi Kumar');

        $range = $this->as($this->u['admin'])->getJson("/api/admin/activity/employee?user_id={$emp->id}&from=2026-11-08&to=2026-11-10")->assertOk();
        $this->assertCount(3, $range->json('days'));   // Sun (off), Mon (worked), today (nothing yet)
        $range->assertJsonPath('totals.days_worked', 1)->assertJsonPath('totals.scheduled_days', 2)->assertJsonPath('totals.half_day', 1)->assertJsonPath('totals.late_days', 1);
        $this->assertSame(25, $range->json('totals.attendance_pct'));   // half a day of 2 scheduled days

        $team = $this->as($this->u['admin'])->getJson('/api/admin/activity/team?from=2026-11-09&to=2026-11-09')->assertOk();
        $codes = array_column(array_column($team->json('employees'), 'employee'), 'employee_code');
        $this->assertContains('FOS1', $codes);
        $this->assertContains('FOS2', $codes);
        $this->assertNotContains('admin', $codes);   // only people who use the phone app
        $fos1 = collect($team->json('employees'))->firstWhere('employee.employee_code', 'FOS1');
        $this->assertSame(['09:12', '17:48', false], [$fos1['login'], $fos1['logout'], $fos1['login_avg']]);   // login / logout, company time
        $range->assertJsonPath('totals.login', '09:12')->assertJsonPath('totals.logout', '17:48');

        $x = $this->as($this->u['admin'])->get("/api/admin/activity/export?kind=employee&format=xlsx&user_id={$emp->id}&from=2026-11-09&to=2026-11-09")->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $x->headers->get('Content-Type'));
        $this->as($this->u['admin'])->get("/api/admin/activity/export?kind=team&format=xlsx&from=2026-11-01&to=2026-11-09")->assertOk();
        $this->as($this->u['admin'])->get("/api/admin/activity/export?kind=employee&format=pdf&user_id={$emp->id}&from=2026-11-09&to=2026-11-09")
            ->assertOk()->assertSee('Employee activity — FOS1')->assertSee('Timeline')->assertSee('Save as PDF');

        // Work hours: shift moved to 09:30 → no longer late; rebuilt rows follow.
        $set = $this->as($this->u['admin'])->getJson('/api/admin/activity/settings')->assertOk();
        $hours = ['days' => [1, 2, 3, 4, 5, 6], 'start' => '09:30', 'end' => '18:00', 'grace_minutes' => 10, 'full_day_hours' => 7, 'half_day_hours' => 4, 'idle_minutes' => 10, 'travel_productive' => true];
        $this->as($this->u['admin'])->putJson('/api/admin/activity/settings', ['hours' => $hours, 'branches' => array_map(fn ($b) => ['id' => $b['id'], 'lat' => $b['lat'], 'lng' => $b['lng'], 'radius_m' => 200], $set->json('branches'))])->assertOk();
        $this->in(fn () => DailyActivity::query()->where('user_id', $emp->id)->update(['final' => false]));   // force a rebuild of the stored days
        $after = $this->as($this->u['admin'])->getJson("/api/admin/activity/employee?user_id={$emp->id}&from=2026-11-09&to=2026-11-09")->assertOk();
        $after->assertJsonPath('days.0.late_min', 0)->assertJsonPath('days.0.attendance', 'present');

        // An employee can't open reports; work events from the phone are accepted.
        $this->as($emp)->getJson("/api/admin/activity/day?user_id={$emp->id}")->assertForbidden();
        $this->as($this->u['admin'])->getJson("/api/admin/activity/day?user_id={$emp->id}&date=2026-12-01")->assertStatus(422);
    }
}
