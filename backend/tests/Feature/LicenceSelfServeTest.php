<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Licensing\LicenceAuthority;
use App\Services\Licensing\LicenceClient;
use App\Services\Licensing\LicenceSigner;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\Licensing;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hardening + self-serve upgrades (13 Oct 2026): replaced / revoked licences can't be extended, payments are settled from the
 * gateway itself (capture, reconcile, amount mismatch), and on-prem installs buy renewals / users / AMC online.
 */
final class LicenceSelfServeTest extends TestCase
{
    use RefreshDatabase;

    private const FP = 'cccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/rovexy-licence-test';
        config(['ammp.licence.private_key' => "{$dir}/private.pem", 'ammp.licence.public_key' => "{$dir}/public.pem"]);
        if (!is_file("{$dir}/private.pem")) {
            LicenceSigner::generate();
        }
        PlatformSettings::set('razorpay_key_id', 'rzp_test_x');
        PlatformSettings::set('razorpay_key_secret', 'shh');
        PlatformSettings::set('razorpay_webhook_secret', 'wh');
    }

    private function plan(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function workspace(string $slug): array
    {
        $c = Company::query()->create(['slug' => $slug, 'name' => ucfirst($slug), 'timezone' => 'UTC', 'default_country' => 'IN']);
        $u = app(CurrentCompany::class)->run($c, function () use ($c, $slug) {
            CompanyDefaults::apply($c);
            $u = User::query()->create(['employee_code' => 'admin', 'name' => 'Admin', 'email' => "admin@{$slug}.test", 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
            $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $c->id]);
            return $u;
        });
        return [$c, fn () => $this->actingAs($u, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => $slug, 'Referer' => 'http://localhost/'])];
    }

    private function sub(Company $c, int $users = 10): Licence
    {
        return LicenceIssuer::issue($this->plan('starter'), $c->name, "billing@{$c->slug}.test", $c->id, 'subscription', $users, 'cloud', 'annual', 'test');
    }

    private function buyer(): array
    {
        return ['buyer_company' => 'Alpha', 'buyer_name' => 'A', 'buyer_email' => 'a@alpha.test', 'state_code' => '36'];
    }

    private function staff()
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => 'super@ametecs.test'], ['name' => 'Super', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function webhook(array $payload)
    {
        $body = json_encode($payload);
        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'wh'), 'CONTENT_TYPE' => 'application/json'], $body);
    }

    public function test_a_replaced_licence_cannot_be_extended_and_late_money_lands_on_the_current_one(): void
    {
        [$alpha] = $this->workspace('alpha');
        $old = $this->sub($alpha);
        $renewal = Sales::createOrder('renewal', ['users' => 12], $this->buyer(), $old, 'test');

        // the workspace gets a new licence (key added / trial upgraded): the old licence's unpaid order is closed with it
        $new = $this->sub($alpha, 15);
        Sales::supersede($old, 'test', 'test');
        $this->assertSame('cancelled', $renewal->fresh()->status);
        $this->expectDomain('licence_closed', fn () => Sales::quote('renewal', [], $this->buyer(), $old->fresh()));

        // the buyer paid the cancelled order anyway (gateway-confirmed): honoured, and applied to the CURRENT licence
        $before = $new->expires_at->copy();
        Sales::markPaid($renewal->fresh(), 'razorpay', 'pay_late', 'pay_late', 'test');
        $this->assertSame('superseded', $old->fresh()->status);                                                // never revived
        $this->assertSame([12, $before->copy()->addYear()->toDateString()], [$new->fresh()->max_users, $new->fresh()->expires_at->toDateString()]);
        $this->assertSame(1, DB::table('platform_audit')->where('action', 'order.applied_to_current')->count());
        $this->assertSame(1, Licence::query()->where('company_id', $alpha->id)->where('status', 'active')->count());
    }

    public function test_revoking_closes_orders_and_a_late_payment_goes_to_review(): void
    {
        [$alpha] = $this->workspace('alpha');
        $l = $this->sub($alpha);
        $o = Sales::createOrder('seats', ['users' => 20], $this->buyer(), $l, 'test');
        $this->staff()->patchJson("/api/super/licences/{$l->id}", ['status' => 'revoked', 'reason' => 'fraud'])->assertOk();
        $this->assertSame('cancelled', $o->fresh()->status);
        Sales::markPaid($o->fresh(), 'razorpay', 'pay_x', 'pay_x', 'test');
        $this->assertSame(['revoked', 10], [$l->fresh()->status, $l->fresh()->max_users]);                     // untouched
        $this->assertSame(1, DB::table('platform_audit')->where('action', 'order.needs_review')->count());
    }

    public function test_payments_are_settled_from_the_gateway(): void
    {
        [$alpha] = $this->workspace('alpha');
        $l = $this->sub($alpha);
        $o = Sales::createOrder('seats', ['users' => 15], $this->buyer(), $l, 'test');
        $paise = (int) round($o->total * 100);
        $captured = [];
        Http::fake(function ($req) use (&$captured, $paise) {
            $u = $req->url();
            if (str_ends_with($u, '/v1/orders')) {
                return Http::response(['id' => 'order_S1']);
            }
            if (str_contains($u, '/v1/orders/order_S1/payments')) {
                return Http::response(['items' => [
                    ['id' => 'pay_short', 'amount' => $paise - 100, 'currency' => 'INR', 'status' => 'captured'],   // wrong amount: flagged, ignored
                    ['id' => 'pay_auth', 'amount' => $paise, 'currency' => 'INR', 'status' => 'authorized']]]);
            }
            if (str_contains($u, '/v1/payments/pay_auth/capture')) {
                $captured[] = $req['amount'];
                return Http::response(['id' => 'pay_auth', 'status' => 'captured']);
            }
            return Http::response([], 404);
        });
        $this->postJson("/pay/{$o->number}/{$o->token()}/razorpay")->assertOk();
        $this->postJson("/pay/{$o->number}/{$o->token()}/razorpay")->assertOk()->assertJsonPath('order_id', 'order_S1');   // one gateway order

        // browser closed, webhook lost: the reconciliation job captures the authorised payment and provisions
        $this->artisan('rovexy:payments-reconcile')->assertSuccessful();
        $this->assertSame([$paise], $captured);
        $this->assertSame(['paid', 'pay_auth'], [$o->fresh()->status, $o->fresh()->razorpay_payment_id]);
        $this->assertSame(15, $l->fresh()->max_users);
        $this->assertSame(1, DB::table('platform_audit')->where('action', 'order.amount_mismatch')->count());
        $this->artisan('rovexy:payments-reconcile')->assertSuccessful();                                           // idempotent
        $this->assertSame(1, SalesOrder::query()->where('status', 'paid')->count());
    }

    public function test_checkout_callback_does_not_provision_an_unpaid_payment(): void
    {
        [$alpha] = $this->workspace('alpha');
        $o = Sales::createOrder('seats', ['users' => 15], $this->buyer(), $this->sub($alpha), 'test');
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_F1']),
            'api.razorpay.com/v1/orders/order_F1/payments' => Http::response(['items' => [['id' => 'pay_f', 'amount' => 1, 'currency' => 'INR', 'status' => 'failed']]])]);
        $this->postJson("/pay/{$o->number}/{$o->token()}/razorpay")->assertOk();
        $sig = hash_hmac('sha256', 'order_F1|pay_f', 'shh');
        $this->postJson("/pay/{$o->number}/{$o->token()}/verify", ['razorpay_order_id' => 'order_F1', 'razorpay_payment_id' => 'pay_f', 'razorpay_signature' => $sig])->assertStatus(422);
        $this->assertSame('created', $o->fresh()->status);
    }

    public function test_webhook_never_drops_money_silently(): void
    {
        [$alpha] = $this->workspace('alpha');
        $o = Sales::createOrder('seats', ['users' => 15], $this->buyer(), $this->sub($alpha), 'test');
        $o->forceFill(['razorpay_order_id' => 'order_W1'])->save();
        $paise = (int) round($o->total * 100);
        $this->webhook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_w', 'order_id' => 'order_W1', 'amount' => $paise - 5]]]])->assertOk();
        $this->assertSame('created', $o->fresh()->status);
        $this->assertSame(1, DB::table('platform_audit')->where('action', 'order.amount_mismatch')->count());
        // unknown gateway order id (e.g. replaced) → found by our order number in the receipt
        $this->webhook(['event' => 'order.paid', 'payload' => ['payment' => ['entity' => ['id' => 'pay_w2', 'order_id' => 'order_OTHER', 'amount' => $paise]],
            'order' => ['entity' => ['id' => 'order_OTHER', 'receipt' => $o->number]]]])->assertOk();
        $this->assertSame('paid', $o->fresh()->status);
    }

    public function test_onprem_install_buys_users_and_amc_online_and_picks_up_the_licence_itself(): void
    {
        $l = LicenceIssuer::issue($this->plan('starter'), 'Acme', 'it@acme.test', null, 'perpetual', 10, 'onprem', null, 'test');
        $api = fn (string $a, array $b = [], string $fp = self::FP) => $this->postJson("/api/v1/license/{$a}", ['key' => $l->key, 'fingerprint' => $fp] + $b);
        $api('offer')->assertStatus(422)->assertJsonPath('error', 'not_allowed');                               // not activated on a server yet
        $l->forceFill(['server_fingerprint' => self::FP])->save();
        $api('offer', [], str_repeat('d', 40))->assertStatus(422)->assertJsonPath('error', 'not_allowed');       // another server can't buy on it
        $this->postJson('/api/v1/license/offer', ['key' => 'RX-AAAA-BBBB-CCCC-DDDD', 'fingerprint' => self::FP])->assertStatus(422)->assertJsonPath('error', 'not_allowed');

        $offer = $api('offer')->assertOk()->json();
        $this->assertSame([false, true, true], [$offer['can_renew'], $offer['can_add_users'], $offer['can_amc']]);
        $api('quote', ['kind' => 'renewal', 'state_code' => '29'])->assertStatus(422);                           // a perpetual licence never renews
        $q = $api('quote', ['kind' => 'seats', 'users' => 25, 'state_code' => '29'])->assertOk()->json();
        $this->assertSame([45000.0], array_map('floatval', array_column($q['lines'], 'amount')));                 // price(25) − price(10), the central price list
        $o = $api('order', ['kind' => 'seats', 'users' => 25, 'state_code' => '29', 'buyer_email' => 'attacker@evil.test'])->assertCreated()->json();
        $order = SalesOrder::query()->where('number', $o['number'])->firstOrFail();
        $this->assertSame(['it@acme.test', 'seats', $l->id], [$order->buyer_email, $order->kind, $order->licence_id]);   // receipts go to the licence holder
        $this->assertStringContainsString('/pay/', $o['pay_url']);
        $api('order', ['kind' => 'seats', 'users' => 25, 'state_code' => '29'])->assertOk()->assertJsonPath('number', $o['number']);   // same request → same order

        Sales::markPaid($order, 'razorpay', 'pay_onprem', 'pay_onprem', 'test');
        $this->assertSame(25, $l->fresh()->max_users);
        $a = $api('order', ['kind' => 'amc', 'state_code' => '29'])->assertCreated()->json();
        Sales::markPaid(SalesOrder::query()->where('number', $a['number'])->firstOrFail(), 'razorpay', 'pay_amc', 'pay_amc', 'test');
        $this->assertSame(now()->addYears(2)->toDateString(), $l->fresh()->amc_expires_at->toDateString());

        $l->forceFill(['status' => 'suspended'])->save();
        $api('offer')->assertStatus(422)->assertJsonPath('error', 'licence_suspended');
    }

    public function test_install_console_buys_through_the_licence_server(): void
    {
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => 'https://central.test', 'ammp.licence.machine_id' => 'shop-machine']);
        \Illuminate\Support\Facades\Cache::forget('rovexy.server_fingerprint');
        $fp = LicenceClient::fingerprint();
        [$c, $as] = $this->workspace('acme');
        config(['ammp.tenancy.mode' => 'fixed', 'ammp.tenancy.fixed_company_id' => $c->id]);
        $l = LicenceIssuer::issue($this->plan('starter'), 'Acme', 'it@acme.test', null, 'subscription', 10, 'onprem', 'annual', 'test');
        $l->forceFill(['server_fingerprint' => $fp])->save();
        $sent = [];
        $paid = false;
        Http::fake(function ($req) use (&$sent, &$paid, $l, $fp) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            $sent[] = [$path, $req['key'] ?? null, $req['fingerprint'] ?? null];
            if ($path === '/api/v1/license/validate') {
                if ($paid) {
                    $l->forceFill(['max_users' => 20])->save();
                }
                $l->refresh();
                return Http::response(['ok' => true, 'licence' => LicenceSigner::sign(LicenceAuthority::payload($l, 'online', $fp))]);
            }
            return match ($path) {
                '/api/v1/license/offer' => Http::response(['can_renew' => true, 'can_add_users' => true, 'can_amc' => false, 'plans' => [], 'cycles' => [], 'open_order' => null]),
                '/api/v1/license/quote' => Http::response(['lines' => [['type' => 'licence', 'label' => 'x', 'amount' => 100.0]], 'total' => 118.0]),
                '/api/v1/license/order' => Http::response(['error' => 'below_minimum', 'message' => 'The Starter plan starts at 5 users.'], 422),
                default => Http::response([], 500),
            };
        });
        app(LicenceClient::class)->saveKey($l->key);
        $page = $as()->getJson('/api/admin/licence')->assertOk();
        $this->assertSame([true, null, 'onprem'], [$page->json('can_renew'), $page->json('shop_error'), $page->json('mode')]);
        $as()->getJson('/api/admin/licence/quote?kind=seats&users=20&plan_id=1&state_code=29')->assertOk()->assertJson(['total' => 118]);
        $as()->postJson('/api/admin/licence/renew', ['kind' => 'seats', 'users' => 2, 'plan_id' => 1, 'state_code' => '29'])
            ->assertStatus(422)->assertJsonPath('message', 'The Starter plan starts at 5 users.');            // the server's own reason, passed on
        $this->assertTrue(collect($sent)->every(fn ($s) => $s[1] === $l->key && $s[2] === $fp));                  // every call carries key + this server's ID

        // offline: the shop says so instead of failing the page
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertStringContainsString('could not be reached', (string) $as()->getJson('/api/admin/licence')->assertOk()->json('shop_error'));
    }

    public function test_an_online_licence_must_reach_the_server_now_and_then(): void
    {
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => 'https://central.test', 'ammp.licence.machine_id' => 'stale-machine', 'ammp.licence.offline_days' => 30]);
        \Illuminate\Support\Facades\Cache::forget('rovexy.server_fingerprint');
        $fp = LicenceClient::fingerprint();
        $l = LicenceIssuer::issue($this->plan('starter'), 'Acme', 'it@acme.test', null, 'perpetual', 10, 'onprem', null, 'test');
        $l->forceFill(['server_fingerprint' => $fp])->save();
        Http::fake(fn () => Http::response(['ok' => true, 'licence' => LicenceSigner::sign(LicenceAuthority::payload($l, 'online', $fp))]));   // signed "now"
        $this->travel(-31)->days();
        app(LicenceClient::class)->saveKey($l->key);
        $this->travelBack();
        $lic = fn () => tap(app(Licensing::class))->forget()->current();
        $this->assertSame('stale', (new LicenceClient())->effective()['status']);
        $this->assertSame('stale', $lic()['state']);
        $this->assertStringContainsString('30 days', (string) $lic()['message']);
        app(LicenceClient::class)->check();                                                                       // back online → fine again
        $this->assertSame('active', tap(app(Licensing::class))->forget()->current()['state']);
    }

    private function expectDomain(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$reason}");
        } catch (\App\Domain\DomainException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
