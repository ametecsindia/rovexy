<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 63)->unique();
            $t->string('name', 150);
            $t->string('status', 20)->default('active'); // active | suspended
            $t->string('timezone', 64)->default('Asia/Kolkata');
            $t->char('default_country', 2)->default('IN');
            $t->string('amapi_enterprise')->nullable()->unique();
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('company_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('host')->unique();
            $t->string('verification_token', 64);
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('company_branding', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('display_name', 150);
            $t->string('logo_key')->nullable();
            $t->char('logo_sha256', 64)->nullable();
            $t->string('logo_mime', 40)->nullable();
            $t->string('primary_color', 7)->default('#1F3A5F');
            $t->string('accent_color', 7)->default('#B8893B');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('platform_admins', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('name');
            $t->string('password');
            $t->text('totp_secret')->nullable();
            $t->unsignedBigInteger('totp_last_step')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
        Schema::dropIfExists('company_branding');
        Schema::dropIfExists('company_domains');
        Schema::dropIfExists('companies');
    }
};
