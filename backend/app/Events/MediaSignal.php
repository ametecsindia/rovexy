<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MediaSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** WebRTC signalling relay (SDP/ICE) on the session's private channel; only the two parties are authorised. */
final class MediaSignal implements ShouldBroadcastNow
{
    use BroadcastsSafely;

    public function __construct(public readonly int $companyId, public readonly string $sessionUuid, public readonly string $from, public readonly string $type, public readonly array $payload)
    {
    }

    public static function dispatchFor(MediaSession $s, string $from, string $type, array $payload): void
    {
        // Laravel's TrimStrings middleware strips the SDP's final CRLF; browsers then reject the last line ("Invalid SDP line").
        if (isset($payload['sdp']) && is_string($payload['sdp']) && !str_ends_with($payload['sdp'], "\r\n")) {
            $payload['sdp'] = rtrim($payload['sdp']) . "\r\n";
        }
        // ponytail: mailbox in the cache (no migration needed; sessions are short and low-volume). Move to a table if sessions must be audited signal-by-signal.
        $key = "media_signals:{$s->id}";
        \Illuminate\Support\Facades\Cache::lock("$key:lock", 5)->block(3, function () use ($key, $from, $type, $payload) {
            $list = \Illuminate\Support\Facades\Cache::get($key, []);
            $list[] = ['id' => count($list) + 1, 'from' => $from, 'type' => $type, 'payload' => $payload];
            \Illuminate\Support\Facades\Cache::put($key, $list, now()->addHours(2));
        });
        self::safely(new self($s->company_id, $s->uuid, $from, $type, $payload));
    }

    /** Signals from the other side after $after (polling fallback when WebSockets are not running), plus the session state. */
    public static function inbox(MediaSession $s, string $from, int $after): array
    {
        $list = \Illuminate\Support\Facades\Cache::get("media_signals:{$s->id}", []);
        return ['state' => $s->state, 'signals' => array_values(array_filter($list, fn ($m) => $m['from'] === $from && $m['id'] > $after))];
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->companyId}.media.{$this->sessionUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'signal';
    }

    public function broadcastWith(): array
    {
        return ['from' => $this->from, 'type' => $this->type, 'payload' => $this->payload];
    }
}
