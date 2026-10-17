<?php

declare(strict_types=1);

/*
 * ROVEXY Windows installer helper. setup.exe runs it with the bundled PHP; nobody needs to run it by hand.
 *
 *   php configure.php check     <job>          wizard checks (server name, ports, certificate, database); messages -> <job>.out
 *   php configure.php phpini    <app>          write php\php.ini (run first, before install)
 *   php configure.php install   <app> [<job>]  settings, database, services, firewall, health check -> logs\install-result.txt
 *   php configure.php licence   <app> [<lic>]  import a .lic with the app's own `rovexy:licence` command -> logs\licence.txt
 *   php configure.php backup    <app>          before an upgrade: dump the database to backups\ (exit 3 = no mysqldump found)
 *   php configure.php health    <app>          check the web server and its certificate -> logs\install-result.txt
 *   php configure.php uninstall <app>          remove the services and the firewall rule (files, .env, data, database stay)
 *
 * <job> is a key=value file that setup.exe writes in its private temp folder. It can hold passwords, so it is deleted as
 * soon as it is read and its secret values are masked in every log line. Nothing here decides about the licence: the
 * .lic file is checked and activated only by the app (LicenceClient via `rovexy:licence import`).
 */

const WIN = PHP_OS_FAMILY === 'Windows';
const SECRET_KEYS = ['dbpass', 'adminpass', 'certpass'];
// ponytail: 4 php-cgi workers serve the console and phones; raise for ~50+ people using the console at the same time
const FASTCGI_PORTS = [9001, 9002, 9003, 9004];
const HEALTH_PORT = 8089;   // local-only http site, so the app can be checked before the certificate exists
const FIREWALL_RULE = 'ROVEXY Web';
// "No domain name" choice: ROVEXY only on this computer, own local certificate, a port that Laragon / IIS do not use
const LOCAL_PORT = 8443;
const LOCAL_URL = 'https://localhost:8443';

$secrets = [];
$logFile = null;
$results = [];

function say(string $m): void
{
    global $secrets, $logFile;
    $m = $secrets ? str_replace($secrets, '****', $m) : $m;
    echo $m, PHP_EOL;
    if ($logFile) {
        @file_put_contents($logFile, date('Y-m-d H:i:s  ') . $m . PHP_EOL, FILE_APPEND);
    }
}

/** A line for the installer's last page: OK / WARN / ERROR. */
function result(string $level, string $m): void
{
    global $results;
    $results[] = "{$level}: {$m}";
    say("{$level}: {$m}");
}

/** The wizard shows these files in plain Windows text boxes: keep them ASCII. */
function ascii(string $s): string
{
    $s = strtr($s, ['—' => '-', '–' => '-', '→' => '->', '…' => '...', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '·' => '-']);
    return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $s);
}

function readJob(?string $file): array
{
    global $secrets;
    if ($file === null || !is_file($file)) {
        return [];
    }
    $job = [];
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));
    @unlink($file);
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if (($p = strpos($line, '=')) !== false) {
            $job[substr($line, 0, $p)] = substr($line, $p + 1);
        }
    }
    foreach (SECRET_KEYS as $k) {
        if (strlen($job[$k] ?? '') >= 3) {
            $secrets[] = $job[$k];
        }
    }
    return $job;
}

/** Runs a program (no shell), returns [exit code, stdout+stderr]. */
function run(array $cmd, ?string $cwd = null, array $env = []): array
{
    $p = @proc_open($cmd, [0 => ['file', WIN ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd,
        $env ? array_merge(getenv(), $env) : null);
    if (!is_resource($p)) {
        return [-1, 'could not start ' . $cmd[0]];
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), trim($out)];
}

function native(string $path): string
{
    return WIN ? str_replace('/', '\\', $path) : $path;
}

function phpExe(string $app): string
{
    return is_file("{$app}/php/php.exe") ? native("{$app}/php/php.exe") : PHP_BINARY;
}

/** SYSTEM and Administrators only (the services run as SYSTEM). */
function restrict(string $path): void
{
    if (WIN) {
        $f = is_dir($path) ? '(OI)(CI)F' : 'F';
        run(['icacls', native($path), '/inheritance:r', '/grant:r', "*S-1-5-18:{$f}", "*S-1-5-32-544:{$f}"]);
    } else {
        @chmod($path, is_dir($path) ? 0700 : 0600);
    }
}

// ── .env ───────────────────────────────────────────────────────────────────────────────────────────────────────────

function envRead(string $path): array
{
    $v = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $l, $m)) {
            continue;
        }
        $s = $m[2];
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $s, $q)) {
            $s = preg_replace('/\\\\(.)/', '$1', $q[1]);
        } elseif (preg_match("/^'([^']*)'/", $s, $q)) {
            $s = $q[1];
        } else {
            $s = trim((string) preg_replace('/\s+#.*$/', '', $s));
        }
        $v[$m[1]] = $s;
    }
    return $v;
}

function envQuote(string $v): string
{
    return preg_match('#^[A-Za-z0-9_./:@+\-]*$#', $v) ? $v : '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v) . '"';
}

function envSet(string $env, string $key, string $value): string
{
    $line = $key . '=' . envQuote($value);
    return preg_match("/^{$key}=.*$/m", $env)
        ? (string) preg_replace_callback("/^{$key}=.*$/m", fn () => $line, $env, 1)
        : rtrim($env) . "\n{$line}\n";
}

function findTool(string $tool): ?string
{
    $exe = WIN ? "{$tool}.exe" : $tool;
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
        if ($d !== '' && is_file("{$d}/{$exe}")) {
            return native("{$d}/{$exe}");
        }
    }
    $globs = [];
    foreach (array_unique([getenv('ProgramFiles') ?: 'C:/Program Files', getenv('ProgramFiles(x86)') ?: 'C:/Program Files (x86)']) as $pf) {
        $pf = str_replace('\\', '/', $pf);
        array_push($globs, "{$pf}/MySQL/*/bin/{$exe}", "{$pf}/MariaDB*/bin/{$exe}");
    }
    array_push($globs, "C:/xampp/mysql/bin/{$exe}", "C:/laragon/bin/mysql/*/bin/{$exe}", "C:/wamp64/bin/mysql/*/bin/{$exe}", "/usr/bin/{$tool}");
    foreach ($globs as $g) {
        $hits = glob($g) ?: [];
        rsort($hits);   // newest version folder first
        if ($hits) {
            return native($hits[0]);
        }
    }
    return null;
}

// ── wizard checks ──────────────────────────────────────────────────────────────────────────────────────────────────

function portFree(int $port): bool
{
    $s = @stream_socket_server("tcp://0.0.0.0:{$port}", $no, $msg, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        stream_context_create(['socket' => ['so_reuseaddr' => false]]));
    $s && fclose($s);
    return $s !== false;
}

function checkServer(array $job, array &$e, array &$w): void
{
    if (($job['tls'] ?? '') === 'local') {
        if (!portFree(LOCAL_PORT)) {
            $e[] = 'Port ' . LOCAL_PORT . ' is already used by another program on this computer. Close that program, then click Next again.';
        }
        return;
    }
    $d = strtolower(trim($job['domain'] ?? ''));
    if (!preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $d)) {
        $e[] = 'Enter this server\'s internet name, for example rovexy.yourcompany.com (not an IP address, not a name without a dot). '
            . 'The phones only connect over https with a certificate for that name.';
    } elseif (!@gethostbynamel($d)) {
        $w[] = "{$d} is not in DNS yet. Phones and the certificate need a DNS record (A) that points {$d} to this server's IP address.";
    }
    if (($job['tls'] ?? 'le') === 'le' && ($job['email'] ?? '') !== '' && !filter_var($job['email'], FILTER_VALIDATE_EMAIL)) {
        $e[] = 'The e-mail for certificate notices is not valid (or leave it empty).';
    }
    foreach ([80, 443] as $port) {
        if (!portFree($port)) {
            $e[] = "Port {$port} is already used by another program on this server (often IIS - \"World Wide Web Publishing Service\" -, "
                . 'Apache, nginx, Laragon or another web server). Stop that program, or go Back and choose "No domain name - only on '
                . 'this computer", then click Next again.';
        }
    }
}

/** Reads the client's own certificate (.pfx/.p12, or .crt/.pem + .key). Returns [certificate chain PEM, key PEM] or nulls. */
function loadCert(array $job, array &$e, array &$w): array
{
    $file = $job['cert'] ?? '';
    $pass = $job['certpass'] ?? '';
    $domain = strtolower(trim($job['domain'] ?? ''));
    if (!is_file($file)) {
        $e[] = 'Choose the certificate file (.pfx, or .crt / .pem).';
        return [null, null];
    }
    $data = (string) file_get_contents($file);
    if (preg_match('/\.(pfx|p12)$/i', $file)) {
        if (!openssl_pkcs12_read($data, $p12, $pass)) {
            $e[] = 'The .pfx file could not be opened. Is the password right?';
            return [null, null];
        }
        [$leaf, $keyPem, $chain] = [$p12['cert'], $p12['pkey'], $p12['extracerts'] ?? []];
    } else {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $data, $m);
        $certs = $m[0];
        $keyFile = $job['key'] ?? '';
        $keyPem = is_file($keyFile) ? (string) file_get_contents($keyFile) : (str_contains($data, 'PRIVATE KEY') ? $data : '');
        if (!$certs) {
            $e[] = 'The certificate file has no certificate in it (PEM text starting with -----BEGIN CERTIFICATE-----).';
            return [null, null];
        }
        if ($keyPem === '') {
            $e[] = 'Choose the private key file (.key) that belongs to the certificate.';
            return [null, null];
        }
        $leaf = array_shift($certs);
        $chain = $certs;
    }
    $key = @openssl_pkey_get_private($keyPem, $pass);
    $x = @openssl_x509_parse($leaf);
    if (!$x) {
        $e[] = 'The certificate could not be read.';
        return [null, null];
    }
    if (!$key) {
        $e[] = 'The private key could not be read (password protected? enter the password).';
        return [null, null];
    }
    if (!openssl_x509_check_private_key($leaf, $key)) {
        $e[] = 'The private key does not belong to this certificate.';
    }
    $until = (int) $x['validTo_time_t'];
    if ($until < time()) {
        $e[] = 'The certificate expired on ' . date('Y-m-d', $until) . '.';
    } elseif ($until < time() + 30 * 86400) {
        $w[] = 'The certificate expires on ' . date('Y-m-d', $until) . '. Renew it soon (then run setup again to install the new one).';
    }
    if (($x['issuer'] ?? null) == ($x['subject'] ?? null)) {
        $e[] = 'This certificate is self-signed. The ROVEXY phone app only accepts certificates from a public certificate authority - '
            . 'choose Let\'s Encrypt, or buy a certificate for this name.';
    }
    $names = [];
    foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $n) {
        if (str_starts_with(trim($n), 'DNS:')) {
            $names[] = strtolower(substr(trim($n), 4));
        }
    }
    if (!$names && isset($x['subject']['CN'])) {
        $names[] = strtolower((string) $x['subject']['CN']);
    }
    $matches = false;
    foreach ($names as $n) {
        $matches = $matches || $n === $domain || (str_starts_with($n, '*.') && substr($domain, strpos($domain, '.') ?: 0) === substr($n, 1));
    }
    if ($domain !== '' && !$matches) {
        $e[] = "The certificate is for " . implode(', ', $names) . ", not for {$domain}.";
    }
    if (!$chain && ($x['issuer'] ?? null) != ($x['subject'] ?? null)) {
        $w[] = 'The certificate file has no intermediate (chain) certificates, so some phones may not trust it. '
            . 'If you have a "full chain" / "bundle" version of the file, use that.';
    }
    openssl_pkey_export($key, $plainKey);
    return [implode("\n", array_map('trim', array_merge([$leaf], $chain))) . "\n", $plainKey];
}

function dbConnect(array $job, array &$e): ?PDO
{
    try {
        return new PDO('mysql:host=' . ($job['dbhost'] ?? '127.0.0.1') . ';port=' . (int) ($job['dbport'] ?? 3306), $job['dbuser'] ?? '',
            $job['dbpass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
    } catch (Throwable $ex) {
        $e[] = 'Cannot connect to MySQL at ' . ($job['dbhost'] ?? '') . ':' . ($job['dbport'] ?? '') . ' - ' . $ex->getMessage();
        return null;
    }
}

/** Checks the client's MySQL and makes the (empty) database when it is missing. An existing database is never emptied. */
function checkDatabase(array $job, array &$e, array &$w, string $appDir = ''): void
{
    $name = $job['dbname'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        $e[] = 'The database name may only have letters, digits and _ (for example rovexy).';
        return;
    }
    if (!($pdo = dbConnect($job, $e))) {
        return;
    }
    $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $maria = stripos($ver, 'mariadb') !== false;
    preg_match('/^\d+\.\d+/', $ver, $vm);
    if (version_compare($vm[0] ?? '0', $maria ? '10.3' : '5.7', '<')) {
        $e[] = "This server runs " . ($maria ? 'MariaDB' : 'MySQL') . " {$ver}. ROVEXY needs MySQL 5.7+ or MariaDB 10.3+.";
        return;
    }
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $q->execute([$name]);
    if (!(int) $q->fetchColumn()) {
        try {
            $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable) {
            $e[] = "Database {$name} does not exist and user " . ($job['dbuser'] ?? '') . " may not create it. Ask the database administrator "
                . "to create an empty database {$name} and give this user all rights on it, then click Next again.";
            return;
        }
    }
    $q = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $q->execute([$name]);
    $tables = $q->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('migrations', $tables, true) && in_array('companies', $tables, true)) {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM `{$name}`.`companies`")->fetchColumn()) {
            return;   // tables of an earlier attempt that stopped before the company was made: setup carries on with them
        }
        $e[] = "Database {$name} already holds ROVEXY data from another install. Its data is encrypted with that install's key, so it "
            . "cannot be taken over by a new install. To keep it: copy that install's rovexy\\.env to {$appDir}\\rovexy\\.env and run "
            . 'setup again (it then upgrades). Otherwise enter a new database name.';
        return;
    }
    if ($tables) {
        $e[] = "Database {$name} already has " . count($tables) . ' tables of another program. Enter a new or empty database name.';
        return;
    }
    try {
        $pdo->exec("CREATE TABLE `{$name}`.`rovexy_install_check` (id INT)");
        $pdo->exec("DROP TABLE `{$name}`.`rovexy_install_check`");
    } catch (Throwable) {
        $e[] = 'User ' . ($job['dbuser'] ?? '') . " may not create tables in {$name}. Give it all rights on that database.";
    }
}

function cmdCheck(string $jobFile): int
{
    $out = $jobFile . '.out';
    $job = readJob($jobFile);
    $e = $w = [];
    $part = $job['part'] ?? '';
    if ($part === 'server') {
        checkServer($job, $e, $w);
    } elseif ($part === 'cert') {
        loadCert($job, $e, $w);
    } elseif ($part === 'database') {
        checkDatabase($job, $e, $w, $job['app'] ?? '');
    } else {
        $e[] = "Unknown check '{$part}'.";
    }
    $lines = array_merge(array_map(fn ($m) => "ERROR: {$m}", $e), array_map(fn ($m) => "WARN: {$m}", $w));
    file_put_contents($out, ascii(implode("\r\n", $lines) . "\r\n"));
    return $e ? 1 : 0;
}

// ── install ────────────────────────────────────────────────────────────────────────────────────────────────────────

function writePhpIni(string $app): void
{
    $dir = native("{$app}/php");
    $ext = native("{$app}/php/ext");
    $l = ['; Written by the ROVEXY installer on every install and upgrade - changes here are replaced.', "extension_dir = \"{$ext}\""];
    foreach (['curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sodium', 'zip', 'exif', 'gmp', 'bcmath'] as $e) {
        if (is_file("{$app}/php/ext/php_{$e}.dll")) {
            $l[] = "extension={$e}";
        }
    }
    if (is_file("{$app}/php/ext/php_opcache.dll")) {
        array_push($l, 'zend_extension=opcache', 'opcache.enable=1', 'opcache.enable_cli=0', 'opcache.memory_consumption=128',
            'opcache.max_accelerated_files=20000', 'opcache.validate_timestamps=1', 'opcache.revalidate_freq=2');
    }
    if (is_file("{$app}/php/extras/ssl/cacert.pem")) {
        $ca = native("{$app}/php/extras/ssl/cacert.pem");
        array_push($l, "curl.cainfo = \"{$ca}\"", "openssl.cafile = \"{$ca}\"");
    }
    array_push($l, 'memory_limit = 512M', 'upload_max_filesize = 100M', 'post_max_size = 110M', 'max_execution_time = 300',
        'date.timezone = UTC', 'expose_php = Off', 'display_errors = Off', 'log_errors = On',
        'error_log = "' . native("{$app}/logs/php-errors.log") . '"', 'realpath_cache_size = 4096K');
    file_put_contents("{$dir}/php.ini", implode("\r\n", $l) . "\r\n");
}

function writeCaddyfile(string $app, string $domain, string $tls, string $email): void
{
    $fw = fn (string $p) => str_replace('\\', '/', $p);
    $root = $fw("{$app}/rovexy/public");
    $up = implode(' ', array_map(fn ($p) => "127.0.0.1:{$p}", FASTCGI_PORTS));
    $tlsLine = $tls === 'file'
        ? "\ttls \"" . $fw("{$app}/data/certs/server.crt") . '" "' . $fw("{$app}/data/certs/server.key") . "\"\n"
        : ($tls === 'local' ? "\tbind 127.0.0.1\n\ttls internal\n" : '');   // IPv4 only: [::1] fails where IPv6 is off
    $site = $tls === 'local' ? 'localhost:' . LOCAL_PORT : $domain;
    // HTTP/3 off: it needs UDP 443 through the firewall as well, and plain https (h1/h2) is what phones use anyway
    $global = "{\n\tadmin off\n\tservers {\n\t\tprotocols h1 h2\n\t}\n" . ($tls === 'le' && $email !== '' ? "\temail {$email}\n" : '')
        . ($tls === 'local' ? "\tauto_https disable_redirects\n" : '') . "}\n";   // local: no port 80 needed
    $c = "# Written by the ROVEXY installer. Run setup again to change the server name or certificate.\n{$global}\n"
        . "(rovexy) {\n\troot * \"{$root}\"\n\trequest_body {\n\t\tmax_size 100MB\n\t}\n\tencode zstd gzip\n"
        . "\tphp_fastcgi {$up} {\n\t\tlb_try_duration 10s\n\t}\n\tfile_server\n}\n\n"
        . "{$site} {\n{$tlsLine}\timport rovexy\n\tlog {\n\t\toutput file \"" . $fw("{$app}/logs/web-access.log")
        . "\" {\n\t\t\troll_size 10MiB\n\t\t\troll_keep 5\n\t\t}\n\t}\n}\n\n"
        . "# for the installer's health check only (this computer)\nhttp://127.0.0.1:" . HEALTH_PORT . " {\n\tbind 127.0.0.1\n\timport rovexy\n}\n";
    @mkdir("{$app}/caddy", 0755, true);
    file_put_contents("{$app}/caddy/Caddyfile", $c);
}

function serviceDefs(string $app, string $php): array
{
    $n = fn (string $p) => native($p);
    $env = ['XDG_DATA_HOME' => $n("{$app}/data"), 'XDG_CONFIG_HOME' => $n("{$app}/data")];
    return [
        'ROVEXY-PHP' => ['ROVEXY PHP', 'Runs the ROVEXY application (PHP FastCGI workers).', $php,
            '"' . $n("{$app}/installer/php-pool.php") . '" ' . implode(',', FASTCGI_PORTS), $n("{$app}/rovexy"), []],
        'ROVEXY-Web' => ['ROVEXY Web server', 'HTTPS web server for the ROVEXY console and phones (Caddy).', $n("{$app}/caddy/caddy.exe"),
            'run --config "' . $n("{$app}/caddy/Caddyfile") . '" --adapter caddyfile', $n("{$app}/caddy"), $env],
        'ROVEXY-Scheduler' => ['ROVEXY Scheduler', 'Runs ROVEXY timed tasks every minute.', $php, 'artisan schedule:work', $n("{$app}/rovexy"), []],
        'ROVEXY-Queue' => ['ROVEXY Queue', 'Runs ROVEXY background jobs.', $php, 'artisan queue:work --sleep=3 --tries=3 --timeout=300',
            $n("{$app}/rovexy"), []],
    ];
}

function serviceXml(string $id, array $d, string $logs): string
{
    $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
    [$name, $desc, $exe, $args, $cwd, $env] = $d;
    $envXml = '';
    foreach ($env as $k => $v) {
        $envXml .= "  <env name=\"{$x($k)}\" value=\"{$x($v)}\"/>\r\n";
    }
    return "<service>\r\n  <id>{$x($id)}</id>\r\n  <name>{$x($name)}</name>\r\n  <description>{$x($desc)}</description>\r\n"
        . "  <executable>{$x($exe)}</executable>\r\n  <arguments>{$x($args)}</arguments>\r\n  <workingdirectory>{$x($cwd)}</workingdirectory>\r\n"
        . "  <startmode>Automatic</startmode>\r\n  <onfailure action=\"restart\" delay=\"5 sec\"/>\r\n  <resetfailure>1 hour</resetfailure>\r\n"
        . "  <stoptimeout>20 sec</stoptimeout>\r\n{$envXml}  <logpath>{$x($logs)}</logpath>\r\n"
        . "  <log mode=\"roll-by-size\">\r\n    <sizeThreshold>10240</sizeThreshold>\r\n    <keepFiles>5</keepFiles>\r\n  </log>\r\n</service>\r\n";
}

function stopServices(string $app, bool $remove): void
{
    foreach (array_reverse(array_keys(serviceDefs($app, ''))) as $id) {
        $exe = native("{$app}/services/{$id}.exe");
        if (is_file($exe)) {
            run([$exe, 'stop']);
            if ($remove) {
                run([$exe, 'uninstall']);
            }
        }
    }
}

/** Registers the four Windows services (start with Windows, restart on failure) and starts them. */
function installServices(string $app, string $php): bool
{
    $ok = true;
    stopServices($app, true);
    @mkdir("{$app}/services", 0755, true);
    $defs = serviceDefs($app, $php);
    foreach ($defs as $id => $d) {
        $exe = native("{$app}/services/{$id}.exe");
        copy("{$app}/winsw/WinSW.exe", $exe);
        file_put_contents("{$app}/services/{$id}.xml", serviceXml($id, $d, native("{$app}/logs")));
        [$code, $out] = run([$exe, 'install']);
        if ($code !== 0) {
            result('ERROR', "Service {$id} could not be registered: {$out}");
            $ok = false;
        }
    }
    foreach (array_keys($defs) as $id) {
        [$code, $out] = run([native("{$app}/services/{$id}.exe"), 'start']);
        if ($code !== 0) {
            result('ERROR', "Service {$id} did not start: {$out} - see {$app}\\logs\\{$id}.err.log");
            $ok = false;
        }
    }
    return $ok;
}

function httpGet(string $url, ?string $host, bool $verify, int $port = 443, ?string $ca = null): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0, CURLOPT_NOPROXY => '*']);   // straight to this computer, never via a proxy
    if ($host) {
        curl_setopt($c, CURLOPT_RESOLVE, ["{$host}:{$port}:127.0.0.1"]);
    }
    if ($ca) {
        curl_setopt($c, CURLOPT_CAINFO, $ca);
    }
    curl_exec($c);
    $r = [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), curl_error($c)];
    curl_close($c);
    return $r;
}

/** The app answers through the web server; then the https certificate is trusted (Let's Encrypt may need a minute). */
function health(string $app, int $certWait = 90): bool
{
    $url = rtrim(envRead("{$app}/rovexy/.env")['APP_URL'] ?? '', '/');
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    $local = $host === 'localhost';
    $root = "{$app}/data/caddy/pki/authorities/local/root.crt";   // Caddy's own certificate authority (local mode)
    $code = 0;
    $err = '';
    for ($i = 0; $i < 30 && $code !== 200; $i++) {
        $i && sleep(2);
        [$code, $err] = httpGet('http://127.0.0.1:' . HEALTH_PORT . '/up', null, false);
    }
    if ($code !== 200) {
        result('ERROR', "ROVEXY did not answer on this computer (" . ($err ?: "HTTP {$code}") . "). See {$app}\\logs (ROVEXY-Web / ROVEXY-PHP logs, php-errors.log).");
        return false;
    }
    result('OK', 'ROVEXY is running (web server, PHP and database answer).');
    $deadline = time() + $certWait;
    $trusted = false;
    do {
        if ($local && !$trusted && is_file($root) && WIN) {
            // browsers on this computer then trust https://localhost (Caddy also tries this itself)
            $trusted = run(['certutil', '-addstore', '-f', 'Root', native($root)])[0] === 0;
            say($trusted ? 'Local certificate authority added to Windows trusted roots' : 'Could not add the local certificate authority to Windows');
        }
        [$code, $err] = httpGet($url . '/up', $host, true, $port, $local && is_file($root) ? $root : null);
    } while ($code !== 200 && time() < $deadline && sleep(5) === 0);
    if ($code === 200) {
        result('OK', $local ? "{$url} works with this computer's own certificate." : "{$url} has a trusted certificate.");
    } elseif ($local) {
        result('WARN', "ROVEXY runs, but {$url} did not answer over https yet ({$err}). Wait a minute and open it again; see {$app}\\logs.");
    } else {
        result('WARN', "ROVEXY runs, but https://{$host} has no trusted certificate yet ({$err}). With Let's Encrypt: make sure {$host} "
            . 'points to this server\'s public IP and ports 80 and 443 are open from the internet. The certificate is then fetched '
            . 'automatically - no reinstall needed.');
    }
    return true;
}

function writeResults(string $app): void
{
    global $results;
    @file_put_contents("{$app}/logs/install-result.txt", ascii(implode("\r\n", $results) . "\r\n"));
}

function cmdLicence(string $app, ?string $file): int
{
    $php = phpExe($app);
    $root = native("{$app}/rovexy");
    $code = 0;
    $head = '';
    if ($file !== null && $file !== '') {
        [$code, $out] = run([$php, 'artisan', 'rovexy:licence', 'import', $file, '--no-ansi'], $root);
        $head = ($code === 0 ? "Licence file accepted and activated.\r\n" : "The licence file was NOT accepted:\r\n{$out}\r\n") . "\r\n";
        say('Licence import: ' . ($code === 0 ? 'activated' : 'refused - ' . $out));
    }
    [, $status] = run([$php, 'artisan', 'rovexy:licence', 'status', '--no-ansi'], $root);
    @file_put_contents("{$app}/logs/licence.txt", ascii($head . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $status)) . "\r\n"));
    return $code === 0 ? 0 : 1;
}

function cmdInstall(string $app, array $job): int
{
    global $logFile;
    @mkdir("{$app}/logs", 0755, true);
    $logFile = "{$app}/logs/install.log";
    $root = "{$app}/rovexy";
    $php = phpExe($app);
    $envPath = "{$root}/.env";
    $fresh = !is_file($envPath);
    $ver = json_decode((string) @file_get_contents("{$root}/version.json"), true)['version'] ?? '?';
    say('---- ROVEXY ' . $ver . ($fresh ? ' - new install' : ' - upgrade / repair') . " in {$app}");

    try {
        if (WIN) {
            // C:\ lets every signed-in user change new sub-folders; the services run these files as SYSTEM, so only
            // SYSTEM and Administrators may change them (Users may read). .env, certificates and backups: no Users at all.
            [$code, $out] = run(['icacls', native($app), '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F',
                '*S-1-5-32-545:(OI)(CI)RX', '/C', '/Q']);   // children inherit; protected .env/certs/backups keep their own
            $code === 0 ? say('Folder permissions set (SYSTEM and Administrators change, Users read)') : result('WARN', "Could not set folder permissions: {$out}");
        }
        if (is_file("{$app}/php/php.exe")) {
            writePhpIni($app);
            say('php.ini written');
        }

        $e = $w = [];
        if ($fresh) {
            foreach (['domain', 'dbname', 'company', 'adminemail', 'adminpass'] as $k) {
                if (($job[$k] ?? '') === '') {
                    result('ERROR', 'Setup did not receive its settings. Run setup.exe again.');
                    return 1;
                }
            }
            checkDatabase($job, $e, $w, native($app));   // repeats the wizard check: never takes over a database with data
            if ($e) {
                array_map(fn ($m) => result('ERROR', $m), $e);
                return 1;
            }
            $env = (string) file_get_contents("{$app}/installer/env.onprem");
            foreach (['APP_URL' => ($job['tls'] ?? '') === 'local' ? LOCAL_URL : 'https://' . strtolower($job['domain']), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $job['dbhost'],
                'DB_PORT' => $job['dbport'], 'DB_DATABASE' => $job['dbname'], 'DB_USERNAME' => $job['dbuser'], 'DB_PASSWORD' => $job['dbpass'] ?? ''] as $k => $v) {
                $env = envSet($env, $k, (string) $v);
            }
        } else {
            $env = (string) file_get_contents($envPath);
        }
        $cur = envRead($envPath);
        $env = envSet($env, 'ROVEXY_PHP_BINARY', $php);
        foreach (['ROVEXY_MYSQLDUMP' => 'mysqldump', 'ROVEXY_MYSQL' => 'mysql'] as $k => $tool) {
            if (($cur[$k] ?? '') === '' && ($found = findTool($tool))) {
                $env = envSet($env, $k, $found);
            }
        }
        file_put_contents($envPath, $env);
        restrict($envPath);
        restrict("{$root}/storage");   // call recordings, uploads, logs
        say('Settings written to rovexy\\.env; .env and rovexy\\storage readable by Administrators and the services only');
        $settings = envRead($envPath);
        if (!($settings['ROVEXY_MYSQLDUMP'] ?? '')) {
            result('WARN', 'mysqldump was not found, so updates cannot back up the database. Install the MySQL client tools on this '
                . 'server, or set ROVEXY_MYSQLDUMP and ROVEXY_MYSQL in rovexy\\.env.');
        }

        if ($fresh || !is_file("{$app}/caddy/Caddyfile")) {
            $domain = strtolower($job['domain'] ?? (string) parse_url($settings['APP_URL'] ?? '', PHP_URL_HOST));
            $tls = $job['tls'] ?? ($domain === 'localhost' ? 'local' : 'le');
            if ($tls === 'file') {
                [$crt, $key] = loadCert($job + ['domain' => $domain], $e, $w);
                if ($e) {
                    array_map(fn ($m) => result('ERROR', $m), $e);
                    return 1;
                }
                @mkdir("{$app}/data/certs", 0755, true);
                file_put_contents("{$app}/data/certs/server.crt", $crt);
                file_put_contents("{$app}/data/certs/server.key", $key);
                restrict("{$app}/data/certs");
            }
            writeCaddyfile($app, $domain, $tls, $job['email'] ?? '');
            say('Web server configured for ' . ($tls === 'local' ? LOCAL_URL . ' (this computer only, own local certificate)'
                : "https://{$domain} (" . ($tls === 'file' ? 'own certificate' : "Let's Encrypt") . ')'));
        }

        say('Running rovexy:setup (app key, database tables, company, administrator)');
        $args = [$php, 'artisan', 'rovexy:setup', '--no-interaction', '--no-ansi'];
        if ($fresh) {
            array_push($args, '--company=' . $job['company'], '--admin-email=' . $job['adminemail']);
        }
        [$code, $out] = run($args, native($root), ['ROVEXY_ADMIN_PASSWORD' => $job['adminpass'] ?? '']);
        say($out);
        if ($code !== 0) {
            result('ERROR', 'Setting up the application failed - see logs\\install.log.');
            return 1;
        }
        result('OK', $fresh ? 'Database tables, company and administrator ' . $job['adminemail'] . ' created.' : 'Database is up to date.');

        cmdLicence($app, ($job['licence'] ?? '') !== '' ? $job['licence'] : null);

        if (WIN) {
            if (!installServices($app, $php)) {
                return 1;
            }
            result('OK', 'Services ROVEXY-Web, ROVEXY-PHP, ROVEXY-Scheduler and ROVEXY-Queue run and start with Windows.');
            run(['netsh', 'advfirewall', 'firewall', 'delete', 'rule', 'name=' . FIREWALL_RULE]);
            if (parse_url($settings['APP_URL'] ?? '', PHP_URL_HOST) !== 'localhost') {   // local mode: nothing to open
                [$code] = run(['netsh', 'advfirewall', 'firewall', 'add', 'rule', 'name=' . FIREWALL_RULE, 'dir=in', 'action=allow',
                    'protocol=TCP', 'localport=80,443']);
                $code === 0 ? say('Windows Firewall: ports 80 and 443 open') : result('WARN', 'Could not open ports 80 and 443 in Windows Firewall - open them by hand.');
            }
            if (!health($app)) {
                return 1;
            }
        } else {
            say('Not Windows: services, firewall and health check skipped');
        }
        result('URL', rtrim($settings['APP_URL'] ?? '', '/') . '/admin');
        return 0;
    } catch (Throwable $ex) {
        result('ERROR', $ex->getMessage());
        return 1;
    } finally {
        writeResults($app);
        say('---- finished');
    }
}

/** A failed new install leaves no .env behind when nothing was created yet, so running setup again asks for the settings again. */
function undoFreshEnv(string $app, array $job): void
{
    $e = [];
    $pdo = dbConnect($job, $e);
    try {
        $has = $pdo && (int) $pdo->query('SELECT COUNT(*) FROM `' . $job['dbname'] . '`.`companies`')->fetchColumn() > 0;
    } catch (Throwable) {
        $has = false;
    }
    if (!$has && is_file("{$app}/rovexy/.env")) {
        @unlink("{$app}/rovexy/.env");
        say('Nothing was created yet: rovexy\\.env removed, so setup asks for the settings again next time.');
    }
}

function cmdBackup(string $app): int
{
    $env = envRead("{$app}/rovexy/.env");
    if (!$env) {
        return 0;
    }
    $dir = "{$app}/backups";
    @mkdir($dir, 0755, true);
    restrict($dir);
    $stamp = date('Ymd-His');
    if (($env['DB_CONNECTION'] ?? 'mysql') === 'sqlite') {
        return @copy((string) $env['DB_DATABASE'], "{$dir}/rovexy-{$stamp}.sqlite") ? 0 : 1;
    }
    $dump = ($env['ROVEXY_MYSQLDUMP'] ?? '') ?: findTool('mysqldump');
    if (!$dump || !is_file($dump)) {
        echo 'mysqldump was not found.', PHP_EOL;
        return 3;
    }
    $out = native("{$dir}/rovexy-{$stamp}.sql");
    [$code, $msg] = run([$dump, '--single-transaction', '--routines', '--no-tablespaces', '-h', $env['DB_HOST'] ?? '127.0.0.1',
        '-P', $env['DB_PORT'] ?? '3306', '-u', $env['DB_USERNAME'] ?? 'root', "--result-file={$out}", $env['DB_DATABASE'] ?? ''],
        null, ['MYSQL_PWD' => $env['DB_PASSWORD'] ?? '']);
    if ($code !== 0 || @filesize($out) < 100) {
        @unlink($out);
        echo "mysqldump failed: {$msg}", PHP_EOL;
        return 1;
    }
    @file_put_contents("{$app}/logs/install.log", date('Y-m-d H:i:s  ') . "Database backed up to {$out}" . PHP_EOL, FILE_APPEND);
    return 0;
}

if (get_included_files()[0] !== __FILE__) {
    return;   // loaded by a test: functions only
}
$cmd = $argv[1] ?? '';
$a = $argv[2] ?? '';
$b = $argv[3] ?? null;
$app = rtrim($a, '\\/');
switch ($cmd) {
    case 'check':
        exit(cmdCheck($a));
    case 'install':
        $job = readJob($b);
        $wasFresh = !is_file("{$app}/rovexy/.env");
        $rc = cmdInstall($app, $job);
        if ($rc !== 0 && $wasFresh && $job) {
            undoFreshEnv($app, $job);
        }
        exit($rc);
    case 'phpini':
        // a separate first call: PHP reads php.ini only when it starts, so `install` runs in a new process with the extensions
        writePhpIni($app);
        exit(0);
    case 'licence':
        exit(cmdLicence($app, $b));
    case 'backup':
        exit(cmdBackup($app));
    case 'health':
        $logFile = "{$app}/logs/install.log";
        $ok = health($app, (int) ($b ?? 90));
        writeResults($app);
        exit($ok ? 0 : 1);
    case 'uninstall':
        stopServices($app, true);
        if (WIN) {
            run(['netsh', 'advfirewall', 'firewall', 'delete', 'rule', 'name=' . FIREWALL_RULE]);
        }
        exit(0);
    default:
        fwrite(STDERR, "usage: php configure.php check|install|licence|backup|health|uninstall ...\n");
        exit(2);
}
