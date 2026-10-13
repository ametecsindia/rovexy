<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin'); // Sanctum needs same-origin Referer/Origin
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (str_starts_with($request->path(), 'admin') || str_starts_with($request->path(), 'super') || str_starts_with($request->path(), 'accounts') || str_starts_with($request->path(), 'w/')) {
            $ws = config('broadcasting.connections.reverb.options.host');
            $h->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://tile.openstreetmap.org https://*.tile.openstreetmap.org; "
                . "media-src 'self' blob:; connect-src 'self'" . ($ws ? ' ' . (config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss' : 'ws') . "://{$ws}:" . config('broadcasting.connections.reverb.options.port') : '') . "; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        }
        if (str_starts_with($request->path(), 'api/')) {
            $h->set('Cache-Control', 'no-store');
        }
        return $response;
    }
}
