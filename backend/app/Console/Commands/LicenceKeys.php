<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Licensing\LicenceSigner;
use Illuminate\Console\Command;

/** Creates the licence-server signing key pair (run once on the central server). */
final class LicenceKeys extends Command
{
    protected $signature = 'rovexy:licence-keys {--force : Replace an existing key (every issued licence file and install must then be re-licensed)}';
    protected $description = 'Create the RSA key pair that signs ROVEXY licences (central server only).';

    public function handle(): int
    {
        try {
            LicenceSigner::generate((bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Private key: ' . config('ammp.licence.private_key') . ' — keep it on this server only, back it up, never commit it.');
        $this->info('Public key:  ' . config('ammp.licence.public_key') . ' — ship this file with every on-premises install.');
        return self::SUCCESS;
    }
}
