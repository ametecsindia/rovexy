<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Test calls (Calling configuration → Test calls) are kept out of Calls, Dashboard, Supervision and exports.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->boolean('is_test')->default(false)->index());
        DB::table('calls')->whereNull('device_id')->where('provider', 'click2call')->update(['is_test' => true]);   // test calls placed before this column
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->dropColumn('is_test'));
    }
};
