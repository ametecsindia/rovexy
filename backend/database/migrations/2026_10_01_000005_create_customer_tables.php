<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('original_name');
            $t->string('stored_key');
            $t->string('format', 8);                    // csv|xlsx
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->json('headers')->nullable();
            $t->json('mapping')->nullable();
            $t->json('options')->nullable();            // on_existing: update|skip
            $t->string('status', 16)->default('uploaded'); // uploaded|validating|ready|committing|completed|failed|cancelled
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('error_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->string('failure')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('import_row_errors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('import_job_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->string('field', 32);
            $t->string('code', 32);
            $t->string('message');
            $t->index(['import_job_id', 'row_number']);
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('customer_ref', 64);
            $t->string('name', 150);
            $t->string('phone', 20);
            $t->string('alt_phone', 20)->nullable();
            $t->string('campaign', 100)->nullable();
            $t->string('status', 20)->default('new');
            $t->text('notes')->nullable();
            $t->date('follow_up_on')->nullable();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $t->timestamp('withdrawn_at')->nullable();
            $t->foreignId('last_import_id')->nullable()->constrained('import_jobs')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'customer_ref']);
            $t->index(['company_id', 'phone']);
            $t->index(['company_id', 'assigned_user_id', 'withdrawn_at']);
            $t->index(['company_id', 'assigned_team_id', 'withdrawn_at']);
        });
        Schema::create('customer_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $t->string('action', 16);                   // assigned|reassigned|withdrawn
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('import_job_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('disposition_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('label', 100);
            $t->boolean('requires_follow_up')->default(false);
            $t->boolean('active')->default(true);
            $t->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        foreach (['disposition_codes', 'customer_assignments', 'customers', 'import_row_errors', 'import_jobs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
