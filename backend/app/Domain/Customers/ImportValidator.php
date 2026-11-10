<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\DomainException;
use App\Domain\Portfolios\PortfolioTypes;

/**
 * Validates mapped import rows. Pure: lookups for existing data are injected as closures.
 * Duplicates: within the file by customer_ref and by phone; against existing company records by customer_ref
 * (=> update) and by phone owned by a different customer_ref (=> error, needs review).
 */
final class ImportValidator
{
    /** Fields every portfolio type has. Map pins are never taken from a file: they come from the address (AutoGeocode). */
    public const CORE = ['customer_ref', 'name', 'phone', 'alt_phone', 'campaign', 'assigned_employee', 'assigned_team', 'notes', 'status', 'follow_up_date', 'city', 'area', 'pincode',
        'email', 'address', 'state', 'preferred_time'];
    /** Collections (the original fields), for callers that don't name a type. */
    public const FIELDS = [...self::CORE, 'loan_amount', 'outstanding', 'principal_os', 'interest_os', 'charges', 'overdue_amount',
        'emi_amount', 'due_date', 'oldest_due_date', 'dpd', 'overdue_installments', 'last_paid_on', 'last_paid_amount'];
    public const MONEY = ['loan_amount', 'outstanding', 'principal_os', 'interest_os', 'charges', 'overdue_amount', 'emi_amount', 'last_paid_amount'];
    public const DATES = ['due_date', 'oldest_due_date', 'last_paid_on'];
    public const STATUSES = ['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'];

    private const SYNONYMS = [
        'customer_ref' => ['customer id', 'customer_id', 'cust id', 'id', 'account', 'account no', 'loan no', 'ref', 'consignee id', 'lead id', 'contact id', 'patient id', 'case id', 'reference id'],
        'name' => ['customer name', 'name', 'full name', 'borrower', 'consignee name', 'consignee', 'lead name', 'contact name', 'contact person', 'patient name', 'patient / doctor name', 'doctor / patient name'],
        'phone' => ['phone', 'phone number', 'mobile', 'mobile number', 'contact', 'number'],
        'alt_phone' => ['alternate number', 'alternate', 'alt phone', 'alt mobile', 'phone 2', 'secondary'],
        'campaign' => ['campaign', 'portfolio', 'bucket'],
        'assigned_employee' => ['caller id', 'caller', 'telecaller id', 'telecaller', 'assigned employee', 'employee', 'agent', 'employee code', 'agent id', 'user id'],
        'assigned_team' => ['assigned team', 'team', 'team code'],
        'notes' => ['notes', 'remarks', 'comment'],
        'status' => ['status'],
        'city' => ['city', 'town', 'district', 'location', 'branch city'],
        'area' => ['area', 'locality', 'zone', 'region', 'branch'],
        'pincode' => ['pincode', 'pin code', 'pin', 'zip', 'postal code'],
        'email' => ['email', 'email id', 'e-mail', 'mail id'],
        'address' => ['address', 'residential address', 'full address', 'customer address', 'current address', 'address line 1'],
        'state' => ['state'],
        'preferred_time' => ['preferred time', 'preferred contact time', 'best time to call', 'contact time'],
        'follow_up_date' => ['follow-up date', 'follow up date', 'followup', 'follow_up', 'ptp date', 'next action'],
    ];

    /** Field keys of a portfolio type (core + its own). */
    public static function fieldsFor(?string $type): array
    {
        return [...self::CORE, ...array_column(PortfolioTypes::fields($type), 'key')];
    }

    /** @return array<string, list<string>> field => header names it matches */
    public static function synonyms(?string $type): array
    {
        $out = self::SYNONYMS;
        foreach (PortfolioTypes::fields($type) as $f) {
            $out[$f['key']] = array_values(array_unique([...($out[$f['key']] ?? []), ...$f['aliases'], strtolower($f['label'])]));
        }
        return $out;
    }

    /** @param list<string> $headers @return array<string,?int> field => header index */
    public static function suggestMapping(array $headers, ?string $type = null): array
    {
        $norm = array_map(static fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $h) ?? '')), $headers);
        $out = [];
        $taken = [];
        foreach (self::synonyms($type) as $field => $syn) {
            $out[$field] = null;
            foreach ($norm as $i => $h) {
                if (!isset($taken[$i]) && ($h === $field || in_array($h, $syn, true))) {   // one column feeds one field
                    $out[$field] = $i;
                    $taken[$i] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,?string>> $rows mapped rows (field => raw string)
     * @param callable(string):?string $existingRefForPhone returns the customer_ref owning a phone in this company
     * @param callable(string):bool $employeeExists
     * @param callable(string):bool $teamExists
     * @return array{valid: list<array<string,mixed>>, errors: list<array{row:int,field:string,code:string,message:string}>}
     */
    public static function validate(array $rows, string $defaultCountry, callable $existingRefForPhone, callable $employeeExists, callable $teamExists, int $firstRowNumber = 2, ?string $type = null): array
    {
        $valid = [];
        $errors = [];
        $typeFields = PortfolioTypes::fields($type);
        $seenRef = [];
        $seenPhone = [];
        foreach ($rows as $i => $raw) {
            $n = $firstRowNumber + $i;
            $rowErrors = [];
            $err = static function (string $field, string $code, string $message) use (&$rowErrors, $n): void {
                $rowErrors[] = ['row' => $n, 'field' => $field, 'code' => $code, 'message' => $message];
            };
            $get = static fn (string $f): string => trim((string) ($raw[$f] ?? ''));

            $ref = $get('customer_ref');
            $name = $get('name');
            if ($ref === '' || strlen($ref) > 64) {
                $err('customer_ref', 'required', 'Customer ID is required (max 64 chars).');
            }
            if ($name === '' || mb_strlen($name) > 150) {
                $err('name', 'required', 'Customer name is required (max 150 chars).');
            }
            $phone = null;
            try {
                $phone = PhoneNormaliser::normalise($get('phone'), $defaultCountry);
            } catch (DomainException $e) {
                $err('phone', 'invalid_phone', $e->getMessage());
            }
            $alt = null;
            if ($get('alt_phone') !== '') {
                try {
                    $alt = PhoneNormaliser::normalise($get('alt_phone'), $defaultCountry);
                } catch (DomainException $e) {
                    $err('alt_phone', 'invalid_phone', $e->getMessage());
                }
            }
            $status = strtolower($get('status')) ?: 'new';
            $status = str_replace([' ', '-'], '_', $status);
            if (!in_array($status, self::STATUSES, true)) {
                $err('status', 'invalid_status', 'Status must be one of: ' . implode(', ', self::STATUSES) . '.');
            }
            $followUp = null;
            if ($get('follow_up_date') !== '') {
                $followUp = self::parseDate($get('follow_up_date'));
                if ($followUp === null) {
                    $err('follow_up_date', 'invalid_date', 'Use YYYY-MM-DD or DD/MM/YYYY.');
                }
            }
            $emp = $get('assigned_employee') ?: null;
            if ($emp !== null && !$employeeExists($emp)) {
                $err('assigned_employee', 'unknown_employee', "Caller ID \"{$emp}\" is not an active employee's User ID here. Fix it, or leave Caller ID blank and assign later.");
            }
            $team = $get('assigned_team') ?: null;
            if ($team !== null && !$teamExists($team)) {
                $err('assigned_team', 'unknown_team', "Group \"{$team}\" does not exist here. Use a group name from Team → Groups & people, or leave it blank.");
            }
            $acct = [];        // real columns (role / loan columns)
            $details = [];     // the type's other fields, typed
            foreach ($typeFields as $f) {
                $v = $get($f['key']);
                if ($v === '') {
                    continue;
                }
                $val = self::typed($f, $v, $defaultCountry);
                if ($val === null) {
                    $err($f['key'], 'invalid_' . $f['kind'], self::hint($f));
                } elseif ($f['col'] !== null) {
                    $acct[$f['col']] = $val;
                } else {
                    $details[$f['key']] = $val;
                }
            }
            foreach ((array) ($raw['__extra'] ?? []) as $header => $v) {   // columns that match no field: kept under their own name
                if (trim((string) $v) !== '') {
                    $details['x:' . mb_substr($header, 0, 60)] = mb_substr(trim((string) $v), 0, 500);
                }
            }
            if ($get('email') !== '') {
                if (filter_var($get('email'), FILTER_VALIDATE_EMAIL)) {
                    $acct['email'] = mb_substr($get('email'), 0, 150);
                } else {
                    $err('email', 'invalid_email', 'Not a valid email address.');
                }
            }
            foreach (['address' => 500, 'state' => 100, 'preferred_time' => 60] as $f => $len) {
                if ($get($f) !== '') {
                    $acct[$f] = mb_substr($get($f), 0, $len);
                }
            }
            if ($ref !== '' && isset($seenRef[$ref])) {
                $err('customer_ref', 'duplicate_in_file', "Duplicate of row {$seenRef[$ref]}.");
            }
            if ($phone !== null && isset($seenPhone[$phone])) {
                $err('phone', 'duplicate_in_file', "Phone duplicates row {$seenPhone[$phone]}.");
            }
            if ($phone !== null) {
                $owner = $existingRefForPhone($phone);
                if ($owner !== null && $owner !== $ref) {
                    $err('phone', 'duplicate_existing', "Phone already belongs to customer {$owner}.");
                }
            }
            if ($rowErrors === []) {
                $seenRef[$ref] = $n;
                $seenPhone[$phone] = $n;
                $valid[] = [
                    'row' => $n, 'customer_ref' => $ref, 'name' => $name, 'phone' => $phone, 'alt_phone' => $alt,
                    'campaign' => $get('campaign') ?: null, 'assigned_employee' => $emp, 'assigned_team' => $team,
                    'notes' => mb_substr($get('notes'), 0, 2000) ?: null, 'status' => $status, 'follow_up_date' => $followUp,
                    'city' => mb_substr($get('city'), 0, 100) ?: null, 'area' => mb_substr($get('area'), 0, 100) ?: null, 'pincode' => mb_substr(preg_replace('/\s+/', '', $get('pincode')), 0, 12) ?: null,
                    'account' => $acct, 'details' => $details,
                ];
            } else {
                array_push($errors, ...$rowErrors);
            }
        }
        return ['valid' => $valid, 'errors' => $errors];
    }

    /** A field's value from the file in its kind, or null when it is not valid. */
    public static function typed(array $f, string $v, string $country = 'IN'): mixed
    {
        return match ($f['kind']) {
            'money' => self::parseMoney($v),
            'date' => self::parseDate($v),
            'int' => ctype_digit(str_replace(',', '', $v)) && (int) str_replace(',', '', $v) <= ($f['max'] ?? PHP_INT_MAX) ? (int) str_replace(',', '', $v) : null,
            'number' => is_numeric(str_replace(',', '', $v)) ? round((float) str_replace(',', '', $v), 3) : null,
            'choice' => self::choice($f['options'] ?? [], $v),
            'phone' => rescue(fn () => PhoneNormaliser::normalise($v, $country), null, false),
            'email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_substr($v, 0, 150) : null,
            default => mb_substr($v, 0, 500),
        };
    }

    /** Matches a choice whatever its case or spacing ("cod", "C.O.D" → "COD"); anything else is kept as written. */
    private static function choice(array $options, string $v): string
    {
        $n = fn ($s) => strtolower(preg_replace('/[^a-z0-9]+/i', '', $s) ?? '');
        foreach ($options as $o) {
            if ($n($o) === $n($v)) {
                return $o;
            }
        }
        return mb_substr($v, 0, 100);
    }

    private static function hint(array $f): string
    {
        return match ($f['kind']) {
            'money' => "{$f['label']}: use a number, e.g. 12500 or 12,500.00.",
            'date' => "{$f['label']}: use YYYY-MM-DD or DD/MM/YYYY.",
            'int' => "{$f['label']}: use a whole number" . ($f['max'] ? " up to {$f['max']}." : '.'),
            'number' => "{$f['label']}: use a number, e.g. 2.5.",
            'phone' => "{$f['label']}: not a valid phone number.",
            'email' => "{$f['label']}: not a valid e-mail address.",
            default => "{$f['label']}: not valid.",
        };
    }

    /** "₹ 12,500.50", "Rs.12500", "12500" → 12500.5; negatives and junk → null. */
    public static function parseMoney(string $s): ?float
    {
        $s = str_replace([',', ' ', '₹'], '', preg_replace('/^(rs\.?|inr)/i', '', trim($s)) ?? '');
        return is_numeric($s) && (float) $s >= 0 && (float) $s < 1e12 ? round((float) $s, 2) : null;
    }

    public static function parseDate(string $s): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!j/n/Y', '!j-n-Y', '!j.n.Y'] as $fmt) {   // j/n: Excel saves 5/1/2026 without leading zeros
            $d = \DateTimeImmutable::createFromFormat($fmt, $s);
            if ($d !== false && $d->format(substr($fmt, 1)) === $s) {
                return $d->format('Y-m-d');
            }
        }
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) { // Excel serial date
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $s . ' days')->format('Y-m-d');
        }
        return null;
    }
}
