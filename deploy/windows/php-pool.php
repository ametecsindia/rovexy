<?php

declare(strict_types=1);

/*
 * ROVEXY-PHP service: keeps one php-cgi FastCGI worker per port running (Windows php-cgi serves one request at a time
 * and has no worker manager of its own). The web server (Caddy) spreads requests over the ports.
 *   php php-pool.php 9001,9002,9003,9004
 * When the service stops, WinSW ends this script and its php-cgi children together.
 */

$ports = array_map('intval', explode(',', $argv[1] ?? '9001'));
$cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
if (!is_file($cgi)) {
    $cgi = trim((string) shell_exec('command -v php-cgi'));   // Linux test runs
}
$env = getenv() + ['PHP_FCGI_MAX_REQUESTS' => '1000'];   // each worker restarts after 1000 requests (keeps memory in check)
$workers = [];
while (true) {
    foreach ($ports as $port) {
        if (!isset($workers[$port]) || !proc_get_status($workers[$port])['running']) {
            if (isset($workers[$port])) {
                proc_close($workers[$port]);
            }
            $workers[$port] = proc_open([$cgi, '-b', "127.0.0.1:{$port}"], [], $pipes, null, $env);
        }
    }
    sleep(1);
}
