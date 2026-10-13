<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('managed_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('asset_tag', 64)->nullable();               // company device asset ID
            $t->char('installation_id', 36);                       // app-generated; NOT hardware identity
            $t->string('amapi_device_name')->nullable();           // enterprises/x/devices/y
            $t->string('manufacturer', 64)->nullable();
            $t->string('model', 64)->nullable();
            $t->string('os_version', 32)->nullable();
            $t->unsignedSmallInteger('sdk_int')->nullable();
            $t->string('app_version', 32)->nullable();
            $t->string('approval_state', 16)->default('pending');  // pending|approved|rejected|revoked
            $t->string('approval_code', 9);
            $t->timestamp('approval_code_expires_at')->nullable();
            $t->string('management_mode', 16)->default('app_only'); // amapi|app_only
            $t->string('management_status', 24)->default('unenrolled');
            $t->json('capabilities')->nullable();
            $t->unsignedInteger('credential_epoch')->default(0);
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $t->char('desired_policy_fingerprint', 16)->nullable();
            $t->char('applied_policy_fingerprint', 16)->nullable();
            $t->char('last_good_policy_fingerprint', 16)->nullable();
            $t->string('policy_state', 24)->default('pending');   // pending|applying|applied|partially_applied|failed
            $t->boolean('compliant')->default(false);
            $t->json('compliance_detail')->nullable();
            $t->string('presence', 10)->default('offline');       // online|offline
            $t->string('work_state', 24)->default('signed_out');  // signed_out|idle|active|on_call|updating_customer
            $t->unsignedTinyInteger('battery_pct')->nullable();
            $t->string('network_type', 16)->nullable();
            $t->timestamp('last_contact_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'installation_id']);
            $t->unique(['company_id', 'approval_code']);
            $t->unique(['company_id', 'asset_tag']);
            $t->index(['company_id', 'approval_state']);
        });
        Schema::create('device_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->text('public_key_pem');
            $t->char('fingerprint', 64)->unique();                // a key can exist once, anywhere
            $t->unsignedInteger('epoch');
            $t->string('status', 12)->default('active');          // active|revoked
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['device_id', 'status']);
        });
        Schema::create('device_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->useCurrent();
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason', 64)->nullable();
            $t->index(['device_id', 'ended_at']);
        });
        Schema::create('device_approval_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->string('action', 32);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('device_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->unsignedInteger('epoch');
            $t->timestamp('expires_at');
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('work_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('access_hash', 64)->unique();
            $t->char('refresh_hash', 64)->unique();
            $t->unsignedInteger('epoch');
            $t->timestamp('access_expires_at');
            $t->timestamp('refresh_expires_at');
            $t->timestamp('lease_expires_at');
            $t->timestamp('last_validated_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason', 32)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['device_id', 'ended_at']);
        });
        Schema::create('push_registrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->string('provider', 16)->default('fcm');
            $t->text('token');
            $t->char('token_hash', 64);
            $t->string('status', 16)->default('active');   // active|invalid|disabled
            $t->string('last_error')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamps();
            $t->unique(['device_id', 'provider']);
            $t->index('token_hash');
        });
        Schema::create('device_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->string('type', 32);          // sync_policy|end_media|lock_work|amapi_lock|amapi_wipe
            $t->json('payload')->nullable();
            $t->string('status', 16)->default('queued'); // queued|sent|acknowledged|failed|expired
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('result')->nullable();
            $t->timestamps();
            $t->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['device_commands', 'push_registrations', 'work_sessions', 'device_sessions', 'device_approval_events', 'device_assignments', 'device_credentials', 'managed_devices'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
