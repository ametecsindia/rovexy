<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Support\Facades\Log;

/** Live events are best-effort: a WebSocket outage must never fail the business operation (clients also poll). */
trait BroadcastsSafely
{
    protected static function safely(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Broadcast failed', ['event' => $event::class, 'error' => $e->getMessage()]);
        }
    }
}
