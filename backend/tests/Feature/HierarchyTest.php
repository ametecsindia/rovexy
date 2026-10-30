<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Call;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Branch → Team → People (30 Oct 2026): branches, the console-wide org filter, bulk upload with branches, branch-wise reports. */
final class HierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private array $u = [];
    private array $t = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Company::query()->create(['slug' => 'alpha', 'name' => 'Alpha', 'timezone' => 'UTC', 'default_country' => 'IN']);
        $this->in(function () {
            CompanyDefaults::apply($this->a);
            $mk = function (string $code, string $role) {
                $u = User::query()->create(['employee_code' => $code, 'name' => $code, 'email' => strtolower($code) . '@alpha.test', 'password' => 'Secret-123456', 'status' => 'active', 'mfa_enabled_at' => now()]);
                $u->roles()->attach(Role::query()->where('key', $role)->value('id'), ['company_id' => $this->a->id]);
                return $u;
            };
            $hyd = Branch::query()->create(['name' => 'Hyderabad', 'code' => 'HYD']);
            $vij = Branch::query()->create(['name' => 'Vijayawada', 'code' => 'VIJ']);
            $this->t = ['h1' => Team::query()->create(['name' => 'H1', 'code' => 'H1', 'branch_id' => $hyd->id]),
                'h2' => Team::query()->create(['name' => 'H2', 'code' => 'H2', 'branch_id' => $hyd->id]),
                'v1' => Team::query()->create(['name' => 'V1', 'code' => 'V1', 'branch_id' => $vij->id])];
            $this->u = ['admin' => $mk('ADM', 'company_admin'), 'mgr' => $mk('MGR', 'supervisor'),
                'e1' => $mk('E1', 'employee'), 'e2' => $mk('E2', 'employee'), 'e3' => $mk('E3', 'employee')];
            $this->u['mgr']->teams()->attach($this->t['h1']->id, ['company_id' => $this->a->id, 'membership' => 'supervisor']);
            foreach (['e1' => 'h1', 'e2' => 'h2', 'e3' => 'v1'] as $e => $t) {
                $this->u[$e]->teams()->attach($this->t[$t]->id, ['company_id' => $this->a->id, 'membership' => 'member']);
                $c = Customer::query()->create(['customer_ref' => "C-{$e}", 'name' => "Cust {$e}", 'phone' => '+919800000001', 'status' => 'new',
                    'assigned_user_id' => $this->u[$e]->id, 'assigned_team_id' => $this->t[$t]->id]);
                Call::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->u[$e]->id, 'customer_id' => $c->id, 'provider' => 'sim', 'which_number' => 'primary', 'agent_leg' => 'sim', 'destination' => '+919800000001',
                    'state' => 'completed', 'initiated_at' => now(), 'answered_at' => now(), 'talk_seconds' => 30]);
            }
        });
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentCompany::class)->run($this->a, $fn);
    }

    private function as(string $who)
    {
        return $this->actingAs($this->u[$who], 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])
            ->withHeaders(['X-Workspace' => 'alpha', 'Referer' => 'http://localhost/']);
    }

    private function callers($res): array
    {
        return collect($res->json('data'))->pluck('user.employee_code')->sort()->values()->all();
    }

    public function test_branch_crud_and_team_moves(): void
    {
        $id = $this->as('admin')->postJson('/api/admin/branches', ['name' => 'Chennai'])->assertCreated()->json('id');
        $this->as('admin')->patchJson("/api/admin/teams/{$this->t['v1']->id}", ['branch_id' => $id])->assertOk();
        $this->assertSame($id, $this->in(fn () => Team::query()->find($this->t['v1']->id)->branch_id));
        $this->as('admin')->patchJson("/api/admin/branches/{$id}", ['name' => 'Chennai South'])->assertOk();
        $this->as('admin')->deleteJson("/api/admin/branches/{$id}")->assertOk();
        $this->assertNull($this->in(fn () => Team::query()->find($this->t['v1']->id)->branch_id));   // team kept, just no branch
        $this->as('mgr')->postJson('/api/admin/branches', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_org_filter_narrows_every_list_but_never_widens(): void
    {
        $hyd = $this->in(fn () => Branch::query()->where('code', 'HYD')->value('id'));
        $this->assertSame(['E1', 'E2', 'E3'], $this->callers($this->as('admin')->getJson('/api/admin/calls')->assertOk()));
        $this->assertSame(['E1', 'E2'], $this->callers($this->as('admin')->getJson("/api/admin/calls?org_branch={$hyd}")));
        $this->assertSame(['E2'], $this->callers($this->as('admin')->getJson("/api/admin/calls?org_team={$this->t['h2']->id}")));
        $this->assertSame(['E3'], $this->callers($this->as('admin')->getJson("/api/admin/calls?org_user={$this->u['e3']->id}")));
        // A manager of H1 asking for Vijayawada or for someone else's employee gets nothing extra.
        $this->assertSame(['E1'], $this->callers($this->as('mgr')->getJson('/api/admin/calls')));
        $this->assertSame([], $this->callers($this->as('mgr')->getJson("/api/admin/calls?org_team={$this->t['v1']->id}")));
        $this->assertSame([], $this->callers($this->as('mgr')->getJson("/api/admin/calls?org_user={$this->u['e3']->id}")));
        // Customers and people follow the same filter.
        $this->assertSame(['C-e1', 'C-e2'], collect($this->as('admin')->getJson("/api/admin/customers?org_branch={$hyd}")->json('data'))->pluck('customer_ref')->sort()->values()->all());
        $this->assertSame(['E2'], collect($this->as('admin')->getJson("/api/admin/employees?org_team={$this->t['h2']->id}")->json('data'))->pluck('employee_code')->all());
        // The filter's own choices are not narrowed by it.
        $org = $this->as('admin')->getJson("/api/admin/org?org_team={$this->t['h2']->id}")->assertOk();
        $this->assertCount(2, $org->json('branches'));
        $this->assertCount(3, $org->json('teams'));
        $this->assertSame(['H1'], collect($this->as('mgr')->getJson('/api/admin/org')->json('teams'))->pluck('name')->all());
    }

    public function test_reports_are_branch_team_and_company_wise(): void
    {
        $r = $this->as('admin')->getJson('/api/admin/reports/portfolio?period=year')->assertOk();
        $this->assertSame('year', $r->json('period'));
        $br = collect($r->json('branches'))->keyBy('name');
        $this->assertSame(2, $br['Hyderabad']['cases']);
        $this->assertSame(2, $br['Hyderabad']['teams']);
        $this->assertSame(1, $br['Vijayawada']['calls']);
        $this->assertSame('Hyderabad', collect($r->json('groups'))->firstWhere('name', 'H2')['branch']);
        foreach (['branch' => 'Hyderabad', 'team' => 'H2', 'company' => 'Company total', 'employee' => 'E1'] as $by => $needle) {
            $csv = $this->as('admin')->get("/api/admin/reports/portfolio.csv?by={$by}")->assertOk()->streamedContent();
            $this->assertStringContainsString($needle, $csv, $by);
        }
        $vij = $this->as('admin')->get("/api/admin/reports/portfolio.csv?by=branch&org_team={$this->t['v1']->id}")->streamedContent();
        $this->assertStringNotContainsString('Hyderabad', $vij);
    }

    public function test_bulk_template_has_dropdowns_and_upload_uses_branches(): void
    {
        $res = $this->as('admin')->get('/api/admin/employees/bulk-template.xlsx')->assertOk();
        $file = $res->baseResponse->getFile()->getPathname();
        $z = new \ZipArchive();
        $this->assertTrue($z->open($file) === true);
        $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<formula1>Lists!$A$1:$A$2</formula1>', $sheet);   // 2 branches
        $this->assertStringContainsString('<formula1>Lists!$B$1:$B$3</formula1>', $sheet);   // 3 teams
        $this->assertStringContainsString('Vijayawada', $z->getFromName('xl/worksheets/sheet2.xml'));
        $z->close();
        // Our own template reads back through the normal upload (OpenSpout) — fill it with people and upload it.
        $rows = [['name', 'branch', 'team', 'role', 'employee_code', 'phone', 'email'],
            ['Asha', 'Hyderabad', 'H1', 'telecaller', '', '', ''], ['Kiran', 'Guntur', 'G1', 'manager', '', '', ''], ['Lata', 'Guntur', 'G1', 'telecaller', '', '', '']];
        $path = \App\Support\XlsxTemplate::build($rows, [['Hyderabad'], ['H1'], ['telecaller', 'manager']], ['B' => 0, 'C' => 1, 'D' => 2]);
        $up = $this->as('admin')->post('/api/admin/employees/bulk', ['email_domain' => 'alpha.test',
            'file' => new UploadedFile($path, 'people.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(['Hyderabad', 'Guntur', 'Guntur'], collect($up->json('created'))->sortBy('name')->pluck('branch')->values()->all());
        $this->assertSame('Guntur', $this->in(fn () => Team::query()->where('name', 'G1')->first()->branch->name));   // new branch + team created
        // A team that lives in another branch is refused, nothing saved.
        $csv = tempnam(sys_get_temp_dir(), 'b') . '.csv';
        file_put_contents($csv, "name,branch,team,role\nMoved,Vijayawada,H1,telecaller\n");
        $bad = $this->as('admin')->post('/api/admin/employees/bulk', ['email_domain' => 'alpha.test',
            'file' => new UploadedFile($csv, 'p.csv', null, null, true)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('is in branch Hyderabad', $bad->json('rows.0.errors.0'));
    }
}
