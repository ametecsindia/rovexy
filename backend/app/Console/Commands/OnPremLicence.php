<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\DomainException;
use App\Services\Licensing\LicenceClient;
use App\Services\Platform\Licensing;
use Illuminate\Console\Command;

/**
 * Command-line side of the install's Licence page (on-prem, LICENCE_MODE=client). Same LicenceClient calls as the console:
 *   id      — this server's ID, which Ametecs puts into the licence file (Super Admin → Licences → Licence file…)
 *   import  — activate with the signed .lic file from Ametecs (checked: signature, this server's ID, active licence)
 *   key     — activate online with a licence key (server with internet)
 *   status  — what the install is licensed for now
 */
final class OnPremLicence extends Command
{
    protected $signature = 'rovexy:licence {action=status : id | import | key | status} {value? : the .lic file (import) or the licence key (key)}';
    protected $description = 'On-premises licence: show the server ID, import a licence file, enter a key, or show the status';

    public function handle(LicenceClient $client, Licensing $licensing): int
    {
        if (!LicenceClient::isClient()) {
            $this->error('This is not an on-premises install (LICENCE_MODE must be client in .env).');
            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        $value = (string) $this->argument('value');
        try {
            switch ($action) {
                case 'id':
                    $this->line(LicenceClient::fingerprint());
                    $this->warnWeak();
                    return self::SUCCESS;
                case 'import':
                    if ($value === '' || !is_file($value)) {
                        $this->error('Give the path of the licence file, for example: php artisan rovexy:licence import C:\\ROVEXY\\RX-ABCD-....lic');
                        return self::FAILURE;
                    }
                    $client->importFile((string) file_get_contents($value));
                    $this->info('Licence file imported and activated.');
                    break;
                case 'key':
                    $row = $client->saveKey($value);
                    $this->line($row->status === 'active' ? 'Licence key verified with the ROVEXY licence server.'
                        : 'Key saved. ' . ($row->last_error ?: 'It is verified the next time the licence server can be reached.'));
                    break;
                case 'status':
                    break;
                default:
                    $this->error('Action must be id, import, key or status.');
                    return self::FAILURE;
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $licensing->forget();
        $this->status($licensing->current());
        return self::SUCCESS;
    }

    private function status(array $l): void
    {
        $this->table(['', ''], [
            ['Server ID', LicenceClient::fingerprint()],
            ['State', $l['state']],
            ['Licence', trim(($l['plan'] ?? '—') . ' ' . ($l['kind'] ? "({$l['kind']})" : ''))],
            ['Users', $l['max_users'] ? "{$l['used_users']} of {$l['max_users']}" : (string) $l['used_users']],
            ['Valid until', $l['expires_at'] ? substr((string) $l['expires_at'], 0, 10) : ($l['kind'] === 'perpetual' ? 'No end date (perpetual)' : '—')],
            ['Key', $l['key'] ?? '—'],
        ]);
        if ($l['message']) {
            $this->line($l['message']);
        }
        $this->warnWeak();
    }

    private function warnWeak(): void
    {
        if (LicenceClient::weakFingerprint()) {
            $this->warn('No machine ID could be read, so the server ID uses the host name: renaming this server changes it. Set LICENCE_MACHINE_ID in .env to fix it.');
        }
    }
}
