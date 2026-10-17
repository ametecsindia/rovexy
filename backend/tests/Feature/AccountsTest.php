<?php

namespace Tests\Feature;

use App\Domain\Security\Totp;
use App\Models\Accounts\Invoice;
use App\Models\Accounts\Transaction;
use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\SalesOrder;
use App\Services\Accounts\Ledger;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** Accountant panel (/accounts), staff sign-in portals, 2-step sign-in and the Super Admin additions (13 Oct 2026). */
final class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Staff-password-1';

    private function person(string $role, string $status = 'active'): PlatformAdmin
    {
        return PlatformAdmin::query()->create(['email' => "{$role}@ametecs.test", 'name' => ucfirst($role), 'password' => self::PW, 'role' => $role, 'status' => $status]);
    }

    private function as(PlatformAdmin $p)
    {
        return $this->actingAs($p, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function login(string $email, string $portal, string $pw = self::PW)
    {
        return $this->withHeaders(['Referer' => 'http://localhost/'])->postJson('/api/super/auth/login', ['email' => $email, 'password' => $pw, 'portal' => $portal]);
    }

    public function test_portals_keep_accountants_and_staff_apart(): void
    {
        $this->person('accountant');
        $this->person('sales');
        $this->person('super');
        $this->login('accountant@ametecs.test', 'staff')->assertStatus(422)->assertJsonPath('error', 'wrong_portal')->assertJsonPath('portal', 'accounts');
        $this->login('sales@ametecs.test', 'accounts')->assertStatus(422)->assertJsonPath('error', 'wrong_portal');
        $this->login('accountant@ametecs.test', 'accounts')->assertOk()->assertJsonPath('role', 'accountant')
            ->assertJsonPath('access.accounts', 'manage')->assertJsonPath('access.companies', 'none')->assertJsonPath('access.settings', 'none');
        Auth::guard('platform')->logout();
        $this->login('super@ametecs.test', 'accounts')->assertOk()->assertJsonPath('access.accounts', 'manage');   // super can open Accounts too
        $this->login('nobody@ametecs.test', 'accounts')->assertStatus(422)->assertJsonPath('error', 'invalid_credentials');
        $this->assertDatabaseHas('platform_audit', ['action' => 'super.login_wrong_portal']);
    }

    public function test_accountant_is_limited_to_accounts(): void
    {
        $acc = $this->as($this->person('accountant'));
        $acc->getJson('/api/super/accounts/dashboard')->assertOk();
        foreach (['dashboard', 'companies', 'licences', 'orders', 'leads', 'plans', 'admins', 'settings', 'audit'] as $m) {
            $acc->getJson("/api/super/{$m}")->assertStatus(403);
        }
        $acc->putJson('/api/super/settings', ['gst_rate' => 5])->assertStatus(403);
        $acc->postJson('/api/super/admins', ['name' => 'X', 'email' => 'x@ametecs.test', 'role' => 'super', 'password' => 'Another-password-9'])->assertStatus(403);
        // sales and support can't open the books
        $this->as($this->person('sales'))->getJson('/api/super/accounts/dashboard')->assertStatus(403);
        $this->as($this->person('support'))->getJson('/api/super/accounts/transactions')->assertStatus(403);
    }

    public function test_super_admin_manages_accountant_accounts(): void
    {
        $super = $this->as($this->person('super'));
        $super->postJson('/api/super/admins', ['name' => 'Asha', 'email' => 'asha@ametecs.test', 'role' => 'accountant', 'password' => 'short'])->assertStatus(422);
        $id = $super->postJson('/api/super/admins', ['name' => 'Asha', 'email' => 'Asha@ametecs.test', 'phone' => '+91 90000 11111', 'role' => 'accountant', 'password' => 'Accounts-pass-2026'])
            ->assertCreated()->assertJsonPath('role', 'accountant')->json('id');
        $super->getJson('/api/super/admins')->assertOk()->assertJsonPath('roles.accountant', 'Accountant')->assertJsonPath('matrix.accountant.accounts', 'manage')
            ->assertJsonPath('matrix.accountant.companies', 'none');
        Auth::guard('platform')->logout();
        $this->login('asha@ametecs.test', 'accounts', 'Accounts-pass-2026')->assertOk();
        // deactivated: blocked on the very next request, and can't sign in again
        PlatformAdmin::query()->whereKey($id)->update(['status' => 'disabled']);
        Auth::guard('platform')->forgetUser();   // each real request loads the account fresh from the database
        $this->getJson('/api/super/accounts/dashboard', ['Referer' => 'http://localhost/'])->assertStatus(401);
        $this->login('asha@ametecs.test', 'accounts', 'Accounts-pass-2026')->assertStatus(422);
        // reactivated by a super admin (from another browser)
        $this->flushSession();
        $super = $this->as(PlatformAdmin::query()->where('role', 'super')->first());
        $super->putJson("/api/super/admins/{$id}", ['name' => 'Asha R', 'email' => 'asha@ametecs.test', 'role' => 'accountant', 'status' => 'active'])->assertOk();
        $this->assertDatabaseHas('platform_admins', ['id' => $id, 'status' => 'active', 'name' => 'Asha R']);
    }

    public function test_password_change_signs_out_other_sessions_and_profile_updates(): void
    {
        $p = $this->person('accountant');
        $this->login($p->email, 'accounts')->assertOk();
        $this->putJson('/api/super/auth/profile', ['name' => 'Asha Rao', 'phone' => '98480 12345'])->assertOk()->assertJsonPath('name', 'Asha Rao');
        $this->putJson('/api/super/auth/password', ['current_password' => 'wrong', 'password' => 'New-password-2026', 'password_confirmation' => 'New-password-2026'])->assertStatus(422);
        $this->putJson('/api/super/auth/password', ['current_password' => self::PW, 'password' => 'New-password-2026', 'password_confirmation' => 'New-password-2026'])->assertOk();
        $this->getJson('/api/super/accounts/lookups')->assertOk();                   // this session stays signed in
        // someone else (e.g. a super admin) resets it: this session is now stale
        $p->refresh()->forceFill(['password' => 'Reset-by-super-1'])->save();
        Auth::guard('platform')->forgetUser();
        $this->getJson('/api/super/accounts/lookups')->assertStatus(401)->assertJsonPath('message', 'Your password was changed. Please sign in again.');
    }

    public function test_two_step_sign_in_for_staff(): void
    {
        $p = $this->person('accountant');
        PlatformSettings::set('staff_mfa_required', '1');
        $this->login($p->email, 'accounts')->assertOk()->assertJsonPath('mfa', 'enrol');
        $this->getJson('/api/super/accounts/dashboard')->assertStatus(401)->assertJsonPath('error', 'mfa_required');
        $this->getJson('/api/super/auth/me')->assertOk()->assertJsonPath('mfa_required', 'enrol');
        $secret = $this->postJson('/api/super/auth/mfa/setup')->assertOk()->json('secret');
        $this->postJson('/api/super/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/super/auth/mfa/verify', ['code' => Totp::code($secret, time())])->assertOk()->assertJsonPath('mfa_enabled', true);
        $this->getJson('/api/super/accounts/dashboard')->assertOk();
        // next sign-in asks for the code
        Auth::guard('platform')->logout();
        $this->login($p->email, 'accounts')->assertJsonPath('mfa', 'verify');
        // a super admin can reset a lost authenticator
        $this->flushSession();
        $this->as($this->person('super'))->postJson("/api/super/admins/{$p->id}/reset-mfa")->assertOk();
        $this->assertNull($p->fresh()->mfa_enabled_at);
    }

    public function test_ledger_invoices_bills_and_payments(): void
    {
        $acc = $this->as($this->person('accountant'));
        $look = $acc->getJson('/api/super/accounts/lookups')->assertOk()->json();
        $income = collect($look['categories'])->firstWhere('name', 'Services & implementation')['id'];
        $rent = collect($look['categories'])->firstWhere('name', 'Rent')['id'];
        $bank = $acc->postJson('/api/super/accounts/accounts', ['name' => 'HDFC Current', 'type' => 'bank', 'bank_name' => 'HDFC Bank', 'account_number' => '50200012345678',
            'ifsc' => 'hdfc0001234', 'opening_balance' => 100000, 'is_default' => true])->assertCreated()->assertJsonPath('account_number_masked', '•••• 5678')->json('id');
        $acc->postJson('/api/super/accounts/accounts', ['name' => 'Bad', 'type' => 'bank', 'ifsc' => 'XYZ'])->assertStatus(422);

        // manual expense; category must match the kind
        $acc->postJson('/api/super/accounts/transactions', ['kind' => 'expense', 'txn_date' => Ledger::today(), 'amount' => 30000, 'category_id' => $income, 'status' => 'completed'])->assertStatus(422);
        $rentId = $acc->postJson('/api/super/accounts/transactions', ['kind' => 'expense', 'txn_date' => Ledger::today(), 'amount' => 30000, 'category_id' => $rent,
            'account_id' => $bank, 'party' => 'Landlord', 'method' => 'bank', 'status' => 'completed'])->assertCreated()->json('id');

        // invoice: draft → issued → part paid → paid
        $inv = $acc->postJson('/api/super/accounts/invoices', ['type' => 'invoice', 'party_name' => 'Delta Collections', 'party_gstin' => '36abcde1234f1z5',
            'issue_date' => Ledger::today(), 'due_date' => Ledger::today(), 'tax_rate' => 18, 'category_id' => $income,
            'items' => [['description' => 'Implementation', 'qty' => 2, 'rate' => 25000], ['description' => 'Training', 'qty' => 1, 'rate' => 10000]]])
            ->assertCreated()->assertJsonPath('subtotal', 60000)->assertJsonPath('tax_amount', 10800)->assertJsonPath('total', 70800)->assertJsonPath('status', 'draft')
            ->assertJsonPath('party_gstin', '36ABCDE1234F1Z5')->json();
        $this->assertMatchesRegularExpression('#^INV/\d{4}-\d{2}/0001$#', $inv['number']);
        $acc->postJson("/api/super/accounts/invoices/{$inv['id']}/payments", ['amount' => 100, 'date' => Ledger::today(), 'method' => 'upi'])->assertStatus(422);   // draft
        $acc->postJson("/api/super/accounts/invoices/{$inv['id']}/issue")->assertOk()->assertJsonPath('status', 'issued');
        $acc->postJson("/api/super/accounts/invoices/{$inv['id']}/payments", ['amount' => 80000, 'date' => Ledger::today(), 'method' => 'bank'])->assertStatus(422);  // overpaid
        $acc->postJson("/api/super/accounts/invoices/{$inv['id']}/payments", ['amount' => 40000, 'date' => Ledger::today(), 'method' => 'bank', 'account_id' => $bank])
            ->assertOk()->assertJsonPath('status', 'partial')->assertJsonPath('balance', 30800);
        $acc->postJson("/api/super/accounts/invoices/{$inv['id']}/cancel", ['reason' => 'x'])->assertStatus(422);   // has payments
        $acc->deleteJson("/api/super/accounts/invoices/{$inv['id']}")->assertStatus(422);                         // not a draft
        $acc->postJson("/api/super/accounts/invoices/{$inv['id']}/payments", ['amount' => 30800, 'date' => Ledger::today(), 'method' => 'upi', 'account_id' => $bank])
            ->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('balance', 0);
        // deleting a payment re-opens the invoice
        $pay = Transaction::query()->where('source', 'invoice')->where('amount', 30800)->value('id');
        $acc->deleteJson("/api/super/accounts/transactions/{$pay}")->assertOk();
        $this->assertSame('partial', Invoice::query()->find($inv['id'])->status);

        // a bill overdue yesterday shows up as overdue payable
        $bill = $acc->postJson('/api/super/accounts/invoices', ['type' => 'bill', 'number' => 'AWS-7781', 'party_name' => 'Amazon Web Services', 'issue_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(1)->toDateString(), 'tax_rate' => 18, 'issue' => true, 'items' => [['description' => 'Cloud', 'qty' => 1, 'rate' => 10000]]])
            ->assertCreated()->assertJsonPath('display_status', 'overdue')->json();
        $acc->getJson('/api/super/accounts/payments?view=overdue')->assertOk()->assertJsonPath('rows.0.ref', 'AWS-7781')->assertJsonPath('total_out', 11800);
        $acc->getJson('/api/super/accounts/invoices?status=overdue&type=bill')->assertOk()->assertJsonPath('total', 1);

        // dashboard and reports agree with the ledger: income 40000 (invoice part payment), expense 30000 (rent)
        $d = $acc->getJson('/api/super/accounts/dashboard')->assertOk()->json();
        $this->assertSame(40000, (int) $d['income']);
        $this->assertSame(30000, (int) $d['expense']);
        $this->assertSame(30800, (int) $d['receivable']);
        $this->assertSame(11800, (int) $d['overdue_payable']);
        $this->assertSame(110000, (int) collect($d['accounts'])->firstWhere('id', $bank)['balance']);   // 100000 + 40000 − 30000
        $rep = $acc->getJson('/api/super/accounts/reports?period=monthly&from=' . now()->startOfMonth()->toDateString() . '&to=' . Ledger::today())->assertOk()->json();
        $this->assertSame(10000, (int) $rep['totals']['net']);
        $acc->getJson('/api/super/accounts/reports?period=yearly')->assertOk()->assertJsonPath('rows.0.key', 'FY ' . Ledger::fyStart()->year . '-' . substr((string) (Ledger::fyStart()->year + 1), 2));
        $acc->getJson('/api/super/accounts/transactions?kind=expense')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('totals.expense', 30000);

        // exports: Excel, CSV and print/PDF page
        $x = $acc->get('/api/super/accounts/transactions/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
        $this->assertStringStartsWith("PK", file_get_contents($x->baseResponse->getFile()->getPathname()));
        $csv = $acc->get('/api/super/accounts/reports/export?format=csv&period=monthly')->assertOk()->streamedContent();
        $this->assertStringContainsString('Income,Expense,Net', $csv);
        $acc->get('/api/super/accounts/invoices/export?format=pdf&type=bill')->assertOk()->assertSee('AWS-7781')->assertSee('Save as PDF');
        $acc->get("/api/super/accounts/invoices/{$inv['id']}/print")->assertOk()->assertSee('TAX INVOICE')->assertSee('Delta Collections')->assertSee('70,800.00');

        // rules that protect the books
        $acc->deleteJson('/api/super/accounts/categories/' . $rent)->assertStatus(422);
        $acc->deleteJson("/api/super/accounts/accounts/{$bank}")->assertStatus(422);
        $acc->putJson("/api/super/accounts/transactions/{$rentId}", ['kind' => 'expense', 'txn_date' => Ledger::today(), 'amount' => 32000, 'category_id' => $rent, 'status' => 'pending'])
            ->assertOk()->assertJsonPath('status', 'pending');
        $acc->getJson('/api/super/accounts/payments?view=pending')->assertOk()->assertJsonPath('rows.1.source', 'entry');
        $this->assertDatabaseHas('platform_audit', ['action' => 'accounts.invoice_payment']);
    }

    public function test_paid_subscription_orders_flow_into_the_books(): void
    {
        $company = Company::query()->create(['slug' => 'beta', 'name' => 'Beta', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $l = LicenceIssuer::issue($plan, 'Beta', 'b@beta.test', $company->id, 'subscription', 10);
        $o = Sales::createOrder('renewal', ['plan_id' => $plan->id], ['buyer_company' => 'Beta', 'buyer_name' => 'B', 'buyer_email' => 'b@beta.test', 'state_code' => '36'], $l, 'test');
        Sales::markPaid($o, 'bank', 'UTR123', null, 'test');
        Sales::markPaid($o->fresh(), 'bank', 'UTR123', null, 'test');   // twice: still one ledger line
        $this->assertSame(1, Transaction::query()->where('source', 'order')->count());
        $t = Transaction::query()->where('source', 'order')->first();
        $this->assertEqualsWithDelta($o->fresh()->total, $t->amount, 0.001);
        $acc = $this->as($this->person('accountant'));
        $acc->getJson('/api/super/accounts/sales-invoices')->assertOk()->assertJsonPath('data.0.buyer_company', 'Beta');
        $acc->deleteJson("/api/super/accounts/transactions/{$t->id}")->assertStatus(422);                       // owned by the order
        $acc->putJson("/api/super/accounts/transactions/{$t->id}", ['description' => 'Renewal FY'])->assertOk();
        $this->assertEqualsWithDelta($o->fresh()->total, $t->fresh()->amount, 0.001);                           // amount untouched
        // unpaid order is a pending receivable
        Sales::createOrder('renewal', ['plan_id' => $plan->id], ['buyer_company' => 'Beta', 'buyer_name' => 'B', 'buyer_email' => 'b@beta.test'], $l, 'test');
        $acc->getJson('/api/super/accounts/payments?view=pending')->assertOk()->assertJsonPath('rows.0.source', 'order');
    }

    public function test_super_admin_company_tools_and_free_access_rules(): void
    {
        $company = Company::query()->create(['slug' => 'gamma', 'name' => 'Gamma', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $sales = $this->as($this->person('sales'));
        $sales->getJson("/api/super/companies/{$company->id}")->assertOk()->assertJsonPath('slug', 'gamma')->assertJsonPath('licences', []);
        $sales->putJson("/api/super/companies/{$company->id}", ['name' => 'Gamma Recoveries'])->assertOk();
        $sales->postJson("/api/super/companies/{$company->id}/licence", ['plan_id' => $plan->id, 'kind' => 'internal', 'reason' => 'x'])->assertStatus(403); // free access: super only
        $sales->postJson("/api/super/companies/{$company->id}/licence", ['plan_id' => $plan->id, 'kind' => 'trial'])->assertCreated();
        $sales->postJson("/api/super/companies/{$company->id}/licence", ['plan_id' => $plan->id, 'kind' => 'trial'])->assertStatus(422);     // already has one
        $l = Licence::query()->where('company_id', $company->id)->first();
        $sales->patchJson("/api/super/licences/{$l->id}", ['expires_at' => null])->assertStatus(403);                                         // never-expiring: super only
        $sales->patchJson("/api/super/licences/{$l->id}", ['expires_at' => now()->addMonth()->toDateString()])->assertOk();
        $this->assertSame('23:59', $l->fresh()->expires_at->setTimezone(Ledger::TZ)->format('H:i'));                                            // end of that Indian day
        $this->assertSame('Gamma Recoveries', $company->fresh()->name);
        // "Subscription" by hand is never free: a trial now + an unpaid order; the subscription starts only when the order is paid
        $r = $sales->postJson('/api/super/companies', ['name' => 'Paid Co', 'slug' => 'paid-co', 'admin_name' => 'P', 'admin_email' => 'p@paid.test', 'admin_password' => 'Paid-pass-123',
            'plan_id' => $plan->id, 'kind' => 'subscription', 'users' => 10, 'billing' => 'annual'])->assertCreated()->json();
        $paid = Company::query()->where('slug', 'paid-co')->firstOrFail();
        $trial = Licence::query()->where('company_id', $paid->id)->sole();
        $this->assertSame(['trial', 'active'], [$trial->kind, $trial->status]);
        $order = \App\Models\Platform\SalesOrder::query()->findOrFail($r['order']['id']);
        $this->assertSame(['upgrade', 'created', $trial->id, 10], [$order->kind, $order->status, $order->licence_id, $order->users]);
        $this->assertNotEmpty($r['order']['pay_url']);
        $super = $this->as($this->person('super'));
        $super->postJson("/api/super/orders/{$order->id}/payment", ['method' => 'bank', 'reference' => 'NEFT-123'])->assertOk();
        $this->assertSame('superseded', $trial->fresh()->status);
        $sub = Licence::query()->where('company_id', $paid->id)->where('status', 'active')->sole();
        $this->assertSame(['subscription', 10], [$sub->kind, $sub->max_users]);
        $this->assertNotNull($order->fresh()->invoice_number);
        // internal (free) needs super and a reason, kept in the licence history
        $super->postJson('/api/super/companies', ['name' => 'Ours', 'slug' => 'ours-co', 'admin_name' => 'O', 'admin_email' => 'o@ours.test', 'admin_password' => 'Ours-pass-123',
            'plan_id' => $plan->id, 'kind' => 'internal'])->assertStatus(422);
        $super->postJson('/api/super/companies', ['name' => 'Ours', 'slug' => 'ours-co', 'admin_name' => 'O', 'admin_email' => 'o@ours.test', 'admin_password' => 'Ours-pass-123',
            'plan_id' => $plan->id, 'kind' => 'internal', 'reason' => 'Ametecs demo workspace'])->assertCreated();
        $int = Licence::query()->where('company_id', Company::query()->where('slug', 'ours-co')->value('id'))->sole();
        $this->assertSame('Ametecs demo workspace', $int->events()->where('type', 'free_access')->value('data')['reason']);
    }
}
