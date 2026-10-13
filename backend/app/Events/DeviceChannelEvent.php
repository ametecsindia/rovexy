<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ManagedDevice;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class DeviceChannelEvent implements ShouldBroadcastNow
{
    use BroadcastsSafely;

    public function __construct(public readonly int $companyId, public readonly int $deviceId, public readonly string $type, public readonly array $data)
    {
    }

    public static function dispatchFor(ManagedDevice $d, string $type, array $data): void
    {
        self::safely(new self($d->company_id, $d->id, $type, $data));
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->companyId}.device.{$this->deviceId}")];
    }

    public function broadcastAs(): string
    {
        return 'device';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'data' => $this->data, 'at' => now()->toAtomString()];
    }
}
