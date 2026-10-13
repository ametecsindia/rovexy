<?php

declare(strict_types=1);

namespace App\Domain\Security;

interface NonceStore
{
    /** Atomically record the nonce; return false if it was already seen within $ttlSeconds. */
    public function remember(string $scopedNonce, int $ttlSeconds): bool;
}
