<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each portfolio picks a recording file-name format (companies.settings.recordings.formats[].id). Null = the company default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', fn (Blueprint $t) => $t->string('recording_format', 16)->nullable());
    }

    public function down(): void
    {
        Schema::table('import_jobs', fn (Blueprint $t) => $t->dropColumn('recording_format'));
    }
};
