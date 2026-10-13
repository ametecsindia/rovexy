<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Address / map-location history (10 Oct 2026): one row per change to a case's address or pin, written by the
 * Customer model on every save (upload, phone GPS, address lookup, bulk geocode), with the before and after values.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_address_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 20);                  // upload|phone_gps|phone_address|bulk_geocode|admin|system
            $t->string('what', 8);                     // address|pin|both
            $t->text('old_address')->nullable();       // address, area, city, state, pincode joined
            $t->text('new_address')->nullable();
            $t->json('old_fields')->nullable();
            $t->json('new_fields')->nullable();
            $t->decimal('old_lat', 10, 7)->nullable();
            $t->decimal('old_lng', 10, 7)->nullable();
            $t->decimal('new_lat', 10, 7)->nullable();
            $t->decimal('new_lng', 10, 7)->nullable();
            $t->unsignedInteger('moved_m')->nullable();   // straight line between the old and new pin
            $t->string('note', 255)->nullable();          // e.g. GPS accuracy, Google's matched address
            $t->foreignId('import_job_id')->nullable()->constrained('import_jobs')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_address_changes');
    }
};
