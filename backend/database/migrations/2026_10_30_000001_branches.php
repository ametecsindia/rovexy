<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Branch → Team → People. A team belongs to at most one branch; deleting a branch leaves its teams without one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name', 100);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'name']);
        });
        Schema::table('teams', function (Blueprint $t) {
            $t->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        Schema::dropIfExists('branches');
    }
};
