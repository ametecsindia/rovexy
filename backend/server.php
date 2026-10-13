<?php

/*
| Router for `php artisan serve` (Laravel uses base_path('server.php') when it exists).
| PHP's built-in server sends public files with no caching headers, so every admin refresh re-downloaded the
| ~800 KB admin bundle and the phone's map page re-fetched everything on each open. Here:
|  - Vite's hashed bundles (admin/assets/*) and versioned vendor scripts: cached for a year (a new build = new names).
|  - Other public files (field-map.html, …): no-cache + ETag, so each load is a tiny 304 check while unchanged.
| Behind Apache / nginx this file is not used; their own ETag / Last-Modified handling does the same job.
*/

$publicPath = getcwd();
$uri = urldecode((string) parse_url('http://x' . $_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $publicPath . $uri;
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'json' => 'application/json'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

if ($uri !== '/' && !str_contains($uri, '..') && isset($types[$ext]) && is_file($file)) {
    $etag = '"' . dechex(filemtime($file)) . '-' . dechex(filesize($file)) . '"';
    header('Content-Type: ' . $types[$ext]);
    header('ETag: ' . $etag);
    header(preg_match('#^/(admin/assets|vendor)/#', $uri) ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: no-cache');
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        return true;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}
if ($uri !== '/' && file_exists($file)) {
    return false;   // other real files: PHP's own static handling, as before
}

require_once $publicPath . '/index.php';
