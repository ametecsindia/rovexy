<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\Call;

interface TelephonyAdapter
{
    public function name(): string;

    /**
     * Start a server-originated call. $p contains only server-validated values (see OriginateCommand).
     * Must throw TelephonyUnavailable when the request was not accepted by the telephony system.
     */
    public function originate(Call $call, array $p): void;

    public function hangup(Call $call, string $cause): void;

    /** @return array{ok:bool, detail:string} */
    public function health(): array;
}
