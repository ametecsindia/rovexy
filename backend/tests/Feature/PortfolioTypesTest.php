<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Portfolios\PortfolioTypes;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DispositionCode;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Every portfolio type: its sample file imports cleanly, each column lands in its field, pins come only from the address. */
final class PortfolioTypesTest extends TestCase
{
    use RefreshDatabase;

    private Company $c;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ammp.sessions.admin_mfa_required' => false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->c = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($this->c, function () {
            CompanyDefaults::apply($this->c);
            $this->admin = User::query()->create(['employee_code' => 'admin', 'name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'Secret-123456', 'status' => 'active']);
            $this->admin->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $this->c->id]);
        });
    }

    private function as()
    {
        return $this->actingAs($this->admin, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    public function test_every_type_sample_file_imports_into_its_fields(): void
    {
        $types = $this->as()->getJson('/api/admin/imports/types')->assertOk()->json();
        $this->assertSame(array_keys(PortfolioTypes::TYPES), array_column($types, 'type'));
        foreach (array_keys(PortfolioTypes::TYPES) as $i => $type) {
            app(CurrentCompany::class)->run($this->c, fn () => Customer::query()->delete());   // samples of different types share phone numbers
            $res = $this->as()->get("/api/admin/imports/template?type={$type}")->assertOk();
            $file = UploadedFile::fake()->createWithContent("sample-{$type}.xlsx", (string) file_get_contents($res->baseResponse->getFile()->getPathname()));
            $job = $this->as()->post('/api/admin/imports', ['file' => $file, 'portfolio' => "Test {$type}", 'portfolio_type' => $type], ['Accept' => 'application/json'])->assertCreated()->json();
            $this->assertSame($type, $job['portfolio_type']);
            // Every column of the sample is matched automatically (no lat / long fields offered at all).
            $mapped = array_filter($job['mapping'], fn ($v) => $v !== null);
            $this->assertNotContains('lat', array_column($job['fields'], 'key'));
            $expected = count($job['headers']) - ($type === 'custom' ? 2 : 0);   // Custom: two columns of the client's own
            $this->assertCount($expected, $mapped, "{$type}: unmatched " . json_encode(array_values(array_diff_key($job['headers'], array_flip($mapped)))));
            $prev = $this->as()->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk()->json('result');
            $this->assertSame([], $prev['errors'], "{$type}: " . json_encode($prev['errors']));
            $this->as()->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
            $this->as()->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
            $st = $this->as()->getJson("/api/admin/imports/{$job['id']}")->json();
            $this->assertSame(['completed', 5], [$st['status'], $st['created_count']], $type);

            app(CurrentCompany::class)->run($this->c, function () use ($type) {
                $rows = Customer::query()->where('portfolio_type', $type)->orderBy('customer_ref')->get();
                $this->assertCount(5, $rows);
                $first = $rows->first();
                $this->assertNull($first->lat);   // no pin from the file
                $shown = PortfolioTypes::present($first);
                $this->assertNotEmpty($shown, $type);
                match ($type) {
                    'courier' => $this->assertSame(['AWB7700125501', 1899.0, 'COD'], [$first->details['awb_no'], (float) $first->overdue_amount, $first->details['payment_type']]),
                    'sales' => $this->assertSame([375000.0, 'Interested', 25], [(float) $first->outstanding, $first->details['stage'], $first->details['quantity']]),
                    'nursing' => $this->assertSame([74, '+919876500011'], [$first->details['age'], $first->details['emergency_contact']]),
                    'education' => $this->assertSame(['Arjun Kumar', 25000.0], [$first->details['student_name'], (float) $first->overdue_amount]),
                    'custom' => $this->assertSame(['Gold', 'North'], [$first->details['x:Your Own Column'], $first->details['x:Another Column']]),
                    'collections' => $this->assertSame([224500.0, null], [(float) $first->outstanding, $first->details]),
                    default => $this->assertNotNull($first->due_date, $type),
                };
            });
        }
    }

    public function test_bad_values_are_reported_per_field_and_lat_long_columns_are_ignored(): void
    {
        $csv = "Customer ID,Consignee Name,Phone Number,Address,Latitude,Longitude,COD Amount,Delivery Date,Packages\n"
            . "A1,Ann,9000011111,\"1 Main Rd, Kondapur\",17.1,78.1,abc,31/02/2026,2\n"
            . "A2,Bob,9000022222,\"2 Main Rd, Kondapur\",17.2,78.2,499,05/12/2026,three\n"
            . "A3,Cat,9000033333,\"3 Main Rd, Kondapur\",17.3,78.3,99,2026-12-06,1\n";
        $job = $this->as()->post('/api/admin/imports', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv), 'portfolio_type' => 'courier'], ['Accept' => 'application/json'])->assertCreated()->json();
        $r = $this->as()->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $job['mapping']])->assertOk()->json('result');
        $this->assertEqualsCanonicalizing(['cod_amount', 'delivery_date', 'packages'], array_column($r['errors'], 'field'));
        $this->assertCount(1, $r['valid']);
        $this->assertArrayNotHasKey('x:Latitude', $r['valid'][0]['details']);
        $this->assertEquals(['2026-12-06', 99], [$r['valid'][0]['account']['due_date'], $r['valid'][0]['account']['overdue_amount']]);
    }

    public function test_outcomes_follow_the_type_and_existing_codes_stay_collections(): void
    {
        app(CurrentCompany::class)->run($this->c, function () {
            $this->assertSame('collections', DispositionCode::query()->where('code', 'CONNECTED_PTP')->value('portfolio_type'));
            $this->assertNull(DispositionCode::query()->where('code', 'RINGING_NO_ANSWER')->value('portfolio_type'));
            $this->assertTrue((bool) DispositionCode::query()->where('code', 'DLV_DELIVERED_COD')->value('success'));
            $this->assertSame(count(PortfolioTypes::outcomes()), DispositionCode::query()->count());
        });
    }
}
