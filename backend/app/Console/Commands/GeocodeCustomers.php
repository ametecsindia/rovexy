<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\DomainException;
use App\Models\Company;
use App\Models\Customer;
use App\Services\GoogleMaps;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Fills missing case pins from the uploaded address (Google Geocoding), so the field map can sort them by distance.
 * Area-level matches (whole locality / city) are skipped: a pin in the middle of a city would make "nearest customer"
 * wrong. Those cases stay under "Location unavailable" until an employee pins them at the door.
 *   php artisan ammp:geocode-customers --limit=500
 */
final class GeocodeCustomers extends Command
{
    protected $signature = 'ammp:geocode-customers {--limit=200 : Most addresses to look up in this run (each one is a billed Geocoding request)} {--dry-run : Only count}';
    protected $description = 'Add map locations to cases that have an address but no latitude / longitude.';

    public function handle(CurrentCompany $current, GoogleMaps $maps): int
    {
        $left = max(0, (int) $this->option('limit'));
        $stats = ['saved' => 0, 'approximate' => 0, 'not_found' => 0];
        foreach (Company::query()->get() as $company) {
            $current->run($company, function () use ($company, $maps, &$left, &$stats) {
                $q = Customer::query()->whereNull('withdrawn_at')->where(fn ($w) => $w->whereNull('lat')->orWhereNull('lng'))
                    ->where(fn ($w) => $w->whereNotNull('address')->orWhereNotNull('pincode'));
                if ($this->option('dry-run')) {
                    $this->line("{$company->name}: {$q->count()} cases have an address but no map location.");
                    return;
                }
                if (!$maps->configured()) {
                    $this->warn("{$company->name}: no Google Maps key (admin: Integrations → Google Maps) — skipped.");
                    return;
                }
                $owners = [];
                foreach ($q->orderBy('id')->limit($left)->get() as $c) {
                    $left--;
                    $parts = array_unique(array_filter(array_map(fn ($v) => trim((string) $v), [$c->address, $c->area, $c->city, $c->state, $c->pincode])));
                    try {
                        $hit = $maps->geocode(implode(', ', $parts), $company->default_country ?: 'IN');
                    } catch (DomainException $e) {
                        $this->error("Stopped: {$e->getMessage()} (details in storage/logs).");
                        $left = 0;
                        return;
                    }
                    if ($hit === null) { $stats['not_found']++; continue; }
                    if (!$hit['precise']) { $stats['approximate']++; continue; }
                    Customer::changedBy('bulk_geocode', null, fn () => $c->forceFill(['lat' => $hit['lat'], 'lng' => $hit['lng']])->save(), 'Matched: ' . $hit['formatted_address']);
                    $stats['saved']++;
                    if ($c->assigned_user_id) $owners[$c->assigned_user_id] = true;
                }
                // Phones holding these cases fetch the new pins now, not at their next 15-minute sync.
                \App\Models\ManagedDevice::query()->whereIn('assigned_user_id', array_keys($owners))->where('approval_state', 'approved')
                    ->each(fn ($d) => app(\App\Services\PushService::class)->ping($d, 'customers'));
            });
            if ($left <= 0) break;
        }
        if (!$this->option('dry-run')) {
            $this->info("Saved {$stats['saved']} · area-only match, skipped {$stats['approximate']} · address not found {$stats['not_found']}");
        }
        return self::SUCCESS;
    }
}
