<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the 29 Oct 2026 gap analysis:
 *  G5  automatic address → map pin after every upload (customers.geo_*, geocode_results = earlier answers reused)
 *  G7  each field employee's own working circle (field_circles)
 *  G8  admin route plans per employee per day (route_plans)
 *  G12 Google Maps usage per month, checked against the company's limits (maps_usage)
 *  G19 fake-GPS (mock location) fixes flagged (location_events.mock)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            // null = not looked up yet · google = precise pin from the address · approx = Google matched only the area (no pin)
            // not_found = Google found nothing · file = pin came with the upload · phone = set on the phone
            $t->string('geo_status', 12)->nullable();
            $t->string('geo_precision', 24)->nullable();   // Google location_type (ROOFTOP, RANGE_INTERPOLATED, …)
            $t->index(['company_id', 'geo_status']);
        });
        Schema::create('geocode_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->char('address_key', 40);                  // sha1 of the normalised address + country
            $t->string('status', 12);                     // google | approx | not_found
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('precision', 24)->nullable();
            $t->string('formatted_address', 300)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'address_key']);
        });
        Schema::create('field_circles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100)->nullable();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->unsignedInteger('radius_m');
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'user_id']);
        });
        Schema::create('route_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('plan_date');
            $t->string('status', 12)->default('draft');   // draft | published
            $t->decimal('start_lat', 10, 7);
            $t->decimal('start_lng', 10, 7);
            $t->json('stops');                            // customer ids in visiting order
            $t->unsignedInteger('distance_m')->nullable();
            $t->unsignedInteger('duration_s')->nullable();
            $t->json('points')->nullable();               // road line to draw
            $t->json('legs')->nullable();
            $t->boolean('optimised')->default(false);     // false = straight-line order (no Google call)
            $t->char('stops_hash', 40)->nullable();       // the optimised answer is reused while the stops don't change
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'user_id', 'plan_date']);
        });
        Schema::create('maps_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->char('month', 7);                         // 2026-10
            $t->string('sku', 20);                        // geocode | matrix | routes | routes_2w
            $t->unsignedInteger('used')->default(0);
            $t->unique(['company_id', 'month', 'sku']);
        });
        Schema::table('location_events', function (Blueprint $t) {
            $t->boolean('mock')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('location_events', fn (Blueprint $t) => $t->dropColumn('mock'));
        Schema::dropIfExists('maps_usage');
        Schema::dropIfExists('route_plans');
        Schema::dropIfExists('field_circles');
        Schema::dropIfExists('geocode_results');
        Schema::table('customers', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'geo_status']);
            $t->dropColumn(['geo_status', 'geo_precision']);
        });
    }
};
