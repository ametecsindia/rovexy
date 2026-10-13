<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time links e-mailed to company users (13 Oct 2026): "set your password" when Ametecs creates a workspace, and
 * "reset your password" from Forgot password. Only a SHA-256 of the token is stored; a link works once and expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 20);                 // welcome | reset
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_tokens');
    }
};
