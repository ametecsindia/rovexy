<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Negotiated prices for one company (or one buyer e-mail before a company exists). Never touches plans.price_inr.
 * Orders keep a snapshot (list_price, pricing, custom_price_id) so a later change never alters an order already raised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('email', 190)->nullable()->index();          // a prospect buying a new workspace on the website
            $t->foreignId('plan_id')->nullable()->constrained('plans')->cascadeOnDelete();   // null = every package
            $t->string('applies_to', 10)->default('any');          // new | renewal (renewals + upgrades) | any
            $t->string('mode', 10);                                // fixed (₹ before GST) | percent (discount %)
            $t->decimal('value', 12, 2);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->boolean('active')->default(true);
            $t->string('note', 190)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'active']);
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->decimal('list_price', 12, 2)->nullable();          // standard package price at the time of the order
            $t->string('pricing', 10)->default('standard');        // standard | custom
            $t->foreignId('custom_price_id')->nullable()->constrained('custom_prices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('custom_price_id');
            $t->dropColumn(['list_price', 'pricing']);
        });
        Schema::dropIfExists('custom_prices');
    }
};
