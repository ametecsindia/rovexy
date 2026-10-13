<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// ponytail: PHP's built-in dev server (`php artisan serve`) maps /admin/<path> to public/admin/index.html and sets
// SCRIPT_NAME accordingly, which makes Laravel strip "/admin" from the route path. Normalise it (dev server only).
if (PHP_SAPI === 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;
    $_SERVER['PHP_SELF'] = '/index.php';
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
