<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing details on the company (pre-fill renewals, invoices) and payment outcome on orders:
 * a failed attempt keeps the order open (it can be retried), cancelled_at/cancel_reason say why an order closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('billing_email', 190)->nullable();
            $t->string('billing_phone', 20)->nullable();
            $t->string('gstin', 15)->nullable();
            $t->char('state_code', 2)->nullable();
            $t->string('address', 300)->nullable();
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('failed_at')->nullable();
            $t->string('failure_reason', 200)->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['attempts', 'failed_at', 'failure_reason', 'cancelled_at', 'cancel_reason']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['billing_email', 'billing_phone', 'gstin', 'state_code', 'address']));
    }
};
