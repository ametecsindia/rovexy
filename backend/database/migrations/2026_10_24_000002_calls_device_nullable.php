<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin test calls (Calling options → Test call) are placed from the console, not from a company phone.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->foreignId('device_id')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->foreignId('device_id')->nullable(false)->change());
    }
};
