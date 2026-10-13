<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Platform\SalesOrder;
use App\Services\Platform\Razorpay;
use App\Services\Platform\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Razorpay webhook: the safety net for payments whose browser callback never arrived (closed tab, network drop). */
final class PaymentWebhookController extends Controller
{
    public function razorpay(Request $r)
    {
        if (!Razorpay::verifyWebhook($r->getContent(), (string) $r->header('X-Razorpay-Signature'))) {
            Log::warning('razorpay.webhook_bad_signature');
            return response()->json(['ok' => false], 400);
        }
        $p = $r->input('payload.payment.entity', []);
        $event = (string) $r->input('event');
        $o = ($p['order_id'] ?? null) ? SalesOrder::query()->where('razorpay_order_id', $p['order_id'])->first() : null;
        // fallback: our order number is the gateway order's receipt
        $o ??= ($rc = $r->input('payload.order.entity.receipt')) ? SalesOrder::query()->where('number', (string) $rc)->first() : null;
        if ($o && in_array($event, ['payment.captured', 'order.paid'], true) && $o->status !== 'paid') {
            if ((int) ($p['amount'] ?? 0) === (int) round($o->total * 100) && ($p['currency'] ?? 'INR') === 'INR') {
                Sales::markPaid($o, 'razorpay', $p['id'] ?? null, $p['id'] ?? null, 'razorpay-webhook');
            } else {   // never silently drop money: staff see it and refund or settle by hand
                \App\Services\Platform\PlatformAudit::record('order.amount_mismatch', $o, ['payment' => $p['id'] ?? null, 'paid' => $p['amount'] ?? null, 'expected' => (int) round($o->total * 100)], 'razorpay-webhook');
                Log::warning('razorpay.amount_mismatch', ['order' => $o->number, 'paid' => $p['amount'] ?? null]);
            }
        } elseif ($o && $event === 'payment.authorized' && $o->status !== 'paid') {
            Razorpay::settle($o, 'razorpay-webhook');   // capture + provision
        } elseif ($o && $event === 'payment.failed') {
            Sales::recordFailure($o, (string) ($p['error_description'] ?? ''), 'razorpay-webhook');
        }
        return response()->json(['ok' => true]);
    }
}
