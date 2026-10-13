<?php

declare(strict_types=1);

namespace App\Domain\Calling;

use App\Domain\DomainException;

/**
 * Server-side call authorisation. The destination ALWAYS comes from the server's customer record;
 * a client-supplied number is only accepted when manual dialling is enabled and it matches the prefix allowlist.
 */
final class CallAuthoriser
{
    /**
     * @param array<string,mixed> $policy effective policy values
     * @param array{id:int, phone:string, alt_phone:?string, status:string, assigned_user_id:?int, assigned_team_id:?int}|null $customer
     * @param array{user_id:int, team_ids:list<int>, compliant:bool, active_call:bool, timezone:string} $ctx
     * @return array{destination:string, record:bool}
     */
    public static function authorise(array $policy, ?array $customer, ?string $requestedNumber, string $which, array $ctx, \DateTimeImmutable $nowUtc): array
    {
        $deny = static fn (string $code, string $msg) => throw new DomainException($code, $msg);

        if (!$policy['calling.enabled']) {
            $deny('calling_disabled', 'Calling is disabled by policy.');
        }
        if (!$ctx['compliant']) {
            $deny('device_not_compliant', 'Device is not compliant with mandatory policy.');
        }
        if ($ctx['active_call']) {
            $deny('call_in_progress', 'Finish the current call first.');
        }
        if (!CallingHours::isOpen($policy['calling.hours'], $ctx['timezone'], $nowUtc)) {
            $deny('outside_calling_hours', 'Outside permitted calling hours.');
        }

        if ($customer !== null) {
            $assigned = $customer['assigned_user_id'] === $ctx['user_id']
                || ($customer['assigned_user_id'] === null && $customer['assigned_team_id'] !== null && in_array($customer['assigned_team_id'], $ctx['team_ids'], true));
            if (!$assigned) {
                $deny('customer_not_assigned', 'Customer is not assigned to you.');
            }
            if ($customer['status'] === 'do_not_call' || $customer['status'] === 'closed') {
                $deny('customer_not_callable', 'Customer status does not permit calls.');
            }
            $destination = match ($which) {
                'primary' => $customer['phone'],
                'alternate' => $customer['alt_phone'] ?? $deny('no_alternate', 'No alternate number on record.'),
                default => $deny('invalid_number_choice', 'Choose primary or alternate.'),
            };
            // A client-supplied number, if any, must match the record exactly (detects tampering).
            if ($requestedNumber !== null && $requestedNumber !== $destination) {
                $deny('destination_mismatch', 'Requested number does not match the customer record.');
            }
        } else {
            if (!$policy['calling.manual_dial']) {
                $deny('manual_dial_disabled', 'Manual dialling is not permitted.');
            }
            $destination = $requestedNumber ?? $deny('destination_required', 'Number required.');
        }

        if (!self::prefixAllowed($destination, $policy['calling.allowed_prefixes'])) {
            $deny('destination_not_allowed', 'Destination is not in the allowed prefixes.');
        }
        return ['destination' => $destination, 'record' => $policy['recording.mode'] !== 'off'];
    }

    /** @param list<string> $prefixes */
    public static function prefixAllowed(string $e164, array $prefixes): bool
    {
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
            return false;
        }
        foreach ($prefixes as $p) {
            if ($p !== '' && str_starts_with($e164, $p)) {
                return true;
            }
        }
        return false;
    }
}
