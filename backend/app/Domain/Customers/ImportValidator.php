<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\DomainException;

/**
 * Validates mapped import rows. Pure: lookups for existing data are injected as closures.
 * Duplicates: within the file by customer_ref and by phone; against existing company records by customer_ref
 * (=> update) and by phone owned by a different customer_ref (=> error, needs review).
 */
final class ImportValidator
{
    public const FIELDS = ['customer_ref', 'name', 'phone', 'alt_phone', 'campaign', 'assigned_employee', 'assigned_team', 'notes', 'status', 'follow_up_date', 'city', 'area', 'pincode',
        'email', 'address', 'state', 'lat', 'lng', 'preferred_time', 'loan_amount', 'outstanding', 'principal_os', 'interest_os', 'charges', 'overdue_amount',
        'emi_amount', 'due_date', 'oldest_due_date', 'dpd', 'overdue_installments', 'last_paid_on', 'last_paid_amount'];
    public const MONEY = ['loan_amount', 'outstanding', 'principal_os', 'interest_os', 'charges', 'overdue_amount', 'emi_amount', 'last_paid_amount'];
    public const DATES = ['due_date', 'oldest_due_date', 'last_paid_on'];
    public const STATUSES = ['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'];

    private const SYNONYMS = [
        'customer_ref' => ['customer id', 'customer_id', 'cust id', 'id', 'account', 'account no', 'loan no', 'ref'],
        'name' => ['customer name', 'name', 'full name', 'borrower'],
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
        'lat' => ['latitude', 'lat'],
        'lng' => ['longitude', 'lng', 'long', 'lon'],
        'preferred_time' => ['preferred time', 'preferred contact time', 'best time to call', 'contact time'],
        'loan_amount' => ['loan amount', 'loan amt', 'sanctioned amount', 'sanction amount', 'disbursed amount', 'finance amount'],
        'outstanding' => ['total outstanding', 'outstanding', 'tos', 'outstanding amount', 'total due', 'balance'],
        'principal_os' => ['principal outstanding', 'pos', 'principal os', 'principal'],
        'interest_os' => ['interest outstanding', 'interest os', 'interest'],
        'charges' => ['charges', 'late fee', 'late fees', 'penalty', 'other charges', 'bounce charges'],
        'overdue_amount' => ['overdue amount', 'overdue', 'total overdue', 'amount overdue', 'arrears', 'od amount'],
        'emi_amount' => ['emi', 'emi amount', 'installment amount', 'instalment amount'],
        'due_date' => ['due date', 'emi date', 'next due date', 'installment due date', 'instalment due date'],
        'oldest_due_date' => ['oldest due date', 'oldest unpaid date', 'first unpaid date', 'default date'],
        'dpd' => ['dpd', 'days past due', 'days overdue', 'overdue days'],
        'overdue_installments' => ['overdue installments', 'overdue instalments', 'overdue emis', 'emis overdue', 'pending emis'],
        'last_paid_on' => ['last payment date', 'last paid date', 'last paid on', 'lpd'],
        'last_paid_amount' => ['last payment amount', 'last paid amount', 'lpa'],
        'follow_up_date' => ['follow-up date', 'follow up date', 'followup', 'follow_up', 'ptp date', 'next action'],
    ];

    /** @param list<string> $headers @return array<string,?int> field => header index */
    public static function suggestMapping(array $headers): array
    {
        $norm = array_map(static fn ($h) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $h) ?? '')), $headers);
        $out = [];
        foreach (self::SYNONYMS as $field => $syn) {
            $out[$field] = null;
            foreach ($norm as $i => $h) {
                if ($h === $field || in_array($h, $syn, true)) {
                    $out[$field] = $i;
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
    public static function validate(array $rows, string $defaultCountry, callable $existingRefForPhone, callable $employeeExists, callable $teamExists, int $firstRowNumber = 2): array
    {
        $valid = [];
        $errors = [];
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
            $acct = [];
            foreach (self::MONEY as $f) {
                if ($get($f) !== '') {
                    $amt = self::parseMoney($get($f));
                    if ($amt === null) {
                        $err($f, 'invalid_amount', 'Use a number, e.g. 12500 or 12,500.00.');
                    } else {
                        $acct[$f] = $amt;
                    }
                }
            }
            foreach (self::DATES as $f) {
                if ($get($f) !== '') {
                    $d = self::parseDate($get($f));
                    if ($d === null) {
                        $err($f, 'invalid_date', 'Use YYYY-MM-DD or DD/MM/YYYY.');
                    } else {
                        $acct[$f] = $d;
                    }
                }
            }
            foreach (['dpd' => 20000, 'overdue_installments' => 1000] as $f => $max) {
                if ($get($f) !== '') {
                    if (ctype_digit($get($f)) && (int) $get($f) <= $max) {
                        $acct[$f] = (int) $get($f);
                    } else {
                        $err($f, 'invalid_number', 'Use a whole number.');
                    }
                }
            }
            if ($get('lat') !== '' || $get('lng') !== '') {
                $lat = is_numeric($get('lat')) ? (float) $get('lat') : 999;
                $lng = is_numeric($get('lng')) ? (float) $get('lng') : 999;
                // 0,0 is a blank export, not a place. Both are needed; an unusable pair is an error, never a guessed pin.
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat != 0.0 || $lng != 0.0)) {
                    $acct += ['lat' => $lat, 'lng' => $lng];
                } else {
                    $err('lat', 'invalid_location', 'Latitude and longitude must both be valid.');
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
                    'account' => $acct,
                ];
            } else {
                array_push($errors, ...$rowErrors);
            }
        }
        return ['valid' => $valid, 'errors' => $errors];
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
