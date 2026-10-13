<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('employee_code', 64);           // the "User ID" typed on the phone
            $t->string('email')->nullable();
            $t->string('name', 150);
            $t->string('phone', 20)->nullable();        // E.164; agent leg for pstn_callback calling
            $t->string('password');
            $t->string('status', 20)->default('active'); // active | suspended | left
            $t->text('totp_secret')->nullable();        // encrypted cast
            $t->unsignedBigInteger('totp_last_step')->nullable();
            $t->timestamp('mfa_enabled_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->unique(['company_id', 'employee_code']);
            $t->unique(['company_id', 'email']);
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key', 64)->unique();
            $t->string('description');
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('key', 64);
            $t->string('name', 100);
            $t->boolean('system')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'key']);
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name', 100);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('team_user', function (Blueprint $t) {
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('membership', 20)->default('member'); // member | supervisor
            $t->primary(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['team_user', 'teams', 'role_user', 'permission_role', 'roles', 'permissions', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
