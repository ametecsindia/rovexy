<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class ConversationEvent implements ShouldBroadcastNow
{
    use BroadcastsSafely;

    public function __construct(public readonly int $companyId, public readonly int $conversationId, public readonly string $type, public readonly array $data)
    {
    }

    public static function dispatchFor(int $companyId, int $conversationId, string $type, array $data): void
    {
        self::safely(new self($companyId, $conversationId, $type, $data));
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->companyId}.conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'conversation';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }
}
