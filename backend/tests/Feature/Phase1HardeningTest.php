<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Services\GoogleMaps;
use App\Services\Licensing\LicenceAuthority;
use App\Services\Licensing\LicenceClient;
use App\Services\Licensing\LicenceSigner;
use App\Services\NightlyBackup;
use App\Services\Platform\LicenceIssuer;
use App\Services\Platform\PlatformSettings;
use App\Services\PushCredential;
use App\Services\PushService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Phase 1 of the 29 Oct 2026 gap analysis: G3 (Firebase via licence), G4 (Google cost), G20 (nightly backup), G23 (tunnel). */
final class Phase1HardeningTest extends TestCase
{
    use RefreshDatabase;

    private function serviceAccount(string $project = 'ametecs-fcm'): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        return json_encode(['type' => 'service_account', 'project_id' => $project, 'private_key_id' => 'k1',
            'private_key' => $pem, 'client_email' => "fcm-sender@{$project}.iam.gserviceaccount.com"]);
    }

    private function staff()
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => 'super@ametecs.test'], ['name' => 'Super', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    // ── G3 ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_firebase_credential_is_set_in_super_admin_encrypted_and_never_shown(): void
    {
        $this->assertFalse(PushCredential::ready());
        $this->assertSame('log', PushService::driver());   // tests: PUSH_DRIVER=log, no credential

        $this->staff()->putJson('/api/super/settings', ['fcm_service_account_json' => '{"type":"user"}'])->assertStatus(422);
        $sa = $this->serviceAccount();
        $res = $this->staff()->putJson('/api/super/settings', ['fcm_service_account_json' => $sa])->assertOk();
        $res->assertJsonPath('push.set', true)->assertJsonPath('push.project_id', 'ametecs-fcm')->assertJsonPath('push.source', 'settings')
            ->assertJsonPath('fcm_service_account_json_set', true);
        $this->assertStringNotContainsString('PRIVATE KEY', $res->getContent());   // the key never goes back to the browser
        $this->assertStringNotContainsString('PRIVATE KEY', (string) DB::table('platform_settings')->where('key', PushCredential::SETTING)->value('value'));   // encrypted at rest
        $this->assertSame('fcm', PushService::driver());   // no .env change needed

        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600])]);
        $this->staff()->postJson('/api/super/settings/test-push')->assertOk()->assertJsonPath('ok', true);

        // blank keeps it; "Remove" clears it
        $this->staff()->putJson('/api/super/settings', ['fcm_service_account_json' => ''])->assertJsonPath('push.set', true);
        $this->staff()->putJson('/api/super/settings', ['clear_secrets' => [PushCredential::SETTING]])->assertJsonPath('push.set', false);
    }

    public function test_licence_server_sends_the_credential_with_a_check_in_and_the_install_stores_it(): void
    {
        $dir = sys_get_temp_dir() . '/rovexy-licence-test';
        config(['ammp.licence.private_key' => "{$dir}/private.pem", 'ammp.licence.public_key' => "{$dir}/public.pem"]);
        if (!is_file("{$dir}/private.pem")) {
            LicenceSigner::generate();
        }
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $l = LicenceIssuer::issue($plan, 'Acme', 'it@acme.test', null, 'perpetual', 10, 'onprem', null, 'test');

        // central: no credential → nothing extra; with one → sent beside the licence, never inside the signed token
        $fp = str_repeat('a', 40);
        $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => $fp])->assertOk()->assertJsonMissingPath('push');
        PushCredential::store($this->serviceAccount(), 'settings');
        $ok = $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => $fp])->assertOk();
        $this->assertSame('ametecs-fcm', json_decode($ok->json('push.fcm'), true)['project_id']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(LicenceSigner::verify($ok->json('licence'))));
        $this->postJson('/api/v1/license/validate', ['key' => $l->key, 'fingerprint' => str_repeat('b', 40)])->assertStatus(403)->assertJsonMissingPath('push');   // other server: nothing
        $sent = $ok->json('push');

        // install: a good check-in stores it encrypted, source 'licence'
        PushCredential::store(null, 'settings');
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => 'https://central.test', 'ammp.licence.machine_id' => 'phase1-machine']);
        \Illuminate\Support\Facades\Cache::forget('rovexy.server_fingerprint');
        $myFp = LicenceClient::fingerprint();
        $l->forceFill(['server_fingerprint' => $myFp])->save();
        app(CurrentCompany::class)->set(Company::query()->create(['slug' => 'onprem', 'name' => 'Acme', 'timezone' => 'UTC', 'default_country' => 'IN']));
        Http::fake(['central.test/*' => Http::response(['ok' => true, 'licence' => LicenceSigner::sign(LicenceAuthority::payload($l, 'online', $myFp)), 'push' => $sent])]);
        app(LicenceClient::class)->saveKey($l->key);
        $this->assertSame(['set' => true, 'source' => 'licence', 'project_id' => 'ametecs-fcm'], array_intersect_key(PushCredential::summary(), ['set' => 1, 'source' => 1, 'project_id' => 1]));
        $this->assertSame('fcm', PushService::driver());

        // a credential pasted on the install itself is never overwritten by the licence server
        PushCredential::store($this->serviceAccount('own-project'), 'settings');
        app(LicenceClient::class)->check();
        $this->assertSame('own-project', PushCredential::projectId());
    }

    // ── G4 / G13 ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_road_distances_send_at_most_five_destinations_with_basic_routing(): void
    {
        app(CurrentCompany::class)->set(Company::query()->create(['slug' => 'maps', 'name' => 'Maps Co', 'timezone' => 'UTC', 'default_country' => 'IN']));
        \App\Models\IntegrationConnection::query()->create(['system' => GoogleMaps::SERVER, 'base_url' => 'https://maps.googleapis.com', 'api_key' => 'server-key', 'enabled' => true]);
        Http::fake(['routes.googleapis.com/*' => Http::response([['originIndex' => 0, 'destinationIndex' => 1, 'distanceMeters' => 900, 'duration' => '120s', 'condition' => 'ROUTE_EXISTS']])]);
        $maps = app(GoogleMaps::class);
        $dests = array_map(fn ($i) => [17.40 + $i / 100, 78.40], range(1, 10));   // an older app asks for 10
        $out = $maps->matrix([17.44, 78.35], $dests);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => count($r['destinations']) === GoogleMaps::MAX_MATRIX && $r['travelMode'] === 'DRIVE' && $r['routingPreference'] === 'TRAFFIC_UNAWARE');
        $this->assertSame(900, $out[1]['distance_m']);
        $this->assertNull($maps->displayKey());   // G13: no display key → phones get nothing, never the server key
    }

    // ── G20 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_nightly_backup_keeps_seven_copies_and_reports_to_system_health(): void
    {
        $db = tempnam(sys_get_temp_dir(), 'rxdb') . '.sqlite';
        file_put_contents($db, str_repeat('SQLite data ', 50));
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $db], 'database.default' => 'backup_test']);
        $dir = NightlyBackup::dir();
        array_map('unlink', glob($dir . '/rovexy-*.gz') ?: []);
        @unlink($dir . '/last-backup.json');
        config(['database.default' => 'sqlite']);
        $this->assertFalse(NightlyBackup::status()['ok']);   // never ran yet

        config(['database.default' => 'backup_test']);
        for ($i = 0; $i < 9; $i++) {
            $this->travel(1)->days();
            $this->assertTrue(NightlyBackup::run()['ok']);
        }
        config(['database.default' => 'sqlite']);
        $files = glob($dir . '/rovexy-*.gz');
        $this->assertCount(NightlyBackup::KEEP, $files);
        $this->assertSame(str_repeat('SQLite data ', 50), gzdecode((string) file_get_contents(end($files))));
        $s = NightlyBackup::status();
        $this->assertTrue($s['ok']);
        $this->assertSame(7, $s['copies_kept']);

        // a failure is recorded, the last good backup stays visible
        config(['database.connections.backup_test.database' => '/nonexistent/x.sqlite', 'database.default' => 'backup_test']);
        $this->assertFalse(NightlyBackup::run()['ok']);
        config(['database.default' => 'sqlite']);
        $this->assertNotNull(NightlyBackup::status()['last_error']);
        $this->assertNotNull(NightlyBackup::status()['last_backup_at']);
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn ($e) => str_contains((string) $e->command, 'rovexy:backup')));
        array_map('unlink', glob($dir . '/rovexy-*.gz') ?: []);
        @unlink($dir . '/last-backup.json');
    }

    // ── G23 ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_requests_from_a_local_tunnel_see_https_and_the_visitors_real_ip(): void
    {
        Route::get('/__probe', fn (\Illuminate\Http\Request $r) => ['secure' => $r->isSecure(), 'ip' => $r->ip()])->middleware('web');
        // cloudflared → local web server (127.0.0.1): forwarded headers are trusted
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '1.2.3.4, 49.36.10.20'])
            ->get('/__probe')->assertJson(['secure' => true, 'ip' => '49.36.10.20']);
    }

    public function test_forwarded_headers_from_anyone_else_are_ignored(): void
    {
        Route::get('/__probe', fn (\Illuminate\Http\Request $r) => ['secure' => $r->isSecure(), 'ip' => $r->ip()])->middleware('web');
        $this->withServerVariables(['REMOTE_ADDR' => '49.36.10.20'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '6.6.6.6'])
            ->get('/__probe')->assertJson(['secure' => false, 'ip' => '49.36.10.20']);
    }
}
