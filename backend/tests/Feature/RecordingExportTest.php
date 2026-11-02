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

    public function test_format_is_saved_validated_and_used_for_single_downloads(): void
    {
        $meta = $this->as()->getJson('/api/admin/reports/recordings/format')->assertOk()->json();
        $this->assertFalse($meta['saved']);
        $this->assertNotNull($meta['sample_call']);
        $t = ['sep' => '_', 'parts' => [['type' => 'text', 'value' => 'HDFC Bank'], ['type' => 'company'], ['type' => 'customer_ref'], ['type' => 'date', 'format' => 'Ymd'], ['type' => 'time', 'format' => 'His']]];
        $name = $this->as()->postJson('/api/admin/reports/recordings/format/preview', ['template' => $t, 'call' => $meta['sample_call']])->assertOk()->json('name');
        $this->assertMatchesRegularExpression('/^HDFC_Bank_Acme_Agency_[A-Z]{2}\d{10}_\d{8}_\d{6}\.mp3$/', $name);
        // Nothing to tell two calls apart → refused; text with odd characters is cleaned.
        $this->as()->putJson('/api/admin/reports/recordings/format', ['template' => ['sep' => '_', 'parts' => [['type' => 'company']]]])->assertStatus(422);
        $saved = $this->as()->putJson('/api/admin/reports/recordings/format', ['template' => array_merge($t, ['parts' => [['type' => 'text', 'value' => 'HDFC/<Bank>'], ...array_slice($t['parts'], 1)]])])->assertOk()->json('template');
        $this->assertSame('HDFC-Bank', $saved['parts'][0]['value']);
        // Team leaders download but can't change the format.
        $this->as('TL101')->putJson('/api/admin/reports/recordings/format', ['template' => $t])->assertForbidden();

        $call = $meta['sample_call'];
        $res = $this->as()->postJson("/api/admin/calls/{$call}/recording/download")->assertOk()->json();
        $this->assertStringStartsWith('HDFC-Bank_Acme_Agency_', $res['filename']);
        $this->assertStringContainsString('filename=' . $res['filename'], (string) $this->get($res['url'])->assertOk()->headers->get('Content-Disposition'));
    }

    public function test_bulk_zip_follows_filters_names_and_scope(): void
    {
        $this->as()->putJson('/api/admin/reports/recordings/format', ['template' => ['sep' => '-', 'parts' => [['type' => 'employee_code'], ['type' => 'customer_phone', 'format' => 'last4'], ['type' => 'date', 'format' => 'Ymd']]]])->assertOk();
        $all = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range())->assertOk()->json();
        $expected = DB::table('calls')->where('company_id', $this->c->id)->where('recording_status', 'available')->count();
        $this->assertSame($expected, $all['count']);
        $this->assertMatchesRegularExpression('/^(TC|FOS)\d{3}-\d{4}-\d{8}(_\d+)?\.mp3$/', $all['names'][0]);

        $short = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . '&outcome=CONNECTED_PTP&min_talk=30')->json('count');
        $this->assertSame(DB::table('calls')->join('call_dispositions as d', 'd.call_id', '=', 'calls.id')->where('calls.company_id', $this->c->id)
            ->where('recording_status', 'available')->where('d.code', 'CONNECTED_PTP')->where('talk_seconds', '>=', 30)->count(), $short);

        // Supervisors play recordings but don't download them (role permission), so no bulk export either.
        $this->as('TL101')->getJson('/api/admin/reports/recordings/count' . $this->range())->assertForbidden();
        $one = DB::table('users')->where('company_id', $this->c->id)->where('employee_code', 'TC201')->value('id');
        $mine = $this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . "&employee={$one}")->json('count');
        $this->assertSame(DB::table('calls')->where('user_id', $one)->where('recording_status', 'available')->count(), $mine);

        $res = $this->as()->get('/api/admin/reports/recordings.zip' . $this->range() . '&outcome=CONNECTED_PTP')->assertOk();
        $zip = new \ZipArchive();
        $zip->open($res->baseResponse->getFile()->getPathname());
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $this->assertContains('index.csv', $names);
        $audio = array_values(array_filter($names, fn ($n) => str_ends_with($n, '.mp3')));
        $this->assertCount(count(array_unique($audio)), $audio);   // names never clash
        $this->assertSame($this->as()->getJson('/api/admin/reports/recordings/count' . $this->range() . '&outcome=CONNECTED_PTP')->json('count'), count($audio));
        $this->assertStringStartsWith('File,"Call number"', (string) $zip->getFromName('index.csv'));
        $this->assertSame(1, DB::table('audit_events')->where('company_id', $this->c->id)->where('action', 'recordings.bulk_exported')->count());

        // The audit pack names recordings the same way.
        $pack = $this->as()->get('/api/admin/reports/audit-pack.zip' . $this->range())->assertOk();
        $p = new \ZipArchive();
        $p->open($pack->baseResponse->getFile()->getPathname());
        $rec = array_values(array_filter(array_map(fn ($i) => $p->getNameIndex($i), range(0, $p->numFiles - 1)), fn ($n) => str_starts_with($n, 'recordings/')));
        $this->assertMatchesRegularExpression('#^recordings/(TC|FOS)\d{3}-\d{4}-\d{8}(_\d+)?\.mp3$#', $rec[0]);
    }
}
