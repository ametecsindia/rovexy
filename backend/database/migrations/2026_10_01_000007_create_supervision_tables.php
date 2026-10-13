<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_events', function (Blueprint $t) {
            // Idempotency ledger for offline-queued events (unique per device).
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->uuid('client_event_id');
            $t->string('type', 24);
            $t->string('result', 24);
            $t->timestamp('received_at')->useCurrent();
            $t->unique(['device_id', 'client_event_id']);
        });
        Schema::create('location_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_session_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->float('accuracy_m')->nullable();
            $t->string('provider', 16)->nullable();
            $t->unsignedTinyInteger('battery_pct')->nullable();
            $t->string('network_type', 16)->nullable();
            $t->timestamp('captured_at');
            $t->timestamp('received_at')->useCurrent();
            $t->index(['company_id', 'user_id', 'captured_at']);
        });
        Schema::create('activity_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 32);
            $t->timestamp('occurred_at');
            $t->json('meta')->nullable();
            $t->index(['company_id', 'user_id', 'occurred_at']);
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $t->string('subject', 150)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
        });
        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->primary(['conversation_id', 'user_id']);
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sender_user_id')->constrained('users');
            $t->text('body')->nullable();
            $t->string('attachment_key')->nullable();
            $t->string('attachment_name')->nullable();
            $t->string('attachment_mime', 64)->nullable();
            $t->unsignedInteger('attachment_size')->nullable();
            $t->uuid('client_message_id')->nullable();
            $t->timestamp('created_at')->useCurrent();          // server timestamp
            $t->timestamp('purged_at')->nullable();
            $t->unique(['conversation_id', 'client_message_id']);
            $t->index(['conversation_id', 'id']);
        });
        Schema::create('message_receipts', function (Blueprint $t) {
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->primary(['message_id', 'user_id']);
        });
        Schema::create('media_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('kind', 8);                              // screen|camera
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('device_id')->constrained('managed_devices');
            $t->foreignId('user_id')->constrained();
            $t->string('purpose', 255);
            $t->string('state', 12)->default('requested');
            $t->string('end_reason', 32)->nullable();
            $t->unsignedSmallInteger('timeout_minutes');
            $t->json('options')->nullable();                    // quality, fps, screenshots_allowed
            $t->boolean('protected_content_reported')->default(false);
            $t->timestamp('requested_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'state']);
        });
        Schema::create('media_screenshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_session_id')->constrained()->cascadeOnDelete();
            $t->string('object_key');
            $t->char('sha256', 64);
            $t->foreignId('taken_by')->constrained('users');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete(); // null only for platform-level actions
            $t->string('actor_type', 16);                       // user|platform_admin|device|system
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 64);
            $t->string('subject_type', 48)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'action']);
        });
        Schema::create('retention_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16);                             // recordings|chat|location
            $t->string('status', 16)->default('planned');       // planned|approved|running|completed|failed|cancelled
            $t->json('scope');                                  // cutoff, counts, bytes, sample ids
            $t->foreignId('planned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('executed_count')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['retention_jobs', 'audit_events', 'media_screenshots', 'media_sessions', 'message_receipts', 'messages', 'conversation_participants', 'conversations', 'activity_events', 'location_events', 'client_events'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
