<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Security\LogRedactor;
use Illuminate\Support\Facades\DB;

/** Append-only audit trail for privileged actions. */
final class Audit
{
    public static function record(string $action, ?object $subject = null, array $meta = [], ?array $actor = null): void
    {
        $request = request();
        $ctx = app(CurrentCompany::class);
        $actor ??= self::actor();
        DB::table('audit_events')->insert([
            'company_id' => $ctx->id(),
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject->id ?? null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'meta' => json_encode(LogRedactor::redact($meta)),
            'created_at' => now(),
        ]);
    }

    private static function actor(): array
    {
        $req = request();
        if ($req && ($u = $req->user())) {
            return ['type' => 'user', 'id' => $u->id];
        }
        if ($req && ($d = $req->attributes->get('device'))) {
            return ['type' => 'device', 'id' => $d->id];
        }
        return ['type' => 'system', 'id' => null];
    }
}
