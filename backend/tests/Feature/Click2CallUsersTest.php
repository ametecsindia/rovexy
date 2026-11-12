<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Click2Call (+ Smart31) for some of the users, chosen at purchase; Super Admin changes it on the licence (12 Nov 2026). */
final class Click2CallUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSettings::set('cloud_setup_base', '0');
        PlatformSettings::set('cloud_setup_per_extra', '0');
    }

    private function check(array $body)
    {
        return $this->postJson('/price-check', $body + ['deployment' => 'cloud', 'billing' => 'quarterly']);
    }

    private function buyer(): array
    {
        return ['buyer_company' => 'Alpha', 'buyer_name' => 'A', 'buyer_email' => 'a@alpha.test', 'state_code' => '36', 'workspace' => 'alpha', 'admin_password' => 'Secret-123456'];
    }

    public function test_cloud_price_adds_click2call_for_only_the_chosen_users(): void
    {
        // 10 users on own Maps (₹150) = ₹4,500 a quarter; Click2Call for 5 of them = 5 × ₹350 × 3 = ₹5,250; with Smart31 5 × ₹550 × 3 = ₹8,250
        $none = $this->check(['plan' => 'cloud-own', 'users' => 10, 'c2c_users' => 0])->assertOk()->json();
        $this->assertEquals(4500, $none['amount']);
        $this->assertCount(1, $none['lines']);
        $c2c = $this->check(['plan' => 'cloud-own', 'users' => 10, 'c2c_users' => 5])->assertOk()->json();
        $this->assertEquals([4500 + 5250, 4500, 1750], [$c2c['amount'], $c2c['list_price'], $c2c['c2c_monthly']]);
        $this->assertSame('c2c', $c2c['lines'][1]['type']);
        $s31 = $this->check(['plan' => 'cloud-own', 'users' => 10, 'c2c_users' => 5, 'smart31' => true])->assertOk()->json();
        $this->assertEquals(4500 + 8250, $s31['amount']);
        $this->assertTrue($s31['smart31']);
        // Smart31 is never sold without Click2Call; Click2Call can't be for more people than the plan
        $this->assertFalse($this->check(['plan' => 'cloud-own', 'users' => 10, 'c2c_users' => 0, 'smart31' => true])->json('smart31'));
        $this->check(['plan' => 'cloud-own', 'users' => 10, 'c2c_users' => 11])->assertStatus(422)->assertJsonPath('error', 'bad_c2c');
    }

    public function test_on_premises_perpetual_shows_click2call_monthly_outside_the_one_time_total(): void
    {
        $base = $this->check(['plan' => 'starter', 'users' => 10, 'deployment' => 'onprem', 'c2c_users' => 0])->assertOk()->json('amount');
        $j = $this->check(['plan' => 'starter', 'users' => 10, 'deployment' => 'onprem', 'c2c_users' => 4])->assertOk()->json();
        $this->assertEquals($base, $j['amount']);
        $this->assertEquals(1400, $j['c2c_monthly']);
    }

    public function test_paid_order_sets_the_licence_click2call_users_and_features(): void
    {
        $plan = Plan::query()->where('code', 'cloud-ametecs')->firstOrFail();
        $o = Sales::createOrder('new', ['plan_id' => $plan->id, 'users' => 10, 'deployment' => 'cloud', 'licence_kind' => 'subscription', 'c2c_users' => 3, 'smart31' => true], $this->buyer());
        $this->assertSame(3, $o->c2c_users);
        Sales::markPaid($o->fresh(), 'bank', 'ref', null, 'test');
        $l = Licence::query()->findOrFail($o->fresh()->licence_id);
        $this->assertSame(3, $l->c2c_users);
        $this->assertContains('click2call', $l->features);
        $this->assertContains('smart31', $l->features);

        // renewal keeps Click2Call by default; the client can drop it
        [, $pr] = Sales::quote('renewal', [], ['buyer_email' => 'a@alpha.test'], $l);
        $this->assertEquals(10 * 300 * 3 + 3 * 550 * 3, $pr['amount']);
        [, $pr] = Sales::quote('renewal', ['c2c_users' => 0], ['buyer_email' => 'a@alpha.test'], $l);
        $this->assertEquals(10 * 300 * 3, $pr['amount']);

        // more Click2Call users mid-term, same users: only the Click2Call difference for the remaining days
        [, $pr] = Sales::quote('seats', ['users' => 10, 'c2c_users' => 6], ['buyer_email' => 'a@alpha.test'], $l);
        $this->assertSame(['c2c'], array_column($pr['lines'], 'type'));
        $this->assertGreaterThan(0, $pr['amount']);
    }

    public function test_super_admin_changes_users_click2call_and_smart31_on_a_licence(): void
    {
        $l = LicenceIssuer::issue(Plan::query()->where('code', 'starter')->firstOrFail(), 'Beta', 'b@beta.test', null, 'perpetual', 10, 'onprem', null, 'test');
        $staff = $this->actingAs(PlatformAdmin::query()->create(['email' => 's@a.test', 'name' => 'S', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']), 'platform')
            ->withHeaders(['Referer' => 'http://localhost/']);
        $staff->patchJson("/api/super/licences/{$l->id}", ['max_users' => 20, 'c2c_users' => 5, 'smart31' => true, 'reason' => 'client call'])->assertOk();
        $l->refresh();
        $this->assertSame([20, 5], [$l->max_users, $l->c2c_users]);
        $this->assertContains('smart31', $l->features);
        $staff->patchJson("/api/super/licences/{$l->id}", ['smart31' => false, 'reason' => 'off'])->assertOk();
        $this->assertNotContains('smart31', $l->fresh()->features);
        $this->assertContains('click2call', $l->fresh()->features);
        $staff->patchJson("/api/super/licences/{$l->id}", ['c2c_users' => 21, 'reason' => 'x'])->assertStatus(422);
        $staff->patchJson("/api/super/licences/{$l->id}", ['c2c_users' => 0, 'reason' => 'off'])->assertOk();
        $this->assertNotContains('click2call', $l->fresh()->features);
        $this->assertSame(0, \App\Services\Licensing\LicenceAuthority::payload($l->fresh(), 'online', null)['c2c_users']);   // on-prem installs get the count
    }

    public function test_website_buy_page_offers_click2call_users_and_maps_old_links(): void
    {
        $this->get('/buy?plan=cloud-own-c2c-smart31&users=10')->assertOk()
            ->assertSee('value="cloud-own"', false)->assertSee('name="c2c_users" id="c2c" value="10"', false)->assertSee('id="s31" checked', false);
        $this->get('/')->assertOk()->assertSee('id="cp-c2c"', false);
        $this->assertFalse((bool) Plan::query()->where('code', 'cloud-own-c2c')->value('is_public'));
    }
}
