<?php

declare(strict_types=1);

use App\Domain\Customers\ImportValidator;
use App\Domain\Customers\PhoneNormaliser;
use App\Domain\DomainException;
use PHPUnit\Framework\TestCase;

final class CustomersTest extends TestCase
{
    public function test_phone_normalisation(): void
    {
        foreach (['98765 43210', '+91-98765-43210', '09876543210', '919876543210', '00919876543210', '(+91) 98765.43210'] as $raw) {
            self::assertSame('+919876543210', PhoneNormaliser::normalise($raw), $raw);
        }
        self::assertSame('+914023456789', PhoneNormaliser::normalise('040 2345 6789'));
        self::assertSame('+971501234567', PhoneNormaliser::normalise('+971 50 123 4567'));
        foreach (['', 'abc', '12345', '+91 12345', '+910876543210'] as $bad) {
            try {
                PhoneNormaliser::normalise($bad);
                self::fail("accepted '{$bad}'");
            } catch (DomainException $e) {
                self::assertSame('invalid_phone', $e->reason);
            }
        }
        self::assertSame('+91••••••3210', PhoneNormaliser::mask('+919876543210'));
    }

    public function test_mapping_suggestion(): void
    {
        $m = ImportValidator::suggestMapping(['Customer ID', 'Customer Name', 'Mobile Number', 'Alternate Number', 'Agent', 'PTP Date']);
        self::assertSame(0, $m['customer_ref']);
        self::assertSame(2, $m['phone']);
        self::assertSame(3, $m['alt_phone']);
        self::assertSame(4, $m['assigned_employee']);
        self::assertSame(5, $m['follow_up_date']);
        self::assertNull($m['campaign']);
    }

    public function test_row_validation_duplicates_and_errors(): void
    {
        $rows = [
            ['customer_ref' => 'C1', 'name' => 'Asha', 'phone' => '9876543210', 'assigned_employee' => 'E01', 'follow_up_date' => '05/10/2026'],
            ['customer_ref' => 'C2', 'name' => 'Bala', 'phone' => '+91 98765 43210'],            // dup phone in file
            ['customer_ref' => 'C1', 'name' => 'Asha again', 'phone' => '9876500000'],           // dup ref in file
            ['customer_ref' => 'C3', 'name' => '', 'phone' => 'xyz', 'status' => 'weird'],        // 3 errors
            ['customer_ref' => 'C4', 'name' => 'Dev', 'phone' => '9000000001'],                   // belongs to C9 already
            ['customer_ref' => 'C5', 'name' => 'Esha', 'phone' => '9000000002', 'assigned_team' => 'NOPE', 'status' => 'Follow Up'],
            ['customer_ref' => 'C6', 'name' => 'Farah', 'phone' => '9000000003', 'status' => 'Do Not Call', 'follow_up_date' => '46000'],
        ];
        $r = ImportValidator::validate($rows, 'IN',
            fn (string $p) => $p === '+919000000001' ? 'C9' : null,
            fn (string $e) => $e === 'E01',
            fn (string $t) => $t === 'T1');
        self::assertSame(['C1', 'C6'], array_column($r['valid'], 'customer_ref'));
        self::assertSame('2026-10-05', $r['valid'][0]['follow_up_date']);
        self::assertSame('do_not_call', $r['valid'][1]['status']);
        $codes = array_map(fn ($e) => $e['row'] . ':' . $e['code'], $r['errors']);
        foreach (['3:duplicate_in_file', '4:duplicate_in_file', '5:required', '5:invalid_phone', '5:invalid_status', '6:duplicate_existing', '7:unknown_team'] as $c) {
            self::assertContains($c, $codes);
        }
    }
}
