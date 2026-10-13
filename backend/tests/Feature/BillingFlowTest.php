<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Company → payment → licence upgrade, end to end: one open order, failed + retry, paid after cancel, billing details (13 Oct 2026). */
final class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private User $admin;
    private Licence $l;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'UTC', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($this->a, function () {
            CompanyDefaults::apply($this->a);
            $this->admin = User::query()->create(['employee_code' => 'admin', 'name' => 'Admin', 'email' => 'admin@alpha.test', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
            $this->admin->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $this->a->id]);
        });
        $this->l = Licence::query()->create(['key' => LicenceIssuer::newKey(), 'company_id' => $this->a->id, 'plan_id' => Plan::query()->where('code', 'starter')->value('id'),
            'holder_name' => 'Alpha', 'kind' => 'subscription', 'max_users' => 10, 'features' => ['calling'], 'status' => 'active', 'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonths(2), 'grace_days' => 7]);
        PlatformSettings::set('razorpay_key_id', 'rzp_test_x');
        PlatformSettings::set('razorpay_key_secret', 'shh');
        PlatformSettings::set('razorpay_webhook_secret', 'wh');
    }

    private function console()
    {
        return $this->actingAs($this->admin, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    private function staff(string $role = 'super')
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => "{$role}@ametecs.test"], ['name' => ucfirst($role), 'password' => 'Staff-password-1', 'role' => $role, 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function webhook(string $event, string $rzOrder, int $paise, array $extra = [])
    {
        $body = json_encode(['event' => $event, 'payload' => ['payment' => ['entity' => ['id' => 'pay_' . $event, 'order_id' => $rzOrder, 'amount' => $paise] + $extra]]]);
        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'wh'), 'CONTENT_TYPE' => 'application/json'], $body);
    }

    public function test_quote_then_one_open_order_per_licence(): void
    {
        $business = Plan::query()->where('code', 'business')->firstOrFail();
        $starter = Plan::query()->where('code', 'starter')->firstOrFail();
        $q = $this->console()->getJson("/api/admin/licence/quote?users=10&plan_id={$business->id}&state_code=36")->assertOk();
        $this->assertSame(42338.4, (float) $q->json('total'));                                          // 10 users × ₹299 × 12 = 35,880 + 18% GST
        $this->assertSame('licence', $q->json('lines.0.type'));
        $this->assertGreaterThan(0, (float) $q->json('cgst'));                                             // Telangana → CGST + SGST
        $this->assertSame($this->l->expires_at->copy()->addMonthsNoOverflow(12)->toDateString(), $q->json('valid_until'));   // renewal starts at expiry

        $first = $this->console()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $business->id, 'state_code' => '36'])->assertCreated();
        $this->assertSame((float) $q->json('total'), (float) $first->json('total'));
        $again = $this->console()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $business->id, 'state_code' => '36'])->assertOk();   // double-click / back button
        $this->assertSame($first->json('number'), $again->json('number'));
        $this->assertSame(1, SalesOrder::query()->where('status', 'created')->count());

        $other = $this->console()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $starter->id, 'state_code' => '36'])->assertCreated();   // changed their mind
        $old = SalesOrder::query()->where('number', $first->json('number'))->firstOrFail();
        $this->assertSame('cancelled', $old->status);
        $this->assertSame('Replaced by a newer order', $old->cancel_reason);
        $this->assertSame(1, SalesOrder::query()->where('status', 'created')->count());
        $this->console()->getJson('/api/admin/licence')->assertOk()->assertJsonPath('open_order.number', $other->json('number'))
            ->assertJsonPath('open_order.pay_state', 'pending')->assertJsonPath('billing.state_code', '36');   // GST state remembered
    }

    public function test_failed_payment_stays_open_retry_activates_once(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_RZ9'])]);
        $business = Plan::query()->where('code', 'business')->firstOrFail();
        $o = SalesOrder::query()->where('number', $this->console()->postJson('/api/admin/licence/renew', ['users' => 50, 'plan_id' => $business->id, 'state_code' => '29'])->json('number'))->firstOrFail();
        $base = "/pay/{$o->number}/{$o->token()}";
        $this->postJson("{$base}/razorpay")->assertOk();
        $paise = (int) round($o->total * 100);

        $this->webhook('payment.failed', 'order_RZ9', $paise, ['error_description' => 'Bank declined the card'])->assertOk();
        $o->refresh();
        $this->assertSame('created', $o->status);                                                          // still payable
        $this->assertSame('failed', $o->pay_state);
        $this->assertSame(1, $o->attempts);
        $this->assertSame(10, $this->l->fresh()->max_users);                                              // nothing activated
        $this->get($base)->assertOk()->assertSee('Bank declined the card');
        $this->console()->getJson('/api/admin/licence')->assertJsonPath('open_order.pay_state', 'failed')->assertJsonPath('open_order.failure_reason', 'Bank declined the card');
        $this->staff()->getJson('/api/super/orders?status=failed')->assertOk()->assertJsonPath('total', 1);

        // a forged "success" from the browser activates nothing
        $this->postJson("{$base}/verify", ['razorpay_order_id' => 'order_RZ9', 'razorpay_payment_id' => 'pay_x', 'razorpay_signature' => 'forged'])->assertStatus(422);
        $this->assertSame('created', $o->fresh()->status);

        // retry succeeds; callback and webhook both arrive → activated once
        $this->postJson("{$base}/razorpay")->assertOk();
        $sig = hash_hmac('sha256', 'order_RZ9|pay_ok', 'shh');
        $this->postJson("{$base}/verify", ['razorpay_order_id' => 'order_RZ9', 'razorpay_payment_id' => 'pay_ok', 'razorpay_signature' => $sig])->assertOk();
        $this->webhook('payment.captured', 'order_RZ9', $paise)->assertOk();
        $o->refresh();
        $this->assertSame('paid', $o->pay_state);
        $this->assertSame(2, $o->attempts);
        $l = $this->l->fresh();
        $this->assertSame(50, $l->max_users);
        $this->assertSame(now()->addMonths(2)->addMonthsNoOverflow(12)->toDateString(), $l->expires_at->toDateString());
        $this->assertSame(1, DB::table('licence_events')->where('licence_id', $l->id)->where('type', 'renewed')->count());
        $page = $this->console()->getJson('/api/admin/licence')->assertOk();
        $this->assertNull($page->json('open_order'));
        $this->assertSame($o->invoice_number, $page->json('paid_orders.0.invoice_number'));
        $this->assertSame('Renewed', $page->json('history.0.what'));
    }

    public function test_cancelled_order_paid_at_gateway_is_honoured_but_not_by_hand(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_RZC'])]);
        $plan = Plan::query()->where('code', 'business')->firstOrFail();
        $o = Sales::createOrder('renewal', ['plan_id' => $plan->id], ['buyer_company' => 'Alpha', 'buyer_name' => 'A', 'buyer_email' => 'a@alpha.test', 'state_code' => '36'], $this->l, 'test');
        $this->postJson("/pay/{$o->number}/{$o->token()}/razorpay")->assertOk();
        $this->staff()->postJson("/api/super/orders/{$o->id}/cancel", ['reason' => 'Customer asked'])->assertOk();
        $this->assertSame('Customer asked', $o->fresh()->cancel_reason);
        $this->postJson("/pay/{$o->number}/{$o->token()}/razorpay")->assertStatus(409);                      // no new checkout
        $this->staff()->postJson("/api/super/orders/{$o->id}/payment", ['method' => 'bank', 'reference' => 'UTR1'])->assertStatus(422);   // staff can't
        $this->webhook('payment.captured', 'order_RZC', (int) round($o->total * 100))->assertOk();          // but the money that came in counts
        $this->assertSame('paid', $o->fresh()->status);
        $this->assertSame('business', $this->l->fresh()->plan->code);
        $this->assertDatabaseHas('platform_audit', ['action' => 'order.paid_after_cancel']);
    }

    public function test_company_cancels_only_its_own_open_order(): void
    {
        $plan = Plan::query()->where('code', 'business')->firstOrFail();
        $mine = $this->console()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => $plan->id, 'state_code' => '36'])->json('number');
        $b = Company::query()->create(['slug' => 'beta', 'name' => 'Beta', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $lb = Licence::query()->create(['key' => LicenceIssuer::newKey(), 'company_id' => $b->id, 'holder_name' => 'Beta', 'kind' => 'subscription', 'max_users' => 5, 'features' => [], 'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addYear(), 'grace_days' => 7]);
        $theirs = Sales::createOrder('renewal', ['plan_id' => $plan->id], ['buyer_company' => 'Beta', 'buyer_name' => 'B', 'buyer_email' => 'b@beta.test', 'state_code' => '36'], $lb, 'test');
        $this->console()->postJson("/api/admin/licence/orders/{$theirs->number}/cancel")->assertNotFound();
        $this->console()->postJson("/api/admin/licence/orders/{$mine}/cancel")->assertOk();
        $this->assertSame('cancelled', SalesOrder::query()->where('number', $mine)->value('status'));
        $this->console()->postJson("/api/admin/licence/orders/{$mine}/cancel")->assertStatus(422);          // already closed
    }

    public function test_super_edits_company_billing_details(): void
    {
        $s = $this->staff();
        $s->putJson("/api/super/companies/{$this->a->id}", ['state_code' => '29', 'gstin' => '36abcde1234f1z5'])->assertStatus(422)->assertJsonPath('error', 'bad_gstin');
        $s->putJson("/api/super/companies/{$this->a->id}", ['name' => 'Alpha Pvt Ltd', 'billing_email' => 'accounts@alpha.test', 'billing_phone' => '9800000000',
            'state_code' => '36', 'gstin' => '36abcde1234f1z5', 'address' => 'Kondapur, Hyderabad'])->assertOk();
        $s->getJson("/api/super/companies/{$this->a->id}")->assertOk()->assertJsonPath('gstin', '36ABCDE1234F1Z5')->assertJsonPath('billing_email', 'accounts@alpha.test');
        $s->putJson("/api/super/companies/{$this->a->id}", ['address' => ''])->assertOk();                  // partial update keeps the rest
        $this->assertSame('Alpha Pvt Ltd', $this->a->fresh()->name);
        $this->assertNull($this->a->fresh()->address);
        $this->staff('support')->putJson("/api/super/companies/{$this->a->id}", ['name' => 'X'])->assertForbidden();

        // renewal uses the company's billing e-mail and pre-fills GST details
        $this->console()->getJson('/api/admin/licence')->assertJsonPath('billing.gstin', '36ABCDE1234F1Z5');
        $n = $this->console()->postJson('/api/admin/licence/renew', ['users' => 10, 'plan_id' => Plan::query()->where('code', 'business')->value('id'), 'state_code' => '36', 'buyer_gstin' => '36ABCDE1234F1Z5'])->json('number');
        $this->assertSame('accounts@alpha.test', SalesOrder::query()->where('number', $n)->value('buyer_email'));
    }
}
