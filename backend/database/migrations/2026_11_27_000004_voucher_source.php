<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 27 Nov 2026: a voucher is either the client's own (added in ROVEXY) or SmartDCM's (taken from the linked SmartDCM). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('c2c_vouchers', function (Blueprint $t) {
            $t->string('source', 20)->default('rovexy')->after('voucher_code');
        });
        // Vouchers SmartDCM's sync created had no creator and no numbers of their own.
        DB::table('c2c_vouchers')->whereNull('created_by')->where('numbers', 0)->update(['source' => 'smartdcm']);
    }

    public function down(): void
    {
        Schema::table('c2c_vouchers', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
