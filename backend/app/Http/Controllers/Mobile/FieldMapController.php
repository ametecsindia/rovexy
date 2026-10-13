<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerEvent;
use App\Services\GoogleMaps;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Field map: driving distance / time, routes and customer pins for the employee's own cases.
 * The phone sends only its position and case IDs; customer coordinates are always read here (Customer::visibleTo),
 * so the server key can't be used to route to arbitrary places. The phone pre-sorts by straight-line distance and
 * asks for road distances only for the nearest few, and only when the employee has moved or asks again.
 */
final class FieldMapController extends Controller
{
    private const MAX_DISTANCES = 25;   // one Routes API matrix call
    private const MAX_STOPS = 10;       // Google Maps app navigation takes up to 9 waypoints + the destination
    public const ARRIVE_METERS = 100;   // "Check in" shows on the phone within this distance of the pin

    /** GET field/config → the key the phone's map view loads Google Maps with (null = admin has not set one). */
    public function config(GoogleMaps $maps)
    {
        return response()->json(['map_key' => $maps->displayKey(), 'routing' => $maps->configured()]);
    }

    /** POST field/distances {lat, lng, customer_ids[]} → road distance / time per case (null = no road route). */
    public function distances(Request $r, GoogleMaps $maps)
    {
        $d = $this->origin($r, ['customer_ids' => 'required|array|min:1|max:' . self::MAX_DISTANCES]);
        $cases = $this->located($r, $d['customer_ids']);
        $res = $cases->isEmpty() ? [] : $maps->matrix([$d['lat'], $d['lng']], $cases->map(fn ($c) => [$c->lat, $c->lng])->all());
        return response()->json(['items' => $cases->values()->map(fn (Customer $c, int $i) => [
            'id' => $c->id, 'distance_m' => $res[$i]['distance_m'] ?? null, 'duration_s' => $res[$i]['duration_s'] ?? null,
        ])]);
    }

    /** POST field/route {lat, lng, customer_ids[], optimize} → line to draw, totals, and the visiting order. */
    public function route(Request $r, GoogleMaps $maps)
    {
        $d = $this->origin($r, ['customer_ids' => 'required|array|min:1|max:' . self::MAX_STOPS, 'optimize' => 'boolean']);
        $cases = $this->located($r, $d['customer_ids']);
        abort_if($cases->isEmpty(), 422, 'None of these customers has a map location.');
        $route = $maps->route([$d['lat'], $d['lng']], $cases->map(fn ($c) => [$c->lat, $c->lng])->all(), (bool) ($d['optimize'] ?? false));
        $ids = $cases->pluck('id')->all();
        return response()->json([
            'distance_m' => $route['distance_m'], 'duration_s' => $route['duration_s'], 'points' => $route['points'],
            'order' => array_map(fn ($i) => $ids[$i], $route['order']),
            'legs' => $route['legs'],
            'skipped' => array_values(array_diff(array_map('intval', $d['customer_ids']), $ids)),   // no / bad pin
        ]);
    }

    /** POST customers/{id}/geocode → a suggested pin from the case address (not saved until the employee confirms). */
    public function geocode(Request $r, int $id, GoogleMaps $maps)
    {
        $c = Customer::query()->visibleTo($r->user())->findOrFail($id);
        $parts = array_filter(array_map(fn ($v) => trim((string) $v), [$c->address, $c->area, $c->city, $c->state, $c->pincode]));
        abort_if($parts === [], 422, 'This case has no address to look up. Use your current location when you are at the customer.');
        $hit = $maps->geocode(implode(', ', array_unique($parts)), (string) (app(\App\Support\CurrentCompany::class)->get()->default_country ?: 'IN'));
        abort_if($hit === null, 422, 'Google Maps could not find this address. Use your current location when you are at the customer.');
        return response()->json($hit);
    }

    /** POST customers/{id}/location {lat, lng, source: gps|address, accuracy_m?} → saves the case pin, logged on the timeline. */
    public function setLocation(Request $r, int $id)
    {
        $c = Customer::query()->visibleTo($r->user())->findOrFail($id);
        $d = $this->origin($r, ['source' => 'required|in:gps,address', 'accuracy_m' => 'nullable|numeric|min:0|max:100000']);
        abort_unless(Customer::validCoords($d['lat'], $d['lng']), 422, 'That is not a usable map location.');
        $old = Customer::validCoords($c->lat, $c->lng);
        $note = $d['source'] === 'gps' && isset($d['accuracy_m']) ? 'GPS ±' . (int) $d['accuracy_m'] . ' m.' : null;
        // History row + timeline line are written by the Customer model (CustomerAddressChange::capture).
        Customer::changedBy($d['source'] === 'gps' ? 'phone_gps' : 'phone_address', $r->user()->id,
            fn () => $c->forceFill(['lat' => round((float) $d['lat'], 7), 'lng' => round((float) $d['lng'], 7)])->save(), $note);
        Audit::record('customer.location_updated', $c, ['source' => $d['source'], 'had_location' => $old]);
        return response()->json(['id' => $c->id, 'lat' => $c->lat, 'lng' => $c->lng]);
    }

    /**
     * POST customers/{id}/visit {lat, lng, accuracy_m?} → "reached the customer", logged on the timeline.
     * Checked here, not only on the phone: the employee's GPS must be within ARRIVE_METERS of the case pin
     * (plus up to 50 m for GPS drift), so a visit can't be marked from the office.
     */
    public function visit(Request $r, int $id)
    {
        $c = Customer::query()->visibleTo($r->user())->findOrFail($id);
        $d = $this->origin($r, ['accuracy_m' => 'nullable|numeric|min:0|max:100000']);
        abort_unless(Customer::validCoords($c->lat, $c->lng), 422, 'This customer has no map location. Set the pin first.');
        $m = (int) round(GoogleMaps::straightMeters([(float) $d['lat'], (float) $d['lng']], [(float) $c->lat, (float) $c->lng]));
        $acc = isset($d['accuracy_m']) ? (int) $d['accuracy_m'] : null;
        abort_if($m > self::ARRIVE_METERS + min($acc ?? 0, 50), 422, "You are {$m} m from the customer's pin. Check in when you are within " . self::ARRIVE_METERS . ' m.');
        $e = CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $r->user()->id, 'type' => 'visit',
            'body' => "Reached the customer ({$m} m from the pin" . ($acc !== null ? ", ±{$acc} m GPS" : '') . ').',
            'meta' => ['lat' => round((float) $d['lat'], 7), 'lng' => round((float) $d['lng'], 7), 'distance_m' => $m, 'accuracy_m' => $acc]]);
        Audit::record('customer.visited', $c, ['distance_m' => $m]);
        return response()->json(['id' => $c->id, 'distance_m' => $m, 'visited_at' => $e->created_at?->toIso8601String()]);
    }

    private function origin(Request $r, array $more): array
    {
        return $r->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'customer_ids.*' => 'integer'] + $more);
    }

    /** The employee's own cases among $ids that have a usable pin, in the order asked. */
    private function located(Request $r, array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = Customer::query()->visibleTo($r->user())->whereIn('id', $ids)->get(['id', 'lat', 'lng'])->keyBy('id');
        return collect($ids)->map(fn ($id) => $found[$id] ?? null)->filter(fn ($c) => $c && Customer::validCoords($c->lat, $c->lng))->values();
    }
}
