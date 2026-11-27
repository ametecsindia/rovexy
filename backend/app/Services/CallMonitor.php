<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Calling\CallLifecycle;
use App\Models\Call;
use App\Models\CallMonitorSession;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Support\Audit;
use App\Domain\Customers\PhoneNormaliser;
use App\Telephony\Click2CallAdapter;
use App\Telephony\Click2CallSettings;
use Illuminate\Support\Facades\Http;

/**
 * Call snooping, as SmartDCM's Live calls → "Join me": the supervisor's own phone is added to a live Click2Call call by the
 * gateway (SmartDCM: Vi CPaaS clicktocall/callConference with cli + call_id + cparty_number). Here the gateway is Telozar,
 * whose conference URL is set in Super Admin → Settings → Click2Call. SIM calls cannot be joined: Android gives no app the
 * audio of a phone call in progress.
 */
final class CallMonitor
{
    /** Click2Call calls in progress now, for the people this user may see. */
    public function live(User $u): array
    {
        $users = $u->usersInView();
        return Call::query()->where('provider', 'click2call')->where('is_test', false)
            ->whereNotIn('state', CallLifecycle::TERMINAL)->where('initiated_at', '>', now()->subHours(2))
            ->when($users !== null, fn ($q) => $q->whereIn('user_id', $users))
            ->with(['user:id,name,employee_code', 'customer:id,customer_ref,name'])
            ->orderByDesc('initiated_at')->limit(200)->get()
            ->map(fn (Call $c) => [
                'id' => $c->id, 'state' => $c->state, 'initiated_at' => $c->initiated_at, 'answered_at' => $c->answered_at,
                'user' => $c->user, 'customer' => $c->customer, 'destination_masked' => PhoneNormaliser::mask($c->destination),
                'caller_number' => $c->caller_number,
            ])->all();
    }

    /** The number the gateway rings for this supervisor: their Click2Call phone, else their phone. */
    public static function listenNumber(User $u): ?string
    {
        $n = Click2CallAdapter::normalize($u->click_agent_number ?: $u->phone);
        return $n !== '' ? $n : null;
    }

    public static function configured(): bool
    {
        return (bool) PlatformSettings::get('c2c_conference_url');
    }

    /**
     * Ask the gateway to add the supervisor's phone to the call. Returns the session (status joined | failed).
     * @throws \DomainException with a message for the console when the call cannot be joined at all
     */
    public function join(User $supervisor, Call $call): CallMonitorSession
    {
        if ($call->provider !== 'click2call') {
            throw new \DomainException('Only Click2Call calls can be joined. SIM calls stay on the phone and cannot be listened to.');
        }
        if (in_array($call->state, CallLifecycle::TERMINAL, true)) {
            throw new \DomainException('This call has already ended.');
        }
        $url = PlatformSettings::get('c2c_conference_url');
        if (!$url) {
            throw new \DomainException('Listening in is not switched on yet: Ametecs has to add Telozar\'s conference API in Super Admin → Settings → Click2Call.');
        }
        $listen = self::listenNumber($supervisor);
        if ($listen === null) {
            throw new \DomainException('Add your phone number to your profile (or your Click2Call phone) so the gateway can ring you.');
        }
        $cfg = Click2CallSettings::effective() ?? config('ammp.telephony.click2call');
        $ref = (new Click2CallAdapter($cfg))->refFor($call);
        $error = null;
        try {
            $r = Http::timeout(15)->acceptJson()->withHeaders(array_filter(['Secret-Key' => $cfg['gateway_secret'] ?? null]))->post($url, [
                // Vi's callConference fields (as SmartDCM) and ROVEXY's Click2Call reference, so either kind of gateway finds the call.
                'cli' => $call->caller_number, 'did' => $call->caller_number, 'call_id' => $call->provider_call_id, 'ref_id' => $ref,
                'cparty_number' => $listen, 'listener_number' => $listen, 'mode' => 'listen',
            ]);
            $body = $r->json();
            $msg = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';
            $bad = !$r->successful() || (is_array($body) && (isset($body['error']) || in_array(strtolower((string) ($body['status'] ?? '')), ['error', 'failed', 'fail', 'false'], true)));
            if ($bad) {
                $error = mb_substr('The gateway refused: HTTP ' . $r->status() . ($msg !== '' ? ' ' . $msg : ' ' . $r->body()), 0, 255);
            }
        } catch (\Throwable $e) {
            $error = mb_substr('The gateway could not be reached: ' . $e->getMessage(), 0, 255);
        }
        $s = CallMonitorSession::query()->create(['call_id' => $call->id, 'user_id' => $supervisor->id, 'listen_number' => $listen,
            'status' => $error ? 'failed' : 'joined', 'error' => $error]);
        Audit::record('call.monitor', $call, ['session' => $s->id, 'agent_id' => $call->user_id, 'listener' => PhoneNormaliser::mask($listen), 'ok' => $error === null]);
        return $s;
    }
}
