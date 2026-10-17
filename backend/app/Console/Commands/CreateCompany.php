<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Console\Command;

/** Provision a company with system roles, default policy and a first Company Administrator (MFA enrolled at first sign-in). */
final class CreateCompany extends Command
{
    protected $signature = 'ammp:create-company {slug} {name} {admin_email} {--timezone=Asia/Kolkata} {--country=IN} {--domain=} {--password= : first admin password (asked when left out)}';
    protected $description = 'Create a company and its first administrator.';

    public function handle(CurrentCompany $current): int
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $this->argument('slug'))) {
            $this->error('Invalid slug.');
            return self::FAILURE;
        }
        $password = $this->option('password') ?: $this->secret('Initial admin password (min 12 chars)');
        if (strlen((string) $password) < 12) {
            $this->error('Password too short.');
            return self::FAILURE;
        }
        $company = Company::query()->create(['slug' => $this->argument('slug'), 'name' => $this->argument('name'),
            'timezone' => $this->option('timezone'), 'default_country' => strtoupper($this->option('country'))]);
        $current->run($company, function () use ($company, $password) {
            CompanyDefaults::apply($company);
            if ($d = $this->option('domain')) {
                $company->domains()->create(['host' => strtolower($d), 'verification_token' => bin2hex(random_bytes(16))]);
                $this->warn("Domain {$d} added UNVERIFIED. Verify it (DNS TXT) before relying on it for company resolution.");
            }
            $u = User::query()->create(['employee_code' => 'admin', 'email' => strtolower($this->argument('admin_email')), 'name' => 'Company Administrator',
                'password' => $password, 'status' => 'active']);
            $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $company->id]);
        });
        $this->info("Company #{$company->id} '{$company->slug}' created.");
        return self::SUCCESS;
    }
}
