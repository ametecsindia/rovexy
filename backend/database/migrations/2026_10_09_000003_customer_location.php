<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Location-wise allocation (9 Oct 2026): city / area / pincode on customers, from the Excel import. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('city', 100)->nullable();
            $t->string('area', 100)->nullable();
            $t->string('pincode', 12)->nullable();
            $t->index(['company_id', 'city']);
            $t->index(['company_id', 'pincode']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'city']);
            $t->dropIndex(['company_id', 'pincode']);
            $t->dropColumn(['city', 'area', 'pincode']);
        });
    }
};
