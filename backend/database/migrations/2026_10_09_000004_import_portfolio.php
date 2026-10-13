<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Portfolios (9 Oct 2026): every uploaded lead file has a name shown in Assignment. Existing imports are named after their file. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_jobs', fn (Blueprint $t) => $t->string('portfolio', 150)->nullable());
        foreach (DB::table('import_jobs')->get(['id', 'original_name']) as $j) {
            DB::table('import_jobs')->where('id', $j->id)->update(['portfolio' => mb_substr(pathinfo($j->original_name, PATHINFO_FILENAME), 0, 150)]);
        }
    }

    public function down(): void
    {
        Schema::table('import_jobs', fn (Blueprint $t) => $t->dropColumn('portfolio'));
    }
};
