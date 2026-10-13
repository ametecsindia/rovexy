<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts (13 Oct 2026): Ametecs' own books for the Accountant panel (/accounts) — money accounts, income/expense
 * categories, one ledger of transactions, and invoices (we bill a customer) / bills (a vendor bills us).
 * Paid sales orders flow into the ledger automatically (source = order). Also: staff profile phone + 2-step sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $t) {
            $t->string('phone', 20)->nullable()->after('name');
            $t->timestamp('mfa_enabled_at')->nullable()->after('totp_last_step');
        });

        Schema::create('acc_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('type', 20);                                   // bank | cash | upi | card | gateway | other
            $t->string('bank_name', 120)->nullable();
            $t->string('account_number', 40)->nullable();             // stored as typed; the UI shows only the last 4
            $t->string('ifsc', 11)->nullable();
            $t->decimal('opening_balance', 14, 2)->default(0);
            $t->date('opening_date')->nullable();
            $t->boolean('is_default')->default(false);                // where online (Razorpay) receipts land
            $t->string('status', 20)->default('active');              // active | closed
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('acc_categories', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);                                   // income | expense
            $t->string('name', 80);
            $t->boolean('active')->default(true);
            $t->boolean('system')->default(false);                    // used by automatic entries; can be renamed, not deleted
            $t->timestamps();
            $t->unique(['kind', 'name']);
        });

        Schema::create('acc_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('type', 10);                                   // invoice (receivable) | bill (payable)
            $t->string('number', 40);
            $t->string('party_name', 150);
            $t->string('party_email', 190)->nullable();
            $t->string('party_phone', 20)->nullable();
            $t->string('party_gstin', 15)->nullable();
            $t->string('party_address', 300)->nullable();
            $t->date('issue_date');
            $t->date('due_date')->nullable();
            $t->json('items');                                        // [{description, qty, rate, amount}]
            $t->decimal('subtotal', 14, 2);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('tax_amount', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->decimal('amount_paid', 14, 2)->default(0);
            $t->string('status', 20)->default('draft');               // draft | issued | partial | paid | cancelled (overdue is computed)
            $t->foreignId('category_id')->nullable()->constrained('acc_categories')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->string('created_by', 120)->nullable();
            $t->timestamps();
            $t->unique(['type', 'number']);
            $t->index(['type', 'status', 'due_date']);
        });

        Schema::create('acc_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);                                   // income | expense
            $t->date('txn_date');
            $t->decimal('amount', 14, 2);                             // always positive; kind gives the direction
            $t->decimal('tax_amount', 14, 2)->default(0);             // GST inside the amount (for reports)
            $t->foreignId('category_id')->nullable()->constrained('acc_categories')->nullOnDelete();
            $t->foreignId('account_id')->nullable()->constrained('acc_accounts')->nullOnDelete();
            $t->string('party', 150)->nullable();
            $t->string('method', 20)->nullable();                     // bank | upi | cash | cheque | card | razorpay | other
            $t->string('reference', 100)->nullable();
            $t->string('description', 500)->nullable();
            $t->string('status', 20)->default('completed');           // completed | pending
            $t->string('source', 20)->default('manual');              // manual | order | invoice | bill
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('created_by', 120)->nullable();
            $t->string('updated_by', 120)->nullable();
            $t->timestamps();
            $t->index(['txn_date', 'kind']);
            $t->index(['source', 'source_id']);
        });

        $now = now();
        $cats = ['income' => ['Software subscriptions', 'Services & implementation', 'Interest', 'Other income'],
            'expense' => ['Salaries & wages', 'Rent', 'Software & cloud', 'Marketing', 'Travel', 'Utilities & internet', 'Professional fees', 'Taxes & duties', 'Bank & gateway charges', 'Office supplies', 'Other expenses']];
        foreach ($cats as $kind => $names) {
            foreach ($names as $n) {
                DB::table('acc_categories')->insert(['kind' => $kind, 'name' => $n, 'active' => true, 'system' => $n === 'Software subscriptions', 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Every sales order already paid becomes a ledger entry, so the books start complete.
        $cat = DB::table('acc_categories')->where('kind', 'income')->where('name', 'Software subscriptions')->value('id');
        foreach (DB::table('sales_orders')->where('status', 'paid')->orderBy('id')->get() as $o) {
            DB::table('acc_transactions')->insert(['kind' => 'income', 'txn_date' => substr((string) ($o->paid_at ?? $o->updated_at), 0, 10), 'amount' => $o->total,
                'tax_amount' => (float) $o->cgst + (float) $o->sgst + (float) $o->igst, 'category_id' => $cat, 'party' => $o->buyer_company, 'method' => $o->payment_method,
                'reference' => $o->invoice_number ?: $o->number, 'description' => "Order {$o->number}", 'status' => 'completed', 'source' => 'order', 'source_id' => $o->id,
                'created_by' => 'system', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['acc_transactions', 'acc_invoices', 'acc_categories', 'acc_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('platform_admins', fn (Blueprint $t) => $t->dropColumn(['phone', 'mfa_enabled_at']));
    }
};
