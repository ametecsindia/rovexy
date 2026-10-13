<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Company admin console feed: IDs and types only; the console re-fetches details through authorised APIs. */
final class AdminFeed implements ShouldBroadcastNow
{
    use BroadcastsSafely;

    public function __construct(public readonly int $companyId, public readonly string $type, public readonly array $data)
    {
    }

    public static function dispatchFor(?int $companyId, string $type, array $data): void
    {
        if ($companyId !== null) {
            self::safely(new self($companyId, $type, $data));
        }
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->companyId}.admin")];
    }

    public function broadcastAs(): string
    {
        return 'feed';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'data' => $this->data, 'at' => now()->toAtomString()];
    }
}
