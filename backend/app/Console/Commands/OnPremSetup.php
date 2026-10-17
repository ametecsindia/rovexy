<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Licensing\LicenceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * First-time setup of an on-premises install from the common client package (run by install.bat / install.sh).
 * Safe to run again: each step is skipped when already done. Nothing client-specific is in the package; this command
 * creates it on the client's server — app key, database tables, the company and its first administrator. The licence is
 * activated through the install's own LicenceClient (licence file or key), exactly as on the console's Licence page.
 */
final class OnPremSetup extends Command
{
    protected $signature = 'rovexy:setup
        {--company= : Company name (asked when left out)}
        {--slug= : Short name for the company, lower-case (default: made from the company name)}
        {--admin-email= : E-mail of the first Company Administrator (asked when left out)}
        {--admin-password= : Password for that administrator, 12+ characters (asked when left out; or env ROVEXY_ADMIN_PASSWORD)}
        {--licence= : The .lic licence file from Ametecs (optional — can be imported later)}
        {--key= : A licence key, for a server with internet (optional)}';
    protected $description = 'Set up an on-premises ROVEXY install: app key, database, first company and administrator, licence';

    public function handle(): int
    {
        if (!LicenceClient::isClient() || config('ammp.tenancy.mode') !== 'fixed') {
            $this->error('.env is not an on-premises configuration: it needs LICENCE_MODE=client and TENANCY_MODE=fixed (copy env.onprem from the package).');
            return self::FAILURE;
        }

        if (str_contains((string) config('app.url'), 'example.com')) {
            $this->error('Set APP_URL in .env to this server\'s https address (it still says example.com).');
            return self::FAILURE;
        }

        $this->info('[1/5] Application key');
        if (!config('app.key')) {
            $this->call('key:generate', ['--force' => true]);
        } else {
            $this->line('      already set');
        }

        $this->info('[2/5] Database tables');
        if ($this->call('migrate', ['--force' => true]) !== 0) {
            $this->error('The database could not be set up. Check DB_* in .env and that the database exists, then run this again.');
            return self::FAILURE;
        }

        $this->info('[3/5] Company and first administrator');
        if (($fixed = Company::query()->find((int) config('ammp.tenancy.fixed_company_id'))) !== null) {
            $this->line("      already set up: {$fixed->name}");
        } elseif (Company::query()->exists()) {
            $this->error('A company exists but TENANCY_FIXED_COMPANY_ID in .env does not point to it. Set it to the company id ('
                . Company::query()->orderBy('id')->value('id') . ').');
            return self::FAILURE;
        } else {
            $name = trim((string) ($this->option('company') ?: $this->ask('Company name')));
            $slug = (string) ($this->option('slug') ?: Str::slug($name) ?: 'company');
            $email = trim((string) ($this->option('admin-email') ?: $this->ask('Administrator e-mail (signs in to the console)')));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('A company name and a valid administrator e-mail are needed.');
                return self::FAILURE;
            }
            $args = ['slug' => substr($slug, 0, 63), 'name' => $name, 'admin_email' => $email];
            // the Windows installer passes it in the environment, so it never shows in a process list or log
            if ($password = $this->option('admin-password') ?: getenv('ROVEXY_ADMIN_PASSWORD')) {
                $args['--password'] = $password;
            }
            if ($this->call('ammp:create-company', $args) !== 0) {
                return self::FAILURE;
            }
            $id = (int) Company::query()->where('slug', $args['slug'])->value('id');
            if ($id !== (int) config('ammp.tenancy.fixed_company_id')) {
                $this->setEnv('TENANCY_FIXED_COMPANY_ID', (string) $id);
                config(['ammp.tenancy.fixed_company_id' => $id]);
            }
            $this->line("      administrator {$email} signs in at " . rtrim((string) config('app.url'), '/') . '/admin');
        }

        $this->info('[4/5] Licence');
        $code = 0;
        if ($file = $this->option('licence')) {
            $code = $this->call('rovexy:licence', ['action' => 'import', 'value' => $file]);
        } elseif ($key = $this->option('key')) {
            $code = $this->call('rovexy:licence', ['action' => 'key', 'value' => $key]);
        } else {
            $this->call('rovexy:licence', ['action' => 'status']);
            if (!(app(\App\Services\Platform\Licensing::class)->current()['key'] ?? null)) {
                $this->line('      No licence file given. Send the Server ID above to Ametecs; they reply with a .lic file.');
                $this->line('      Then run activate-licence (or Console → Plan & licence → Import & activate).');
            }
        }

        $this->info('[5/5] Health check');
        $this->callSilently('storage:link');   // optional (Windows needs admin rights for links); downloads still work without it
        $health = $this->call('rovexy:health-check');
        if ($health !== 0 || $code !== 0) {
            $this->error($health !== 0 ? 'Setup finished but the health check failed — see the lines above.' : 'Setup finished but the licence was not activated — see the message above.');
            return self::FAILURE;
        }
        $this->info('ROVEXY is set up. Open ' . rtrim((string) config('app.url'), '/') . '/admin');
        return self::SUCCESS;
    }

    /** Writes one KEY=value line in .env (replaces it, or appends it). */
    private function setEnv(string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $env = (string) @file_get_contents($path);
        $line = "{$key}={$value}";
        $env = preg_match("/^{$key}=.*$/m", $env) ? preg_replace("/^{$key}=.*$/m", $line, $env) : rtrim($env) . "\n{$line}\n";
        file_put_contents($path, $env);
    }
}
