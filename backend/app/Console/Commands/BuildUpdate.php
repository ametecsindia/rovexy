<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Updates\UpdatePackage;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Builds the on-premises update package from this backend: a FLAT zip (artisan, app/, config/ … at the root) with
 * version.json and manifest.json (every file, so the updater can remove files a release deleted). Never includes .env,
 * storage/, tests, .git or node_modules. The version in version.json is bumped only after a successful build.
 * Run `composer install --no-dev --optimize-autoloader` and build the admin first.
 */
final class BuildUpdate extends Command
{
    protected $signature = 'rovexy:build-update {--out= : Folder for the ZIP (default storage/app/updates)} {--no-bump : Keep version.json as it is}';
    protected $description = 'Build the flat ROVEXY update ZIP for on-premises servers';

    private const INCLUDE = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor', 'updater', 'artisan', 'composer.json', 'composer.lock', 'server.php', 'version.json'];
    private const SKIP = ['#^bootstrap/cache/.+\.php$#', '#^public/storage$#', '#^public/hot$#', '#(^|/)\.git(/|$)#', '#(^|/)node_modules(/|$)#', '#(^|/)\.DS_Store$#'];

    public function handle(): int
    {
        $base = base_path();
        $ver = json_decode((string) @file_get_contents("{$base}/version.json"), true);
        if (($ver['product'] ?? null) !== UpdatePackage::PRODUCT || !preg_match('/^\d+(\.\d+){0,3}$/', (string) ($ver['version'] ?? ''))) {
            $this->error('version.json is missing or invalid.');
            return self::FAILURE;
        }
        $files = [];
        foreach (self::INCLUDE as $top) {
            $p = "{$base}/{$top}";
            if (is_file($p)) {
                $files[] = $top;
            } elseif (is_dir($p)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isLink() || !$f->isFile()) {
                        continue;
                    }
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                    foreach (self::SKIP as $rx) {
                        if (preg_match($rx, $rel)) {
                            continue 2;
                        }
                    }
                    $files[] = $rel;
                }
            }
        }
        sort($files);
        $out = rtrim((string) ($this->option('out') ?: storage_path('app/updates')), '/\\');
        @mkdir($out, 0755, true);
        $zipPath = "{$out}/ROVEXY-Update-{$ver['version']}.zip";
        if (is_file($zipPath)) {
            $this->error("{$zipPath} already exists — this version was built before. Bump version.json first.");
            return self::FAILURE;
        }
        $z = new ZipArchive();
        $z->open("{$zipPath}.part", ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $rel) {
            $z->addFile("{$base}/{$rel}", $rel);
        }
        $z->addFromString('manifest.json', json_encode(['product' => UpdatePackage::PRODUCT, 'version' => $ver['version'], 'files' => $files], JSON_UNESCAPED_SLASHES));
        $z->close();
        rename("{$zipPath}.part", $zipPath);
        try {
            $info = UpdatePackage::inspect($zipPath);   // the same check Central runs when the package is added
        } catch (\Throwable $e) {
            @unlink($zipPath);
            $this->error('The built package failed its check: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->info("Built {$zipPath} — version {$info['version']}, {$info['files']} files, " . round(filesize($zipPath) / 1048576, 1) . ' MB.');
        $this->line('Add it in Super Admin → Software updates (pick it from the server folder), then Publish.');
        if (!$this->option('no-bump')) {
            $next = self::bump($ver['version']);
            file_put_contents("{$base}/version.json", json_encode(['product' => UpdatePackage::PRODUCT, 'version' => $next, 'channel' => $ver['channel'] ?? 'stable'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->line("version.json is now {$next} for the next release.");
        }
        return self::SUCCESS;
    }

    /** 1.1 → 1.2, 1.9 → 2.0, 1.5.9 → 1.6.0 (carry at 10). */
    public static function bump(string $v): string
    {
        $p = array_map('intval', explode('.', $v));
        for ($i = count($p) - 1; $i >= 0; $i--) {
            if (++$p[$i] < 10 || $i === 0) {
                break;
            }
            $p[$i] = 0;
        }
        return implode('.', $p);
    }
}
