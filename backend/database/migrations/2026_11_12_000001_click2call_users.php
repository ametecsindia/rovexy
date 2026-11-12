<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Click2Call for some users only (12 Nov 2026): a plan is bought for N users and Click2Call (optionally with Smart31) for
 * any number of them, 0..N. licences.c2c_users is the licensed Click2Call head-count; NULL = set before this change (the
 * plan's features decide, no count). The four ROVEXY Cloud plans with the add-on baked in are taken off the website;
 * licences already on them keep renewing at their price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licences', fn (Blueprint $t) => $t->unsignedInteger('c2c_users')->nullable());
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->unsignedInteger('c2c_users')->nullable();   // NULL = order made before this change (no add-on line)
            $t->boolean('smart31')->default(false);
        });
        DB::table('plans')->where('cloud_model', 'quarterly')->where('code', 'like', '%-c2c%')->update(['is_public' => false]);
    }

    public function down(): void
    {
        DB::table('plans')->where('cloud_model', 'quarterly')->where('code', 'like', '%-c2c%')->update(['is_public' => true]);
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['c2c_users', 'smart31']));
        Schema::table('licences', fn (Blueprint $t) => $t->dropColumn('c2c_users'));
    }
};
