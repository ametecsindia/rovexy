<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Domain\DomainException;
use App\Services\Licensing\LicenceSigner;
use ZipArchive;

/**
 * An on-premises update package: a FLAT zip of the backend (artisan, app/, config/ … at the root) with version.json and
 * manifest.json. The version is read from inside the zip (never typed), the shape is checked before the file is kept, and
 * the hash is signed with the licence key so installs can prove the package came from Ametecs (not just that it is intact).
 */
final class UpdatePackage
{
    public const PRODUCT = 'rovexy';
    // a complete build only: the updater removes files missing from the manifest, so a partial package must never pass
    public const REQUIRED = ['artisan', 'version.json', 'manifest.json', 'config/ammp.php', 'app/Providers/AppServiceProvider.php', 'bootstrap/app.php',
        'public/index.php', 'vendor/autoload.php', 'routes/api.php', 'updater/updater.php'];

    /** @return array{version:string, channel:string, files:int} */
    public static function inspect(string $zipPath): array
    {
        $z = new ZipArchive();
        if ($z->open($zipPath) !== true) {
            throw new DomainException('bad_package', 'This is not a readable ZIP file.');
        }
        try {
            foreach (self::REQUIRED as $f) {
                if ($z->locateName($f) === false) {
                    throw new DomainException('bad_package', "Not a ROVEXY update package: {$f} must be at the root of the ZIP. "
                        . 'Build it with php artisan rovexy:build-update (a ZIP with a folder inside is an installer, not an update).');
                }
            }
            $v = json_decode((string) $z->getFromName('version.json'), true);
            if (($v['product'] ?? null) !== self::PRODUCT || !preg_match('/^\d+(\.\d+){0,3}$/', (string) ($v['version'] ?? ''))) {
                throw new DomainException('bad_package', 'version.json inside the package is missing or not for ROVEXY.');
            }
            $manifest = json_decode((string) $z->getFromName('manifest.json'), true);
            if (!is_array($manifest['files'] ?? null) || !$manifest['files']) {
                throw new DomainException('bad_package', 'manifest.json inside the package is empty.');
            }
            foreach ($manifest['files'] as $f) {
                if (str_contains((string) $f, '..') || str_starts_with((string) $f, '/')) {
                    throw new DomainException('bad_package', 'The package lists an unsafe path.');
                }
            }
            return ['version' => (string) $v['version'], 'channel' => (string) ($v['channel'] ?? 'stable'), 'files' => count($manifest['files'])];
        } finally {
            $z->close();
        }
    }

    public static function signature(string $version, string $sha256): string
    {
        return LicenceSigner::sign(['type' => 'update', 'product' => self::PRODUCT, 'version' => $version, 'sha256' => $sha256]);
    }

    /** True when $signature is Ametecs' signature for exactly this version + hash (checked with the install's public key). */
    public static function verify(string $signature, string $version, string $sha256): bool
    {
        $p = LicenceSigner::verify($signature);
        return $p !== null && ($p['type'] ?? null) === 'update' && ($p['product'] ?? null) === self::PRODUCT
            && ($p['version'] ?? null) === $version && hash_equals((string) ($p['sha256'] ?? ''), $sha256);
    }
}
