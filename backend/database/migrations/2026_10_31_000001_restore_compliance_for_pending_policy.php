<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A policy change used to flip every phone to "not compliant" until it reported back, so phones that were off or
 * offline showed as non-compliant. Restore the last reported verdict for those phones (app-only; AMAPI keeps its own).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('managed_devices')
            ->where('approval_state', 'approved')->where('policy_state', 'pending')->where('compliant', false)
            ->where('management_mode', '!=', 'amapi')
            ->whereNotNull('applied_policy_fingerprint')
            ->whereColumn('applied_policy_fingerprint', 'last_good_policy_fingerprint')
            ->update(['compliant' => true]);
    }

    public function down(): void {}
};
