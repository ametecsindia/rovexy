<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Security\NonceStore;
use Illuminate\Support\Facades\Cache;

final class RedisNonceStore implements NonceStore
{
    public function remember(string $scopedNonce, int $ttlSeconds): bool
    {
        // Cache::add is atomic on Redis (SET NX EX).
        return Cache::add('nonce:' . hash('sha256', $scopedNonce), 1, $ttlSeconds);
    }
}
