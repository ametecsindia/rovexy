<?php

use App\Domain\DomainException;
use App\Http\Middleware\AuthenticateDeviceSession;
use App\Http\Middleware\AuthenticateWorkSession;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RequireCompliance;
use App\Http\Middleware\ResolveCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyDeviceSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'company', 'auth.any']])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi(); // Sanctum SPA cookies + CSRF for /api/admin
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'company' => ResolveCompany::class,
            'device.signed' => VerifyDeviceSignature::class,
            'device.session' => AuthenticateDeviceSession::class,
            'work.session' => AuthenticateWorkSession::class,
            'work.compliant' => RequireCompliance::class,
            'mfa' => EnsureMfaVerified::class,
            'perm' => EnsurePermission::class,
            'auth.any' => \App\Http\Middleware\AuthenticateAnyPrincipal::class,
            'platform' => \App\Http\Middleware\PlatformAccess::class,
            'feature' => \App\Http\Middleware\RequireFeature::class,
            'central' => \App\Http\Middleware\CentralOnly::class,
        ]);
        // Company resolution must precede authentication (users are tenant-scoped), and device/work-session
        // authentication must precede rate limiting so limits are keyed per device/user rather than shared.
        $middleware->prependToPriorityList(before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, prepend: ResolveCompany::class);
        foreach ([VerifyDeviceSignature::class, AuthenticateDeviceSession::class, AuthenticateWorkSession::class] as $mw) {
            $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\ThrottleRequests::class, prepend: $mw);
        }
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', env('TRUSTED_PROXIES')) : []);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (DomainException $e, Request $request) {
            $status = match (true) {
                str_starts_with($e->reason, 'company_') => 404,
                in_array($e->reason, ['maps_unavailable', 'maps_not_configured'], true) => 503,
                str_starts_with($e->reason, 'signature_'), $e->reason === 'unauthenticated' => 401,
                in_array($e->reason, ['device_not_approved', 'device_revoked', 'not_assigned', 'forbidden', 'device_not_compliant', 'lease_expired', 'licence_inactive'], true) => 403,
                default => 422,
            };
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $status);
        });
        $exceptions->dontFlash(['password', 'password_confirmation', 'totp', 'current_password']);
    })
    ->create();
