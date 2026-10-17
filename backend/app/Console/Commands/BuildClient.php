<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Updates\UpdatePackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use ZipArchive;

/**
 * Builds the ONE on-premises install package that every client gets (run by build-client.bat):
 *
 *   ROVEXY-OnPrem-<version>/
 *     rovexy/            the app — exactly the files of an update package (BuildUpdate::files), plus empty storage folders
 *     install.bat/.sh, activate-licence.bat/.sh, env.onprem, INSTALL.md   (from deploy/onprem)
 *
 * Nothing client-specific is inside: no .env, no app key, no database, no licence, no signing private key. Each client's
 * server makes its own key, database and company at install time, and is activated with its own .lic file, which is
 * locked to that server's ID by the licence server (Super Admin → Licences → Licence file…).
 * Run from a copy of the backend that had `composer install --no-dev` and the console build (build-client.bat does both).
 */
final class BuildClient extends Command
{
    protected $signature = 'rovexy:build-client
        {--out= : Folder for the ZIP (default storage/app/client)}
        {--extras= : Folder with the install scripts (default ../deploy/onprem)}
        {--verify : Unpack the ZIP in a temp folder, install it there with SQLite and run the health check}
        {--allow-dev-packages : Allow composer dev packages (testing only)}';
    protected $description = 'Build the common ROVEXY on-premises client package (one ZIP for every client)';

    private const STORAGE = ['storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions',
        'storage/framework/views', 'storage/logs', 'bootstrap/cache'];
    private const EXTRAS = ['install.bat', 'install.sh', 'activate-licence.bat', 'activate-licence.sh', 'env.onprem', 'INSTALL.md'];

    public function handle(): int
    {
        $base = base_path();
        $ver = json_decode((string) @file_get_contents("{$base}/version.json"), true);
        if (($ver['product'] ?? null) !== UpdatePackage::PRODUCT || !preg_match('/^\d+(\.\d+){0,3}$/', (string) ($ver['version'] ?? ''))) {
            return $this->stop('version.json is missing or invalid.');
        }
        $extras = rtrim((string) ($this->option('extras') ?: dirname($base) . '/deploy/onprem'), '/\\');
        foreach (self::EXTRAS as $f) {
            if (!is_file("{$extras}/{$f}")) {
                return $this->stop("{$extras}/{$f} is missing.");
            }
        }
        if (!is_file("{$base}/public/admin/index.html")) {
            return $this->stop('The console is not built (public/admin/index.html missing). Run npm run build in admin first.');
        }
        if (!is_file("{$base}/vendor/autoload.php")) {
            return $this->stop('vendor/ is missing. Run composer install --no-dev --optimize-autoloader first.');
        }
        $installed = json_decode((string) @file_get_contents("{$base}/vendor/composer/installed.json"), true);
        if (($installed['dev'] ?? true) && !$this->option('allow-dev-packages')) {
            return $this->stop('vendor/ has development packages. Run composer install --no-dev --optimize-autoloader first.');
        }
        $this->checkPublicKey($base);

        $files = BuildUpdate::files($base);
        $leaks = preg_grep('#^(\.env|storage/)|licence-private|\.lic$#', $files);
        if ($leaks) {
            return $this->stop('Refusing to package client-specific or secret files: ' . implode(', ', array_slice($leaks, 0, 5)));
        }

        $name = "ROVEXY-OnPrem-{$ver['version']}";
        $out = rtrim((string) ($this->option('out') ?: storage_path('app/client')), '/\\');
        @mkdir($out, 0755, true);
        $zipPath = "{$out}/{$name}.zip";
        $this->line('Packing ' . count($files) . ' files…');
        $z = new ZipArchive();
        if ($z->open("{$zipPath}.part", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->stop("Cannot write {$zipPath}.part");
        }
        foreach ($files as $rel) {
            $z->addFile("{$base}/{$rel}", "{$name}/rovexy/{$rel}");
        }
        foreach (self::STORAGE as $dir) {
            $z->addFromString("{$name}/rovexy/{$dir}/.gitignore", "*\n!.gitignore\n");
        }
        foreach (self::EXTRAS as $f) {
            $z->addFile("{$extras}/{$f}", "{$name}/{$f}");
            if (str_ends_with($f, '.sh')) {
                $z->setExternalAttributesName("{$name}/{$f}", ZipArchive::OPSYS_UNIX, 0100755 << 16);
            }
        }
        $z->addFromString("{$name}/VERSION.txt", "ROVEXY on-premises {$ver['version']} ({$ver['channel']})\nBuilt " . now()->toDateTimeString() . "\n");
        if (!$z->close()) {
            return $this->stop('Could not finish the ZIP.');
        }
        @unlink($zipPath);
        rename("{$zipPath}.part", $zipPath);
        $sha = hash_file('sha256', $zipPath);
        file_put_contents("{$zipPath}.sha256", "{$sha}  {$name}.zip\n");
        $this->info("Built {$zipPath} — " . round(filesize($zipPath) / 1048576, 1) . " MB, SHA-256 {$sha}");

        if ($this->option('verify') && !$this->verify($zipPath, $name)) {
            return $this->stop('The package was built but did not install cleanly — see above.');
        }
        return self::SUCCESS;
    }

    /** The public key in the package decides which licence files every install will trust — warn when it is this PC's own test key. */
    private function checkPublicKey(string $base): void
    {
        $pub = (string) @file_get_contents("{$base}/resources/licence/public.pem");
        $priv = @openssl_pkey_get_private((string) @file_get_contents((string) config('ammp.licence.private_key')));
        if ($pub === '') {
            $this->warn('resources/licence/public.pem is missing — installs will not accept any licence file.');
        } elseif ($priv && trim(openssl_pkey_get_details($priv)['key']) === trim($pub)) {
            $this->warn('resources/licence/public.pem was made on THIS computer. Installs from this package only trust licence files signed here.');
            $this->warn('For clients, put the LIVE licence server\'s public.pem into the repo first (deploy/README.md section 2).');
        }
    }

    /** Unpack, install with SQLite in a temp folder (as a client would), run the health check. */
    private function verify(string $zipPath, string $name): bool
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->warn('Install check skipped: this PHP has no pdo_sqlite (enable extension=pdo_sqlite in php.ini to run it).');
            return true;
        }
        $tmp = sys_get_temp_dir() . '/rovexy-verify-' . bin2hex(random_bytes(4));
        $z = new ZipArchive();
        $z->open($zipPath);
        $z->extractTo($tmp);
        $z->close();
        $app = "{$tmp}/{$name}/rovexy";
        touch("{$tmp}/verify.sqlite");
        $env = (string) file_get_contents("{$tmp}/{$name}/env.onprem");
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => "{$tmp}/verify.sqlite", 'APP_URL' => 'https://rovexy.verify.test', 'LICENCE_MACHINE_ID' => 'package-verify'] as $k => $v) {
            $env = preg_replace_callback("/^{$k}=.*$/m", fn () => "{$k}={$v}", $env);   // callback: Windows paths have backslashes
        }
        file_put_contents("{$app}/.env", $env);
        $this->line("Verifying in {$tmp} …");
        $r = Process::path($app)->timeout(600)->env(['APP_ENV' => false, 'DB_CONNECTION' => false, 'DB_DATABASE' => false, 'CACHE_STORE' => false,
            'SESSION_DRIVER' => false, 'QUEUE_CONNECTION' => false, 'APP_KEY' => false])
            ->run([PHP_BINARY, 'artisan', 'rovexy:setup', '--no-interaction', '--company=Package Check', '--admin-email=check@example.com', '--admin-password=Package-check-123']);
        $this->line(trim($r->output() . $r->errorOutput()));
        $ok = $r->successful();
        $this->{$ok ? 'info' : 'error'}($ok ? 'Verify OK: the package installs and passes the health check.' : 'Verify FAILED.');
        Process::run(PHP_OS_FAMILY === 'Windows' ? ['cmd', '/c', 'rmdir', '/s', '/q', $tmp] : ['rm', '-rf', $tmp]);
        return $ok;
    }

    private function stop(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}
