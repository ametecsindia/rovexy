<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank files as they are (20 Nov 2026):
 *  - import_jobs.header_row: which row of the file holds the column names (0 = first row; bank files often start with title lines)
 *  - import_jobs.import_mapping_id: the saved column mapping used for this upload
 *  - import_mappings: saved column mappings per bank / file format, matched by column NAME so a reordered file still fits
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $t) {
            $t->unsignedSmallInteger('header_row')->default(0);
            $t->unsignedBigInteger('import_mapping_id')->nullable();
        });
        Schema::create('import_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);                     // e.g. "HDFC Bank — monthly allocation"
            $t->string('portfolio_type', 24);
            $t->unsignedSmallInteger('header_row')->default(0);
            $t->json('headers');                         // the file's column names (normalised) — used to recognise the format
            $t->json('fields');                          // ROVEXY field => column name
            $t->json('ignored')->nullable();             // other columns the client chose not to keep
            $t->boolean('keep_other_columns')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
        Schema::table('import_jobs', fn (Blueprint $t) => $t->dropColumn(['header_row', 'import_mapping_id']));
    }
};
