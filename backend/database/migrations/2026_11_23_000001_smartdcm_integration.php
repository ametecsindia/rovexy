<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SmartDCM integration (23 Nov 2026).
 *  - integration_connections gets what a two-way link needs: a public uuid (webhook / recording URLs SmartDCM calls),
 *    an inbound secret (SmartDCM signs webhooks with it and sends it to read recordings), options and sync state.
 *  - external_refs: SmartDCM id ⇄ ROVEXY id for branches, teams, users, portfolios and cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $t) {
            $t->string('uuid', 36)->nullable()->unique();
            $t->text('inbound_secret')->nullable();      // encrypted (model cast)
            $t->json('options')->nullable();
            $t->json('state')->nullable();
        });
        Schema::create('external_refs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('system', 16);                    // smartdcm
            $t->string('entity', 16);                    // branch | team | user | portfolio | case
            $t->string('remote_id', 64);
            $t->unsignedBigInteger('local_id');
            $t->timestamps();
            $t->unique(['company_id', 'system', 'entity', 'remote_id'], 'external_refs_remote');
            $t->index(['company_id', 'system', 'entity', 'local_id'], 'external_refs_local');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_refs');
        Schema::table('integration_connections', function (Blueprint $t) {
            $t->dropUnique(['uuid']);
            $t->dropColumn(['uuid', 'inbound_secret', 'options', 'state']);
        });
    }
};
