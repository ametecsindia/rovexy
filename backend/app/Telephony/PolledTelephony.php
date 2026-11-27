<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\Call;

/**
 * Cloud click-to-call providers (Exotel, Click2Call): the provider rings the employee's phone first, then the customer,
 * records on its side, and the result is READ BACK from the provider (poll / callback trigger / scheduler).
 */
interface PolledTelephony extends TelephonyAdapter
{
    /** Call.provider values handled this way (used by the poll queries). */
    public const PROVIDERS = ['exotel', 'click2call'];

    /**
     * Current state of a call from the provider, normalised. Null when the provider cannot be asked right now.
     * @return array{terminal:bool,state:string,talk:int,ring:?int,started:?string,ended:?string,recording_url:?string,cause:string}|null
     */
    public function details(Call $call): ?array;

    /** Recording bytes from a provider URL; null when not (yet) available or not a trusted source. */
    public function fetchRecording(string $url): ?string;

    /** Number the employee's phone is rung from (the app auto-answers it); null when unknown. */
    public function callbackNumber(): ?string;
}
