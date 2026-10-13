<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\Call;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * Exotel "connect two numbers" (click-to-call): Exotel rings the employee's phone (From) first, then the customer (To),
 * shows the company ExoPhone as caller ID, and records both sides on Exotel. The result is read back from Exotel's
 * call-details API (polling) — the status callback only triggers an earlier read, its body is never trusted.
 * https://developer.exotel.com/api/make-a-call-api · https://developer.exotel.com/api/call-details
 */
final class ExotelAdapter implements TelephonyAdapter
{
    public function __construct(private readonly array $cfg)
    {
    }

    public function name(): string
    {
        return 'exotel';
    }

    private function base(): string
    {
        return sprintf('https://%s/v1/Accounts/%s', $this->cfg['subdomain'], rawurlencode((string) $this->cfg['sid']));
    }

    private function http()
    {
        return Http::withBasicAuth((string) $this->cfg['api_key'], (string) $this->cfg['api_token'])->acceptJson()->timeout(20);
    }

    public function originate(Call $call, array $p): void
    {
        if (empty($this->cfg['sid']) || empty($this->cfg['api_key']) || empty($this->cfg['api_token']) || empty($this->cfg['caller_id'])) {
            throw new TelephonyUnavailable('Exotel is not configured (EXOTEL_SID / EXOTEL_API_KEY / EXOTEL_API_TOKEN / EXOTEL_CALLER_ID).');
        }
        $form = [
            'From' => $p['agent_target'], 'To' => $p['customer_e164'], 'CallerId' => $this->cfg['caller_id'],
            'TimeOut' => (int) $this->cfg['ring_timeout'], 'TimeLimit' => (int) $this->cfg['time_limit'], 'CustomField' => $call->uuid,
            'StatusCallback' => URL::signedRoute('telephony.exotel', ['uuid' => $call->uuid]), 'StatusCallbackContentType' => 'application/json',
            'StatusCallbackEvents[0]' => 'terminal',
        ];
        if ($p['record_path'] !== null) {
            $form += ['Record' => 'true', 'RecordingChannels' => $this->cfg['recording_channels'], 'RecordingFormat' => 'mp3'];
        }
        if (!empty($this->cfg['call_type'])) {
            $form['CallType'] = $this->cfg['call_type'];
        }
        try {
            $r = $this->http()->asForm()->post($this->base() . '/Calls/connect.json', $form);
        } catch (\Throwable $e) {
            throw new TelephonyUnavailable('Exotel unreachable: ' . $e->getMessage());
        }
        $sid = $r->json('Call.Sid');
        if (!$r->successful() || !$sid) {
            throw new TelephonyUnavailable('Exotel refused the call: HTTP ' . $r->status() . ' ' . mb_substr((string) ($r->json('RestException.Message') ?? $r->body()), 0, 200));
        }
        $call->forceFill(['provider_call_id' => $sid])->save();
    }

    /**
     * Current state of a call from Exotel, normalised. Null when Exotel cannot be asked right now.
     * @return array{terminal:bool,state:string,talk:int,ring:?int,started:?string,ended:?string,recording_url:?string,cause:string}|null
     */
    public function details(Call $call): ?array
    {
        if (!$call->provider_call_id) {
            return null;
        }
        try {
            $r = $this->http()->get($this->base() . '/Calls/' . rawurlencode($call->provider_call_id) . '.json', ['details' => 'true']);
        } catch (\Throwable) {
            return null;
        }
        $c = $r->json('Call');
        if (!$r->successful() || !is_array($c)) {
            return null;
        }
        $status = (string) ($c['Status'] ?? '');
        $d = $c['Details'] ?? [];
        $talk = (int) ($d['ConversationDuration'] ?? 0);
        $leg1 = strtolower((string) ($d['Leg1Status'] ?? ''));
        $leg2 = strtolower((string) ($d['Leg2Status'] ?? ''));
        $terminal = in_array($status, ['completed', 'failed', 'busy', 'no-answer', 'canceled'], true);
        $state = match (true) {
            !$terminal => $status === 'in-progress' ? 'answered' : 'agent_ringing',
            $talk > 0 => 'completed',
            $leg1 !== '' && $leg1 !== 'completed' => 'agent_unanswered',   // the employee did not pick up; customer never dialled
            $leg2 === 'busy' || $status === 'busy' => 'busy',
            $leg2 === 'no-answer' || $status === 'no-answer' => 'no_answer',
            $leg2 === 'canceled' || $status === 'canceled' => 'cancelled',
            $status === 'completed' => 'disconnected',
            default => 'failed',
        };
        $tz = new \DateTimeZone((string) $this->cfg['timezone']);
        $iso = fn ($v) => $v ? (new \DateTimeImmutable((string) $v, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null;
        $duration = isset($c['Duration']) ? (int) $c['Duration'] : null;
        return ['terminal' => $terminal, 'state' => $state, 'talk' => $talk, 'ring' => $duration !== null && $terminal ? max(0, $duration - $talk) : null,
            'started' => $iso($c['StartTime'] ?? null), 'ended' => $iso($c['EndTime'] ?? null), 'recording_url' => $c['RecordingUrl'] ?: null,
            'cause' => 'EXOTEL_' . strtoupper(str_replace('-', '_', $status)) . ($leg2 !== '' ? '/' . strtoupper($leg2) : '')];
    }

    /** Recording bytes from Exotel's URL (authenticated with the same API credentials). */
    public function fetchRecording(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (!str_starts_with($url, 'https://') || !(str_ends_with($host, 'exotel.com') || str_ends_with($host, 'exotel.in') || str_ends_with($host, 'amazonaws.com'))) {
            return null; // only ever fetch from Exotel's own storage
        }
        $r = $this->http()->timeout(60)->get($url);
        return $r->successful() ? $r->body() : null;
    }

    public function hangup(Call $call, string $cause): void
    {
        // The connect API has no hang-up call; the employee ends it on the phone and Exotel reports the result.
    }

    public function health(): array
    {
        $missing = array_keys(array_filter(['EXOTEL_SID' => $this->cfg['sid'], 'EXOTEL_API_KEY' => $this->cfg['api_key'], 'EXOTEL_API_TOKEN' => $this->cfg['api_token'], 'EXOTEL_CALLER_ID' => $this->cfg['caller_id']], fn ($v) => empty($v)));
        if ($missing) {
            return ['ok' => false, 'detail' => 'Exotel not configured: missing ' . implode(', ', $missing)];
        }
        // ponytail: config check only; credentials are proven by the first call (a refusal shows on that call and in the log).
        return ['ok' => true, 'detail' => 'Exotel configured (' . $this->cfg['subdomain'] . ', caller ID ' . $this->cfg['caller_id'] . ')'];
    }
}
