<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Domain\DomainException;
use App\Models\Platform\SalesOrder;
use Illuminate\Support\Facades\Http;

/** Razorpay Standard Checkout via the REST API (no SDK). Keys come from Super Admin → Settings. */
final class Razorpay
{
    public static function keyId(): ?string
    {
        return PlatformSettings::get('razorpay_key_id') ?: null;
    }

    public static function configured(): bool
    {
        return self::keyId() !== null && PlatformSettings::get('razorpay_key_secret') !== null;
    }

    /** Creates (once) the Razorpay order for this sales order; returns its id. */
    public static function orderFor(SalesOrder $o): string
    {
        // Locked: two clicks at once must not create two gateway orders (the webhook finds our order by the gateway order id).
        return \Illuminate\Support\Facades\DB::transaction(function () use ($o) {
            $o = SalesOrder::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            return $o->razorpay_order_id ?: self::createGatewayOrder($o);
        });
    }

    private static function createGatewayOrder(SalesOrder $o): string
    {
        if (!self::configured()) {
            throw new DomainException('payments_unavailable', 'Online payment is not set up yet. Please contact Ametecs to pay by bank transfer.');
        }
        $res = Http::timeout(20)->withBasicAuth(self::keyId(), (string) PlatformSettings::get('razorpay_key_secret'))
            ->post('https://api.razorpay.com/v1/orders', ['amount' => (int) round($o->total * 100), 'currency' => 'INR', 'receipt' => $o->number,
                'notes' => ['order' => $o->number, 'company' => $o->buyer_company]]);
        if (!$res->successful() || !$res->json('id')) {
            \Illuminate\Support\Facades\Log::warning('razorpay.order_failed', ['order' => $o->number, 'status' => $res->status(), 'error' => $res->json('error.description')]);
            throw new DomainException('payments_unavailable', 'The payment gateway did not respond. Please try again in a minute.');
        }
        $o->forceFill(['razorpay_order_id' => $res->json('id')])->save();
        return $o->razorpay_order_id;
    }

    /**
     * Asks Razorpay what was really paid against this order and settles it: a captured payment of the full amount marks the
     * order paid; an authorised one is captured first. true = paid, false = nothing paid, null = gateway unreachable.
     * Used by the checkout callback and the reconciliation job, so a lost webhook or closed browser never loses a payment.
     */
    public static function settle(SalesOrder $o, string $actor): ?bool
    {
        if ($o->status === 'paid') {
            return true;
        }
        if (!$o->razorpay_order_id || !self::configured()) {
            return false;
        }
        $auth = fn () => Http::timeout(15)->withBasicAuth(self::keyId(), (string) PlatformSettings::get('razorpay_key_secret'));
        try {
            $res = $auth()->get("https://api.razorpay.com/v1/orders/{$o->razorpay_order_id}/payments");
            if (!$res->successful()) {
                return null;
            }
            $amount = (int) round($o->total * 100);
            foreach ((array) $res->json('items', []) as $p) {
                if ((int) ($p['amount'] ?? 0) !== $amount || ($p['currency'] ?? 'INR') !== 'INR') {
                    if (in_array($p['status'] ?? '', ['captured', 'authorized'], true) && \Illuminate\Support\Facades\Cache::add('rzp_mismatch:' . ($p['id'] ?? ''), 1, now()->addDays(30))) {
                        PlatformAudit::record('order.amount_mismatch', $o, ['payment' => $p['id'] ?? null, 'paid' => $p['amount'] ?? null, 'expected' => $amount], $actor);
                    }
                    continue;
                }
                if (($p['status'] ?? '') === 'authorized') {
                    $cap = $auth()->post("https://api.razorpay.com/v1/payments/{$p['id']}/capture", ['amount' => $amount, 'currency' => 'INR']);
                    if (!$cap->successful() || $cap->json('status') !== 'captured') {
                        return null;   // try again on the next run
                    }
                    $p['status'] = 'captured';
                }
                if (($p['status'] ?? '') === 'captured') {
                    Sales::markPaid($o, 'razorpay', $p['id'], $p['id'], $actor);
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('razorpay.settle_failed', ['order' => $o->number, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Checkout callback: signature = HMAC-SHA256(order_id|payment_id, key secret). */
    public static function verifyPayment(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = PlatformSettings::get('razorpay_key_secret');
        return $secret !== null && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    /** Webhook: X-Razorpay-Signature = HMAC-SHA256(raw body, webhook secret). */
    public static function verifyWebhook(string $body, string $signature): bool
    {
        $secret = PlatformSettings::get('razorpay_webhook_secret');
        return $secret !== null && $signature !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }
}
