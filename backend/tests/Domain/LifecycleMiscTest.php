<?php

declare(strict_types=1);

use App\Domain\DomainException;
use App\Domain\Media\MediaSessionState;
use App\Domain\Retention\RetentionPlanner;
use App\Domain\Sync\OfflineLease;
use App\Domain\Sync\QueuedEvent;
use PHPUnit\Framework\TestCase;

final class LifecycleMiscTest extends TestCase
{
    public function test_offline_lease_expires_as_configured(): void
    {
        $v = new DateTimeImmutable('2026-10-01T06:00:00Z');
        $exp = OfflineLease::expiresAt($v, 240);
        self::assertTrue(OfflineLease::isValid($exp, new DateTimeImmutable('2026-10-01T09:59:59Z')));
        self::assertFalse(OfflineLease::isValid($exp, new DateTimeImmutable('2026-10-01T10:00:00Z')));
        self::assertFalse(OfflineLease::isValid(OfflineLease::expiresAt($v, 0), $v), 'zero lease = no offline access');
    }

    public function test_queued_event_validation(): void
    {
        $now = new DateTimeImmutable('2026-10-01T10:00:00Z');
        $lease = new DateTimeImmutable('2026-10-01T09:00:00Z');
        $ok = QueuedEvent::validate(['id' => 'A3B1C2D4-1111-4222-8333-444455556666', 'type' => 'disposition', 'occurred_at' => '2026-10-01T08:30:00+00:00', 'payload' => ['x' => 1]], $now, $lease);
        // Android sends Instant.toString(): fractional seconds + Z. Must be accepted (it was silently rejected before).
        $this->assertSame('2026-10-01T08:30:00+00:00', QueuedEvent::validate(['id' => 'a3b1c2d4-1111-4222-8333-444455556667', 'type' => 'location', 'occurred_at' => '2026-10-01T08:30:00.123456789Z', 'payload' => []], $now, $lease)['occurred_at']->format(DATE_ATOM));
        self::assertSame('a3b1c2d4-1111-4222-8333-444455556666', $ok['id']);
        $this->expectExceptionObject(new DomainException('event_after_lease_expiry'));
        QueuedEvent::validate(['id' => 'a3b1c2d4-1111-4222-8333-444455556666', 'type' => 'note', 'occurred_at' => '2026-10-01T09:30:00+00:00', 'payload' => []], $now, $lease);
    }

    private function s(): array
    {
        return ['state' => 'requested', 'kind' => 'screen', 'requested_at' => '2026-10-01T10:00:00Z', 'started_at' => null, 'timeout_minutes' => 10];
    }

    public function test_media_session_requires_acceptance_and_expires(): void
    {
        $t = static fn (string $s) => new DateTimeImmutable($s);
        try {
            MediaSessionState::transition($this->s(), 'start', $t('2026-10-01T10:00:10Z'));
            self::fail('started without acceptance');
        } catch (DomainException $e) {
            self::assertSame('invalid_media_transition', $e->reason);
        }
        $a = MediaSessionState::transition($this->s(), 'accept', $t('2026-10-01T10:00:10Z'));
        $active = MediaSessionState::transition($a, 'start', $t('2026-10-01T10:00:20Z'));
        self::assertSame('active', $active['state']);
        self::assertSame('timeout', MediaSessionState::expireIfDue($active, $t('2026-10-01T10:10:20Z'))['end_reason']);
        self::assertSame('revoked', MediaSessionState::transition($active, 'end', $t('2026-10-01T10:01:00Z'), 'revoked')['end_reason']);
        // Stale request after reconnection is expired, never executed.
        self::assertSame('expired', MediaSessionState::expireIfDue($this->s(), $t('2026-10-01T10:05:00Z'))['state']);
        $this->expectException(DomainException::class);
        MediaSessionState::transition($this->s(), 'accept', $t('2026-10-01T10:05:00Z'));
    }

    public function test_retention_planning(): void
    {
        self::assertSame(129_600_000_000, RetentionPlanner::estimateBytes(50, 120, 3.0, 30)); // 50 agents × 120 calls × 3 min × 30 days ≈ 130 GB at 32 kbit/s
        $plan = RetentionPlanner::scope([
            ['id' => 1, 'expires_at' => '2026-01-01T00:00:00Z', 'legal_hold' => false, 'size' => 100],
            ['id' => 2, 'expires_at' => '2026-01-01T00:00:00Z', 'legal_hold' => true, 'size' => 100],
            ['id' => 3, 'expires_at' => '2027-01-01T00:00:00Z', 'legal_hold' => false, 'size' => 100],
        ], new DateTimeImmutable('2026-10-01T00:00:00Z'));
        self::assertSame(['delete_ids' => [1], 'bytes' => 100, 'held' => 1], $plan);
    }
}
