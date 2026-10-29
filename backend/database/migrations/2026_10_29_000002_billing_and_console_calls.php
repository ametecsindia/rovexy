<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 of the 29 Oct 2026 gap analysis:
 *  G14 calls from the console: permission calls.console and a new system role "Telecaller (console)" in every company
 *  G15 monthly Click2Call / Smart31 users per licence (licence_usage; on-prem installs report it at check-in)
 *  G16 deposits per licence (licence_deposits) and the AMC amount per year (licences.amc_amount)
 *  G22 when each phone's call recorder was proved to work (managed_devices.recording_verified_at)
 */
return new class extends Migration {
    public function up(): void
    {
        $pid = DB::table('permissions')->where('key', 'calls.console')->value('id')
            ?? DB::table('permissions')->insertGetId(['key' => 'calls.console', 'description' => 'Place Click2Call calls from the admin console']);
        $perms = DB::table('permissions')->whereIn('key', ['calls.make', 'calls.console', 'chat.use'])->pluck('id');
        foreach (DB::table('companies')->pluck('id') as $cid) {
            $rid = DB::table('roles')->where('company_id', $cid)->where('key', 'telecaller')->value('id')
                ?? DB::table('roles')->insertGetId(['company_id' => $cid, 'key' => 'telecaller', 'name' => 'Telecaller (console)', 'system' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($perms as $p) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $rid, 'permission_id' => $p]);
            }
        }
        Schema::create('licence_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('licence_id')->constrained('licences')->cascadeOnDelete();
            $t->char('month', 7);
            $t->unsignedInteger('c2c_users')->default(0);       // people who placed at least one Click2Call call that month
            $t->unsignedInteger('smart31_users')->default(0);   // … of them, with a Smart31 rotation number
            $t->timestamps();
            $t->unique(['licence_id', 'month']);
        });
        Schema::create('licence_deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('licence_id')->constrained('licences')->cascadeOnDelete();
            $t->string('kind', 10);                              // received | refund
            $t->unsignedInteger('fos_count')->nullable();
            $t->decimal('amount', 12, 2);
            $t->date('on_date');
            $t->string('reference', 100)->nullable();
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();    // platform admin
            $t->timestamps();
        });
        Schema::table('licences', fn (Blueprint $t) => $t->decimal('amc_amount', 12, 2)->nullable());
        Schema::table('managed_devices', fn (Blueprint $t) => $t->timestamp('recording_verified_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('managed_devices', fn (Blueprint $t) => $t->dropColumn('recording_verified_at'));
        Schema::table('licences', fn (Blueprint $t) => $t->dropColumn('amc_amount'));
        Schema::dropIfExists('licence_deposits');
        Schema::dropIfExists('licence_usage');
        DB::table('roles')->where('key', 'telecaller')->delete();
        $pid = DB::table('permissions')->where('key', 'calls.console')->value('id');
        DB::table('permission_role')->where('permission_id', $pid)->delete();
        DB::table('permissions')->where('id', $pid)->delete();
    }
};
