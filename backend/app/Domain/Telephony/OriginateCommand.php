<?php

declare(strict_types=1);

namespace App\Domain\Telephony;

use App\Domain\DomainException;

/**
 * Builds FreeSWITCH bgapi commands from server-validated values only. Every interpolated value is checked
 * against a strict character class so no client data can inject dialplan/channel variables.
 */
final class OriginateCommand
{
    /**
     * @param array{call_id:string, company_id:int, agent_leg:string, agent_target:string, customer_e164:string,
     *              gateway:string, caller_id:string, record_path:?string, sip_domain?:string, ring_timeout?:int} $p
     */
    public static function build(array $p): string
    {
        $uuid = self::uuid($p['call_id']);
        $gw = self::token($p['gateway'], '/^[A-Za-z0-9_.-]{1,64}$/');
        $cli = self::digits($p['caller_id']);
        $customer = self::digits($p['customer_e164']);
        $timeout = max(10, min(90, (int) ($p['ring_timeout'] ?? 35)));

        $agentVars = [
            'origination_uuid' => $uuid,
            'ametecs_call_id' => $uuid,
            'ametecs_company' => (string) (int) $p['company_id'],
            'ametecs_leg' => 'agent',
            'origination_caller_id_number' => $cli,
            'ignore_early_media' => 'true',
            'originate_timeout' => (string) $timeout,
            'hangup_after_bridge' => 'true',
        ];
        $agentDial = match ($p['agent_leg']) {
            'pstn_callback' => 'sofia/gateway/' . $gw . '/' . self::digits($p['agent_target']),
            'sip_endpoint' => 'user/' . self::token($p['agent_target'], '/^[A-Za-z0-9_.-]{1,64}$/') . '@'
                . self::token($p['sip_domain'] ?? '', '/^[A-Za-z0-9.-]{1,253}$/'),
            default => throw new DomainException('invalid_agent_leg'),
        };
        $customerVars = [
            'ametecs_call_id' => $uuid,
            'ametecs_company' => (string) (int) $p['company_id'],
            'ametecs_leg' => 'customer',
            'origination_caller_id_number' => $cli,
            'leg_timeout' => (string) $timeout,
        ];
        if ($p['record_path'] !== null) {
            $customerVars['execute_on_answer'] = 'record_session::' . self::token($p['record_path'], '#^(?!.*\.\.)[A-Za-z0-9_-][A-Za-z0-9/_.-]{0,254}\.(wav|mp3)$#');
        }
        return 'bgapi originate ' . self::vars('{', '}', $agentVars) . $agentDial
            . ' &bridge(' . self::vars('[', ']', $customerVars) . 'sofia/gateway/' . $gw . '/' . $customer . ')';
    }

    public static function hangup(string $callId, string $cause = 'MANAGER_REQUEST'): string
    {
        return 'bgapi uuid_kill ' . self::uuid($callId) . ' ' . self::token($cause, '/^[A-Z_]{3,40}$/');
    }

    private static function vars(string $open, string $close, array $vars): string
    {
        $parts = [];
        foreach ($vars as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return $open . implode(',', $parts) . $close;
    }

    private static function uuid(string $s): string
    {
        return self::token(strtolower($s), '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
    }

    private static function digits(string $e164): string
    {
        return self::token(ltrim($e164, '+'), '/^[1-9]\d{7,14}$/');
    }

    private static function token(string $v, string $re): string
    {
        if (!preg_match($re, $v)) {
            throw new DomainException('unsafe_telephony_value', 'Refusing to build a telephony command from an unsafe value.');
        }
        return $v;
    }
}
