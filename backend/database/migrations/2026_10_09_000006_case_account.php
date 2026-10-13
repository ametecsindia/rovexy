<?php

use App\Support\Outcomes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Case (account) details from the lead file — address, contact and loan / dues — plus a per-customer activity log
 * (notes, status changes, field entries) and the extra call outcomes (Connected, Busy, Other).
 * Money is as uploaded; collections are added from the payment records, never typed into these columns.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('email', 150)->nullable();
            $t->string('address', 500)->nullable();
            $t->string('state', 100)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('preferred_time', 60)->nullable();
            foreach (['loan_amount', 'outstanding', 'principal_os', 'interest_os', 'charges', 'overdue_amount', 'emi_amount', 'last_paid_amount'] as $c) {
                $t->decimal($c, 14, 2)->nullable();
            }
            $t->date('due_date')->nullable();            // next instalment due
            $t->date('oldest_due_date')->nullable();     // oldest unpaid instalment → days overdue
            $t->unsignedSmallInteger('overdue_installments')->nullable();
            $t->date('last_paid_on')->nullable();
            $t->date('balance_as_of')->nullable();       // date the amounts above were uploaded
        });
        Schema::create('customer_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 16);                      // note|status
            $t->text('body')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'customer_id', 'created_at']);
        });
        foreach (DB::table('companies')->pluck('id') as $cid) {
            foreach (Outcomes::CODES as [$code, $label, $fu, $captures]) {
                DB::table('disposition_codes')->insertOrIgnore(['company_id' => $cid, 'code' => $code, 'label' => $label,
                    'requires_follow_up' => $fu, 'active' => true, 'captures' => $captures]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_events');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['email', 'address', 'state', 'lat', 'lng', 'preferred_time', 'loan_amount', 'outstanding', 'principal_os', 'interest_os',
                'charges', 'overdue_amount', 'emi_amount', 'last_paid_amount', 'due_date', 'oldest_due_date', 'overdue_installments', 'last_paid_on', 'balance_as_of']);
        });
    }
};
