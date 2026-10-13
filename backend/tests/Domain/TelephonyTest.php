<?php

declare(strict_types=1);

use App\Domain\DomainException;
use App\Domain\Telephony\EslEventMapper;
use App\Domain\Telephony\EslFrameParser;
use App\Domain\Telephony\OriginateCommand;
use PHPUnit\Framework\TestCase;

final class TelephonyTest extends TestCase
{
    private const ID = '0b7c1d2e-3f40-4a51-8b62-7c83d94e05f6';

    public function test_originate_pstn_callback(): void
    {
        $cmd = OriginateCommand::build([
            'call_id' => self::ID, 'company_id' => 4, 'agent_leg' => 'pstn_callback', 'agent_target' => '+919811111111',
            'customer_e164' => '+919876543210', 'gateway' => 'telozar_gw', 'caller_id' => '+914040404040',
            'record_path' => 'ametecs/4/2026/10/' . self::ID . '.wav',
        ]);
        self::assertStringStartsWith('bgapi originate {origination_uuid=' . self::ID . ',', $cmd);
        self::assertStringContainsString('}sofia/gateway/telozar_gw/919811111111 &bridge([', $cmd);
        self::assertStringContainsString('execute_on_answer=record_session::ametecs/4/2026/10/' . self::ID . '.wav]sofia/gateway/telozar_gw/919876543210)', $cmd);
    }

    public function test_originate_rejects_injection(): void
    {
        $base = ['call_id' => self::ID, 'company_id' => 4, 'agent_leg' => 'sip_endpoint', 'agent_target' => 'dev_42', 'sip_domain' => 'sip.acme.in',
            'customer_e164' => '+919876543210', 'gateway' => 'gw', 'caller_id' => '+914040404040', 'record_path' => null];
        self::assertStringContainsString('}user/dev_42@sip.acme.in &bridge(', OriginateCommand::build($base));
        foreach ([['customer_e164' => '+9198,ametecs_leg=x'], ['agent_target' => 'a}b'], ['gateway' => 'g w'], ['record_path' => '../../etc/x.wav'], ['call_id' => 'not-a-uuid']] as $o) {
            try {
                OriginateCommand::build($o + $base);
                self::fail('accepted ' . json_encode($o));
            } catch (DomainException $e) {
                self::assertSame('unsafe_telephony_value', $e->reason);
            }
        }
        self::assertSame('bgapi uuid_kill ' . self::ID . ' MANAGER_REQUEST', OriginateCommand::hangup(strtoupper(self::ID)));
    }

    public function test_esl_parser_handles_fragmented_frames_and_events(): void
    {
        $evBody = "Event-Name: CHANNEL_ANSWER\nCore-UUID: core-1\nEvent-Sequence: 77\nUnique-ID: u-1\n"
            . "Event-Date-Timestamp: 1790000000123456\nvariable_ametecs_call_id: " . self::ID . "\nvariable_ametecs_leg: customer\nvariable_ametecs_company: 4\n"
            . "Caller-Caller-ID-Name: A%20B\n\n";
        $stream = "Content-Type: auth/request\n\n"
            . "Content-Type: command/reply\nReply-Text: +OK accepted\n\n"
            . "Content-Length: " . strlen($evBody) . "\nContent-Type: text/event-plain\n\n" . $evBody;
        $p = new EslFrameParser();
        $frames = [];
        foreach (str_split($stream, 7) as $chunk) {
            array_push($frames, ...$p->feed($chunk));
        }
        self::assertCount(3, $frames);
        self::assertSame('auth/request', $frames[0]['headers']['Content-Type']);
        self::assertSame('+OK accepted', $frames[1]['headers']['Reply-Text']);
        self::assertSame('A B', $frames[2]['event']['Caller-Caller-ID-Name']);

        $m = EslEventMapper::map($frames[2]['event']);
        self::assertSame(['call_id' => self::ID, 'company_id' => 4, 'provider_event_id' => 'core-1:77', 'provider_call_id' => 'u-1', 'type' => 'customer_answered', 'at' => '2026-09-21T14:13:20Z'], $m);
        self::assertNull(EslEventMapper::map(['Event-Name' => 'CHANNEL_ANSWER', 'Core-UUID' => 'c', 'Event-Sequence' => '1']), 'foreign channels ignored');
    }

    public function test_hangup_cause_prefers_bridge_outcome(): void
    {
        $m = EslEventMapper::map(['Event-Name' => 'CHANNEL_HANGUP_COMPLETE', 'Core-UUID' => 'c', 'Event-Sequence' => '9', 'Unique-ID' => 'u',
            'variable_ametecs_call_id' => self::ID, 'variable_ametecs_leg' => 'agent', 'Hangup-Cause' => 'NORMAL_CLEARING', 'variable_originate_disposition' => 'USER_BUSY']);
        self::assertSame('hangup', $m['type']);
        self::assertSame('USER_BUSY', $m['cause']);
    }
}
