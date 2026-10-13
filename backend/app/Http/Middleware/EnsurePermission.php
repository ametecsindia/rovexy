<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DomainException;
use Closure;
use Illuminate\Http\Request;

final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        foreach ($permissions as $p) {
            if ($user === null || !$user->hasPermission($p)) {
                throw new DomainException('forbidden', "Missing permission: {$p}");
            }
        }
        return $next($request);
    }
}
