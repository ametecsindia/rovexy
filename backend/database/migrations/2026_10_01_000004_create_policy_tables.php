<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('policy_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('description')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });
        Schema::create('policy_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_id')->constrained('policy_profiles')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('settings');                       // partial overrides (key => value)
            $t->string('status', 16)->default('draft'); // draft|published|superseded
            $t->json('conflicts')->nullable();
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->unsignedBigInteger('rolled_back_from_version_id')->nullable();
            $t->timestamps();
            $t->unique(['profile_id', 'version']);
        });
        Schema::create('policy_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_id')->constrained('policy_profiles')->cascadeOnDelete();
            $t->string('target_type', 10);                       // company|team|device
            $t->unsignedBigInteger('target_id')->default(0);     // 0 for company scope
            $t->timestamps();
            $t->unique(['company_id', 'target_type', 'target_id']);
        });
        Schema::create('device_policy_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->char('fingerprint', 16);
            $t->json('values');
            $t->json('sources');
            $t->json('version_refs');                   // profile/version ids that produced it
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['device_id', 'fingerprint']);
        });
        Schema::create('policy_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained('managed_devices')->cascadeOnDelete();
            $t->char('fingerprint', 16);
            $t->string('source', 12);                   // app|amapi
            $t->json('results');                        // key => {status, verified, detail}
            $t->string('state', 24);
            $t->boolean('compliant');
            $t->timestamp('reported_at');
            $t->index(['device_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        foreach (['policy_acknowledgements', 'device_policy_snapshots', 'policy_assignments', 'policy_versions', 'policy_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
