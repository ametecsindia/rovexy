<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud + on-premises licensing with per-user pricing (13 Oct 2026, modelled on SmartEPT Central).
 *  - plans become tiers with two price ladders: cloud ₹/user/month by size band, on-prem perpetual one-time price by band
 *  - licences: kind trial | subscription | perpetual | internal; deployment cloud | onprem; billing cycle; AMC; server binding
 *  - orders: line items snapshot, users, billing, deployment; quote validity
 *  - installation_licence: the on-prem install's own cached, signed verdict from the licence server
 * Status / kind columns stay VARCHAR — the application owns the value sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('min_users')->default(5);
        });
        Schema::create('plan_cloud_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('min_users');
            $t->unsignedInteger('max_users')->nullable();          // NULL on the last row = "custom quote above this"
            $t->decimal('rate_inr', 10, 2)->default(0);            // ₹ per user per month on annual billing
        });
        Schema::create('plan_perpetual_bands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('min_users');
            $t->unsignedInteger('max_users')->nullable();          // NULL on the last row = custom quote
            $t->decimal('price_inr', 12, 2)->nullable();           // one-time price at the band's top (max_users)
        });
        Schema::table('licences', function (Blueprint $t) {
            $t->string('deployment', 10)->default('cloud');        // cloud | onprem
            $t->string('billing', 12)->nullable();                 // annual | half_yearly | quarterly (subscriptions)
            $t->timestamp('amc_expires_at')->nullable();           // perpetual: updates & support cover
            $t->string('server_fingerprint', 64)->nullable();      // on-prem: bound on the first check-in
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('last_validated_at')->nullable();
            $t->unsignedInteger('reported_users')->nullable();     // what the install reported at its last check-in
        });
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->string('deployment', 10)->default('cloud');
            $t->string('licence_kind', 12)->nullable();            // subscription | perpetual
            $t->string('billing', 12)->nullable();
            $t->unsignedInteger('users')->nullable();               // seats after this order
            $t->json('lines')->nullable();                         // line items, snapshotted
            $t->date('valid_until')->nullable();                   // quote validity: the pay link stops after this
        });
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('setup_fee_paid')->default(false);
        });
        Schema::create('installation_licence', function (Blueprint $t) {   // on-prem install only; one row
            $t->id();
            $t->string('licence_key', 40)->nullable();
            $t->timestamp('key_saved_at')->nullable();             // anchors the "key entered, not yet verified" window
            $t->text('token')->nullable();                         // signed bundle from the licence server (verified on every read)
            $t->string('source', 10)->nullable();                  // online | file
            $t->string('status', 30)->default('none');             // none | active | unknown_key | server_mismatch | licence_expired | ...
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('unreachable_since')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->timestamp('installed_at')->nullable();             // starts the evaluation window
            $t->timestamps();
        });

        // Existing paid packages are cloud annual subscriptions.
        DB::table('licences')->where('kind', 'package')->update(['kind' => 'subscription', 'billing' => 'annual']);
        DB::table('sales_orders')->whereNull('licence_kind')->update(['licence_kind' => 'subscription', 'billing' => 'annual']);

        // Starting price ladders (placeholders — set real prices in Super Admin → Plans & pricing before selling).
        $ladders = [
            'starter' => [[[5, 25, 199], [26, 100, 179], [101, 500, 149], [501, null, 0]], [[5, 10, 40000], [11, 25, 85000], [26, 50, 150000], [51, 100, 250000], [101, 500, 900000], [501, null, null]]],
            'business' => [[[5, 25, 299], [26, 100, 259], [101, 500, 219], [501, null, 0]], [[5, 10, 60000], [11, 25, 130000], [26, 50, 225000], [51, 100, 375000], [101, 500, 1350000], [501, null, null]]],
            'enterprise' => [[[5, 25, 399], [26, 100, 349], [101, 500, 299], [501, null, 0]], [[5, 10, 80000], [11, 25, 175000], [26, 50, 300000], [51, 100, 500000], [101, 500, 1800000], [501, null, null]]],
        ];
        foreach ($ladders as $code => [$tiers, $bands]) {
            $id = DB::table('plans')->where('code', $code)->value('id');
            if (!$id) {
                continue;
            }
            foreach ($tiers as [$min, $max, $rate]) {
                DB::table('plan_cloud_tiers')->insert(['plan_id' => $id, 'min_users' => $min, 'max_users' => $max, 'rate_inr' => $rate]);
            }
            foreach ($bands as [$min, $max, $price]) {
                DB::table('plan_perpetual_bands')->insert(['plan_id' => $id, 'min_users' => $min, 'max_users' => $max, 'price_inr' => $price]);
            }
        }
    }

    public function down(): void
    {
        DB::table('licences')->where('kind', 'subscription')->update(['kind' => 'package']);
        Schema::dropIfExists('installation_licence');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('setup_fee_paid'));
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['deployment', 'licence_kind', 'billing', 'users', 'lines', 'valid_until']));
        Schema::table('licences', fn (Blueprint $t) => $t->dropColumn(['deployment', 'billing', 'amc_expires_at', 'server_fingerprint', 'activated_at', 'last_validated_at', 'reported_users']));
        Schema::dropIfExists('plan_perpetual_bands');
        Schema::dropIfExists('plan_cloud_tiers');
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('min_users'));
    }
};
