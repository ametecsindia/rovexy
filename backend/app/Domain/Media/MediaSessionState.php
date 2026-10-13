<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\DomainException;

/**
 * Screen / camera session lifecycle. Every session needs explicit employee acceptance plus OS consent
 * (MediaProjection or camera permission). Requests not answered within CONSENT_WINDOW expire and are never
 * executed later (e.g. after reconnection).
 */
final class MediaSessionState
{
    public const CONSENT_WINDOW_SECONDS = 90;
    public const STATES = ['requested', 'accepted', 'active', 'declined', 'expired', 'ended'];
    public const END_REASONS = ['employee_stopped', 'supervisor_stopped', 'timeout', 'revoked', 'connection_failed', 'policy_changed', 'os_consent_denied'];

    /** @param array{state:string, kind:string, requested_at:string, started_at:?string, timeout_minutes:int} $s */
    public static function transition(array $s, string $action, \DateTimeImmutable $now, ?string $reason = null): array
    {
        $s = self::expireIfDue($s, $now);
        $bad = static fn () => throw new DomainException('invalid_media_transition', "Cannot {$action} a session in state {$s['state']}.");
        switch ($action) {
            case 'accept':
                $s['state'] === 'requested' || $bad();
                $s['state'] = 'accepted';
                return $s;
            case 'decline':
                $s['state'] === 'requested' || $bad();
                $s['state'] = 'declined';
                $s['end_reason'] = 'employee_declined';
                return $s;
            case 'start': // OS consent granted and media flowing
                $s['state'] === 'accepted' || $bad();
                $s['state'] = 'active';
                $s['started_at'] = $now->format(DATE_ATOM);
                return $s;
            case 'end':
                in_array($s['state'], ['requested', 'accepted', 'active'], true) || $bad();
                in_array($reason, self::END_REASONS, true) || throw new DomainException('invalid_end_reason');
                $s['state'] = 'ended';
                $s['end_reason'] = $reason;
                $s['ended_at'] = $now->format(DATE_ATOM);
                return $s;
        }
        return $bad();
    }

    public static function expireIfDue(array $s, \DateTimeImmutable $now): array
    {
        $req = new \DateTimeImmutable($s['requested_at']);
        if (in_array($s['state'], ['requested', 'accepted'], true) && $now->getTimestamp() - $req->getTimestamp() > self::CONSENT_WINDOW_SECONDS) {
            $s['state'] = 'expired';
            $s['end_reason'] = 'consent_window_elapsed';
        }
        if ($s['state'] === 'active' && $s['started_at'] !== null) {
            $deadline = (new \DateTimeImmutable($s['started_at']))->modify('+' . $s['timeout_minutes'] . ' minutes');
            if ($now >= $deadline) {
                $s['state'] = 'ended';
                $s['end_reason'] = 'timeout';
                $s['ended_at'] = $deadline->format(DATE_ATOM);
            }
        }
        return $s;
    }
}
