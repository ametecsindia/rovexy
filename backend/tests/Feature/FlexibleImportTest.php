<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportMapping;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Bank files as they are: title lines, blank rows, own column names and order, several Reference columns, saved mappings. */
final class FlexibleImportTest extends TestCase
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

    private function upload(string $csv, string $name = 'bank.csv'): array
    {
        return $this->as()->post('/api/admin/imports', ['file' => UploadedFile::fake()->createWithContent($name, $csv), 'portfolio_type' => 'collections'], ['Accept' => 'application/json'])
            ->assertCreated()->json();
    }

    /** HDFC-style: two title lines, own names, Reference / Reference 2 / duplicate "Reference", a blank line, a total line. */
    private const BANK = "HDFC BANK LTD - COLLECTION ALLOCATION,,,,,,,\n"
        . "Month: Nov-2026,,,,,,,\n"
        . "Loan Account No,Borrower Full Name,Reference,Mobile No,Reference,Reference 2,Branch Code,Total Due\n"
        . "LN001,Ravi Kumar,R1-A,9000011111,R1-B,R2-A,HYD01,\"12,500\"\n"
        . ",,,,,,,\n"
        . "LN002,Sita Devi,R1-C,9000022222,R1-D,R2-B,HYD02,8000\n"
        . "LN003,No Phone,R1-E,,R1-F,R2-C,HYD03,100\n";

    public function test_bank_file_with_title_lines_and_reference_columns_imports(): void
    {
        $job = $this->upload(self::BANK);
        $this->assertSame(2, $job['header_row']);   // the 3rd line holds the column names
        $this->assertSame(['Loan Account No', 'Borrower Full Name', 'Reference', 'Mobile No', 'Reference (2)', 'Reference 2', 'Branch Code', 'Total Due'], $job['headers']);

        $mapping = array_merge($job['mapping'], ['customer_ref' => 0, 'name' => 1, 'phone' => 3, 'outstanding' => 7]);
        $prev = $this->as()->postJson("/api/admin/imports/{$job['id']}/preview", ['mapping' => $mapping, 'ignore_columns' => [6]])->assertOk()->json();
        $this->assertCount(2, $prev['result']['valid']);
        $this->assertSame([['row' => 7, 'field' => 'phone']], array_map(fn ($e) => ['row' => $e['row'], 'field' => $e['field']], $prev['result']['errors']));   // row as Excel shows it; the blank line is skipped
        $this->assertSame('LN001', $prev['sample'][0][0]);

        $this->as()->postJson("/api/admin/imports/{$job['id']}/validate")->assertStatus(202);
        $this->as()->postJson("/api/admin/imports/{$job['id']}/commit")->assertStatus(202);
        $st = $this->as()->getJson("/api/admin/imports/{$job['id']}")->json();
        $this->assertSame(['completed', 3, 2, 2], [$st['status'], $st['total_rows'], $st['valid_rows'], $st['created_count']]);

        app(CurrentCompany::class)->run($this->c, function () {
            $c = Customer::query()->where('customer_ref', 'LN001')->firstOrFail();
            $this->assertSame([12500.0, 'R1-A', 'R1-B', 'R2-A'], [(float) $c->outstanding, $c->details['x:Reference'], $c->details['x:Reference (2)'], $c->details['x:Reference 2']]);
            $this->assertArrayNotHasKey('x:Branch Code', $c->details);   // the client chose not to keep it
        });

        // Reports: every reference column is its own column in the customers export.
        $csv = $this->as()->get('/api/admin/reports/export/customers.csv?format=csv&from=2020-01-01&to=2030-12-31')->assertOk()->streamedContent();
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv), fn ($l) => trim($l) !== '')));
        $head = array_search('Customer ID', array_column($lines, 0), true);
        $cols = $lines[$head];
        foreach (['Reference', 'Reference (2)', 'Reference 2'] as $h) {
            $this->assertContains($h, $cols);
        }
        $row = collect($lines)->first(fn ($l) => $l[0] === 'LN001');
        $this->assertSame('R2-A', $row[array_search('Reference 2', $cols, true)]);
    }

    public function test_saved_mapping_is_found_again_for_the_same_bank_format_in_another_order(): void
    {
        $job = $this->upload(self::BANK);
        $mapping = array_merge($job['mapping'], ['customer_ref' => 0, 'name' => 1, 'phone' => 3, 'outstanding' => 7]);
        $this->as()->postJson("/api/admin/imports/{$job['id']}/save-mapping", ['name' => 'HDFC Bank', 'mapping' => $mapping, 'ignore_columns' => [6]])->assertOk();
        $list = $this->as()->getJson('/api/admin/import-mappings')->assertOk()->json();
        $this->assertSame([['HDFC Bank', 2]], array_map(fn ($m) => [$m['name'], $m['header_row']], $list));

        // Next month: another title, columns in another order, one title line only.
        $next = "HDFC BANK LTD - DEC 2026,,,,,,,\n"
            . "Mobile No,Total Due,Loan Account No,Reference,Branch Code,Borrower Full Name,Reference,Reference 2\n"
            . "9000033333,500,LN010,A,X,Anil,B,C\n";
        $saved = ImportMapping::query()->withoutGlobalScopes()->first();
        $job2 = $this->upload($next, 'hdfc-dec.csv');
        $this->assertSame('HDFC Bank', $job2['saved_mapping']);
        $this->assertSame([2, 5, 0, 1], [$job2['mapping']['customer_ref'], $job2['mapping']['name'], $job2['mapping']['phone'], $job2['mapping']['outstanding']]);
        $this->assertSame([4], $job2['options']['ignore_columns']);   // Branch Code, wherever it is now

        // A file without one of the mapped columns cannot use it.
        $other = $this->upload("Cust,Name,Phone\nZ1,Zed,9000044444\n", 'other.csv');
        $this->assertNull($other['saved_mapping']);
        $this->as()->postJson("/api/admin/imports/{$other['id']}/apply-mapping", ['mapping_id' => $saved->id])->assertStatus(422);
    }

    public function test_header_row_can_be_chosen_and_the_template_still_starts_on_row_one(): void
    {
        $job = $this->upload("Customer ID,Customer Name,Phone Number\nC1,Ann,9000055555\n", 'tpl.csv');
        $this->assertSame([0, 0, 1, 2], [$job['header_row'], $job['mapping']['customer_ref'], $job['mapping']['name'], $job['mapping']['phone']]);
        $j = $this->as()->postJson("/api/admin/imports/{$job['id']}/header-row", ['header_row' => 1])->assertOk()->json();
        $this->assertSame(['C1', 'Ann', '9000055555'], $j['headers']);
        $this->as()->postJson("/api/admin/imports/{$job['id']}/header-row", ['header_row' => 9])->assertStatus(422);
    }
}
