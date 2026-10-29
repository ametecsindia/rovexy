<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AutoGeocode;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Fills missing case pins from the address now (G5 does this by itself after every upload and every night at 01:30).
 *   php artisan ammp:geocode-customers --limit=500
 */
final class GeocodeCustomers extends Command
{
    protected $signature = 'ammp:geocode-customers {--limit=200 : Most cases to look up per company in this run} {--dry-run : Only count}';
    protected $description = 'Add map locations to cases that have an address but no latitude / longitude.';

    public function handle(CurrentCompany $current): int
    {
        foreach (Company::query()->get() as $company) {
            $current->run($company, function () use ($company) {
                if ($this->option('dry-run')) {
                    $this->line("{$company->name}: " . AutoGeocode::pending()->count() . ' cases have an address but no map location.');
                    return;
                }
                $r = AutoGeocode::run(max(0, (int) $this->option('limit')));
                $this->info("{$company->name}: saved {$r['saved']} (reused {$r['reused']}) · area-only {$r['approx']} · not found {$r['not_found']} · still waiting {$r['left']}");
                if ($r['stopped']) $this->warn("Stopped: {$r['stopped']}");
            });
        }
        return self::SUCCESS;
    }
}
