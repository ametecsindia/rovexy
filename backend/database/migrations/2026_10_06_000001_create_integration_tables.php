<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SmartPRS2 / SmartEPT connections (one row per company per system) and the
 * outbox that delivers field-force events to them at-least-once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('system', 16);                 // smartprs | smartept
            $t->string('base_url', 255);
            $t->text('api_key');                      // encrypted at rest (model cast)
            $t->boolean('enabled')->default(true);
            $t->boolean('enforce_geofence')->default(true);   // smartprs only: block sign-in outside the fence
            $t->string('last_status', 255)->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'system']);
        });

        Schema::create('integration_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('system', 16);
            $t->string('kind', 16);                   // attendance | punch | location
            $t->json('payload');
            $t->string('status', 8)->default('pending');   // pending | sent | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->index(['company_id', 'system', 'status', 'next_attempt_at'], 'outbox_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('integration_connections');
    }
};
