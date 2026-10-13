<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\DomainException;

/**
 * Validates an offline-queued client event. Each event carries a client-generated UUID; the server stores it
 * under UNIQUE(company_id, device_id, client_event_id), so resubmission is a no-op (idempotent sync).
 */
final class QueuedEvent
{
    public const TYPES = ['disposition', 'note', 'status', 'follow_up', 'activity', 'location', 'chat_read'];

    public static function validate(array $e, \DateTimeImmutable $now, \DateTimeImmutable $leaseExpiredAt): array
    {
        $id = (string) ($e['id'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new DomainException('invalid_event_id');
        }
        $type = (string) ($e['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new DomainException('invalid_event_type');
        }
        // ISO-8601 with an explicit zone; fractional seconds allowed (Android's Instant.toString() sends e.g. 05:51:34.123456789Z).
        $raw = (string) ($e['occurred_at'] ?? '');
        preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{1,9})?(Z|[+-]\d\d:\d\d)$/', $raw) || throw new DomainException('invalid_occurred_at');
        $occurred = new \DateTimeImmutable($raw);
        if ($occurred > $now->modify('+5 minutes')) {
            throw new DomainException('event_in_future');
        }
        // Work recorded after the offline lease ran out was not authorised.
        if ($occurred > $leaseExpiredAt) {
            throw new DomainException('event_after_lease_expiry');
        }
        if (!is_array($e['payload'] ?? null)) {
            throw new DomainException('invalid_payload');
        }
        return ['id' => strtolower($id), 'type' => $type, 'occurred_at' => $occurred->setTimezone(new \DateTimeZone('UTC')), 'payload' => $e['payload']];
    }
}
