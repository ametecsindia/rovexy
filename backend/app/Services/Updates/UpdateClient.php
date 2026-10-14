<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Domain\DomainException;
use App\Services\Licensing\LicenceClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * On-premises self-update (LICENCE_MODE=client): check → download → install, as separate steps so progress is honest.
 * Central only answers; the customer's admin decides when. The package hash AND Ametecs' signature are checked here and
 * again by the updater, which runs as a separate detached PHP process (a web request can't replace the files it runs).
 * State: storage/app/updates/state.json (also read by public/update-status.php during maintenance).
 */
final class UpdateClient
{
    public function __construct(private readonly LicenceClient $licence)
    {
    }

    public static function dir(): string
    {
        $d = storage_path('app/updates');
        @mkdir($d, 0755, true);
        return $d;
    }

    public static function stateFile(): string
    {
        return self::dir() . '/state.json';
    }

    public static function versionInfo(): array
    {
        $v = json_decode((string) @file_get_contents(base_path('version.json')), true);
        return ['version' => (string) ($v['version'] ?? '0.0.0'), 'channel' => ($v['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable'];
    }

    public function state(): array
    {
        $s = json_decode((string) @file_get_contents(self::stateFile()), true);
        return is_array($s) ? $s : ['phase' => 'idle'];
    }

    private function save(array $patch, ?string $log = null): array
    {
        $s = array_merge($this->state(), $patch, ['updated_at' => now()->toAtomString()]);
        if ($log !== null) {
            $s['log'] = array_slice(array_merge($s['log'] ?? [], [now()->format('H:i:s') . ' ' . $log]), -200);
        }
        file_put_contents(self::stateFile() . '.tmp', json_encode($s, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename(self::stateFile() . '.tmp', self::stateFile());
        return $s;
    }

    /** What the Licence page shows. */
    public function status(): array
    {
        $s = $this->state();
        $v = self::versionInfo();
        // an install that died without finishing (power cut) must not block the button forever
        if (($s['phase'] ?? '') === 'installing' && isset($s['updated_at']) && now()->diffInMinutes($s['updated_at'], true) > 30) {
            $s = $this->save(['phase' => 'failed', 'message' => 'The last install stopped without finishing. Check storage/app/updates/updater.log, then try again.']);
        }
        [$canInstall, $why] = $this->canInstall();
        return ['current_version' => $v['version'], 'channel' => $v['channel'], 'phase' => $s['phase'] ?? 'idle', 'percent' => $s['percent'] ?? 0,
            'message' => $s['message'] ?? null, 'available' => $s['available'] ?? null, 'checked_at' => $s['checked_at'] ?? null,
            'downloaded' => !empty($s['package']) && is_file((string) $s['package']), 'log' => array_slice($s['log'] ?? [], -20),
            'can_install' => $canInstall, 'install_problem' => $why];
    }

    public function check(): array
    {
        $row = $this->licence->row();
        $url = (string) config('ammp.licence.server_url');
        if (!$row->licence_key) {
            throw new DomainException('no_key', 'Enter and validate the licence key first.');
        }
        if ($url === '') {
            throw new DomainException('no_server', 'LICENCE_SERVER_URL is not set in .env, so this server cannot ask for updates.');
        }
        $v = self::versionInfo();
        try {
            $res = Http::timeout(20)->acceptJson()->post("{$url}/api/v1/updates/check", ['key' => $row->licence_key, 'fingerprint' => LicenceClient::fingerprint(),
                'current_version' => $v['version'], 'channel' => $v['channel']]);
        } catch (\Throwable) {
            throw new DomainException('update_server_unreachable', "The ROVEXY update server could not be reached (asked {$url}). Check this server's internet connection.");
        }
        $j = $res->json();
        if (!$res->successful() || !is_array($j) || ($j['ok'] ?? false) !== true) {
            throw new DomainException((string) ($j['reason'] ?? 'update_refused'), (string) ($j['message'] ?? ('The update server answered HTTP ' . $res->status() . '.')));
        }
        if (!($j['update_available'] ?? false)) {
            return $this->save(['phase' => 'idle', 'available' => null, 'checked_at' => now()->toAtomString(), 'message' => (string) ($j['message'] ?? 'Up to date.')], 'Checked: up to date.');
        }
        if (!UpdatePackage::verify((string) ($j['signature'] ?? ''), (string) $j['version'], (string) $j['package_hash'])) {
            throw new DomainException('bad_signature', 'The offered update is not signed by Ametecs, so it was refused.');
        }
        $offer = array_intersect_key($j, array_flip(['version', 'minimum_version', 'title', 'notes', 'size_bytes', 'package_hash', 'signature', 'download_url', 'released_at']));
        return $this->save(['phase' => 'available', 'available' => $offer, 'package' => null, 'checked_at' => now()->toAtomString(),
            'message' => "Version {$j['version']} is available."], "Checked: version {$j['version']} available.");
    }

    public function download(): array
    {
        $s = $this->state();
        $a = $s['available'] ?? null;
        if (!$a || empty($a['download_url'])) {
            throw new DomainException('no_offer', 'Press Check for update first.');
        }
        if (!is_writable(self::dir())) {
            throw new DomainException('not_writable', 'storage/app/updates is not writable by the web server.');
        }
        $file = self::dir() . '/ROVEXY-' . preg_replace('/[^0-9.]/', '', (string) $a['version']) . '-update.zip';
        $this->save(['phase' => 'downloading', 'percent' => 0, 'message' => 'Downloading…'], "Downloading version {$a['version']}…");
        try {
            $res = Http::timeout(900)->withHeaders(['X-Rovexy-Server' => LicenceClient::fingerprint()])->sink($file)->get($a['download_url']);
        } catch (\Throwable $e) {
            @unlink($file);
            $this->save(['phase' => 'available', 'message' => 'The download did not complete. Try again.'], 'Download failed: ' . $e->getMessage());
            throw new DomainException('download_failed', 'The download did not complete. Try again.');
        }
        if (!$res->successful() || !is_file($file) || filesize($file) === 0) {
            @unlink($file);
            $this->save(['phase' => 'idle', 'available' => null, 'message' => 'The download link has expired. Check for update again.'], 'Download refused: HTTP ' . $res->status());
            throw new DomainException('download_failed', 'The download link may have expired — check for update again.');
        }
        if (!hash_equals((string) $a['package_hash'], hash_file('sha256', $file)) || !UpdatePackage::verify((string) $a['signature'], (string) $a['version'], (string) $a['package_hash'])) {
            @unlink($file);
            $this->save(['phase' => 'available', 'message' => 'The download failed its integrity check and was deleted. Try again.'], 'Integrity check failed.');
            throw new DomainException('bad_package', 'The download failed its integrity check and was deleted. Try again.');
        }
        return $this->save(['phase' => 'downloaded', 'percent' => 0, 'package' => $file, 'message' => "Version {$a['version']} is downloaded and verified. Install when ready."], 'Downloaded and verified.');
    }

    public function install(): array
    {
        $s = $this->state();
        $a = $s['available'] ?? null;
        if (($s['phase'] ?? '') === 'installing') {
            return $s;   // never start twice
        }
        if (!$a || empty($s['package']) || !is_file((string) $s['package']) || !hash_equals((string) $a['package_hash'], hash_file('sha256', (string) $s['package']))) {
            throw new DomainException('not_downloaded', 'Download the update first.');
        }
        [$ok, $why] = $this->canInstall();
        if (!$ok) {
            throw new DomainException('cannot_install', $why);
        }
        $php = (string) self::phpBinary();
        $token = Str::random(40);
        $db = $this->dbJob();
        $job = self::dir() . '/job.json';
        file_put_contents($job, json_encode(['base' => base_path(), 'package' => $s['package'], 'version' => $a['version'], 'from_version' => self::versionInfo()['version'],
            'sha256' => $a['package_hash'], 'signature' => $a['signature'], 'php' => $php, 'state_file' => self::stateFile(), 'db' => $db,
            'started_at' => now()->toAtomString()], JSON_UNESCAPED_SLASHES));
        @chmod($job, 0600);   // holds the database password until the updater finishes and deletes it
        $this->save(['phase' => 'installing', 'percent' => 5, 'poll_token' => $token, 'message' => 'Starting the installer…'], "Installing version {$a['version']}…");
        $log = self::dir() . '/updater.log';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(base_path('updater/updater.php')) . ' ' . escapeshellarg($job);
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B ' . $cmd . ' > ' . escapeshellarg($log) . ' 2>&1', 'r'));   // detached; inherits the redirect
        } else {
            exec($cmd . ' > ' . escapeshellarg($log) . ' 2>&1 &');
        }
        return $this->state() + ['poll_url' => url('/update-status.php') . '?t=' . $token];
    }

    /** @return array{0:bool,1:?string} */
    public function canInstall(): array
    {
        if (!is_file(base_path('updater/updater.php'))) {
            return [false, 'updater/updater.php is missing from this install.'];
        }
        if (!self::phpBinary()) {
            return [false, 'The PHP command-line program was not found. Set ROVEXY_PHP_BINARY in .env to its full path (e.g. C:\\laragon\\bin\\php\\php-8.3\\php.exe).'];
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true) || (PHP_OS_FAMILY === 'Windows' && in_array('popen', $disabled, true))) {
            return [false, 'PHP may not start background programs here (exec/popen disabled).'];
        }
        if (!class_exists(\ZipArchive::class)) {
            return [false, 'The PHP zip extension is not enabled.'];
        }
        try {
            $this->dbJob();
        } catch (DomainException $e) {
            return [false, $e->getMessage()];
        }
        return [true, null];
    }

    /** The database settings the updater needs to back up (and on failure restore) the database. */
    private function dbJob(): array
    {
        $name = config('database.default');
        $c = config("database.connections.{$name}");
        $db = ['driver' => $c['driver'] ?? $name, 'database' => $c['database'] ?? null, 'host' => $c['host'] ?? null, 'port' => $c['port'] ?? null,
            'username' => $c['username'] ?? null, 'password' => $c['password'] ?? null];
        if (in_array($db['driver'], ['mysql', 'mariadb'], true)) {
            $db['mysqldump'] = self::findTool('mysqldump');
            $db['mysql'] = self::findTool('mysql');
            if (!$db['mysqldump'] || !$db['mysql']) {
                throw new DomainException('no_mysqldump', 'mysqldump / mysql were not found, so the database could not be backed up before an update. Set ROVEXY_MYSQLDUMP and ROVEXY_MYSQL in .env.');
            }
        } elseif ($db['driver'] !== 'sqlite') {
            throw new DomainException('db_unsupported', "Automatic updates support MySQL/MariaDB and SQLite (this server uses {$db['driver']}).");
        }
        return $db;
    }

    public static function findTool(string $tool): ?string
    {
        if ($p = config('ammp.updates.' . $tool)) {
            return is_file($p) ? $p : null;
        }
        $exe = PHP_OS_FAMILY === 'Windows' ? "{$tool}.exe" : $tool;
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $exe)) {
                return $dir . DIRECTORY_SEPARATOR . $exe;
            }
        }
        foreach (array_merge(glob('C:/laragon/bin/mysql/*/bin/' . $exe) ?: [], ["/usr/bin/{$tool}", "/usr/local/bin/{$tool}", "/opt/homebrew/bin/{$tool}"]) as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    /** Under a web server PHP_BINARY is php-fpm / php-cgi / httpd, which can't run a script — find the real CLI. */
    public static function phpBinary(): ?string
    {
        $exe = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $c = array_filter([
            config('ammp.updates.php_binary'),
            PHP_BINDIR . DIRECTORY_SEPARATOR . $exe,
            in_array(strtolower(basename(PHP_BINARY)), ['php', 'php.exe'], true) ? PHP_BINARY : null,
            php_ini_loaded_file() ? dirname((string) php_ini_loaded_file()) . DIRECTORY_SEPARATOR . $exe : null,
            ini_get('extension_dir') ? dirname((string) ini_get('extension_dir')) . DIRECTORY_SEPARATOR . $exe : null,
            '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '/usr/bin/php', '/usr/local/bin/php',
        ]);
        foreach ($c as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }
}
