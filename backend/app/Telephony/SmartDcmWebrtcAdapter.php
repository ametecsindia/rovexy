<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\C2c\Vmn;
use App\Models\Call;
use App\Models\ExternalRef;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\SmartDcm\SmartDcmClient;
use App\Services\SmartDcm\SmartDcmSync;
use Illuminate\Support\Facades\Http;

/**
 * WebRTC calling through the client's SmartDCM (Telozar SIP over WSS): the telecaller talks in the browser.
 *   1. POST {smartdcm}/api/rovexy/v1/webrtc/calls  {ref, user, customer_number, caller_id} → a one-time dial code + the
 *      user's SIP login. The browser dials the CODE, never the customer's number (telecallers don't see full numbers);
 *      SmartDCM's switch maps the code to the customer, sets the caller id and records the call under {ref}.
 *   2. GET  {smartdcm}/api/rovexy/v1/webrtc/calls/{ref} → state, talk time, recording URL (polled like Click2Call).
 * ref = our call uuid.
 */
final class SmartDcmWebrtcAdapter implements PolledTelephony
{
    public const PROVIDER = 'smartdcm_webrtc';

    /** SIP login + dial code from the last originate(), handed to the browser once (never stored). */
    public ?array $session = null;

    public function __construct(private readonly IntegrationConnection $conn)
    {
    }

    public static function connection(): ?IntegrationConnection
    {
        $c = IntegrationConnection::query()->where('system', SmartDcmSync::SYSTEM)->where('enabled', true)->first();
        return $c && $c->option('use_calling', true) && !empty($c->state['webrtc']['enabled']) ? $c : null;
    }

    public function name(): string
    {
        return self::PROVIDER;
    }

    /** Company number shown to the customer: Smart31 rotation if on, else a number flagged WebRTC for the person's groups. */
    public static function callerNumber(User $u): ?string
    {
        if ($n = Click2CallSettings::nextSmart31($u)) {
            return $n;
        }
        $teams = $u->teams()->pluck('teams.id')->map(fn ($i) => (int) $i)->all();
        return Vmn::query()->where('webrtc', true)->orderBy('virtual_number')->get()
            ->first(fn ($v) => $v->isAvailable() && array_intersect(array_map('intval', $v->team_ids ?? []), $teams))?->virtual_number;   // same rule as Click2Call numbers
    }

    public function originate(Call $call, array $p): void
    {
        $user = User::query()->find($call->user_id);
        try {
            $b = (new SmartDcmClient($this->conn))->post('/webrtc/calls', array_filter([
                'ref' => $call->uuid, 'user_id' => ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'user', (int) $call->user_id),
                'employee_code' => $user?->employee_code, 'customer_number' => $p['customer_e164'], 'caller_id' => $p['caller_number'] ?? null,
                'case_id' => $call->customer_id ? ExternalRef::remoteFor(SmartDcmSync::SYSTEM, 'case', (int) $call->customer_id) : null,
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            throw new TelephonyUnavailable('SmartDCM WebRTC: ' . mb_substr($e->getMessage(), 0, 200));
        }
        $sip = (array) ($b['sip'] ?? []);
        if (empty($b['dial']) || empty($sip['wss_url']) || empty($sip['username'])) {
            throw new TelephonyUnavailable('SmartDCM WebRTC: no dial code or SIP login in the answer.');
        }
        $this->session = ['dial' => (string) $b['dial'], 'wss_url' => (string) $sip['wss_url'], 'domain' => (string) ($sip['domain'] ?? parse_url((string) $sip['wss_url'], PHP_URL_HOST)),
            'username' => (string) $sip['username'], 'password' => (string) ($sip['password'] ?? ''), 'display_name' => (string) ($sip['display_name'] ?? $user?->name ?? ''),
            'ice_servers' => array_values((array) ($sip['ice_servers'] ?? []))];
        \App\Support\Audit::record('call.provider_start', $call, ['provider' => self::PROVIDER, 'dial' => $this->session['dial']]);
        $call->forceFill(['provider_call_id' => $call->uuid, 'caller_number' => $p['caller_number'] ?? null])->save();
    }

    public function details(Call $call): ?array
    {
        try {
            $d = (new SmartDcmClient($this->conn))->get('/webrtc/calls/' . $call->uuid);
        } catch (\Throwable) {
            return null;
        }
        $state = (string) ($d['state'] ?? 'ringing');
        $terminal = in_array($state, ['completed', 'failed', 'no_answer', 'busy', 'cancelled'], true);
        $talk = (int) ($d['talk_seconds'] ?? 0);
        return ['terminal' => $terminal, 'state' => $terminal ? ($talk > 0 ? 'completed' : (in_array($state, ['no_answer', 'busy', 'cancelled'], true) ? $state : 'failed')) : ($state === 'answered' ? 'answered' : 'ringing'),
            'talk' => $talk, 'ring' => isset($d['ring_seconds']) ? (int) $d['ring_seconds'] : null, 'started' => $d['started_at'] ?? null, 'ended' => $d['ended_at'] ?? null,
            'recording_url' => filled($d['recording_url'] ?? null) ? (string) $d['recording_url'] : null, 'cause' => (string) ($d['cause'] ?? $state)];
    }

    /** Only from the SmartDCM server itself, with the same key (a recording link can't send our key elsewhere). */
    public function fetchRecording(string $url): ?string
    {
        $base = parse_url($this->conn->url(''), PHP_URL_HOST);
        if (!$base || parse_url($url, PHP_URL_HOST) !== $base || (parse_url($url, PHP_URL_SCHEME) !== 'https' && !app()->environment('local', 'testing'))) {
            return null;
        }
        $r = Http::withHeaders(['X-Api-Key' => $this->conn->api_key])->timeout(60)->get($url);
        return $r->successful() && $r->body() !== '' ? $r->body() : null;
    }

    public function hangup(Call $call, string $cause): void
    {
        try {
            (new SmartDcmClient($this->conn))->post('/webrtc/calls/' . $call->uuid . '/hangup', ['cause' => $cause]);
        } catch (\Throwable) {
            // the browser ends the SIP call itself; this only tells SmartDCM early
        }
    }

    public function health(): array
    {
        try {
            (new SmartDcmClient($this->conn))->get('/ping');
            return ['ok' => true, 'detail' => 'SmartDCM reachable'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    public function callbackNumber(): ?string
    {
        return null;
    }
}
