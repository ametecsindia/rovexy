<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Call feedback (9 Oct 2026): promise / payment details on the outcome, payment slip upload from the app,
 * admin review (verify / reject) and e-mailing of the slip.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('disposition_codes', function (Blueprint $t) {
            $t->string('captures', 12)->default('none');          // none | promise | payment
        });
        Schema::table('call_dispositions', function (Blueprint $t) {
            $t->decimal('amount', 12, 2)->nullable();              // promised (promise) or received (payment)
            $t->string('payment_mode', 20)->nullable();
            $t->string('payment_ref', 64)->nullable();
            $t->date('paid_on')->nullable();
            $t->string('slip_key')->nullable();                    // company_files disk: companies/{cid}/payment-slips/…
            $t->string('slip_mime', 64)->nullable();
            $t->string('slip_name', 150)->nullable();
            $t->string('review_status', 12)->nullable();           // pending | verified | rejected (payments only)
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->string('emailed_to')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->index(['company_id', 'review_status']);
        });

        // Outcome list for collections. Existing codes are kept; only missing ones are added, per company.
        $codes = \App\Support\Outcomes::CODES;
        foreach (DB::table('companies')->pluck('id') as $cid) {
            foreach ($codes as [$code, $label, $fu, $captures]) {
                $row = DB::table('disposition_codes')->where('company_id', $cid)->where('code', $code)->first();
                if ($row) {
                    DB::table('disposition_codes')->where('id', $row->id)->update(['captures' => $captures]);
                } else {
                    DB::table('disposition_codes')->insert(['company_id' => $cid, 'code' => $code, 'label' => $label, 'requires_follow_up' => $fu, 'active' => true, 'captures' => $captures]);
                }
            }
        }

        $pid = DB::table('permissions')->where('key', 'payments.verify')->value('id')
            ?? DB::table('permissions')->insertGetId(['key' => 'payments.verify', 'description' => 'Verify payment slips and e-mail them']);
        foreach (DB::table('roles')->whereIn('key', ['company_admin', 'supervisor'])->pluck('id') as $rid) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $rid, 'permission_id' => $pid]);
        }
    }

    public function down(): void
    {
        Schema::table('call_dispositions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropIndex(['company_id', 'review_status']);
            $t->dropColumn(['amount', 'payment_mode', 'payment_ref', 'paid_on', 'slip_key', 'slip_mime', 'slip_name', 'review_status', 'reviewed_at', 'review_note', 'emailed_to', 'emailed_at']);
        });
        Schema::table('disposition_codes', fn (Blueprint $t) => $t->dropColumn('captures'));
    }
};
