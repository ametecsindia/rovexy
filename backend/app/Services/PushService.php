<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ManagedDevice;
use App\Models\PushRegistration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Data-only "please sync" pings. Never carries customer data, recordings or credentials, and is never the source
 * of truth: the app always pulls state over authenticated HTTPS, and also polls/reconnects when push is unavailable.
 */
final class PushService
{
    public const REASONS = ['approval', 'revoked', 'reassigned', 'policy', 'customers', 'chat', 'media', 'command'];

    public function ping(ManagedDevice $device, string $reason): bool
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown push reason.');
        }
        $reg = PushRegistration::query()->where('device_id', $device->id)->where('status', 'active')->first();
        if ($reg === null) {
            return false;
        }
        $driver = self::driver();
        if ($driver === 'log') {
            if (app()->isProduction()) {
                throw new \RuntimeException('PUSH_DRIVER=log is not allowed in production.');
            }
            Log::info('[DEV PUSH — NOT SENT] sync ping', ['device_id' => $device->id, 'reason' => $reason]);
            return true;
        }
        if (!PushCredential::ready()) {
            return false;   // no Firebase credential yet (e.g. licence not checked in): the app syncs by polling
        }
        try {
            $resp = Http::withToken(PushCredential::accessToken())->timeout(5)->post(
                'https://fcm.googleapis.com/v1/projects/' . PushCredential::projectId() . '/messages:send',
                ['message' => [
                    'token' => $reg->token,
                    'data' => ['type' => 'sync', 'reason' => $reason],   // internal destination chosen by the app, never a URL
                    'android' => ['priority' => 'HIGH', 'ttl' => '600s'],
                ]],
            );
        } catch (\Throwable $e) {
            $reg->forceFill(['last_error' => 'transport: ' . mb_substr($e->getMessage(), 0, 200)])->save();
            return false;
        }
        if ($resp->successful()) {
            $reg->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
            return true;
        }
        $code = (string) data_get($resp->json(), 'error.details.0.errorCode', $resp->status());
        $reg->forceFill(['last_error' => $code, 'status' => in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true) ? 'invalid' : 'active'])->save();
        return false;
    }

    /**
     * 'fcm' as soon as a Firebase credential is stored (Super Admin / licence, G3) — no .env change needed; otherwise the
     * old PUSH_DRIVER from .env ('log' in development).
     */
    public static function driver(): string
    {
        return PushCredential::ready() ? 'fcm' : (string) config('ammp.push.driver');
    }
}
