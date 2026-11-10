<?php
namespace Tests\Feature;

use App\Services\Demo\DemoWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoTrailKmTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_field_trail_is_a_realistic_day(): void
    {
        Storage::fake('company_files'); Storage::fake('recordings');
        $c = DemoWorkspace::register(['name' => 'T', 'business' => 'Altaf', 'mobile' => '9876543210', 'email' => 'a@b.in',
            'address' => 'x', 'city' => 'hyderabad', 'password' => bcrypt('Demo@1234')]);
        $rows = DB::table('location_events')->where('company_id', $c->id)->orderBy('user_id')->orderBy('captured_at')->get();
        $km = []; $prev = null; $gap = 0;
        foreach ($rows as $r) {
            if ($prev && $prev->user_id === $r->user_id && substr($prev->captured_at, 0, 10) === substr($r->captured_at, 0, 10)) {
                $d = 6371 * 2 * asin(sqrt(sin(deg2rad($r->lat - $prev->lat) / 2) ** 2 + cos(deg2rad($prev->lat)) * cos(deg2rad($r->lat)) * sin(deg2rad($r->lng - $prev->lng) / 2) ** 2));
                $km[(str_starts_with(DB::table('users')->where('id', $r->user_id)->value('employee_code'), 'FOS') ? 'F' : 'O') . $r->user_id . substr($r->captured_at, 0, 10)] = ($km[(str_starts_with(DB::table('users')->where('id', $r->user_id)->value('employee_code'), 'FOS') ? 'F' : 'O') . $r->user_id . substr($r->captured_at, 0, 10)] ?? 0) + $d;
                $gap = max($gap, strtotime($r->captured_at) - strtotime($prev->captured_at));
            }
            $prev = $r;
        }
        foreach (['F' => 'field', 'O' => 'office'] as $k => $l) {
            $v = array_values(array_filter($km, fn ($x, $key) => $key[0] === $k, ARRAY_FILTER_USE_BOTH)); sort($v);
            fwrite(STDERR, sprintf("%s: days %d, km/day min %.1f median %.1f max %.1f\n", $l, count($v), $v[0], $v[intdiv(count($v), 2)], end($v)));
        }
        sort($km);
        fwrite(STDERR, sprintf("points %d, user-days %d, km/day median %.1f max %.1f, max gap %ds\n", count($rows), count($km), $km[intdiv(count($km), 2)], end($km), $gap));
        $ev = DB::table('activity_events')->where('company_id', $c->id)->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type')->all();
        fwrite(STDERR, json_encode($ev) . "\n");
        $this->assertGreaterThan(300, $ev['signed_in'] ?? 0);
        $this->assertGreaterThan(300, $ev['break_start'] ?? 0);
        $this->assertLessThan(120, end($km));
        $this->assertLessThanOrEqual(300, $gap);
    }
}
