<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Updates\UpdateClient;
use Illuminate\Support\Facades\Process;

/**
 * Nightly database backup (G20). Runs from the scheduler every night (routes/console.php), keeps the newest KEEP copies
 * and records the result for System health. Before this, a Windows install was backed up only before an upgrade.
 *
 * Where: the install's own backups folder next to the app (Windows installer: <install>\backups, readable by
 * Administrators only), otherwise storage/app/backups. What: the whole database (MySQL/MariaDB via mysqldump, gzip;
 * SQLite: a copy). Recordings and uploaded files are already files on disk and are not copied every night.
 *
 * A backup on the same disk protects against mistakes, a broken update and database damage — not against the disk
 * itself failing. Copy the backups folder to another disk or computer as well (see the installation guide).
 */
final class NightlyBackup
{
    public const KEEP = 7;
    private const STATUS = 'last-backup.json';

    public static function dir(): string
    {
        $installer = dirname(base_path()) . DIRECTORY_SEPARATOR . 'backups';   // <install>\backups (Windows installer)
        return is_dir($installer) && is_file(dirname(base_path()) . DIRECTORY_SEPARATOR . 'rovexy' . DIRECTORY_SEPARATOR . 'artisan')
            ? $installer : storage_path('app' . DIRECTORY_SEPARATOR . 'backups');
    }

    /** Takes one backup now and removes the oldest beyond KEEP. @return array{ok: bool, file?: string, bytes?: int, error?: string} */
    public static function run(): array
    {
        $dir = self::dir();
        @mkdir($dir, 0700, true);
        $stamp = now()->format('Ymd-His');
        try {
            $file = self::dump($dir, $stamp);
            clearstatcache();
            $res = ['ok' => true, 'file' => basename($file), 'bytes' => (int) filesize($file)];
            self::rotate($dir);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        self::record($dir, $res);
        return $res;
    }

    /** For System health: when the last backup ran, whether it worked, and how many copies are kept. */
    public static function status(): array
    {
        $dir = self::dir();
        $s = is_file($dir . DIRECTORY_SEPARATOR . self::STATUS) ? (json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . self::STATUS), true) ?: []) : [];
        $lastOk = isset($s['last_ok_at']) ? \Illuminate\Support\Carbon::parse($s['last_ok_at']) : null;
        $fresh = $lastOk !== null && $lastOk->gt(now()->subHours(36));   // nightly, with a few hours' slack
        return [
            'ok' => $fresh,
            'last_backup_at' => $lastOk?->toIso8601String(),
            'last_attempt_at' => $s['last_attempt_at'] ?? null,
            'last_error' => ($s['ok'] ?? true) ? null : ($s['error'] ?? null),
            'copies_kept' => count(self::files($dir)),
            'folder' => $dir,
            'detail' => $lastOk === null ? 'No nightly backup has run yet (it runs every night at 00:30).'
                : ($fresh ? 'Last backup ' . $lastOk->diffForHumans() . '.' : 'The last good backup is older than a day — check the error and the Scheduler service.'),
        ];
    }

    private static function dump(string $dir, string $stamp): string
    {
        $name = config('database.default');
        $c = (array) config("database.connections.{$name}");
        $driver = $c['driver'] ?? $name;
        if ($driver === 'sqlite') {
            $src = (string) ($c['database'] ?? '');
            if ($src === '' || $src === ':memory:' || !is_file($src)) {
                throw new \RuntimeException('The SQLite database file was not found.');
            }
            $out = $dir . DIRECTORY_SEPARATOR . "rovexy-{$stamp}.sqlite.gz";
            self::gzip($src, $out);
            return $out;
        }
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException("Nightly backup supports MySQL/MariaDB and SQLite (this server uses {$driver}).");
        }
        $bin = UpdateClient::findTool('mysqldump') ?? throw new \RuntimeException('mysqldump was not found on this server.');
        $sql = $dir . DIRECTORY_SEPARATOR . "rovexy-{$stamp}.sql";
        $r = Process::timeout(3600)->env(['MYSQL_PWD' => (string) ($c['password'] ?? '')])->run([$bin, '--single-transaction', '--routines', '--triggers',
            '--no-tablespaces', '--hex-blob', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? '3306'),
            '-u', (string) ($c['username'] ?? 'root'), "--result-file={$sql}", (string) ($c['database'] ?? '')]);
        if (!$r->successful() || !is_file($sql) || filesize($sql) < 100) {
            @unlink($sql);
            throw new \RuntimeException('mysqldump failed: ' . trim($r->errorOutput() ?: $r->output()));
        }
        self::gzip($sql, $sql . '.gz');
        @unlink($sql);
        return $sql . '.gz';
    }

    private static function gzip(string $src, string $out): void
    {
        $in = fopen($src, 'rb');
        $gz = gzopen($out, 'wb6');
        if (!$in || !$gz) {
            throw new \RuntimeException('Could not write the backup file in ' . dirname($out) . '.');
        }
        while (!feof($in)) {
            gzwrite($gz, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($gz);
    }

    /** @return list<string> backup files, newest first */
    private static function files(string $dir): array
    {
        $f = glob($dir . DIRECTORY_SEPARATOR . 'rovexy-*.gz') ?: [];
        rsort($f);   // names carry the date and time, so this is newest first
        return $f;
    }

    private static function rotate(string $dir): void
    {
        foreach (array_slice(self::files($dir), self::KEEP) as $old) {
            @unlink($old);
        }
    }

    private static function record(string $dir, array $res): void
    {
        $p = $dir . DIRECTORY_SEPARATOR . self::STATUS;
        $s = is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
        $s = ['last_attempt_at' => now()->toIso8601String(), 'ok' => $res['ok'], 'error' => $res['error'] ?? null]
            + ($res['ok'] ? ['last_ok_at' => now()->toIso8601String(), 'file' => $res['file']] : array_intersect_key($s, ['last_ok_at' => 1, 'file' => 1]));
        @file_put_contents($p, json_encode($s, JSON_PRETTY_PRINT));
    }
}
