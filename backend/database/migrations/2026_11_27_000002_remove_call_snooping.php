<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 27 Nov 2026: call snooping (listen in to live Click2Call calls) removed — its table and permission go. SmartRMI stays. */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('call_monitor_sessions');
        $id = DB::table('permissions')->where('key', 'calls.monitor')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        DB::table('platform_settings')->where('key', 'c2c_conference_url')->delete();
    }

    public function down(): void
    {
        // Not restored: the feature was removed.
    }
};
