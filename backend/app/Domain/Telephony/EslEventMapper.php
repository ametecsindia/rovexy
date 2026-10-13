<?php

declare(strict_types=1);

namespace App\Domain\Telephony;

/**
 * Maps FreeSWITCH channel events (tagged by OriginateCommand with ametecs_* variables) to platform call events.
 * provider_event_id = Core-UUID:Event-Sequence, which is unique per FreeSWITCH instance → used for idempotency.
 */
final class EslEventMapper
{
    /** @param array<string,string> $e @return array{call_id:string, company_id:int, provider_event_id:string, type:string, at:string, cause?:string, provider_call_id:string, record_path?:string}|null */
    public static function map(array $e): ?array
    {
        $callId = $e['variable_ametecs_call_id'] ?? null;
        $leg = $e['variable_ametecs_leg'] ?? null;
        if ($callId === null || $leg === null || !isset($e['Event-Name'], $e['Core-UUID'], $e['Event-Sequence'])) {
            return null; // not ours
        }
        $type = match ([$e['Event-Name'], $leg]) {
            ['CHANNEL_PROGRESS', 'agent'], ['CHANNEL_PROGRESS_MEDIA', 'agent'] => 'agent_ringing',
            ['CHANNEL_ANSWER', 'agent'] => 'agent_answered',
            ['CHANNEL_PROGRESS', 'customer'], ['CHANNEL_PROGRESS_MEDIA', 'customer'] => 'customer_ringing',
            ['CHANNEL_ANSWER', 'customer'] => 'customer_answered',
            ['CHANNEL_HANGUP_COMPLETE', 'agent'] => 'hangup',
            ['CHANNEL_HANGUP_COMPLETE', 'customer'] => 'customer_hangup',
            ['RECORD_START', 'customer'] => 'recording_started',
            ['RECORD_STOP', 'customer'] => 'recording_stopped',
            default => null,
        };
        if ($type === null) {
            return null;
        }
        $micro = (int) ($e['Event-Date-Timestamp'] ?? 0);
        $at = $micro > 0
            ? (new \DateTimeImmutable('@' . intdiv($micro, 1_000_000)))->format('Y-m-d\TH:i:s\Z')
            : gmdate('Y-m-d\TH:i:s\Z');
        $out = [
            'call_id' => strtolower($callId),
            'company_id' => (int) ($e['variable_ametecs_company'] ?? 0),
            'provider_event_id' => $e['Core-UUID'] . ':' . $e['Event-Sequence'],
            'provider_call_id' => $e['Unique-ID'] ?? '',
            'type' => $type,
            'at' => $at,
        ];
        if (isset($e['Hangup-Cause'])) {
            // On the agent (A) leg, the customer leg's outcome surfaces via bridge variables. Verify on the target FS build.
            $disp = $e['variable_originate_disposition'] ?? null;
            $out['cause'] = $e['variable_last_bridge_hangup_cause']
                ?? (($disp !== null && $disp !== 'SUCCESS' && $leg === 'agent') ? $disp : null)
                ?? $e['Hangup-Cause'];
        }
        if (isset($e['Record-File-Path'])) {
            $out['record_path'] = $e['Record-File-Path'];
        }
        return $out;
    }
}
