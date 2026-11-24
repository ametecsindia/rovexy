<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher daily sync (LeadGER calling reference §2.4, §3.2 step 8, §4.2–4.3):
 *  smart31_history — voucher Smart31 numbers replaced by a new voucher or by the daily list, so the groups the company
 *  gave a Smart31 group are found again when the licence server sends that group without teams (inheritAssignments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart31_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('number', 15);
            $t->string('group_name', 100)->nullable();
            $t->string('type', 20)->nullable();
            $t->json('team_ids')->nullable();
            $t->date('usage_date')->nullable();
            $t->unsignedInteger('calls')->default(0);
            $t->timestamp('replaced_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'group_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart31_history');
    }
};
