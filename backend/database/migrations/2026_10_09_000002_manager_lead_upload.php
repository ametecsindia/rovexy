<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** SmartDCM lead allocation (9 Oct 2026): managers (supervisors) may upload leads for their own group. */
return new class extends Migration {
    public function up(): void
    {
        $pid = DB::table('permissions')->where('key', 'customers.import')->value('id');
        if ($pid) {
            foreach (DB::table('roles')->where('key', 'supervisor')->pluck('id') as $rid) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
    }

    public function down(): void
    {
        $pid = DB::table('permissions')->where('key', 'customers.import')->value('id');
        DB::table('permission_role')->where('permission_id', $pid)->whereIn('role_id', DB::table('roles')->where('key', 'supervisor')->pluck('id'))->delete();
    }
};
