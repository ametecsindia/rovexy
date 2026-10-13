<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Licensing\LicenceClient;
use Closure;
use Illuminate\Http\Request;

/** Super Admin, Accounts, website, payments and the licence server exist only on the central server, not on an on-prem install. */
final class CentralOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (LicenceClient::isClient()) {
            return $request->isMethod('GET') && $request->path() === '/' ? redirect('/admin') : abort(404);
        }
        return $next($request);
    }
}
