<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * SAFE DEVELOPMENT SEED — fictitious companies and people, reserved example numbers. Refuses to run in production.
 * Two companies are created so isolation can be exercised manually.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Development seed refused in production.');
        }
        $ctx = app(CurrentCompany::class);
        foreach ([['demo-collections', 'Demo Collections Pvt Ltd (fictitious)'], ['sample-recoveries', 'Sample Recoveries (fictitious)']] as $i => [$slug, $name]) {
            $company = Company::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
            $ctx->run($company, function () use ($company, $i) {
                CompanyDefaults::apply($company);
                $team = Team::query()->firstOrCreate(['code' => 'T1'], ['name' => 'Team One']);
                $mk = function (string $code, string $name, ?string $email, string $role, string $membership = 'member', ?string $phone = null) use ($company, $team) {
                    $u = User::query()->firstOrCreate(['employee_code' => $code], ['name' => $name, 'email' => $email, 'phone' => $phone,
                        'password' => 'DevOnly-Passw0rd!', 'status' => 'active']);
                    $u->roles()->syncWithoutDetaching([Role::query()->where('key', $role)->value('id') => ['company_id' => $company->id]]);
                    $u->teams()->syncWithoutDetaching([$team->id => ['company_id' => $company->id, 'membership' => $membership]]);
                    return $u;
                };
                $mk('admin', 'Dev Admin', "admin@{$company->slug}.example", 'company_admin');
                $mk('sup1', 'Dev Supervisor', "sup1@{$company->slug}.example", 'supervisor', 'supervisor');
                $emp = $mk('EMP001', 'Dev Employee', null, 'employee', 'member', '+9190000' . ($i + 1) . '0000');
                foreach (range(1, 25) as $n) {
                    // 555-style reserved pattern is not available in India; use the simulator scenario suffixes 0001–0003.
                    Customer::query()->firstOrCreate(['customer_ref' => sprintf('DEV-%04d', $n)], [
                        'name' => "Test Customer {$n}", 'phone' => sprintf('+91900000%04d', $n), 'status' => 'new',
                        'campaign' => 'Development', 'assigned_user_id' => $emp->id, 'assigned_team_id' => $team->id,
                        // Fictitious dues so the case screens have something to show in development.
                        'city' => 'Hyderabad', 'area' => 'Kondapur', 'pincode' => '500084', 'address' => "{$n}-1, Test Street", 'state' => 'Telangana',
                        'loan_amount' => 100000 + $n * 5000, 'outstanding' => 40000 + $n * 1500, 'overdue_amount' => $n % 5 === 0 ? 0 : 2000 * ($n % 4 + 1),
                        'emi_amount' => 4000, 'due_date' => now()->addDays($n % 10)->toDateString(),
                        'oldest_due_date' => $n % 5 === 0 ? null : now()->subDays(15 * ($n % 7))->toDateString(), 'balance_as_of' => now()->toDateString(),
                    ]);
                }
            });
        }
    }
}
