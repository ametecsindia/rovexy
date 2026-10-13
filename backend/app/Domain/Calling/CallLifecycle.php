<?php

declare(strict_types=1);

namespace App\Domain\Calling;

/**
 * Applies telephony lifecycle events to a call snapshot. Pure and idempotent:
 * re-applying the same event, or applying an older event after a terminal one, changes nothing.
 * Event de-duplication by provider event ID happens at the DB unique index; this protects semantics.
 */
final class CallLifecycle
{
    public const TERMINAL = ['agent_unanswered', 'completed', 'disconnected', 'busy', 'no_answer', 'rejected', 'failed', 'cancelled'];
    private const ORDER = ['initiated' => 0, 'agent_ringing' => 1, 'agent_connected' => 2, 'ringing' => 3, 'answered' => 4];

    /**
     * @param array{state:string, initiated_at:string, agent_answered_at:?string, ringing_at:?string, answered_at:?string, ended_at:?string, hangup_cause:?string} $call
     * @param array{type:string, at:string, cause?:string} $event  type: agent_ringing|agent_answered|customer_ringing|customer_answered|hangup|failed
     */
    public static function apply(array $call, array $event): array
    {
        if (in_array($call['state'], self::TERMINAL, true)) {
            return $call;
        }
        $at = $event['at'];
        switch ($event['type']) {
            case 'agent_ringing':
                return self::advance($call, 'agent_ringing');
            case 'agent_answered':
                $call['agent_answered_at'] ??= $at;
                return self::advance($call, 'agent_connected');
            case 'customer_ringing':
                $call['ringing_at'] ??= $at;
                return self::advance($call, 'ringing');
            case 'customer_answered':
                $call['ringing_at'] ??= $at;
                $call['answered_at'] ??= $at;
                return self::advance($call, 'answered');
            case 'hangup':
            case 'failed':
                $call['ended_at'] = $at;
                $call['hangup_cause'] = $event['cause'] ?? ($event['type'] === 'failed' ? 'FAILED' : 'NORMAL_CLEARING');
                $call['state'] = self::outcome($call, $call['hangup_cause'], $event['type'] === 'failed');
                return $call;
        }
        return $call;
    }

    private static function advance(array $call, string $to): array
    {
        if ((self::ORDER[$to] ?? -1) > (self::ORDER[$call['state']] ?? -1)) {
            $call['state'] = $to;
        }
        return $call;
    }

    public static function outcome(array $call, string $cause, bool $failed): string
    {
        $cause = strtoupper($cause);
        if ($call['answered_at'] !== null) {
            return in_array($cause, ['NORMAL_CLEARING', 'NORMAL_UNSPECIFIED', 'ORIGINATOR_CANCEL', 'MANAGER_REQUEST', 'ALLOTTED_TIMEOUT'], true)
                ? 'completed' : 'disconnected';
        }
        if ($failed) {
            return 'failed';
        }
        if (($call['agent_answered_at'] ?? null) === null && in_array($call['state'], ['initiated', 'agent_ringing'], true)) {
            return 'agent_unanswered'; // the employee's leg never connected, the customer was not dialled
        }
        return match ($cause) {
            'USER_BUSY' => 'busy',
            'NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT', 'PROGRESS_TIMEOUT' => 'no_answer',
            'CALL_REJECTED' => 'rejected',
            'ORIGINATOR_CANCEL', 'NORMAL_CLEARING', 'LOSE_RACE' => 'cancelled',
            default => 'failed',
        };
    }

    /** @return array{ring_seconds:?int, talk_seconds:int, total_seconds:?int} */
    public static function durations(array $call): array
    {
        $ts = static fn (?string $s) => $s === null ? null : (new \DateTimeImmutable($s))->getTimestamp();
        $init = $ts($call['initiated_at']);
        $ring = $ts($call['ringing_at']);
        $ans = $ts($call['answered_at']);
        $end = $ts($call['ended_at']);
        return [
            'ring_seconds' => $ring === null ? null : max(0, ($ans ?? $end ?? $ring) - $ring),
            'talk_seconds' => ($ans !== null && $end !== null) ? max(0, $end - $ans) : 0,
            'total_seconds' => $end === null ? null : max(0, $end - $init),
        ];
    }
}
