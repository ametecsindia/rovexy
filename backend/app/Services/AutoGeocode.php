<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Models\Customer;
use App\Models\CustomerAddressChange;
use App\Models\GeocodeResult;
use App\Models\ManagedDevice;
use App\Support\CurrentCompany;

/**
 * G5: address → map pin for every case, automatically. Runs after each upload (GeocodePending job) and every night for
 * anything left. Google's answer is kept per address (geocode_results), so the same address next month costs nothing.
 * Area-only matches (APPROXIMATE) give no pin — a pin in the middle of a locality would make "nearest" wrong; those
 * cases are marked 'approx' and the employee pins them at the door. Stops (and resumes next run) at the monthly limit.
 */
final class AutoGeocode
{
    public const BATCH = 200;

    /** Cases of the bound company that need a lookup. */
    public static function pending(): \Illuminate\Database\Eloquent\Builder
    {
        return Customer::query()->whereNull('withdrawn_at')->whereNull('geo_status')
            ->where(fn ($w) => $w->whereNull('lat')->orWhereNull('lng')->orWhere(fn ($z) => $z->where('lat', 0)->where('lng', 0)))
            ->where(fn ($w) => $w->whereNotNull('address')->orWhereNotNull('pincode'));
    }

    public static function addressOf(Customer $c): string
    {
        return implode(', ', array_unique(array_filter(array_map(fn ($k) => trim((string) $c->{$k}), CustomerAddressChange::ADDRESS_FIELDS))));
    }

    public static function key(string $address, string $country): string
    {
        return sha1(mb_strtolower(preg_replace('/\s+/u', ' ', trim($address))) . '|' . strtoupper($country));
    }

    /**
     * One batch for the bound company. @return array{saved: int, approx: int, not_found: int, reused: int, left: int, stopped: ?string}
     */
    public static function run(int $max = self::BATCH): array
    {
        $maps = app(GoogleMaps::class);
        $stats = ['saved' => 0, 'approx' => 0, 'not_found' => 0, 'reused' => 0, 'left' => 0, 'stopped' => null];
        if (!$maps->configured()) {
            $stats['stopped'] = 'Google Maps is not set up (Integrations → Google Maps).';
            $stats['left'] = self::pending()->count();
            return $stats;
        }
        $country = (string) (app(CurrentCompany::class)->get()->default_country ?: 'IN');
        $owners = [];
        foreach (self::pending()->orderBy('id')->limit($max)->get() as $c) {
            $address = self::addressOf($c);
            if ($address === '') {   // blank cells: nothing to look up
                $c->forceFill(['geo_status' => 'not_found'])->saveQuietly();
                $stats['not_found']++;
                continue;
            }
            $key = self::key($address, $country);
            $hit = GeocodeResult::query()->where('address_key', $key)->first();
            if ($hit) {
                $stats['reused']++;
            } else {
                try {
                    $g = $maps->geocode($address, $country);
                } catch (DomainException $e) {
                    $stats['stopped'] = $e->getMessage();   // limit reached / key refused / no internet: the rest wait for the next run
                    break;
                }
                $hit = GeocodeResult::query()->create(['address_key' => $key, 'status' => $g === null ? 'not_found' : ($g['precise'] ? 'google' : 'approx'),
                    'lat' => $g['lat'] ?? null, 'lng' => $g['lng'] ?? null, 'precision' => $g['precision'] ?? null,
                    'formatted_address' => isset($g['formatted_address']) ? mb_substr($g['formatted_address'], 0, 300) : null]);
            }
            if ($hit->status === 'google') {
                Customer::changedBy('bulk_geocode', null, fn () => $c->forceFill(['lat' => $hit->lat, 'lng' => $hit->lng, 'geo_status' => 'google', 'geo_precision' => $hit->precision])->save(),
                    'Matched: ' . $hit->formatted_address);
                $stats['saved']++;
                if ($c->assigned_user_id) $owners[$c->assigned_user_id] = true;
            } else {
                $c->forceFill(['geo_status' => $hit->status, 'geo_precision' => $hit->precision])->saveQuietly();
                $stats[$hit->status === 'approx' ? 'approx' : 'not_found']++;
            }
        }
        // Phones holding these cases fetch the new pins now, not at their next sync.
        ManagedDevice::query()->whereIn('assigned_user_id', array_keys($owners))->where('approval_state', 'approved')
            ->each(fn ($d) => app(PushService::class)->ping($d, 'customers'));
        $stats['left'] = self::pending()->count();
        return $stats;
    }

    /** G6: a verified pin (GPS at the door) is kept for the case's address, so another case at that address gets it free. */
    public static function remember(Customer $c, float $lat, float $lng, string $precision): void
    {
        $address = self::addressOf($c);
        if ($address === '') return;
        $country = (string) (app(CurrentCompany::class)->get()->default_country ?: 'IN');
        GeocodeResult::query()->updateOrCreate(['address_key' => self::key($address, $country)],
            ['status' => 'google', 'lat' => $lat, 'lng' => $lng, 'precision' => mb_substr($precision, 0, 24), 'formatted_address' => mb_substr('Verified at the door: ' . $address, 0, 300)]);
    }

    /** Progress for one upload (Portfolios page). */
    public static function progress(int $importId): array
    {
        $q = fn () => Customer::query()->whereNull('withdrawn_at')->where('last_import_id', $importId);
        $total = $q()->count();
        $pinned = $q()->whereNotNull('lat')->whereNotNull('lng')->where(fn ($w) => $w->where('lat', '!=', 0)->orWhere('lng', '!=', 0))->count();
        return ['total' => $total, 'pinned' => $pinned, 'waiting' => self::pending()->where('last_import_id', $importId)->count(),
            'area_only' => $q()->where('geo_status', 'approx')->count(), 'not_found' => $q()->where('geo_status', 'not_found')->count(),
            'no_address' => $q()->whereNull('address')->whereNull('pincode')->where(fn ($w) => $w->whereNull('lat')->orWhereNull('lng'))->count()];
    }
}
