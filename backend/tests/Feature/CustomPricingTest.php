<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\CustomPrice;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\Sales;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Negotiated prices per company (13 Oct 2026): priority, validity, snapshot on orders, security, audit. */
final class CustomPricingTest extends TestCase
{
    use RefreshDatabase;

    private array $co = [];
    private array $admin = [];
    private array $lic = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['alpha', 'beta'] as $slug) {
            $c = Company::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'timezone' => 'UTC', 'default_country' => 'IN']);
            app(CurrentCompany::class)->run($c, function () use ($c, $slug) {
                CompanyDefaults::apply($c);
                $u = User::query()->create(['employee_code' => 'admin', 'name' => 'Admin', 'email' => "admin@{$slug}.test", 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $c->id]);
                $this->admin[$slug] = $u;
            });
            $this->co[$slug] = $c;
            $this->lic[$slug] = Licence::query()->create(['key' => LicenceIssuer::newKey(), 'company_id' => $c->id, 'plan_id' => $this->plan('starter')->id, 'holder_name' => $slug,
                'kind' => 'subscription', 'max_users' => 10, 'features' => [], 'status' => 'active', 'starts_at' => now()->subMonth(), 'expires_at' => now()->addMonths(2), 'grace_days' => 7]);
        }
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function console(string $slug)
    {
        return $this->actingAs($this->admin[$slug], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => $slug, 'Referer' => 'http://localhost/']);
    }

    private function staff(string $role = 'super')
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => "{$role}@ametecs.test"], ['name' => ucfirst($role), 'password' => 'Staff-password-1', 'role' => $role, 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function quote(string $slug, string $plan, int $users = 10): array
    {
        return $this->console($slug)->getJson("/api/admin/licence/quote?users={$users}&plan_id={$this->plan($plan)->id}&state_code=36")->assertOk()->json();
    }

    public function test_only_that_company_gets_the_price_and_the_order_keeps_it(): void
    {
        $id = $this->staff()->postJson('/api/super/custom-prices', ['company_id' => $this->co['alpha']->id, 'plan_id' => $this->plan('business')->id,
            'applies_to' => 'any', 'mode' => 'fixed', 'value' => 30000, 'note' => 'Negotiated by Ravi'])->assertCreated()->json('id');

        $a = $this->quote('alpha', 'business');
        // standard: 10 users × ₹299 × 12 = 35,880; negotiated 30,000 for the year
        $this->assertSame(['custom', 35880.0, 5880.0, 30000.0, 35400.0], [$a['pricing'], (float) $a['list_price'], (float) $a['discount'], (float) $a['amount'], (float) $a['total']]);
        $b = $this->quote('beta', 'business');                                                 // another company: standard
        $this->assertSame(['standard', 42338.4], [$b['pricing'], (float) $b['total']]);
        $this->assertSame('standard', $this->quote('alpha', 'starter')['pricing']);            // other package: standard
        $this->assertSame(299.0, (float) $this->plan('business')->cloudTiers()->where('min_users', 5)->value('rate_inr'));   // global price untouched

        // the browser can't send a price: extra fields are ignored, the server computes it
        $n = $this->console('alpha')->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $this->plan('business')->id, 'state_code' => '36', 'total' => 1, 'amount' => 1])->assertCreated()->json('number');
        $o = SalesOrder::query()->where('number', $n)->firstOrFail();
        $this->assertSame([35400.0, 'custom', $id, 35880.0], [$o->total, $o->pricing, $o->custom_price_id, $o->list_price]);
        $this->get("/pay/{$o->number}/{$o->token()}")->assertOk()->assertSee('Negotiated price for your account')->assertSee('35,400.00');

        // price changed later: the open order keeps its quoted price until it is replaced by a new request
        $this->staff()->putJson("/api/super/custom-prices/{$id}", ['company_id' => $this->co['alpha']->id, 'plan_id' => $this->plan('business')->id, 'applies_to' => 'any', 'mode' => 'fixed', 'value' => 25000])->assertOk();
        $this->assertSame(35400.0, $o->fresh()->total);
        $n2 = $this->console('alpha')->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $this->plan('business')->id, 'state_code' => '36'])->assertCreated()->json('number');
        $this->assertSame('cancelled', $o->fresh()->status);                                    // no two open orders
        $o2 = SalesOrder::query()->where('number', $n2)->firstOrFail();
        $this->assertSame(29500.0, $o2->total);
        Sales::markPaid($o2, 'bank', 'UTR1');
        $this->assertSame('business', $this->lic['alpha']->fresh()->plan->code);                // renewed on the new plan at the negotiated price
        $this->get("/pay/{$o2->number}/{$o2->token()}/invoice")->assertOk()->assertSee('Negotiated price')
            ->assertSee('Rupees Twenty Nine Thousand Five Hundred Only')->assertSee('TAX INVOICE')->assertSee('Workspace: alpha');
    }

    public function test_most_specific_rule_wins_and_validity_dates_revert_to_standard(): void
    {
        $c = $this->co['alpha']->id;
        CustomPrice::query()->create(['company_id' => $c, 'plan_id' => null, 'applies_to' => 'any', 'mode' => 'percent', 'value' => 10]);
        $this->assertSame(32292.0, (float) $this->quote('alpha', 'business')['amount']);         // 10% off every package (35,880 → 32,292)
        $specific = CustomPrice::query()->create(['company_id' => $c, 'plan_id' => $this->plan('business')->id, 'applies_to' => 'renewal', 'mode' => 'fixed', 'value' => 80000]);
        $this->assertSame(80000.0, (float) $this->quote('alpha', 'business')['amount']);         // package rule beats "all packages"; higher than standard is allowed
        $this->assertSame(0.0, (float) $this->quote('alpha', 'business')['discount']);
        $specific->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->assertSame(32292.0, (float) $this->quote('alpha', 'business')['amount']);         // expired → next valid rule
        CustomPrice::query()->update(['starts_on' => now()->addDay()->toDateString(), 'ends_on' => null]);
        $this->assertSame('standard', $this->quote('alpha', 'business')['pricing']);            // not started yet → standard
        CustomPrice::query()->update(['starts_on' => null, 'active' => false]);
        $this->assertSame('standard', $this->quote('alpha', 'business')['pricing']);            // switched off → standard
    }

    public function test_no_discount_code_on_top_and_custom_quote_size_becomes_payable(): void
    {
        $c = $this->co['alpha']->id;
        $e = $this->plan('enterprise')->id;
        $this->console('alpha')->getJson("/api/admin/licence/quote?users=600&plan_id={$e}&state_code=36")->assertStatus(422)->assertJsonPath('error', 'custom_quote');   // beyond the price bands
        CustomPrice::query()->create(['company_id' => $c, 'plan_id' => $e, 'applies_to' => 'any', 'mode' => 'fixed', 'value' => 2000000]);
        $this->assertSame(2360000.0, (float) $this->quote('alpha', 'enterprise', 600)['total']);
        $this->console('alpha')->postJson('/api/admin/licence/renew', ['users' => 600, 'plan_id' => $e, 'state_code' => '36'])->assertCreated();
        $this->console('beta')->postJson('/api/admin/licence/renew', ['users' => 600, 'plan_id' => $e, 'state_code' => '36'])->assertStatus(422);   // still a quotation for others

        \App\Models\Platform\Coupon::query()->create(['code' => 'DIWALI20', 'type' => 'percent', 'value' => 20, 'active' => true]);
        $this->console('alpha')->getJson("/api/admin/licence/quote?users=600&plan_id={$e}&state_code=36&coupon=DIWALI20")->assertStatus(422)->assertJsonPath('error', 'bad_coupon');
        $beta = $this->console('beta')->getJson("/api/admin/licence/quote?users=10&plan_id={$this->plan('business')->id}&state_code=36&coupon=DIWALI20")->json();
        $this->assertSame(['standard', 7176.0], [$beta['pricing'], (float) $beta['discount']]);   // 20% of 35,880
    }

    public function test_email_rule_prices_a_new_workspace_bought_on_the_website(): void
    {
        $this->staff()->postJson('/api/super/custom-prices', ['email' => 'Owner@Gamma.test', 'plan_id' => $this->plan('starter')->id, 'applies_to' => 'new', 'mode' => 'percent', 'value' => 50])->assertCreated();
        $this->staff()->postJson('/api/super/custom-prices', ['email' => 'x@y.test', 'applies_to' => 'renewal', 'mode' => 'percent', 'value' => 5])->assertStatus(422);   // renewals need a company
        $this->post('/buy', ['plan' => 'starter', 'users' => 10, 'billing' => 'annual', 'buyer_company' => 'Gamma Ltd', 'buyer_name' => 'G', 'buyer_email' => 'owner@gamma.test', 'buyer_phone' => '9822222222',
            'state_code' => '29', 'workspace' => 'gamma', 'password' => 'Gamma-pass-123', 'password_confirmation' => 'Gamma-pass-123', 'terms' => '1']);
        $o = SalesOrder::query()->where('workspace', 'gamma')->firstOrFail();
        $this->assertSame(['custom', 11940.0, 14089.2], [$o->pricing, $o->amount, $o->total]);   // half of 10 × ₹199 × 12, + IGST
        $this->post('/buy', ['plan' => 'starter', 'users' => 10, 'billing' => 'annual', 'buyer_company' => 'Delta', 'buyer_name' => 'D', 'buyer_email' => 'd@delta.test', 'buyer_phone' => '9822222223',
            'state_code' => '29', 'workspace' => 'delta', 'password' => 'Delta-pass-123', 'password_confirmation' => 'Delta-pass-123', 'terms' => '1']);
        $this->assertSame('standard', SalesOrder::query()->where('workspace', 'delta')->value('pricing'));
    }

    public function test_only_super_admins_change_prices_and_every_change_is_audited(): void
    {
        $body = ['company_id' => $this->co['alpha']->id, 'applies_to' => 'any', 'mode' => 'percent', 'value' => 15];
        $this->staff('sales')->postJson('/api/super/custom-prices', $body)->assertForbidden();
        $this->staff('support')->getJson('/api/super/custom-prices')->assertOk();                 // can look
        $this->staff('accountant')->getJson('/api/super/custom-prices')->assertForbidden();
        \Illuminate\Support\Facades\Auth::guard('platform')->forgetUser();
        $this->console('alpha')->postJson('/api/super/custom-prices', $body)->assertUnauthorized();          // a company admin is not Super Admin staff
        $this->staff()->postJson('/api/super/custom-prices', ['value' => 100] + $body)->assertStatus(422);   // never free through a discount

        $id = $this->staff()->postJson('/api/super/custom-prices', $body)->assertCreated()->json('id');
        $this->staff()->putJson("/api/super/custom-prices/{$id}", ['value' => 20, 'note' => 'Year 2'] + $body)->assertOk();
        $h = $this->staff()->getJson("/api/super/custom-prices/{$id}/history")->assertOk()->json();
        $this->assertSame('custom_price.updated', $h[0]['action']);
        $this->assertSame([15.0, 20.0], [(float) $h[0]['before']['value'], (float) $h[0]['after']['value']]);
        $this->assertSame('Super', $h[0]['by']);
        $list = $this->staff()->getJson('/api/super/custom-prices')->assertOk();
        $this->assertSame('active', $list->json('rules.0.state'));
        $this->assertSame(28704.0, (float) collect($list->json('rules.0.prices'))->firstWhere('plan', 'Business')['custom']);   // 20% off 35,880 at this company's 10 users

        // used on an order → kept; switch it off instead
        Sales::createOrder('renewal', ['plan_id' => $this->plan('business')->id], ['buyer_company' => 'Alpha', 'buyer_name' => 'A', 'buyer_email' => 'a@alpha.test', 'state_code' => '36'], $this->lic['alpha'], 'test');
        $this->staff()->deleteJson("/api/super/custom-prices/{$id}")->assertStatus(422)->assertJsonPath('error', 'custom_price_used');
        $this->staff()->putJson("/api/super/custom-prices/{$id}", ['value' => 20, 'active' => false] + $body)->assertOk();
        $this->assertSame('off', $this->staff()->getJson('/api/super/custom-prices?state=off')->json('rules.0.state'));
        $unused = CustomPrice::query()->create(['company_id' => $this->co['beta']->id, 'applies_to' => 'any', 'mode' => 'percent', 'value' => 5]);
        $this->staff()->deleteJson("/api/super/custom-prices/{$unused->id}")->assertOk();
        $this->assertSame('custom_price.deleted', $this->staff()->getJson("/api/super/custom-prices/{$unused->id}/history")->json('0.action'));
    }
}
