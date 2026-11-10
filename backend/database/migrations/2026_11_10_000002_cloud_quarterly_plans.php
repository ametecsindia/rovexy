<?php

declare(strict_types=1);

use App\Services\Platform\CloudPlans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROVEXY Cloud pricing of 10 Nov 2026: six cloud plans (2 Google Maps models × no telephony / Click2Call / Click2Call +
 * Smart31), quarterly only, the tier rate charged exactly (no annual-discount maths). Existing plans and their on-premises
 * prices are untouched; their cloud tiers stay for licences already sold on them (renewals, more users).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->string('cloud_model', 16)->nullable();   // quarterly = sold only quarterly at the tier rate
        });
        $sort = (int) DB::table('plans')->max('sort');
        foreach (CloudPlans::MAPS as $maps => $mapsName) {
            foreach (CloudPlans::TELEPHONY as $tel => $suffix) {
                $code = CloudPlans::code($maps, $tel);
                if (DB::table('plans')->where('code', $code)->exists()) {
                    continue;
                }
                $id = DB::table('plans')->insertGetId([
                    'code' => $code, 'name' => $mapsName . $suffix, 'cloud_model' => 'quarterly',
                    'tagline' => $maps === 'ametecs' ? 'Google Maps managed by Ametecs.' : 'Google Maps on your own Google account and billing.',
                    'price_inr' => null, 'months' => 3, 'max_users' => 0, 'min_users' => 5, 'features' => json_encode(CloudPlans::features($tel)),
                    'is_public' => true, 'active' => true, 'sort' => ++$sort, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (CloudPlans::SEED[$maps] as [$min, $max, $rate]) {
                    DB::table('plan_cloud_tiers')->insert(['plan_id' => $id, 'min_users' => $min, 'max_users' => $max, 'rate_inr' => $rate + CloudPlans::ADDON[$tel]]);
                }
                $seed = CloudPlans::SEED[$maps];
                $last = end($seed);
                DB::table('plan_cloud_tiers')->insert(['plan_id' => $id, 'min_users' => $last[1] + 1, 'max_users' => null, 'rate_inr' => 0]);   // above: custom quote
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('plans')->where('cloud_model', 'quarterly')->pluck('id');
        if (!DB::table('sales_orders')->whereIn('plan_id', $ids)->exists() && !DB::table('licences')->whereIn('plan_id', $ids)->exists()) {
            DB::table('plans')->whereIn('id', $ids)->delete();
        }
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('cloud_model'));
    }
};
