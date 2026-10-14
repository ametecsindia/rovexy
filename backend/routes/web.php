<?php

use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

// The admin SPA (React build output copied to public/admin). Served from the company's own origin so cookies,
// CSRF and CSP stay same-origin. The Electron desktop app loads this URL.
Route::get('/admin/{path?}', function () {
    $index = public_path('admin/index.html');
    abort_unless(is_file($index), 404, 'Admin UI not built. Run: cd admin && npm run build');
    return response()->file($index, ['Cache-Control' => 'no-store']);
})->where('path', '.*'); // real files (admin/assets/*) are served by the web server before Laravel

// A company's console by path, for servers without a sub-domain per company (no TENANCY_BASE_DOMAIN): /w/<slug>/admin.
Route::get('/w/{slug}/admin/{path?}', function () {
    return response()->file(public_path('admin/index.html'), ['Cache-Control' => 'no-store']);
})->where(['slug' => '[a-z0-9][a-z0-9-]{1,38}[a-z0-9]', 'path' => '.*']);

// Company users: set a password from the welcome e-mail, or reset a forgotten one.
Route::get('/password-forgot', [\App\Http\Controllers\Site\PasswordController::class, 'forgot']);
Route::post('/password-forgot', [\App\Http\Controllers\Site\PasswordController::class, 'sendReset'])->middleware('throttle:6,1');
Route::get('/password/{token}', [\App\Http\Controllers\Site\PasswordController::class, 'show'])->where('token', '[A-Za-z0-9]{48}');
Route::post('/password/{token}', [\App\Http\Controllers\Site\PasswordController::class, 'store'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1');

// Central server only (not on an on-prem install): Super Admin, Accounts, website, buying and paying.
Route::middleware('central')->group(function () {
    // Super Admin console (Ametecs staff): the same SPA build, which switches to the Super Admin app under /super.
    Route::get('/super/{path?}', function () {
        return response()->file(public_path('admin/index.html'), ['Cache-Control' => 'no-store']);
    })->where('path', '.*');

    // Accountant panel (Ametecs accounts staff): the same SPA build, which switches to the Accounts app under /accounts.
    Route::get('/accounts/{path?}', function () {
        return response()->file(public_path('admin/index.html'), ['Cache-Control' => 'no-store']);
    })->where('path', '.*');

    // Public website: marketing page, free trial, buy → Razorpay, invoices.
    Route::get('/', [SiteController::class, 'home']);
    Route::get('/buy', [SiteController::class, 'buy']);
    // Live demo of the Android app (static page in public/live-demo; sample data, no server calls).
    Route::get('/demo', fn () => response()->file(public_path('live-demo/index.html'), ['Cache-Control' => 'no-cache']));
    Route::middleware('throttle:site')->group(function () {
        Route::post('/enquiry', [SiteController::class, 'enquiry']);
        Route::post('/trial', [SiteController::class, 'trial']);
        Route::post('/trial/verify', [SiteController::class, 'verifyTrial']);
        Route::post('/buy', [SiteController::class, 'placeOrder']);
    });
    Route::post('/price-check', [SiteController::class, 'priceCheck'])->middleware('throttle:site-pay');
    Route::get('/pay/{number}/{token}', [SiteController::class, 'pay']);
    Route::get('/pay/{number}/{token}/invoice', [SiteController::class, 'invoice']);
    Route::post('/pay/{number}/{token}/razorpay', [SiteController::class, 'razorpayOrder'])->middleware('throttle:site-pay');
    Route::post('/pay/{number}/{token}/verify', [SiteController::class, 'razorpayVerify'])->middleware('throttle:site-pay');
    Route::post('/pay/{number}/{token}/failed', [SiteController::class, 'razorpayFailed'])->middleware('throttle:site-pay');
    Route::get('/{page}', [SiteController::class, 'page'])->whereIn('page', ['thank-you', 'privacy', 'terms', 'refund', 'delivery', 'contact']);
    Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /admin\nDisallow: /super\nDisallow: /accounts\nDisallow: /w/\nDisallow: /password\nDisallow: /pay/\nDisallow: /api/\nSitemap: " . url('/sitemap.xml') . "\n", 200, ['Content-Type' => 'text/plain']));
    Route::get('/sitemap.xml', fn () => response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . collect(['/', '/demo', '/buy', '/contact', '/privacy', '/terms', '/refund', '/delivery'])->map(fn ($p) => '<url><loc>' . e(url($p)) . '</loc></url>')->implode('')
        . '</urlset>', 200, ['Content-Type' => 'application/xml']));
});
