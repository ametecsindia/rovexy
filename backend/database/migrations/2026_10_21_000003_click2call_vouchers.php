<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Click2Call kit (LeadGER) integrated into ROVEXY, per company:
 *  c2c_vouchers / c2c_vmns / c2c_vmn_history  — Add Voucher from the Ametecs licence server (LC) → company numbers, assigned to groups
 *  c2c_daily_attempts                          — Click2Call attempts per customer number per day (limit, LeadGER default 100)
 *  smart31_numbers: source (admin|voucher), type ("1600"), group_name, usage_date — voucher Smart31 numbers join the existing pool
 *  users: click_agent_number (the phone the provider rings; blank = work phone), c2c_default_number
 * Blocked attempts go to the audit log (action call.blocked), like every other refusal in ROVEXY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c2c_vouchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('voucher_code', 100);
            $t->unsignedInteger('numbers')->default(0);
            $t->unsignedInteger('smart31_numbers')->default(0);
            $t->date('expires_at')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('c2c_vmns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('virtual_number', 20);
            $t->boolean('click2call')->default(false);
            $t->boolean('webrtc')->default(false);
            $t->boolean('obd')->default(false);
            $t->boolean('tc_verified')->default(false);
            $t->json('team_ids')->nullable();                  // groups that may use it; empty = admins only (as LeadGER)
            $t->date('expires_at')->nullable();
            $t->foreignId('voucher_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'virtual_number']);
        });
        Schema::create('c2c_vmn_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('virtual_number', 20);
            $t->boolean('click2call')->default(false);
            $t->boolean('webrtc')->default(false);
            $t->boolean('obd')->default(false);
            $t->boolean('tc_verified')->default(false);
            $t->json('team_ids')->nullable();
            $t->date('expires_at')->nullable();
            $t->foreignId('voucher_id')->nullable();
            $t->timestamp('replaced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('c2c_daily_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 20);
            $t->date('call_date');
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'phone', 'call_date']);
        });
        Schema::table('smart31_numbers', function (Blueprint $t) {
            $t->string('source', 10)->default('admin');        // admin | voucher (replaced by the next voucher)
            $t->string('type', 20)->nullable();                // "1600" for 1600-series numbers
            $t->string('group_name', 100)->nullable();         // Smart31 group name from the licence server
            $t->date('usage_date')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('click_agent_number', 20)->nullable();
            $t->string('c2c_default_number', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['click_agent_number', 'c2c_default_number']));
        Schema::table('smart31_numbers', fn (Blueprint $t) => $t->dropColumn(['source', 'type', 'group_name', 'usage_date']));
        foreach (['c2c_daily_attempts', 'c2c_vmn_history', 'c2c_vmns', 'c2c_vouchers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
