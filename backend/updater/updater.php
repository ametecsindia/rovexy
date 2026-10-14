<?php
/**
 * ROVEXY on-premises updater — a standalone PHP CLI script (no Laravel), started detached by the console:
 *     php updater/updater.php storage/app/updates/job.json
 *
 * verify (hash + Ametecs signature + package shape) → backup (files AND database) → maintenance on → copy files →
 * remove files the release deleted (manifest) → migrate → health check → version stamp → maintenance off.
 * Any failure after the backup rolls back: replaced entries are DELETED then restored (never merged), the database is
 * restored, the app comes back up on the previous version. Never touched: .env, storage/, .git.
 * Progress goes to storage/app/updates/state.json, readable during maintenance through public/update-status.php.
 */

declare(strict_types=1);

set_time_limit(0);
ignore_user_abort(true);
error_reporting(E_ALL);

const PROTECTED_ENTRIES = ['.env', 'storage', '.git', 'manifest.json', '.htaccess.local'];
const KEEP_BACKUPS = 3;

$jobFile = $argv[1] ?? '';
$job = is_file($jobFile) ? json_decode((string) file_get_contents($jobFile), true) : null;
if (!is_array($job) || empty($job['base']) || empty($job['state_file'])) {
    fwrite(STDERR, "usage: php updater.php <job.json>\n");
    exit(2);
}
$base = rtrim($job['base'], '/\\');
$php = $job['php'] ?? PHP_BINARY;
$stamp = date('Ymd_His');

function state(array $patch, ?string $log = null): void
{
    global $job;
    $f = $job['state_file'];
    $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $s = array_merge($s, $patch, ['updated_at' => date('c')]);
    if ($log !== null) {
        $s['log'] = array_slice(array_merge($s['log'] ?? [], [date('H:i:s') . ' ' . $log]), -200);
        echo $log, PHP_EOL;
    }
    file_put_contents($f . '.tmp', json_encode($s, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename($f . '.tmp', $f);
}

function step(string $phase, int $pct, string $msg): void
{
    state(['phase' => $phase, 'percent' => $pct, 'message' => $msg], $msg);
}

function artisan(string $args): array
{
    global $php, $base;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($base . '/artisan') . ' ' . $args . ' 2>&1';
    $out = [];
    $code = 1;
    $cwd = getcwd();
    chdir($base);
    exec($cmd, $out, $code);
    chdir($cwd);
    $tail = implode(' | ', array_slice(array_filter(array_map('trim', $out)), -6));
    state([], "artisan {$args} → exit {$code}" . ($tail !== '' ? " ({$tail})" : ''));
    return [$code, $out];
}

function rrmdir(string $p): void
{
    if (is_link($p) || is_file($p)) {
        @unlink($p);
        return;
    }
    if (!is_dir($p)) {
        return;
    }
    foreach (scandir($p) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            rrmdir($p . '/' . $e);
        }
    }
    @rmdir($p);
}

/** Copies $src over $dst (files overwritten, folders merged). Symlinks in $dst are left alone. Returns files copied. */
function rcopy(string $src, string $dst): int
{
    if (is_file($src)) {
        @mkdir(dirname($dst), 0755, true);
        if (is_link($dst)) {
            return 0;
        }
        if (!copy($src, $dst)) {
            throw new RuntimeException("Could not write {$dst} (file in use or no permission).");
        }
        return 1;
    }
    $n = 0;
    @mkdir($dst, 0755, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            $n += rcopy($src . '/' . $e, $dst . '/' . $e);
        }
    }
    return $n;
}

function b64d(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/'), true);
}

function verifySignature(string $token, string $version, string $sha, string $pubFile): bool
{
    if (!is_file($pubFile) || !str_contains($token, '.')) {
        return false;
    }
    [$a, $b] = explode('.', $token, 2);
    $json = b64d($a);
    $sig = b64d($b);
    $key = openssl_pkey_get_public((string) file_get_contents($pubFile));
    if ($json === false || $sig === false || !$key || openssl_verify($json, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
        return false;
    }
    $p = json_decode($json, true);
    return is_array($p) && ($p['type'] ?? '') === 'update' && ($p['product'] ?? '') === 'rovexy' && ($p['version'] ?? '') === $version
        && hash_equals((string) ($p['sha256'] ?? ''), $sha);
}

function dbBackup(array $db, string $dir): ?string
{
    $driver = $db['driver'] ?? '';
    if ($driver === 'sqlite') {
        $f = $db['database'] ?? '';
        if (!is_file($f) || !copy($f, $dir . '/database.sqlite')) {
            throw new RuntimeException('Could not copy the SQLite database.');
        }
        return 'sqlite';
    }
    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        $bin = $db['mysqldump'] ?? '';
        if ($bin === '' || !is_file($bin)) {
            throw new RuntimeException('mysqldump was not found, so the database cannot be backed up. Set ROVEXY_MYSQLDUMP in .env.');
        }
        $out = $dir . '/database.sql';
        $cmd = escapeshellarg($bin) . ' --single-transaction --routines --no-tablespaces -h ' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1'))
            . ' -P ' . escapeshellarg((string) ($db['port'] ?? '3306')) . ' -u ' . escapeshellarg((string) ($db['username'] ?? 'root'))
            . ' ' . escapeshellarg((string) ($db['database'] ?? '')) . ' > ' . escapeshellarg($out) . ' 2>&1';
        putenv('MYSQL_PWD=' . ($db['password'] ?? ''));
        exec($cmd, $o, $code);
        putenv('MYSQL_PWD');
        if ($code !== 0 || !is_file($out) || filesize($out) < 100) {
            throw new RuntimeException('mysqldump failed (exit ' . $code . ').');
        }
        return 'mysql';
    }
    throw new RuntimeException("Database type '{$driver}' cannot be backed up by the updater.");
}

function dbRestore(array $db, string $dir): void
{
    $driver = $db['driver'] ?? '';
    if ($driver === 'sqlite') {
        copy($dir . '/database.sqlite', $db['database']);
        return;
    }
    $bin = $db['mysql'] ?? '';
    if ($bin === '' || !is_file($bin)) {
        throw new RuntimeException('mysql client not found — restore ' . $dir . '/database.sql by hand.');
    }
    putenv('MYSQL_PWD=' . ($db['password'] ?? ''));
    $cmd = escapeshellarg($bin) . ' -h ' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1')) . ' -P ' . escapeshellarg((string) ($db['port'] ?? '3306'))
        . ' -u ' . escapeshellarg((string) ($db['username'] ?? 'root')) . ' ' . escapeshellarg((string) $db['database']) . ' < ' . escapeshellarg($dir . '/database.sql') . ' 2>&1';
    exec($cmd, $o, $code);
    putenv('MYSQL_PWD');
    if ($code !== 0) {
        throw new RuntimeException('Database restore failed (exit ' . $code . '): restore ' . $dir . '/database.sql by hand.');
    }
}

$updDir = $base . '/storage/app/updates';
$extract = "{$updDir}/extract_{$job['version']}_{$stamp}";
$backup = "{$updDir}/backup_" . ($job['from_version'] ?? 'old') . "_{$stamp}";
$entries = [];
$existed = [];
$backedUp = false;
$down = false;

try {
    // 1 — verify before touching anything
    step('verifying', 10, 'Checking the package…');
    $pkg = (string) ($job['package'] ?? '');
    if (!is_file($pkg) || !hash_equals((string) $job['sha256'], hash_file('sha256', $pkg))) {
        throw new RuntimeException('The package is missing or failed its integrity check.');
    }
    if (!verifySignature((string) ($job['signature'] ?? ''), (string) $job['version'], (string) $job['sha256'], $base . '/resources/licence/public.pem')) {
        throw new RuntimeException('The package is not signed by Ametecs (signature check failed).');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled.');
    }
    $z = new ZipArchive();
    if ($z->open($pkg) !== true || !$z->extractTo($extract)) {
        throw new RuntimeException('The package could not be unpacked.');
    }
    $z->close();
    foreach (['artisan', 'app', 'config', 'version.json', 'manifest.json', 'bootstrap/app.php', 'public/index.php', 'vendor/autoload.php', 'routes/api.php', 'updater/updater.php'] as $need) {
        if (!file_exists("{$extract}/{$need}")) {
            throw new RuntimeException("Not a ROVEXY update package ({$need} missing at its root).");
        }
    }
    $pv = json_decode((string) file_get_contents("{$extract}/version.json"), true);
    if (($pv['version'] ?? null) !== $job['version']) {
        throw new RuntimeException('The package version does not match what was offered.');
    }
    $manifest = json_decode((string) file_get_contents("{$extract}/manifest.json"), true)['files'] ?? [];
    $manifest = array_flip(array_map('strval', $manifest));
    $entries = array_values(array_filter(scandir($extract) ?: [], fn ($e) => $e !== '.' && $e !== '..' && !in_array($e, PROTECTED_ENTRIES, true)));
    step('verifying', 15, 'Package OK: version ' . $job['version'] . ', ' . count($manifest) . ' files.');

    // 2 — backup files and database
    step('backup', 25, 'Backing up the current version and the database…');
    @mkdir("{$backup}/files", 0755, true);
    foreach ($entries as $e) {
        if (file_exists("{$base}/{$e}")) {
            $existed[] = $e;
            rcopy("{$base}/{$e}", "{$backup}/files/{$e}");
        }
    }
    $dbKind = dbBackup($job['db'] ?? [], $backup);
    file_put_contents("{$backup}/backup.json", json_encode(['from' => $job['from_version'] ?? null, 'to' => $job['version'], 'entries' => $entries, 'existed' => $existed, 'db' => $dbKind, 'at' => date('c')]));
    $backedUp = true;
    step('backup', 30, 'Backup saved in ' . basename($backup) . " (files + {$dbKind} database).");

    // 3 — maintenance
    step('maintenance', 35, 'Putting ROVEXY into maintenance mode…');
    [$c] = artisan('down --retry=60');
    if ($c !== 0) {
        @mkdir("{$base}/storage/framework", 0755, true);
        file_put_contents("{$base}/storage/framework/down", json_encode(['retry' => 60, 'status' => 503, 'time' => time()]));
    }
    $down = true;

    // 4 — install files, then remove files this release no longer has
    step('installing', 55, 'Installing the new files…');
    $n = 0;
    foreach ($entries as $e) {
        $n += rcopy("{$extract}/{$e}", "{$base}/{$e}");
    }
    if ($n === 0) {
        throw new RuntimeException('No files were copied.');
    }
    $removed = 0;
    foreach ($entries as $e) {
        if (!is_dir("{$base}/{$e}") || is_link("{$base}/{$e}")) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$e}", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            if ($f->isLink() || str_starts_with($rel, 'bootstrap/cache/') || $rel === 'public/storage' || str_starts_with($rel, 'public/storage/')) {
                continue;
            }
            if ($f->isFile() && !isset($manifest[$rel])) {
                @unlink($f->getPathname());
                $removed++;
            }
        }
    }
    step('installing', 60, "{$n} files installed, {$removed} old files removed.");

    // 5 — database
    step('migrating', 70, 'Updating the database…');
    artisan('optimize:clear');
    [$c] = artisan('migrate --force');
    if ($c !== 0) {
        throw new RuntimeException('The database update (migrate) failed.');
    }

    // 6 — real health check
    step('checking', 85, 'Checking that everything works…');
    [$c] = artisan('rovexy:health-check');
    if ($c !== 0) {
        throw new RuntimeException('The health check failed after the update.');
    }

    // 7 — finish
    step('finishing', 95, 'Finishing…');
    $old = json_decode((string) @file_get_contents("{$backup}/files/version.json"), true) ?: [];
    file_put_contents("{$base}/version.json", json_encode(['product' => 'rovexy', 'version' => $job['version'], 'channel' => $old['channel'] ?? ($pv['channel'] ?? 'stable'),
        'installed_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    artisan('optimize:clear');
    artisan('up');
    @unlink("{$base}/storage/framework/down");
    $down = false;
    rrmdir($extract);
    @unlink($pkg);
    $olds = glob("{$updDir}/backup_*", GLOB_ONLYDIR) ?: [];
    sort($olds);
    foreach (array_slice($olds, 0, max(0, count($olds) - KEEP_BACKUPS)) as $o) {
        rrmdir($o);   // keep the last few backups only
    }
    state(['phase' => 'done', 'percent' => 100, 'current_version' => $job['version'], 'available' => null, 'package' => null,
        'message' => "Updated to version {$job['version']}. The previous version is kept in " . basename($backup) . '.'], 'Done.');
    @unlink($jobFile);
    exit(0);
} catch (Throwable $e) {
    $reason = $e->getMessage();
    state([], 'ERROR: ' . $reason);
    if ($backedUp) {
        step('rolling_back', 90, 'Restoring the previous version…');
        try {
            foreach ($entries as $en) {
                rrmdir("{$base}/{$en}");                                // delete, never merge
                if (in_array($en, $existed, true)) {
                    rcopy("{$backup}/files/{$en}", "{$base}/{$en}");
                }
            }
            dbRestore($job['db'] ?? [], $backup);
            artisan('optimize:clear');
        } catch (Throwable $r) {
            $reason .= ' — ROLLBACK PROBLEM: ' . $r->getMessage() . ' (backup: ' . basename($backup) . ')';
        }
    }
    if ($down || $backedUp) {
        artisan('up');
        @unlink("{$base}/storage/framework/down");
    }
    rrmdir($extract);
    state(['phase' => 'failed', 'percent' => 100, 'message' => ($backedUp ? 'Update cancelled. This server is still running its previous version. Reason: ' : 'Update not started — nothing was changed. Reason: ') . $reason], 'Failed.');
    @unlink($jobFile);
    exit(1);
}
