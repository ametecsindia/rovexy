<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Models\Company;
use App\Models\IntegrationConnection;
use App\Models\LocationEvent;
use App\Models\User;
use App\Support\Audit;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Caller ⇄ SmartPRS2 ⇄ SmartEPT field-force link.
 *
 *   SmartPRS2  owns the employee's geofence (Field Force → Geofence Rule) and is the
 *              attendance/payroll system of record:
 *                sign-in      → POST /api/v1/fieldforce/geofence/check  (live, may block)
 *                login/logout → POST /api/v1/fieldforce/attendance      (outbox)
 *   SmartEPT   enforcement + monitoring:
 *                login/logout → POST /api/v1/attendance/punches         (outbox; gate, attendance, derivation)
 *                GPS fixes    → POST /api/v1/fieldforce/locations       (outbox; GEOFENCE_EXIT violations)
 *
 * Employees are matched by employee code (Caller users.employee_code = PRS emp_code = EPT employee_code).
 * Nothing here may break the core app: delivery is via an outbox, and only the deliberate
 * "outside your area" verdict ever stops a sign-in.
 */
final class FieldForceIntegration
{
    private const BATCH = 200;

    private const ENDPOINTS = [
        'smartprs.attendance' => ['/api/v1/fieldforce/attendance', 'events'],
        'smartept.punch' => ['/api/v1/attendance/punches', 'punches'],
        'smartept.location' => ['/api/v1/fieldforce/locations', 'locations'],
    ];

    public function __construct(private readonly CurrentCompany $ctx)
    {
    }

    public function connection(string $system): ?IntegrationConnection
    {
        return IntegrationConnection::query()->where('system', $system)->where('enabled', true)->first();
    }

    /**
     * Called during sign-in, after the password is verified and before a work session exists.
     * Throws outside_geofence when SmartPRS says no. Fail-open (status "unverified") if SmartPRS is unreachable,
     * so a server outage never locks the whole field team out — the attendance row then says so.
     */
    public function geofenceGate(User $user, ?float $lat, ?float $lng): array
    {
        $prs = $this->connection('smartprs');
        if ($prs === null) {
            return ['status' => 'not-connected'];
        }
        try {
            $res = $this->http($prs)->post($prs->url('/api/v1/fieldforce/geofence/check'),
                array_filter(['emp_code' => $user->employee_code, 'lat' => $lat, 'lng' => $lng], fn ($v) => $v !== null));
            $res->throw();
            $v = $res->json();
        } catch (\Throwable $e) {
            Log::warning('fieldforce.geofence_unreachable', ['user' => $user->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return ['status' => 'unverified'];
        }
        if (! empty($v['fence'])) {
            Cache::put($this->fenceKey($user), $v['fence'], now()->addHours(14));
        } else {
            Cache::forget($this->fenceKey($user));
        }
        if (($v['allowed'] ?? true) === false && $prs->enforce_geofence) {
            Audit::record('mobile.login_outside_geofence', $user, ['status' => $v['status'] ?? null, 'distance_km' => $v['distance_km'] ?? null]);
            throw new DomainException('outside_geofence', (string) ($v['message'] ?? 'You are outside your allowed area. Sign-in blocked.'));
        }
        return ['status' => (string) ($v['status'] ?? 'unknown'), 'distance_km' => $v['distance_km'] ?? null];
    }

    /** Sign-in / sign-out → SmartPRS attendance + SmartEPT punch (via the outbox). */
    public function recordSession(User $user, string $type, ?float $lat = null, ?float $lng = null, ?float $acc = null, ?string $geo = null): void
    {
        $this->safely(function () use ($user, $type, $lat, $lng, $acc, $geo) {
            $at = now()->setTimezone($this->tz());
            $id = 'caller-' . $type . '-' . $user->id . '-' . $at->format('YmdHis');
            if ($this->connection('smartprs')) {
                $this->enqueue('smartprs', 'attendance', ['external_id' => $id, 'emp_code' => $user->employee_code, 'type' => $type,
                    'at' => $at->toAtomString(), 'lat' => $lat, 'lng' => $lng, 'accuracy_m' => $acc]);
            }
            if ($this->connection('smartept')) {
                // EPT stores the sender's wall clock (company timezone), like its other punch sources.
                $this->enqueue('smartept', 'punch', array_filter(['external_id' => $id, 'employee_code' => $user->employee_code,
                    'punch_type' => $type === 'login' ? 'IN' : 'OUT', 'punched_at' => $at->format('Y-m-d H:i:s'), 'source' => 'CALLER',
                    'lat' => $lat, 'lng' => $lng, 'accuracy_m' => $acc,
                    'geo_status' => in_array($geo, ['within', 'outside', 'no-gps', 'no-rule', 'unverified'], true) ? $geo : null], fn ($v) => $v !== null));
            }
            $company = $this->ctx->get();
            \Illuminate\Support\defer(fn () => $this->flushCompany($company));   // deliver right after the response
        });
    }

    /** Each GPS fix → SmartEPT, with the verdict against the fence SmartPRS returned at sign-in. */
    public function recordLocation(User $user, LocationEvent $loc): void
    {
        $this->safely(function () use ($user, $loc) {
            if (! $this->connection('smartept')) {
                return;
            }
            $fence = Cache::get($this->fenceKey($user));
            $dist = $fence ? self::haversineKm((float) $fence['lat'], (float) $fence['lng'], (float) $loc->lat, (float) $loc->lng) : null;
            $this->enqueue('smartept', 'location', array_filter([
                'external_id' => 'caller-loc-' . $loc->id, 'employee_code' => $user->employee_code,
                'captured_at' => $loc->captured_at->setTimezone($this->tz())->toAtomString(),
                'lat' => (float) $loc->lat, 'lng' => (float) $loc->lng, 'accuracy_m' => $loc->accuracy_m, 'battery_pct' => $loc->battery_pct,
                'geo_status' => $fence ? ($dist > (float) $fence['limit_km'] ? 'outside' : 'within') : 'no-rule',
                'distance_km' => $dist !== null ? round($dist, 3) : null, 'limit_km' => $fence['limit_km'] ?? null,
            ], fn ($v) => $v !== null));
        });
    }

    /** Deliver due outbox rows for one company. Safe to call often (scheduler + after each request). */
    public function flushCompany(Company $company): void
    {
        $this->ctx->run($company, function () use ($company) {
            foreach (IntegrationConnection::query()->where('enabled', true)->get() as $conn) {
                foreach (self::ENDPOINTS as $key => [$path, $field]) {
                    [$system, $kind] = explode('.', $key);
                    if ($system !== $conn->system) {
                        continue;
                    }
                    $rows = DB::table('integration_outbox')->where('company_id', $company->id)->where('system', $system)->where('kind', $kind)
                        ->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                        ->orderBy('id')->limit(self::BATCH)->get();
                    if ($rows->isNotEmpty()) {
                        $this->deliver($conn, $rows, $path, $field);
                    }
                }
            }
        });
    }

    /** Admin "Test connection": the key works and points at the right customer. */
    public function test(IntegrationConnection $conn): array
    {
        try {
            $res = $this->http($conn)->get($conn->url('/api/v1/ping'));
            $ok = $res->successful();
            $detail = $ok ? (string) ($res->json('company_name') ?? $res->json('company.name') ?? 'connected') : $res->status() . ' ' . mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 150);
        } catch (\Throwable $e) {
            [$ok, $detail] = [false, mb_substr($e->getMessage(), 0, 150)];
        }
        $conn->forceFill(['last_status' => ($ok ? 'OK — ' : 'FAILED — ') . $detail] + ($ok ? ['last_ok_at' => now()] : []))->save();
        return ['ok' => $ok, 'detail' => $detail];
    }

    private function deliver(IntegrationConnection $conn, $rows, string $path, string $field): void
    {
        $ids = $rows->pluck('id')->all();
        try {
            $res = $this->http($conn)->post($conn->url($path), [$field => $rows->map(fn ($r) => json_decode($r->payload, true))->all()]);
        } catch (\Throwable $e) {
            $this->retry($ids, $rows, mb_substr($e->getMessage(), 0, 255), $conn);
            return;
        }
        if ($res->successful()) {
            // Per-item verdicts (duplicate / unknown employee) are final: the receiver has decided.
            DB::table('integration_outbox')->whereIn('id', $ids)->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
            $conn->forceFill(['last_status' => 'OK — delivered ' . count($ids) . ' ' . $rows->first()->kind, 'last_ok_at' => now()])->save();
        } elseif ($res->status() === 422) {
            // The receiver rejected the batch as malformed; retrying the same bytes cannot succeed.
            DB::table('integration_outbox')->whereIn('id', $ids)->update(['status' => 'failed', 'last_error' => mb_substr($res->body(), 0, 255)]);
            $conn->forceFill(['last_status' => 'FAILED — 422 ' . mb_substr((string) $res->json('message', ''), 0, 150)])->save();
        } else {
            $this->retry($ids, $rows, $res->status() . ' ' . mb_substr($res->body(), 0, 200), $conn);
        }
    }

    /** Back off 1, 2, 4 … 60 min; keep trying (a PRS server can be down for a weekend). */
    private function retry(array $ids, $rows, string $error, IntegrationConnection $conn): void
    {
        $attempts = (int) $rows->max('attempts') + 1;
        DB::table('integration_outbox')->whereIn('id', $ids)->update(['attempts' => $attempts, 'last_error' => mb_substr($error, 0, 255),
            'next_attempt_at' => now()->addMinutes(min(60, 2 ** min($attempts - 1, 6)))]);
        $conn->forceFill(['last_status' => 'RETRYING — ' . mb_substr($error, 0, 200)])->save();
    }

    private function enqueue(string $system, string $kind, array $payload): void
    {
        DB::table('integration_outbox')->insert(['company_id' => $this->ctx->id(), 'system' => $system, 'kind' => $kind,
            'payload' => json_encode($payload), 'status' => 'pending', 'created_at' => now()]);
    }

    private function http(IntegrationConnection $c): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['X-Api-Key' => $c->api_key])->acceptJson()->connectTimeout(5)->timeout(15);
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('fieldforce.integration_error', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    private function tz(): string
    {
        return $this->ctx->get()->timezone ?: 'Asia/Kolkata';
    }

    private function fenceKey(User $u): string
    {
        return "ff:fence:{$u->company_id}:{$u->id}";
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
