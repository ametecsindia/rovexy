<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\Call;
use Illuminate\Support\Facades\Http;

/**
 * Click2Call (same provider setup as LeadGER): Telozar c2c_call rings the employee's phone (A party) first, then the
 * customer (B party) from the company VMN, and records the call. The result and recording URL are READ from the Ametecs
 * OBD gateway (click2call_data/{ref_id}/{vmn}); the provider's webhook only triggers that read, its body is never trusted.
 * ref_id = the call's uuid — in LeadGER's dialer format {uuid}_d_{crm_id}_{user_id}_1 when a CRM id is set (the OBD gateway reads the
 * CRM from it and answers 400 to a bare uuid) — so every lookup is exact (never "latest call to this number").
 */
final class Click2CallAdapter implements PolledTelephony
{
    public function __construct(private readonly array $cfg)
    {
    }

    public function name(): string
    {
        return 'click2call';
    }

    /** Digits only; 91XXXXXXXXXX → XXXXXXXXXX (what the gateway expects). */
    public static function normalize(?string $number): string
    {
        $n = preg_replace('/\D/', '', (string) $number);
        return (strlen($n) === 12 && str_starts_with($n, '91')) ? substr($n, 2) : $n;
    }

    /** Reference sent to the provider and used for the gateway lookup (LeadGER WebRTCController format when a CRM id is set). */
    public function refFor(Call $call): string
    {
        // The reference actually sent is kept in provider_call_id (Telozar returns no call id): later lookups use it even
        // if the CRM id in Calling options changes afterwards (otherwise the gateway answers "Click2call Not found" forever).
        if (str_starts_with((string) $call->provider_call_id, $call->uuid . '_d_')) {
            return (string) $call->provider_call_id;
        }
        $crm = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($this->cfg['crm_id'] ?? ''));
        return $crm !== '' ? "{$call->uuid}_d_{$crm}_{$call->user_id}_1" : $call->uuid;   // OBD guide 2.3: dialer Click2Call
    }

    /** Our call uuid from a provider reference (bare uuid or LeadGER format). */
    public static function uuidFromRef(string $ref): string
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $ref, $m) ? strtolower($m[0]) : $ref;
    }

    public function originate(Call $call, array $p): void
    {
        if (empty($this->cfg['start_url']) || (empty($this->cfg['did']) && empty($p['caller_number']))) {
            throw new TelephonyUnavailable('Click2Call is not configured (C2C_START_URL / C2C_DID).');
        }
        $did = self::normalize($p['caller_number'] ?? null) ?: self::normalize($this->cfg['did']);
        $call->forceFill(['caller_number' => $did])->save();   // the CDR is looked up by ref_id + this number
        $ref = $this->refFor($call);
        try {
            $r = Http::timeout((int) $this->cfg['timeout'])->acceptJson()->post($this->cfg['start_url'], [
                'did' => $did, 'a_party_no' => self::normalize($p['agent_target']),
                'b_party_no' => self::normalize($p['customer_e164']), 'ref_id' => $ref,
                // The provider records every call; all three flags are sent, as LeadGER does, to be safe with the gateway.
                'record' => 1, 'recording' => 1, 'record_call' => 1,
            ]);
        } catch (\Throwable $e) {
            throw new TelephonyUnavailable('Click2Call unreachable: ' . $e->getMessage());
        }
        $body = $r->json();
        if (!$r->successful() || !is_array($body) || isset($body['error']) || isset($body['errors'])) {
            throw new TelephonyUnavailable('Click2Call refused the call: HTTP ' . $r->status() . ' ' . mb_substr($r->body(), 0, 200));
        }
        // The provider's own answer, kept on the call's audit trail (Audit log → call.provider_start) to see why a call never rang.
        \App\Support\Audit::record('call.provider_start', $call, ['http' => $r->status(), 'reply' => mb_substr($r->body(), 0, 500)]);
        $id = $body['call_id'] ?? $body['callId'] ?? $body['uuid'] ?? ($body['response']['call_id'] ?? null);
        $call->forceFill(['provider_call_id' => mb_substr((string) ($id ?? $ref), 0, 128)])->save();   // column is 128
    }

    /** Provider dial status text → leg result (LeadGER Helper::callStateFromDialStatus). */
    public static function legState(?string $status): ?string
    {
        $s = preg_replace('/[^a-z]/', '', strtolower((string) $status));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, 'busy')) {
            return 'busy';
        }
        foreach (['noanswer', 'unanswer', 'notanswered', 'missed'] as $k) {
            if (str_contains($s, $k)) {
                return 'no_answer';
            }
        }
        foreach (['fail', 'cancel', 'reject', 'congestion', 'chanunavail'] as $k) {
            if (str_contains($s, $k)) {
                return 'failed';
            }
        }
        foreach (['answer', 'connect', 'completed', 'success'] as $k) {
            if (str_contains($s, $k)) {
                return 'answered';
            }
        }
        return null;
    }

    /** Fetch the gateway record. @return array{cdr:array,recording_url:?string,duration:?int}|null */
    /** Why the last gateway read gave nothing (e.g. HTTP401 = wrong gateway secret), shown on the call as its cause. */
    private string $why = '';
    private string $whyText = '';

    /** Why the last gateway read returned nothing ('' = it returned a record). */
    /** Gateway's verdict on a Secret-Key from its answer to a lookup: true = refused. */
    public static function keyRefused(int $http, mixed $json): bool
    {
        $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? $json['msg'] ?? '') : '';
        return in_array($http, [401, 403], true) || (is_array($json) && in_array((int) ($json['status'] ?? 0), [401, 403], true))
            || preg_match('/secret.*(not|invalid|wrong|mismatch)|unauthori/i', $msg) === 1;
    }

    public function lastProblem(): string
    {
        return $this->why;
    }

    /** The gateway's own message for the last failed read (e.g. "Invalid ref_id"), '' when none. */
    public function lastProblemText(): string
    {
        return $this->whyText;
    }

    private function record(Call $call): ?array
    {
        $this->why = '';
        $this->whyText = '';
        if (empty($this->cfg['cdr_url'])) {
            $this->why = 'NO_URL';
            return null;
        }
        try {
            // ponytail: 10 s (LeadGER 3–5 s) — the gateway sometimes answers slowly while a call is in progress.
            $r = Http::timeout(10)->connectTimeout(5)->acceptJson()->withHeaders(['Secret-Key' => (string) $this->cfg['gateway_secret']])
                ->get(rtrim((string) $this->cfg['cdr_url'], '/') . '/' . rawurlencode($this->refFor($call)) . '/' . rawurlencode($call->caller_number ?: self::normalize($this->cfg['did'])));
        } catch (\Throwable $e) {
            $this->why = 'UNREACHABLE';
            $this->whyText = mb_substr(preg_replace('/[A-Fa-f0-9]{32}/', '…', $e->getMessage()), 0, 160);   // e.g. cURL error 28: timed out
            return null;
        }
        $j = $r->json();
        if (!is_array($j) || (int) ($j['status'] ?? 0) !== 200 || !is_array($j['data'] ?? null)) {
            $this->why = 'HTTP' . $r->status() . (is_array($j) && isset($j['status']) ? '_S' . (int) $j['status'] : '');
            $msg = is_array($j) ? ($j['message'] ?? $j['error'] ?? $j['msg'] ?? null) : null;
            $this->whyText = is_string($msg) ? mb_substr(strip_tags($msg), 0, 160) : '';
            if (preg_match('/secret.*(not|invalid|wrong|mismatch)|unauthori/i', $this->whyText)) {
                $this->why = 'KEY_REFUSED';   // the gateway answers HTTP 200 + {"status":400,"message":"Secret Key not matched"}
            }
            return null;
        }
        $cdr = $j['data']['response'] ?? [];
        if (is_string($cdr)) {
            $cdr = json_decode($cdr, true) ?: [];
        }
        $cdr = is_array($cdr) ? $cdr : [];
        $url = $j['data']['recording_url'] ?? $cdr['RecordVoice'] ?? $cdr['recording_url'] ?? null;
        return ['cdr' => $cdr, 'recording_url' => is_string($url) ? self::httpsUrl($url) : null,
            'duration' => isset($j['data']['duration']) ? (int) $j['data']['duration'] : null];
    }

    /** http(s) link → https link; anything else (e.g. "No Voice") → null. TLS stays on (OBD guide 10). */
    public static function httpsUrl(string $u): ?string
    {
        $u = trim($u);
        $u = str_starts_with($u, 'http://') ? 'https://' . substr($u, 7) : $u;
        return str_starts_with($u, 'https://') && filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
    }

    /** Recording URL from a provider payload (the spellings LeadGER accepts). */
    public static function recordingUrlIn(array $p): ?string
    {
        foreach (['recording_url', 'RecordVoice', 'record_voice', 'record_url', 'audio_url', 'file_url'] as $k) {
            if (is_string($p[$k] ?? null) && ($u = self::httpsUrl($p[$k]))) {
                return $u;
            }
        }
        return null;
    }

    /**
     * $payload = the provider's end-of-call webhook (secret verified): used as the record and treated as final, as the
     * kit does — a final record with talk time or a recording but no dial status is a completed call.
     */
    public function details(Call $call, ?array $payload = null): ?array
    {
        $final = $payload !== null;
        $rec = $final ? ['cdr' => $payload, 'recording_url' => self::recordingUrlIn($payload), 'duration' => null] : $this->record($call);
        if ($rec === null) {
            // No record at all long after the start: the provider never placed the call. End it so it doesn't block calling.
            if ($call->initiated_at && $call->initiated_at->lt(now()->subMinutes(15))) {
                return ['terminal' => true, 'state' => 'failed', 'talk' => 0, 'ring' => null, 'started' => null, 'ended' => null,
                    'recording_url' => null, 'cause' => rtrim('C2C_NO_RECORD_' . $this->why, '_')];
            }
            return null;
        }
        $p = $rec['cdr'];
        $get = fn (string $k) => $p[$k] ?? $p[strtolower($k)] ?? null;
        $a = self::legState($get('A_DIAL_STATUS'));
        $b = self::legState($get('B_DIAL_STATUS'));
        $tz = new \DateTimeZone((string) $this->cfg['timezone']);
        $time = function (string $k) use ($get, $tz): ?\DateTimeImmutable {
            $v = (string) ($get($k) ?? '');
            $t = $v !== '' ? \DateTimeImmutable::createFromFormat('!dmYHis', $v, $tz) : false;
            return $t ?: null;
        };
        $connected = $time('B_PARTY_CONNECTED_TIME');
        $bEnd = $time('B_PARTY_END_TIME');
        $aEnd = $time('A_PARTY_END_TIME');

        $outcome = $b ?? (($a !== null && $a !== 'answered') ? 'agent_unanswered' : ($connected ? 'answered' : null));
        $rawTalk = ($connected && $bEnd) ? max(0, $bEnd->getTimestamp() - $connected->getTimestamp())
            : (int) ($p['talk_duration'] ?? $p['billsec'] ?? $p['BILLSEC'] ?? 0);
        if ($final && $outcome === null && ($rawTalk > 0 || $rec['recording_url'])) {
            $outcome = 'answered';
        }
        $talk = $outcome === 'answered' ? $rawTalk : 0;
        $event = strtolower((string) ($get('EVENT_TYPE') ?? ''));
        $terminal = str_contains($event, 'end') || str_contains($event, 'hangup') || $bEnd || $aEnd || ($outcome !== null && $outcome !== 'answered')
            || ($final && $outcome !== null);
        // Still "ringing" 15 minutes after the start: the result will not come any more (kit sweep).
        if (!$terminal && !$connected && $call->initiated_at && $call->initiated_at->lt(now()->subMinutes(15))) {
            return ['terminal' => true, 'state' => 'failed', 'talk' => 0, 'ring' => null, 'started' => null, 'ended' => null,
                'recording_url' => null, 'cause' => 'C2C_NO_RESULT'];
        }

        $state = match (true) {
            !$terminal => $connected ? 'answered' : ($a === 'answered' ? 'ringing' : 'agent_ringing'),
            $talk > 0 => 'completed',
            $outcome === 'agent_unanswered' => 'agent_unanswered',   // the employee did not pick up; customer never dialled
            $outcome === 'busy' => 'busy',
            $outcome === 'no_answer' => 'no_answer',
            $outcome === 'answered' => 'disconnected',
            default => 'failed',
        };
        $utc = fn (?\DateTimeImmutable $t) => $t?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $duration = $p['DURATION'] ?? $p['duration'] ?? $rec['duration'];
        $cause = 'C2C_A_' . strtoupper((string) ($get('A_DIAL_STATUS') ?: '-')) . '/B_' . strtoupper((string) ($get('B_DIAL_STATUS') ?: '-'));
        return ['terminal' => (bool) $terminal, 'state' => $state, 'talk' => $talk,
            'ring' => $terminal && $duration !== null ? max(0, (int) $duration - $talk) : null,
            'started' => null, 'ended' => $utc($bEnd ?? $aEnd),
            // An unanswered call can carry the provider's recording of the ringing: kept, without talk time (OBD guide 10).
            'recording_url' => $terminal ? $rec['recording_url'] : null, 'cause' => preg_replace('/[^A-Z0-9_\/-]/', '', $cause)];
    }

    public function fetchRecording(string $url): ?string
    {
        // The URL comes from the gateway record or the secret-checked webhook; https only, optional host allow-list
        // (C2C_RECORDING_HOSTS), and the answer must be audio: no error pages saved as recordings (LeadGER guide 9).
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $hosts = (array) ($this->cfg['recording_hosts'] ?? config('ammp.telephony.click2call.recording_hosts', []));
        if (!str_starts_with($url, 'https://') || $host === '' || ($hosts && !in_array($host, $hosts, true))) {
            return null;
        }
        $r = Http::timeout(60)->connectTimeout(15)->get($url);
        $body = $r->body();
        if (!$r->successful() || $body === '' || strlen($body) > 200 * 1024 * 1024
            || str_contains(strtolower((string) $r->header('Content-Type')), 'text/html') || str_starts_with(ltrim(substr($body, 0, 64)), '<')) {
            return null;
        }
        return $body;
    }

    /** The employee is rung from the agent caller ID when set, else from the number this call goes out on. */
    public function callbackNumberFor(Call $call): ?string
    {
        return ($this->cfg['agent_caller_id'] ?? null) ?: ($call->caller_number ?: $this->callbackNumber());
    }

    public function callbackNumber(): ?string
    {
        return ($this->cfg['agent_caller_id'] ?? null) ?: ($this->cfg['did'] ?? null);
    }

    public function hangup(Call $call, string $cause): void
    {
        // No hang-up API; the employee ends the call on the phone and the gateway reports the result.
    }

    public function health(): array
    {
        $missing = array_keys(array_filter(['C2C_START_URL' => $this->cfg['start_url'], 'C2C_CDR_URL' => $this->cfg['cdr_url'],
            'C2C_GATEWAY_SECRET' => $this->cfg['gateway_secret'], 'C2C_DID' => $this->cfg['did']], fn ($v) => empty($v)));
        if ($missing) {
            return ['ok' => false, 'detail' => 'Click2Call not configured: missing ' . implode(', ', $missing)];
        }
        return ['ok' => true, 'detail' => 'Click2Call configured (VMN ' . $this->cfg['did'] . ')'];
    }
}
