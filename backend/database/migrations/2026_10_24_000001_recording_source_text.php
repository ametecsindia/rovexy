<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Provider recording links (signed S3/CDN URLs) can exceed 255 chars; they were cut and the download then failed.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('recordings', fn (Blueprint $t) => $t->text('source_path')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('recordings', fn (Blueprint $t) => $t->string('source_path')->nullable()->change());
    }
};
