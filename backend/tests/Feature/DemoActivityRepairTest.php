<?php
namespace Tests\Feature;

use App\Services\Demo\DemoWorkspace;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoActivityRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_fills_activity_and_keeps_client_changes(): void
    {
        Storage::fake('company_files'); Storage::fake('recordings');
        $c = DemoWorkspace::register(['name' => 'T', 'business' => 'Altaf', 'mobile' => '9876543210', 'email' => 'a@b.in',
            'address' => 'x', 'city' => 'hyderabad', 'password' => bcrypt('Demo@1234')]);
        // Like a demo built by the old code: no activity events, sessions without the demo mark.
        DB::table('activity_events')->where('company_id', $c->id)->delete();
        DB::table('work_sessions')->where('company_id', $c->id)->update(['access_hash' => DB::raw("'x' || substr(access_hash, 2)")]);
        // The client's own changes after the demo was built.
        $this->travel(5)->minutes();
        $cust = DB::table('customers')->where('company_id', $c->id)->value('id');
        DB::table('customers')->where('id', $cust)->update(['name' => 'Changed By Client', 'updated_at' => now()]);
        $calls = DB::table('calls')->where('company_id', $c->id)->count();
        $pays = DB::table('calls')->where('company_id', $c->id)->whereNotNull('amount')->count();
        $people = DB::table('users')->where('company_id', $c->id)->count();

        $s = DemoWorkspace::repairActivity($c->fresh());
        $s2 = DemoWorkspace::repairActivity($c->fresh());   // runs again safely

        $this->assertSame($s['events'], $s2['events']);
        $this->assertSame(8, $s['employees']);
        $this->assertSame('Changed By Client', DB::table('customers')->where('id', $cust)->value('name'));
        $this->assertSame($calls, DB::table('calls')->where('company_id', $c->id)->count());
        $this->assertSame($pays, DB::table('calls')->where('company_id', $c->id)->whereNotNull('amount')->count());
        $this->assertSame($people, DB::table('users')->where('company_id', $c->id)->count());
        $ev = DB::table('activity_events')->where('company_id', $c->id)->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type');
        $days = DB::table('work_sessions')->where('company_id', $c->id)->count();
        fwrite(STDERR, json_encode($s) . ' ' . json_encode($ev) . " sessions {$days}\n");
        $this->assertSame($days, (int) $ev['signed_in']);
        $this->assertSame(0, DB::table('location_events')->where('company_id', $c->id)->whereNotIn('work_session_id', DB::table('work_sessions')->select('id'))->count());
        $this->artisan('demo:activity', ['slug' => $c->slug])->assertSuccessful();

        // What the Employee activity report shows for the last full working day.
        $day = now('Asia/Kolkata')->subDay();
        while ($day->isSunday()) {
            $day->subDay();
        }
        app(CurrentCompany::class)->run($c->fresh(), function () use ($day) {
            foreach (\App\Models\User::query()->where(fn ($q) => $q->where('employee_code', 'like', 'TC2%')->orWhere('employee_code', 'like', 'FOS3%'))->orderBy('employee_code')->get() as $u) {
                $s = app(\App\Services\Activity\DayBuilder::class)->build($u, $day->toDateString())['summary'];
                fwrite(STDERR, sprintf("%-7s %s worked %4.1fh productive %4.1fh idle %3dm break %3dm offline %3dm travel %3dm %5.1f km calls %d visits %d\n",
                    $u->employee_code, $s['attendance'], $s['worked_s'] / 3600, $s['productive_s'] / 3600, $s['idle_s'] / 60, $s['break_s'] / 60, $s['offline_s'] / 60,
                    $s['travel_s'] / 60, $s['distance_m'] / 1000, $s['calls'], $s['visits']));
                $this->assertContains($s['attendance'], ['present', 'half_day']);
                $this->assertGreaterThan(6 * 3600, $s['worked_s']);
                $this->assertGreaterThan(20 * 60, $s['break_s']);
                $this->assertLessThan(150_000, $s['distance_m']);
            }
        });
    }
}
