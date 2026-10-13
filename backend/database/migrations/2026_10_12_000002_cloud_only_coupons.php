<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Caller is sold on our cloud only (12 Oct 2026): on-premise columns go. Adds coupons and the reminder-mail log.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('deployment'));
        Schema::table('licences', fn (Blueprint $t) => $t->dropColumn(['deployment', 'server_fingerprint', 'activated_at', 'last_validated_at']));
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropColumn('deployment');
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->string('coupon_code', 30)->nullable()->after('workspace');
            $t->decimal('discount', 12, 2)->default(0)->after('coupon_code');   // before GST, already taken off `amount`
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('type', 10);                               // percent | flat
            $t->decimal('value', 12, 2);
            $t->json('plan_ids')->nullable();                     // NULL = every plan
            $t->boolean('new_only')->default(false);               // first purchase only (not renewals)
            $t->unsignedInteger('max_uses')->nullable();          // NULL = unlimited
            $t->unsignedInteger('used_count')->default(0);
            $t->date('valid_from')->nullable();
            $t->date('valid_until')->nullable();
            $t->boolean('active')->default(true);
            $t->string('note', 190)->nullable();
            $t->timestamps();
        });
        Schema::create('platform_mail_log', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40);
            $t->string('ref', 80);                                // what it was about, e.g. licence:12:2027-10-12
            $t->string('email', 190);
            $t->timestamp('created_at')->nullable();
            $t->unique(['kind', 'ref']);                          // each reminder goes once
        });
        DB::table('platform_settings')->where('key', 'licence_signing_key')->delete();
        DB::table('plans')->where('code', 'enterprise')->where('tagline', 'like', '%own server%')->update(['tagline' => 'Large teams and the SmartPRS / SmartEPT link']);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_log');
        Schema::dropIfExists('coupons');
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['coupon_code', 'discount']));
        Schema::table('sales_orders', fn (Blueprint $t) => $t->string('deployment', 10)->default('cloud'));
        Schema::table('licences', function (Blueprint $t) {
            $t->string('deployment', 10)->default('cloud');
            $t->string('server_fingerprint', 64)->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('last_validated_at')->nullable();
        });
        Schema::table('plans', fn (Blueprint $t) => $t->string('deployment', 10)->default('both'));
    }
};
