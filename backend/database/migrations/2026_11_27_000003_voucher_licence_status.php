<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 27 Nov 2026 — LC enforcement for Click2Call / Smart31 (as SmartDCM's dailyValidations, but checked at call time too):
 *  c2c_vouchers.status         active | revoked | expired (revoked/expired = its numbers are removed and can't be used)
 *  c2c_vouchers.last_ok_at     last time the LC Manager confirmed the voucher (did_voucher_validate status 200)
 *  c2c_vouchers.checked_at     last time ROVEXY asked
 *  c2c_vouchers.status_message what the LC Manager answered when it refused
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('c2c_vouchers', function (Blueprint $t) {
            $t->string('status', 12)->default('active');
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('status_message', 255)->nullable();
        });
        DB::table('c2c_vouchers')->update(['last_ok_at' => DB::raw('created_at')]);   // checked again within minutes of the deploy
    }

    public function down(): void
    {
        Schema::table('c2c_vouchers', fn (Blueprint $t) => $t->dropColumn(['status', 'last_ok_at', 'checked_at', 'revoked_at', 'status_message']));
    }
};
