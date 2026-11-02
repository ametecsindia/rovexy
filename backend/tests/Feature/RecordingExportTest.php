<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Demo\DemoWorkspace;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Reports → Recording export (2 Nov 2026): the company's recording file-name format, used by every download, and the bulk ZIP. */
final class RecordingExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ammp.sessions.admin_mfa_required' => false]);
        $this->c = DemoWorkspace::register(['name' => 'R', 'business' => 'Acme Agency', 'mobile' => '9876543210', 'email' => 'r@acme.test', 'address' => 'x', 'city' => 'hyderabad', 'password' => bcrypt('Acme1234')]);
    }

    private function as(string $code = 'admin')
    {
        $u = app(CurrentCompany::class)->run($this->c, fn () => User::query()->where('employee_code', $code)->first());
        return $this->actingAs($u, 'web')->withSession(['mfa_verified_at' => time(), 'auth_started_at' => time()])->withHeaders(['X-Workspace' => $this->c->slug, 'Referer' => 'http://localhost/']);
    }

    private function range(): string
    {
        return '?from=' . now()->subDays(70)->toDateString() . '&to=' . now()->toDateString();
    }

    private function newFormat(string $name, array $parts, string $sep = '_', bool $default = false): string
    {
        return $this->as()->postJson('/api/admin/recording-formats', ['name' => $name, 'template' => ['sep' => $sep, 'parts' => $parts], 'make_default' => $default])->assertCreated()->json('id');
    }

    public function test_named_formats_portfolios_pick_one_and_single_downloads_follow_it(): void
    {
        $meta = $this->as()->getJson('/api/admin/recording-formats')->assertOk()->json();
        $this->assertSame(['standard'], array_column($meta['formats'], 'id'));          // built-in until the company adds its own
        $hdfc = $this->newFormat('HDFC Bank', [['type' => 'text', 'value' => 'HDFC/<Bank>'], ['type' => 'company'], ['type' => 'customer_ref'], ['type' => 'date', 'format' => 'Ymd'], ['type' => 'time', 'format' => 'His']]);
        $icici = $this->newFormat('ICICI', [['type' => 'text', 'value' => 'ICICI'], ['type' => 'call_id']], '-', true);
        // Validation: a name is needed and unique; something must tell calls apart.
        $this->as()->postJson('/api/admin/recording-formats', ['name' => 'hdfc bank', 'template' => ['sep' => '_', 'parts' => [['type' => 'call_id']]]])->assertStatus(422);
        $this->as()->postJson('/api/admin/recording-formats', ['name' => 'X', 'template' => ['sep' => '_', 'parts' => [['type' => 'company']]]])->assertStatus(422);
        $list = $this->as()->getJson('/api/admin/recording-formats')->json();
        $this->assertSame($icici, $list['default_id']);
        $this->assertSame('HDFC-Bank', collect($list['formats'])->firstWhere('id', $hdfc)['parts'][0]['value']);

        // Portfolio 1 → HDFC; the others use the default (ICICI).
        $job = DB::table('import_jobs')->where('company_id', $this->c->id)->orderBy('id')->value('id');
        $this->as()->patchJson("/api/admin/imports/{$job}", ['recording_format' => $hdfc])->assertOk();
        $this->as()->patchJson("/api/admin/imports/{$job}", ['recording_format' => 'nope'])->assertStatus(422);
        $this->assertSame($hdfc, collect($this->as()->getJson('/api/admin/portfolios')->json())->firstWhere('id', $job)['recording_format']);
        $inHdfc = DB::table('calls')->join('customers', 'customers.id', '=', 'calls.customer_id')->where('calls.company_id', $this->c->id)
            ->where('customers.last_import_id', $job)->where('recording_status', 'available')->value('calls.id');
        $other = DB::table('calls')->join('customers', 'customers.id', '=', 'calls.customer_id')->where('calls.company_id', $this->c->id)
            ->where('customers.last_import_id', '!=', $job)->where('recording_status', 'available')->value('calls.id');
        $a = $this->as()->postJson("/api/admin/calls/{$inHdfc}/recording/download")->assertOk()->json();
        $this->assertMatchesRegularExpression('/^HDFC-Bank_Acme_Agency_[A-Z]{2}\d{10}_\d{8}_\d{6}\.mp3$/', $a['filename']);
        $this->assertStringContainsString('filename=' . $a['filename'], (string) $this->get($a['url'])->assertOk()->headers->get('Content-Disposition'));
        $this->assertSame("ICICI-{$other}.mp3", $this->as()->postJson("/api/admin/calls/{$other}/recording/download")->json('filename'));

        // Deleting a format: its portfolios fall back to the default; the last format can't be deleted.
        $this->as()->deleteJson("/api/admin/recording-formats/{$hdfc}")->assertOk();
        $this->assertNull(DB::table('import_jobs')->where('id', $job)->value('recording_format'));
        $this->assertStringStartsWith('ICICI-', $this->as()->postJson("/api/admin/calls/{$inHdfc}/recording/download")->json('filename'));
        $this->as()->deleteJson("/api/admin/recording-formats/{$icici}")->assertOk();   // default moves to the built-in one
        $last = $this->as()->getJson('/api/admin/recording-formats')->json('formats');
        $this->assertCount(1, $last);
        $this->as()->deleteJson("/api/admin/recording-formats/{$last[0]['id']}")->assertStatus(422);
        $this->as('TL101')->getJson('/api/admin/recording-formats')->assertOk();   // team leaders see and pick formats too (customers.import)
    }

    public function test_bulk_zip_follows_filters_names_and_scope(): void
    {
        $fmt = $this->newFormat('Field team', [['type' => 'employee_code'], ['type' => 'customer_phone', 'format' => 'last4'], ['type' => 'date', 'format' => 'Ymd']], '-', true);
        $all = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range())->assertOk()->json();
        $expected = DB::table('calls')->where('company_id', $this->c->id)->where('recording_status', 'available')->count();
        $this->assertSame($expected, $all['count']);
        $this->assertMatchesRegularExpression('/^(TC|FOS)\d{3}-\d{4}-\d{8}\.mp3$/', $all['names'][0]);
        $this->assertNull($all['portfolio_format']);
        // A portfolio's own format is offered when that portfolio is chosen; any other can be picked.
        $job = DB::table('import_jobs')->where('company_id', $this->c->id)->orderBy('id')->value('id');
        $other = $this->newFormat('Other bank', [['type' => 'text', 'value' => 'OB'], ['type' => 'call_id']]);
        $this->as()->patchJson("/api/admin/imports/{$job}", ['recording_format' => $other])->assertOk();
        $pf = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . "&portfolio={$job}")->json();
        $this->assertSame($other, $pf['portfolio_format']);
        $this->assertMatchesRegularExpression('/^OB_\d+\.mp3$/', $pf['names'][0]);                       // blank = the portfolio's own
        $picked = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . "&portfolio={$job}&format={$fmt}")->json();
        $this->assertMatchesRegularExpression('/^(TC|FOS)\d{3}-\d{4}-\d{8}\.mp3$/', $picked['names'][0]);
        $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . '&format=gone')->assertStatus(422);

        $short = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . '&outcome=CONNECTED_PTP&min_talk=30')->json('count');
        $this->assertSame(DB::table('calls')->join('call_dispositions as d', 'd.call_id', '=', 'calls.id')->where('calls.company_id', $this->c->id)
            ->where('recording_status', 'available')->where('d.code', 'CONNECTED_PTP')->where('talk_seconds', '>=', 30)->count(), $short);

        // Supervisors play recordings but don't download them (role permission), so no bulk export either.
        $this->as('TL101')->getJson('/api/admin/reports/recordings/count' . $this->range())->assertForbidden();
        $one = DB::table('users')->where('company_id', $this->c->id)->where('employee_code', 'TC201')->value('id');
        $mine = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . "&employee={$one}")->json('count');
        $this->assertSame(DB::table('calls')->where('user_id', $one)->where('recording_status', 'available')->count(), $mine);

        // ZIP with each portfolio's own format: portfolio 1 files are OB_…, the rest use the default.
        $res = $this->as()->get('/api/admin/reports/recordings.zip' . $this->range() . '&outcome=CONNECTED_PTP')->assertOk();
        $zip = new \ZipArchive();
        $zip->open($res->baseResponse->getFile()->getPathname());
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $this->assertContains('index.csv', $names);
        $audio = array_values(array_filter($names, fn ($n) => str_ends_with($n, '.mp3')));
        $this->assertCount(count(array_unique($audio)), $audio);   // names never clash
        $this->assertSame($this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . '&outcome=CONNECTED_PTP')->json('count'), count($audio));
        $this->assertNotEmpty(array_filter($audio, fn ($n) => str_starts_with($n, 'OB_')));
        $this->assertNotEmpty(array_filter($audio, fn ($n) => preg_match('/^(TC|FOS)\d{3}-/', $n)));
        $this->assertStringStartsWith('File,"Call number"', (string) $zip->getFromName('index.csv'));
        $this->assertSame(1, DB::table('audit_events')->where('company_id', $this->c->id)->where('action', 'recordings.bulk_exported')->count());

        // The audit pack names recordings by their portfolio's format too.
        $pack = $this->as()->get('/api/admin/reports/audit-pack.zip' . $this->range())->assertOk();
        $p = new \ZipArchive();
        $p->open($pack->baseResponse->getFile()->getPathname());
        $rec = array_values(array_filter(array_map(fn ($i) => $p->getNameIndex($i), range(0, $p->numFiles - 1)), fn ($n) => str_starts_with($n, 'recordings/')));
        $this->assertNotEmpty(array_filter($rec, fn ($n) => str_starts_with($n, 'recordings/OB_')));
        $this->assertNotEmpty(array_filter($rec, fn ($n) => preg_match('#^recordings/(TC|FOS)\d{3}-#', $n)));
    }

    public function test_the_single_format_saved_before_keeps_working(): void
    {
        $c = $this->c;
        $c->forceFill(['settings' => ['recordings' => ['filename' => ['sep' => '_', 'parts' => [['type' => 'text', 'value' => 'OLD'], ['type' => 'call_id']]]]] + (array) $c->settings])->save();
        $call = DB::table('calls')->where('company_id', $c->id)->where('recording_status', 'available')->value('id');
        $this->assertSame("OLD_{$call}.mp3", $this->as()->postJson("/api/admin/calls/{$call}/recording/download")->json('filename'));
        $this->assertSame('Standard', $this->as()->getJson('/api/admin/recording-formats')->json('formats.0.name'));
    }
}
