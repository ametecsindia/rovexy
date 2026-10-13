<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Customers given to a caller before 9 Oct have no group: put them in the caller's group (so group filters and managers see them). */
return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('customers')->whereNotNull('assigned_user_id')->whereNull('assigned_team_id')->distinct()->pluck('assigned_user_id');
        foreach ($rows as $uid) {
            $team = DB::table('team_user')->where('user_id', $uid)->orderByRaw("membership = 'member' desc")->value('team_id');
            if ($team) {
                DB::table('customers')->where('assigned_user_id', $uid)->whereNull('assigned_team_id')->update(['assigned_team_id' => $team]);
            }
        }
    }

    public function down(): void
    {
    }
};
