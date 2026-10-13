<?php

namespace Tests\Support;

use App\Models\Call;
use App\Telephony\TelephonyAdapter;
use App\Telephony\TelephonyUnavailable;

/** Test double recording what the server asked telephony to do. Not used outside tests. */
final class FakeTelephony implements TelephonyAdapter
{
    public array $originated = [];
    public array $hungUp = [];
    public bool $down = false;

    public function name(): string
    {
        return 'fake';
    }

    public function originate(Call $call, array $p): void
    {
        if ($this->down) {
            throw new TelephonyUnavailable('down');
        }
        $this->originated[] = $p;
    }

    public function hangup(Call $call, string $cause): void
    {
        $this->hungUp[] = [$call->uuid, $cause];
    }

    public function health(): array
    {
        return ['ok' => true, 'detail' => 'fake'];
    }
}
