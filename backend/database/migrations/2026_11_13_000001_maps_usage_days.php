<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google Maps service management (13 Nov 2026):
 *  - maps_usage_days: every Google Maps call per company per day per API (requests, billable units, failed), replacing the
 *    monthly maps_usage counter (its rows are kept as day 1 of their month, so this month's limits carry on)
 *  - licences.maps_enabled: Super Admin switch; off = this company's ROVEXY makes no Google Maps calls
 *  - licence_maps_usage: what on-premises installs report at their licence check-in (Super Admin report)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maps_usage_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->string('sku', 20);                         // MapsUsage::SKUS
            $t->unsignedInteger('requests')->default(0);   // calls sent to Google
            $t->unsignedInteger('units')->default(0);      // billable events (= requests, except Route Matrix elements)
            $t->unsignedInteger('failed')->default(0);     // calls Google refused or that did not reach Google
            $t->unique(['company_id', 'day', 'sku']);
        });
        foreach (DB::table('maps_usage')->get() as $r) {
            DB::table('maps_usage_days')->insert(['company_id' => $r->company_id, 'day' => $r->month . '-01', 'sku' => $r->sku, 'requests' => $r->used, 'units' => $r->used]);
        }
        Schema::dropIfExists('maps_usage');

        Schema::table('licences', fn (Blueprint $t) => $t->boolean('maps_enabled')->default(true));

        Schema::create('licence_maps_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('licence_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->string('sku', 20);
            $t->unsignedInteger('requests')->default(0);
            $t->unsignedInteger('units')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unique(['licence_id', 'day', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_maps_usage');
        Schema::table('licences', fn (Blueprint $t) => $t->dropColumn('maps_enabled'));
        Schema::create('maps_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->char('month', 7);
            $t->string('sku', 20);
            $t->unsignedInteger('used')->default(0);
            $t->unique(['company_id', 'month', 'sku']);
        });
        Schema::dropIfExists('maps_usage_days');
    }
};
