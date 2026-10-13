<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Maps Platform, server side: Routes API (driving distance / time, route lines, multi-stop order) and
 * Geocoding API (address → pin). Keys are set by an admin in Integrations → Google Maps (stored encrypted per company,
 * nowhere else). The phone only ever gets the map-display key. Results are cached briefly; Google's raw errors are logged.
 */
final class GoogleMaps
{
    private const CACHE_SECONDS = 600;   // 10 min: traffic changes, a route from the same spot does not
    /** Field agents ride bikes: two-wheeler routes and ETAs (Routes API TWO_WHEELER, supported in India). */
    private const MODE = 'TWO_WHEELER';

    /** Integrations rows: 'gmaps' = server key (Routes + Geocoding), 'gmaps_web' = optional separate map-display key. */
    public const SERVER = 'gmaps';
    public const DISPLAY = 'gmaps_web';

    public function configured(): bool
    {
        return $this->stored(self::SERVER) !== null;
    }

    /** Key the phone's map view loads Maps JavaScript with: the display key, else the server key. */
    public function displayKey(): ?string
    {
        return $this->stored(self::DISPLAY) ?? $this->stored(self::SERVER);
    }

    /** Admin "Save & test": one uncached Geocoding call with the given key. */
    public function test(string $key): array
    {
        // Admin-only screen: Google's own reason is shown, it says exactly what to fix in Google Cloud.
        try {
            $g = Http::timeout(10)->connectTimeout(5)->acceptJson()->get('https://maps.googleapis.com/maps/api/geocode/json', ['address' => 'Hyderabad, India', 'key' => $key]);
            $r = Http::timeout(10)->connectTimeout(5)->acceptJson()->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'routes.distanceMeters'])
                ->post('https://routes.googleapis.com/directions/v2:computeRoutes', ['origin' => self::waypoint([17.4485, 78.3908]), 'destination' => self::waypoint([17.4399, 78.4983]), 'travelMode' => self::MODE]);
        } catch (\Illuminate\Http\Client\HttpClientException) {   // connection / proxy failures
            return ['ok' => false, 'detail' => 'The server could not reach Google (internet / firewall).'];
        }
        $geo = ($g->json('status') === 'OK') ? 'Geocoding API ✓' : 'Geocoding API ✗ ' . trim((string) ($g->json('status') ?? $g->status()) . ' ' . (string) ($g->json('error_message') ?? ''));
        $rt = $r->successful() ? 'Routes API ✓' : 'Routes API ✗ ' . self::reason($r);
        return ['ok' => $g->json('status') === 'OK' && $r->successful(), 'detail' => $geo . ' · ' . $rt
            . ' · Maps JavaScript API is checked by the phone map (it shows Google\'s error there).'];
    }

    /** Google's error text from a Routes / JSON API response (objects or the matrix's [ {error} ] array). */
    private static function reason(\Illuminate\Http\Client\Response $r): string
    {
        $e = $r->json('error') ?? $r->json('0.error') ?? [];
        return trim($r->status() . ' ' . ($e['status'] ?? '') . ' ' . mb_substr((string) ($e['message'] ?? mb_substr($r->body(), 0, 200)), 0, 300));
    }

    private function stored(string $system): ?string
    {
        $k = \App\Models\IntegrationConnection::query()->where('system', $system)->where('enabled', true)->first()?->api_key;
        return filled($k) ? (string) $k : null;
    }

    /**
     * Driving distance and time from one origin to each destination (≤ 25, one Routes API matrix call for the misses).
     * Returns one entry per destination, in order: ['distance_m' => int, 'duration_s' => int] or null (no road route).
     * Element results are cached per (origin to ~100 m, destination) so moving a few steps or reopening the map is free.
     *
     * @param array{0: float, 1: float} $origin
     * @param list<array{0: float, 1: float}> $dests
     */
    public function matrix(array $origin, array $dests): array
    {
        $origin = self::round($origin);
        $keys = array_map(fn ($d) => 'maps:mx2:' . $this->pref() . ':' . implode(',', $origin) . ':' . implode(',', self::round($d)), $dests);
        $out = [];
        $miss = [];
        foreach ($keys as $i => $k) {
            $hit = Cache::get($k);
            $hit === null ? $miss[] = $i : $out[$i] = $hit === 'none' ? null : $hit;
        }
        if ($miss) {
            $rows = $this->post('/distanceMatrix/v2:computeRouteMatrix', [
                'origins' => [['waypoint' => self::waypoint($origin)]],
                'destinations' => array_map(fn ($i) => ['waypoint' => self::waypoint($dests[$i])], $miss),
                'travelMode' => self::MODE, 'routingPreference' => $this->pref(),
            ], 'originIndex,destinationIndex,distanceMeters,duration,condition');
            $got = [];
            foreach ($rows as $e) {   // proto3 JSON leaves out zero values, so index 0 arrives as "missing"
                if (($e['condition'] ?? '') === 'ROUTE_EXISTS') {
                    $got[(int) ($e['destinationIndex'] ?? 0)] = ['distance_m' => (int) ($e['distanceMeters'] ?? 0), 'duration_s' => self::seconds($e['duration'] ?? '0s')];
                }
            }
            foreach ($miss as $j => $i) {
                $out[$i] = $got[$j] ?? null;
                Cache::put($keys[$i], $out[$i] ?? 'none', self::CACHE_SECONDS);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Driving route from the origin through the stops. With $optimize and 2+ stops, the farthest stop (straight line)
     * becomes the end and Google orders the rest; 'order' gives the stop indices in driving order.
     *
     * @param list<array{0: float, 1: float}> $stops
     * @return array{distance_m: int, duration_s: int, points: list<array{0: float, 1: float}>, order: list<int>, legs: list<array{distance_m: int, duration_s: int}>}
     */
    public function route(array $origin, array $stops, bool $optimize): array
    {
        $key = 'maps:rt2:' . $this->pref() . ':' . sha1(json_encode([self::round($origin), $stops, $optimize]));
        if (($hit = Cache::get($key)) !== null) return $hit;

        $idx = array_keys($stops);
        $last = count($stops) - 1;
        if ($optimize && count($stops) > 1) {
            $far = array_map(fn ($s) => self::straightMeters($origin, $s), $stops);
            $last = array_search(max($far), $far, true);
        }
        $between = array_values(array_filter($idx, fn ($i) => $i !== $last));
        $body = ['origin' => self::waypoint($origin), 'destination' => self::waypoint($stops[$last]), 'travelMode' => self::MODE, 'routingPreference' => $this->pref()];
        if ($between) {
            $body['intermediates'] = array_map(fn ($i) => self::waypoint($stops[$i]), $between);
            if ($optimize) $body['optimizeWaypointOrder'] = true;
        }
        $r = $this->post('/directions/v2:computeRoutes', $body,
            'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.legs.distanceMeters,routes.legs.duration,routes.optimizedIntermediateWaypointIndex');
        $route = $r['routes'][0] ?? throw new DomainException('maps_no_route', 'No road route was found to this customer.');
        $opt = $route['optimizedIntermediateWaypointIndex'] ?? [];
        $order = ($optimize && $opt && min($opt) >= 0) ? array_map(fn ($k) => $between[$k], $opt) : $between;
        $res = [
            'distance_m' => (int) ($route['distanceMeters'] ?? 0),
            'duration_s' => self::seconds($route['duration'] ?? '0s'),
            'points' => self::decodePolyline($route['polyline']['encodedPolyline'] ?? ''),
            'order' => [...$order, $last],
            'legs' => array_map(fn ($l) => ['distance_m' => (int) ($l['distanceMeters'] ?? 0), 'duration_s' => self::seconds($l['duration'] ?? '0s')], $route['legs'] ?? []),
        ];
        Cache::put($key, $res, self::CACHE_SECONDS);
        return $res;
    }

    /**
     * Address → pin, or null when Google finds nothing. 'precise' is false for area-level matches (APPROXIMATE),
     * which are too coarse to sort customers by distance.
     *
     * @return array{lat: float, lng: float, formatted_address: string, precise: bool}|null
     */
    public function geocode(string $address, string $region): ?array
    {
        $key = 'maps:gc:' . sha1(mb_strtolower($address) . '|' . $region);
        if (($hit = Cache::get($key)) !== null) return $hit === 'none' ? null : $hit;
        $res = $this->send(fn ($http) => $http->get('https://maps.googleapis.com/maps/api/geocode/json',
            ['address' => $address, 'region' => strtolower($region), 'key' => $this->key()]));
        $status = $res['status'] ?? 'UNKNOWN_ERROR';
        if ($status === 'ZERO_RESULTS') {
            Cache::put($key, 'none', 86400);
            return null;
        }
        if ($status !== 'OK') $this->fail('geocode', $status, (string) ($res['error_message'] ?? ''));
        $g = $res['results'][0];
        $out = ['lat' => (float) $g['geometry']['location']['lat'], 'lng' => (float) $g['geometry']['location']['lng'],
            'formatted_address' => (string) ($g['formatted_address'] ?? ''), 'precise' => ($g['geometry']['location_type'] ?? '') !== 'APPROXIMATE'];
        Cache::put($key, $out, 86400);   // addresses don't move
        return $out;
    }

    /** Google's encoded polyline → [[lat, lng], …]. */
    public static function decodePolyline(string $enc): array
    {
        $pts = [];
        $i = $lat = $lng = 0;
        $n = strlen($enc);
        while ($i < $n) {
            foreach (['lat', 'lng'] as $axis) {
                $shift = $result = 0;
                do {
                    $b = ord($enc[$i++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20 && $i < $n);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $axis === 'lat' ? $lat += $delta : $lng += $delta;
            }
            $pts[] = [round($lat / 1e5, 5), round($lng / 1e5, 5)];
        }
        return $pts;
    }

    /** Great-circle ("as the crow flies") distance in metres — a pre-filter only, never shown as road distance. */
    public static function straightMeters(array $a, array $b): float
    {
        [$la1, $lo1, $la2, $lo2] = array_map('deg2rad', [$a[0], $a[1], $b[0], $b[1]]);
        $h = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(($lo2 - $lo1) / 2) ** 2;
        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }

    private function post(string $path, array $body, string $fields): array
    {
        return $this->send(fn ($http) => $http->withHeaders(['X-Goog-Api-Key' => $this->key(), 'X-Goog-FieldMask' => $fields])
            ->post('https://routes.googleapis.com' . $path, $body));
    }

    private function send(callable $call): array
    {
        try {
            $res = $call(Http::timeout(10)->connectTimeout(5)->acceptJson());
        } catch (\Illuminate\Http\Client\HttpClientException $e) {   // connection / proxy failures
            $this->fail('network', 'unreachable', $e->getMessage());
        }
        if (!$res->successful()) {
            $this->fail('http', (string) $res->status(), self::reason($res));
        }
        return (array) $res->json();
    }

    /** Key/quota/billing/network problems: logged for the admin, one plain message for the employee. */
    private function fail(string $where, string $status, string $detail): never
    {
        Log::warning('google_maps.error', ['where' => $where, 'status' => $status, 'detail' => mb_substr($detail, 0, 300)]);
        throw new DomainException('maps_unavailable', match (true) {
            in_array($status, ['429', 'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT'], true) => 'Too many map requests right now. Road distances will be back in a few minutes.',
            in_array($status, ['400', '401', '403', 'REQUEST_DENIED'], true) => 'Road distances are off: Google refused the key (admin: Integrations → Google Maps → Save & test shows why).',
            default => 'Road distances are not available right now (no connection to Google).',
        });
    }

    private function key(): string
    {
        return $this->stored(self::SERVER)
            ?? throw new DomainException('maps_not_configured', 'Driving distances are not set up yet (admin: Integrations → Google Maps). Straight-line distance is shown instead.');
    }

    private function pref(): string
    {
        return config('ammp.maps.traffic_aware') ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE';
    }

    private static function waypoint(array $p): array
    {
        return ['location' => ['latLng' => ['latitude' => (float) $p[0], 'longitude' => (float) $p[1]]]];
    }

    /** ~110 m grid: nearby origins share cache entries; far below what changes a driving ETA. */
    private static function round(array $p): array
    {
        return [round((float) $p[0], 3), round((float) $p[1], 3)];
    }

    private static function seconds(string $d): int
    {
        return (int) round((float) rtrim($d, 's'));
    }
}
