<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telephony;

use App\Http\Controllers\Controller;
use App\Services\CallService;
use Illuminate\Http\Request;

/**
 * HMAC-SHA256 signed lifecycle events from a compatible Ametecs telephony system:
 * X-Telephony-Timestamp, X-Telephony-Signature = hex(HMAC(secret, timestamp . "." . body)).
 * Body: {call_id, company_id, provider_event_id, type, at, cause?, provider_call_id?}
 */
final class TelephonyWebhookController extends Controller
{
    public function store(Request $r, CallService $calls)
    {
        $secret = (string) config('ammp.telephony.webhook_secret');
        $ts = (string) $r->header('X-Telephony-Timestamp');
        $sig = (string) $r->header('X-Telephony-Signature');
        if ($secret === '' || !ctype_digit($ts) || abs(time() - (int) $ts) > 300
            || !hash_equals(hash_hmac('sha256', $ts . '.' . $r->getContent(), $secret), $sig)) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $e = $r->validate([
            'call_id' => 'required|uuid', 'company_id' => 'required|integer', 'provider_event_id' => 'required|string|max:128',
            'type' => 'required|in:agent_ringing,agent_answered,customer_ringing,customer_answered,customer_hangup,hangup,failed,recording_started,recording_stopped',
            'at' => 'required|date', 'cause' => 'nullable|string|max:48', 'provider_call_id' => 'nullable|string|max:128',
        ]);
        $e['at'] = (new \DateTimeImmutable($e['at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        return response()->json(['result' => $calls->ingest($e)]);
    }
}
