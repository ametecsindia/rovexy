<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\Call;
use Illuminate\Support\Facades\Http;

/**
 * Click2Call (same provider setup as LeadGER): Telozar c2c_call rings the employee's phone (A party) first, then the
 * customer (B party) from the company VMN, and records the call. The result and recording URL are READ from the Ametecs
 * OBD gateway (click2call_data/{ref_id}/{vmn}); the provider's webhook only triggers that read, its body is never trusted.
 * ref_id = the call's uuid, so every lookup is exact (never "latest call to this number").
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

    public function originate(Call $call, array $p): void
    {
        if (empty($this->cfg['start_url']) || (empty($this->cfg['did']) && empty($p['caller_number']))) {
            throw new TelephonyUnavailable('Click2Call is not configured (C2C_START_URL / C2C_DID).');
        }
        $did = self::normalize($p['caller_number'] ?? null) ?: self::normalize($this->cfg['did']);
        $call->forceFill(['caller_number' => $did])->save();   // the CDR is looked up by ref_id + this number
        try {
            $r = Http::timeout((int) $this->cfg['timeout'])->acceptJson()->post($this->cfg['start_url'], [
                'did' => $did, 'a_party_no' => self::normalize($p['agent_target']),
                'b_party_no' => self::normalize($p['customer_e164']), 'ref_id' => $call->uuid,
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
        $call->forceFill(['provider_call_id' => mb_substr((string) ($id ?? $call->uuid), 0, 64)])->save();
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

    private function record(Call $call): ?array
    {
        $this->why = '';
        if (empty($this->cfg['cdr_url'])) {
            $this->why = 'NO_URL';
            return null;
        }
        try {
            $r = Http::timeout(5)->acceptJson()->withHeaders(['Secret-Key' => (string) $this->cfg['gateway_secret']])
                ->get(rtrim((string) $this->cfg['cdr_url'], '/') . '/' . rawurlencode($call->uuid) . '/' . rawurlencode($call->caller_number ?: self::normalize($this->cfg['did'])));
        } catch (\Throwable) {
            $this->why = 'UNREACHABLE';
            return null;
        }
        $j = $r->json();
        if (!is_array($j) || (int) ($j['status'] ?? 0) !== 200 || !is_array($j['data'] ?? null)) {
            $this->why = 'HTTP' . $r->status() . (is_array($j) && isset($j['status']) ? '_S' . (int) $j['status'] : '');
            return null;
        }
        $cdr = $j['data']['response'] ?? [];
        if (is_string($cdr)) {
            $cdr = json_decode($cdr, true) ?: [];
        }
        $cdr = is_array($cdr) ? $cdr : [];
        $url = $j['data']['recording_url'] ?? $cdr['RecordVoice'] ?? $cdr['recording_url'] ?? null;
        return ['cdr' => $cdr, 'recording_url' => is_string($url) && $url !== '' ? $url : null,
            'duration' => isset($j['data']['duration']) ? (int) $j['data']['duration'] : null];
    }

    /** Recording URL from a provider payload (the spellings LeadGER accepts). */
    public static function recordingUrlIn(array $p): ?string
    {
        foreach (['recording_url', 'RecordVoice', 'record_voice', 'record_url', 'audio_url', 'file_url'] as $k) {
            if (is_string($p[$k] ?? null) && str_starts_with($p[$k], 'https://')) {
                return $p[$k];
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
            'recording_url' => $talk > 0 ? $rec['recording_url'] : null, 'cause' => preg_replace('/[^A-Z0-9_\/-]/', '', $cause)];
    }

    public function fetchRecording(string $url): ?string
    {
        // ponytail: the URL comes from the authenticated gateway record, never from the webhook body; https only.
        // Add a host allow-list (C2C_RECORDING_HOSTS) if the provider's storage host is ever known to be fixed.
        if (!str_starts_with($url, 'https://')) {
            return null;
        }
        $r = Http::timeout(60)->connectTimeout(15)->get($url);
        return $r->successful() && $r->body() !== '' ? $r->body() : null;
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
