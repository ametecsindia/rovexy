<?php

use App\Domain\Portfolios\PortfolioTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio types (Collections, Courier, Sales, Marketing, Health Care / Pharma, Nursing, Education, Service, Custom):
 * the type of each upload and case, each type's own fields (customers.details), and per-type outcome codes.
 * Everything that exists today becomes Collections, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', fn (Blueprint $t) => $t->string('portfolio_type', 24)->default(PortfolioTypes::DEFAULT));
        Schema::table('customers', function (Blueprint $t) {
            $t->string('portfolio_type', 24)->default(PortfolioTypes::DEFAULT);
            $t->json('details')->nullable();
            $t->index(['company_id', 'portfolio_type']);
        });
        Schema::table('disposition_codes', function (Blueprint $t) {
            $t->string('portfolio_type', 24)->nullable();   // null = every type
            $t->boolean('success')->default(false);          // counts as a success (delivered, order won, paid…)
        });

        // Map pins come only from addresses now: cases Google could not place get one more try (area / pincode pin).
        DB::table('customers')->where('geo_status', 'not_found')->whereNull('lat')->update(['geo_status' => null]);

        $outcomes = PortfolioTypes::outcomes();
        $byCode = collect($outcomes)->keyBy('code');
        foreach (DB::table('companies')->pluck('id') as $cid) {
            $have = DB::table('disposition_codes')->where('company_id', $cid)->pluck('id', 'code');
            foreach ($have as $code => $id) {   // existing codes: Collections ones belong to Collections; calling results to all
                if ($o = $byCode->get($code)) {
                    DB::table('disposition_codes')->where('id', $id)->update(['portfolio_type' => $o['type'], 'success' => $o['success']]);
                } else {
                    DB::table('disposition_codes')->where('id', $id)->update(['portfolio_type' => PortfolioTypes::DEFAULT]);   // the company's own code
                }
            }
            $new = [];
            foreach ($outcomes as $o) {
                if (!isset($have[$o['code']])) {
                    $new[] = ['company_id' => $cid, 'code' => $o['code'], 'label' => $o['label'], 'requires_follow_up' => $o['follow_up'], 'active' => true,
                        'captures' => $o['captures'], 'portfolio_type' => $o['type'], 'success' => $o['success']];
                }
            }
            foreach (array_chunk($new, 200) as $chunk) {
                DB::table('disposition_codes')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        $codes = collect(PortfolioTypes::outcomes())->whereNotIn('type', [null, PortfolioTypes::DEFAULT])->pluck('code');
        DB::table('disposition_codes')->whereIn('code', $codes)->delete();
        Schema::table('disposition_codes', fn (Blueprint $t) => $t->dropColumn(['portfolio_type', 'success']));
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'portfolio_type']);
            $t->dropColumn(['portfolio_type', 'details']);
        });
        Schema::table('import_jobs', fn (Blueprint $t) => $t->dropColumn('portfolio_type'));
    }
};
