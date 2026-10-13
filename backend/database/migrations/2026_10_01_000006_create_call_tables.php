<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();                          // internal call ID (also FreeSWITCH origination_uuid)
            $t->string('provider', 16);                          // freeswitch|simulator|webhook
            $t->string('provider_call_id', 128)->nullable();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('device_id')->constrained('managed_devices');
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('destination', 20);
            $t->string('which_number', 10)->default('primary');
            $t->string('calling_mode', 16)->default('server_voip');
            $t->string('agent_leg', 16);
            $t->string('state', 20)->default('initiated');
            $t->string('hangup_cause', 48)->nullable();
            $t->string('failure_reason', 64)->nullable();
            $t->timestamp('initiated_at');
            $t->timestamp('agent_answered_at')->nullable();
            $t->timestamp('ringing_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('ring_seconds')->nullable();
            $t->unsignedInteger('talk_seconds')->default(0);
            $t->unsignedInteger('total_seconds')->nullable();
            $t->boolean('recording_required')->default(false);
            $t->string('recording_status', 24)->default('not_required');
            $t->string('disposition_status', 12)->default('pending'); // pending|submitted
            $t->timestamps();
            $t->index(['company_id', 'user_id', 'initiated_at']);
            $t->index(['company_id', 'customer_id']);
            $t->index(['company_id', 'state']);
        });
        Schema::create('call_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_id')->constrained()->cascadeOnDelete();
            $t->string('provider_event_id', 128);
            $t->string('type', 32);
            $t->timestamp('occurred_at');
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['call_id', 'provider_event_id']);       // duplicate telephony events are no-ops
        });
        Schema::create('recordings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('status', 24);
            $t->string('source_path')->nullable();               // path on the telephony host (relative)
            $t->string('object_key')->nullable();                // companies/{cid}/recordings/…
            $t->string('format', 8)->nullable();
            $t->string('mime', 32)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('last_error')->nullable();
            $t->boolean('legal_hold')->default(false);
            $t->timestamp('retention_expires_at')->nullable();
            $t->timestamp('available_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'retention_expires_at']);
        });
        Schema::create('call_dispositions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('code', 32);
            $t->text('notes')->nullable();
            $t->timestamp('follow_up_at')->nullable();
            $t->timestamp('submitted_at');
        });
        Schema::create('follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('due_at');
            $t->string('status', 12)->default('open');           // open|done|cancelled
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'user_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        foreach (['follow_ups', 'call_dispositions', 'recordings', 'call_events', 'calls'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
