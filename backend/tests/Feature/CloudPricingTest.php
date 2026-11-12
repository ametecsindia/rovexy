<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Services\Platform\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ROVEXY Cloud pricing of 10 Nov 2026 (quarterly, two Google Maps models, C2C / Smart31) — and on-premises unchanged. */
final class CloudPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\Platform\PlatformSettings::set('cloud_setup_base', '0'); \App\Services\Platform\PlatformSettings::set('cloud_setup_per_extra', '0');   // these check subscription prices; the Cloud setup fee is tested in CloudPricingTest / OnPremLicensingTest
    }

    public function test_first_cloud_order_carries_setup_and_onboarding(): void
    {
        \App\Services\Platform\PlatformSettings::set('cloud_setup_base', '2500');
        \App\Services\Platform\PlatformSettings::set('cloud_setup_per_extra', '100');
        $setup = fn (int $n) => collect($this->check('cloud-ametecs', $n)->assertOk()->json('lines'))->firstWhere('type', 'setup')['amount'] ?? null;
        $this->assertEquals([2500, 2500, 3500], [$setup(10), $setup(25), $setup(35)]);   // ₹2,500 up to 25 users + ₹100 per extra user
        $this->get('/')->assertSee('One-time setup &amp; onboarding on the first order: ₹2,500 for up to 25 users, plus ₹100 for each user above that', false);
    }

    private function check(string $plan, int $users, string $dep = 'cloud', string $billing = 'quarterly')
    {
        return $this->postJson('/price-check', ['plan' => $plan, 'users' => $users, 'deployment' => $dep, 'billing' => $billing]);
    }

    public function test_the_six_examples_from_the_pricing_instructions(): void
    {
        $ex = [['cloud-ametecs', 5, 5250], ['cloud-ametecs', 10, 9000], ['cloud-ametecs', 50, 37500], ['cloud-own', 5, 2250],
            ['cloud-own-c2c', 10, 15000], ['cloud-own-c2c-smart31', 10, 21000]];
        foreach ($ex as [$plan, $n, $quarter]) {
            $j = $this->check($plan, $n)->assertOk()->json();
            $this->assertEquals($quarter, $j['amount'], "$plan × $n");
            $this->assertEquals(round($quarter * 1.18, 2), $j['total'], "$plan × $n with 18% GST");
            $this->assertSame('quarterly', $j['billing']);
            $this->assertCount(1, $j['lines'], 'no setup fee or other line');
        }
        $j = $this->check('cloud-own-c2c', 10)->json();
        $this->assertEquals([150, 350, 4500, 10500], [$j['base_per_user_month'], $j['addon_per_user_month'], $j['base_quarter'], $j['addon_quarter']]);
        $j = $this->check('cloud-own-c2c-smart31', 10)->json();
        $this->assertEquals([150, 550, 4500, 16500], [$j['base_per_user_month'], $j['addon_per_user_month'], $j['base_quarter'], $j['addon_quarter']]);
    }

    public function test_every_size_for_both_google_maps_models_and_add_ons(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // ~80 checks in one go
        $ametecs = [5 => 350, 9 => 350, 10 => 300, 24 => 300, 25 => 300, 49 => 300, 50 => 250];
        foreach (['' => 0, '-c2c' => 350, '-c2c-smart31' => 550] as $sfx => $add) {
            foreach ($ametecs as $n => $rate) {
                $this->assertEquals($n * ($rate + $add) * 3, $this->check("cloud-ametecs{$sfx}", $n)->assertOk()->json('amount'), "ametecs{$sfx} × $n");
            }
            $this->check("cloud-ametecs{$sfx}", 51)->assertStatus(422)->assertJsonPath('error', 'custom_quote');   // above 50: contact sales
            foreach ([5, 9, 10, 24, 25, 49, 50, 51, 120] as $n) {
                $this->assertEquals($n * (150 + $add) * 3, $this->check("cloud-own{$sfx}", $n)->assertOk()->json('amount'), "own{$sfx} × $n");
            }
            $this->check("cloud-own{$sfx}", 4)->assertStatus(422)->assertJsonPath('error', 'below_minimum');
        }
        // Quarterly only: an annual request is priced quarterly at the same rate.
        $this->check('cloud-ametecs', 10, 'cloud', 'annual')->assertOk()->assertJsonPath('billing', 'quarterly')->assertJsonPath('amount', 9000.0);
    }

    public function test_on_premises_prices_and_rules_are_unchanged(): void
    {
        // The seeded on-prem bands (2026_10_13 migration) — the same numbers before this change.
        $expect = ['starter' => [5 => 40000, 10 => 40000, 25 => 85000, 50 => 150000], 'business' => [5 => 60000, 25 => 130000, 50 => 225000],
            'enterprise' => [5 => 80000, 25 => 175000, 100 => 500000]];
        foreach ($expect as $plan => $sizes) {
            foreach ($sizes as $n => $price) {
                $j = $this->check($plan, $n, 'onprem', 'annual')->assertOk()->json();
                $this->assertEquals($price, $j['list_price'], "$plan on-prem × $n");
                $this->assertSame('perpetual', $j['licence_kind']);
            }
        }
        // The website sells cloud only as ROVEXY Cloud, and ROVEXY Cloud only on the cloud.
        $this->check('business', 25, 'cloud', 'annual')->assertStatus(422)->assertJsonPath('error', 'not_offered');
        $this->check('cloud-own', 25, 'onprem')->assertStatus(422)->assertJsonPath('error', 'not_offered');
    }

    public function test_pages_default_to_on_premises_and_show_the_cloud_rates(): void
    {
        $home = $this->get('/')->assertOk();
        $html = $home->getContent();
        $this->assertMatchesRegularExpression('/name="dep" value="onprem" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="dep" value="cloud" checked/', $html);
        $home->assertSee('Cloud Server — Powered by Ametecs Google Maps API')->assertSee('Cloud Server — Use Your Own Google Maps API')
            ->assertSee('₹5,250')->assertSee('₹9,000')->assertSee('₹37,500')->assertSee('Above 50 users')
            ->assertSee('Google Maps API consumption charges are not included in the ROVEXY subscription and are billed separately by Google.')
            ->assertSee('up to 50 connected calls per user per day', false);
        $this->assertStringNotContainsString('(per user per month, annual)', $html);   // old cloud offers gone from structured data

        $this->get('/buy?plan=cloud-own-c2c&users=10')->assertOk()->assertSee('value="cloud-own"', false)->assertSee('Billed quarterly in advance');   // old add-on link → Click2Call users (12 Nov)
        $this->get('/buy?plan=business')->assertOk()->assertSee('Rent · Cloud');                 // an old cloud link lands on ROVEXY Cloud
        $this->get('/buy?kind=perpetual&plan=business&users=25')->assertOk()->assertSee('Own · Perpetual');
    }

    public function test_a_cloud_order_carries_the_add_on_features_and_quarterly_term(): void
    {
        [$plan, $pr] = Sales::quote('new', ['plan_id' => Plan::query()->where('code', 'cloud-ametecs-c2c-smart31')->value('id'), 'users' => 10, 'deployment' => 'cloud',
            'licence_kind' => 'subscription', 'billing' => 'annual'], ['buyer_email' => 'a@b.test', 'state_code' => '36']);
        $this->assertSame('quarterly', $pr['billing']);
        $this->assertEquals(10 * 850 * 3, $pr['amount']);
        $this->assertContains('click2call', $plan->features);
        $this->assertContains('smart31', $plan->features);
        $this->assertNotContains('click2call', Plan::query()->where('code', 'cloud-ametecs')->first()->features);
    }
}
