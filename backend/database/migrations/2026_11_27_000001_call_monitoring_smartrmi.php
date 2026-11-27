<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 27 Nov 2026 — SmartRMI (AI call analysis + angry-call alerts).
 *  rmi_analyses           the SmartRMI engine's result per call (main fields pulled out for lists; the engine's JSON kept as sent)
 *  rmi_alerts             angry-call / risk alerts sent by the engine, acknowledged in the console
 *  permission             rmi.view (SmartRMI pages) — given to company admins and supervisors
 *  (call snooping was built here first and removed the same day: see 2026_11_27_000002)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rmi_analyses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // the agent (copied from the call for reports)
            $t->string('status', 24)->default('done');
            $t->decimal('score', 5, 1)->nullable();               // 0–100
            $t->string('tone', 40)->nullable();
            $t->string('sentiment', 40)->nullable();
            $t->string('aggression', 40)->nullable();             // e.g. none | low | medium | high
            $t->string('compliance_risk', 40)->nullable();        // e.g. low | medium | high
            $t->boolean('angry')->default(false);
            $t->text('summary')->nullable();
            $t->longText('transcript')->nullable();
            $t->json('coaching')->nullable();                     // list of coaching points
            $t->json('results');                                  // the engine's results exactly as sent
            $t->timestamp('analysed_at');
            $t->timestamps();
            $t->index(['company_id', 'analysed_at']);
            $t->index(['company_id', 'user_id', 'analysed_at']);
        });
        Schema::create('rmi_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // the agent
            $t->string('kind', 24)->default('angry_call');        // angry_call | compliance_risk | other
            $t->string('severity', 12)->default('high');
            $t->string('message', 500)->nullable();
            $t->json('details')->nullable();
            $t->unsignedSmallInteger('emailed_to')->default(0);
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'acknowledged_at', 'created_at']);
        });

        $new = ['rmi.view' => 'View SmartRMI call analysis and alerts'];
        foreach ($new as $key => $desc) {
            $pid = DB::table('permissions')->where('key', $key)->value('id') ?? DB::table('permissions')->insertGetId(['key' => $key, 'description' => $desc]);
            foreach (DB::table('roles')->whereIn('key', ['company_admin', 'supervisor'])->pluck('id') as $rid) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['rmi.view'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('rmi_alerts');
        Schema::dropIfExists('rmi_analyses');
    }
};
