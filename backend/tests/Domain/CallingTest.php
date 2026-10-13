<?php

declare(strict_types=1);

use App\Domain\Calling\CallAuthoriser;
use App\Domain\Calling\CallingHours;
use App\Domain\Calling\CallLifecycle;
use App\Domain\Calling\RecordingState;
use App\Domain\DomainException;
use App\Domain\Policy\PolicyResolver;
use PHPUnit\Framework\TestCase;

final class CallingTest extends TestCase
{
    private const TZ = 'Asia/Kolkata';

    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function test_calling_hours_in_company_timezone(): void
    {
        $h = ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '19:00'];
        // Thu 2026-10-01 03:30Z = 09:00 IST
        self::assertTrue(CallingHours::isOpen($h, self::TZ, $this->utc('2026-10-01 03:30:00')));
        self::assertFalse(CallingHours::isOpen($h, self::TZ, $this->utc('2026-10-01 03:29:00')));
        self::assertFalse(CallingHours::isOpen($h, self::TZ, $this->utc('2026-10-01 13:30:00'))); // 19:00 IST, end exclusive
        self::assertFalse(CallingHours::isOpen($h, self::TZ, $this->utc('2026-10-03 05:00:00'))); // Saturday
        $night = ['days' => [5], 'start' => '22:00', 'end' => '02:00'];
        self::assertTrue(CallingHours::isOpen($night, self::TZ, $this->utc('2026-10-02 19:00:00')));  // Sat 00:30 IST, inside Friday's overnight window
        self::assertFalse(CallingHours::isOpen($night, self::TZ, $this->utc('2026-10-01 19:00:00'))); // Fri 00:30 IST, belongs to Thursday's window (not allowed)
    }

    private function ctx(array $o = []): array
    {
        return $o + ['user_id' => 10, 'team_ids' => [3], 'compliant' => true, 'active_call' => false, 'timezone' => self::TZ];
    }

    private function cust(array $o = []): array
    {
        return $o + ['id' => 1, 'phone' => '+919876543210', 'alt_phone' => '+919000000009', 'status' => 'new', 'assigned_user_id' => 10, 'assigned_team_id' => null];
    }

    public function test_authorisation_uses_server_record_and_resists_tampering(): void
    {
        $p = PolicyResolver::effective([])['values'];
        $now = $this->utc('2026-10-01 06:00:00');
        self::assertSame('+919876543210', CallAuthoriser::authorise($p, $this->cust(), null, 'primary', $this->ctx(), $now)['destination']);
        self::assertSame('+919000000009', CallAuthoriser::authorise($p, $this->cust(), null, 'alternate', $this->ctx(), $now)['destination']);
        self::assertSame('+919876543210', CallAuthoriser::authorise($p, $this->cust(['assigned_user_id' => null, 'assigned_team_id' => 3]), null, 'primary', $this->ctx(), $now)['destination']);

        $cases = [
            'destination_mismatch' => fn () => CallAuthoriser::authorise($p, $this->cust(), '+911111111111', 'primary', $this->ctx(), $now),
            'customer_not_assigned' => fn () => CallAuthoriser::authorise($p, $this->cust(['assigned_user_id' => 99]), null, 'primary', $this->ctx(), $now),
            'manual_dial_disabled' => fn () => CallAuthoriser::authorise($p, null, '+919999999999', 'primary', $this->ctx(), $now),
            'outside_calling_hours' => fn () => CallAuthoriser::authorise($p, $this->cust(), null, 'primary', $this->ctx(), $this->utc('2026-10-01 20:00:00')),
            'device_not_compliant' => fn () => CallAuthoriser::authorise($p, $this->cust(), null, 'primary', $this->ctx(['compliant' => false]), $now),
            'call_in_progress' => fn () => CallAuthoriser::authorise($p, $this->cust(), null, 'primary', $this->ctx(['active_call' => true]), $now),
            'customer_not_callable' => fn () => CallAuthoriser::authorise($p, $this->cust(['status' => 'do_not_call']), null, 'primary', $this->ctx(), $now),
            'destination_not_allowed' => fn () => CallAuthoriser::authorise($p, $this->cust(['phone' => '+971501234567']), null, 'primary', $this->ctx(), $now),
        ];
        foreach ($cases as $code => $fn) {
            try {
                $fn();
                self::fail("{$code} not enforced");
            } catch (DomainException $e) {
                self::assertSame($code, $e->reason);
            }
        }
        $manual = PolicyResolver::effective(['calling.manual_dial' => true])['values'];
        self::assertSame('+919999999999', CallAuthoriser::authorise($manual, null, '+919999999999', 'primary', $this->ctx(), $now)['destination']);
    }

    private function call(): array
    {
        return ['state' => 'initiated', 'initiated_at' => '2026-10-01T06:00:00Z', 'agent_answered_at' => null, 'ringing_at' => null, 'answered_at' => null, 'ended_at' => null, 'hangup_cause' => null];
    }

    public function test_lifecycle_answered_call_durations_and_idempotency(): void
    {
        $c = $this->call();
        foreach ([
            ['type' => 'agent_ringing', 'at' => '2026-10-01T06:00:02Z'],
            ['type' => 'agent_answered', 'at' => '2026-10-01T06:00:05Z'],
            ['type' => 'customer_ringing', 'at' => '2026-10-01T06:00:06Z'],
            ['type' => 'customer_answered', 'at' => '2026-10-01T06:00:16Z'],
            ['type' => 'customer_ringing', 'at' => '2026-10-01T06:00:20Z'], // late/duplicate: no regression
            ['type' => 'hangup', 'at' => '2026-10-01T06:02:16Z', 'cause' => 'NORMAL_CLEARING'],
        ] as $e) {
            $c = CallLifecycle::apply($c, $e);
        }
        self::assertSame('completed', $c['state']);
        $again = CallLifecycle::apply($c, ['type' => 'hangup', 'at' => '2026-10-01T06:05:00Z', 'cause' => 'USER_BUSY']);
        self::assertSame($c, $again, 'events after terminal state are no-ops');
        self::assertSame(['ring_seconds' => 10, 'talk_seconds' => 120, 'total_seconds' => 136], CallLifecycle::durations($c));
    }

    public function test_lifecycle_outcomes(): void
    {
        $connected = CallLifecycle::apply($this->call(), ['type' => 'agent_answered', 'at' => '2026-10-01T06:00:05Z']);
        $ringing = CallLifecycle::apply($connected, ['type' => 'customer_ringing', 'at' => '2026-10-01T06:00:06Z']);
        self::assertSame('busy', CallLifecycle::apply($ringing, ['type' => 'hangup', 'at' => '2026-10-01T06:00:09Z', 'cause' => 'USER_BUSY'])['state']);
        self::assertSame('no_answer', CallLifecycle::apply($ringing, ['type' => 'hangup', 'at' => '2026-10-01T06:00:40Z', 'cause' => 'NO_ANSWER'])['state']);
        self::assertSame('rejected', CallLifecycle::apply($ringing, ['type' => 'hangup', 'at' => '2026-10-01T06:00:09Z', 'cause' => 'CALL_REJECTED'])['state']);
        self::assertSame('failed', CallLifecycle::apply($ringing, ['type' => 'hangup', 'at' => '2026-10-01T06:00:09Z', 'cause' => 'UNALLOCATED_NUMBER'])['state']);
        self::assertSame('agent_unanswered', CallLifecycle::apply($this->call(), ['type' => 'hangup', 'at' => '2026-10-01T06:00:40Z', 'cause' => 'NO_ANSWER'])['state']);
        $ans = CallLifecycle::apply($ringing, ['type' => 'customer_answered', 'at' => '2026-10-01T06:00:10Z']);
        self::assertSame('disconnected', CallLifecycle::apply($ans, ['type' => 'hangup', 'at' => '2026-10-01T06:00:50Z', 'cause' => 'MEDIA_TIMEOUT'])['state']);
        $r = CallLifecycle::durations(CallLifecycle::apply($ringing, ['type' => 'hangup', 'at' => '2026-10-01T06:00:40Z', 'cause' => 'NO_ANSWER']));
        self::assertSame(['ring_seconds' => 34, 'talk_seconds' => 0, 'total_seconds' => 40], $r);
    }

    public function test_recording_states_do_not_follow_call_success(): void
    {
        self::assertSame(RecordingState::Processing, RecordingState::Recording->to(RecordingState::Processing));
        self::assertSame(RecordingState::Failed, RecordingState::Recording->to(RecordingState::Failed));
        self::assertSame(RecordingState::Processing, RecordingState::Failed->to(RecordingState::Processing), 'recovery path');
        self::assertSame(RecordingState::Available, RecordingState::Available->to(RecordingState::Available), 'idempotent');
        $this->expectException(DomainException::class);
        RecordingState::Pending->to(RecordingState::Available);
    }

    public function test_mandatory_recording_failure_action(): void
    {
        self::assertSame('terminate', RecordingState::failureAction('mandatory', 'terminate'));
        self::assertSame('flag', RecordingState::failureAction('mandatory', 'continue_flagged'));
        self::assertSame('flag', RecordingState::failureAction('optional', 'terminate'));
        self::assertSame('ignore', RecordingState::failureAction('off', 'terminate'));
    }
}
