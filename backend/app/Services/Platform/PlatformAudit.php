<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;

/** Audit trail for the Super Admin side (staff actions, payments, trials). */
final class PlatformAudit
{
    public static function record(string $action, ?object $subject = null, array $meta = [], ?string $actor = null): void
    {
        $admin = auth('platform')->user();
        if ($actor !== null) {
            $meta['by'] = $actor;
        }
        DB::table('platform_audit')->insert(['admin_id' => $admin?->id, 'action' => $action, 'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject->id ?? null, 'meta' => json_encode($meta), 'ip' => request()?->ip(), 'created_at' => now()]);
    }
}
