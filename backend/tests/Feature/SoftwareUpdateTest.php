<?php

namespace Tests\Feature;

use App\Console\Commands\BuildUpdate;
use App\Models\Platform\Licence;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ProductUpdate;
use App\Services\Licensing\LicenceSigner;
use App\Services\Platform\LicenceIssuer;
use App\Services\Updates\UpdatePackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use ZipArchive;

/**
 * On-premises self-update (SmartEPT update logic + its listed defects fixed): signed catalogue with the version read from
 * the package, licence/AMC-gated check, server-bound download token, and the detached updater with database backup,
 * manifest clean-up, real health check and delete-then-restore rollback.
 */
final class SoftwareUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const FP = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/rovexy-licence-test';
        config(['ammp.licence.private_key' => "{$dir}/private.pem", 'ammp.licence.public_key' => "{$dir}/public.pem"]);
        if (!is_file("{$dir}/private.pem")) {
            LicenceSigner::generate();
        }
        $this->tmp = sys_get_temp_dir() . '/rovexy-upd-' . uniqid();
        mkdir($this->tmp, 0755, true);
        @mkdir(storage_path('app/updates'), 0755, true);
        foreach (glob(storage_path('app/updates/ROVEXY-Update-*.zip')) ?: [] as $f) {
            unlink($f);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        foreach (glob(storage_path('app/updates/ROVEXY-Update-*.zip')) ?: [] as $f) {
            unlink($f);
        }
        parent::tearDown();
    }

    /** A tiny fake app tree: its "artisan" logs commands, migrate marks the database, health fails when FAIL_HEALTH exists. */
    private function fakeArtisan(): string
    {
        return <<<'PHP'
<?php
$b = __DIR__; $cmd = $argv[1] ?? '';
file_put_contents("$b/artisan.log", implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
if ($cmd === 'migrate') { file_put_contents("$b/database.sqlite", "MIGRATED\n", FILE_APPEND); exit(is_file("$b/FAIL_MIGRATE") ? 1 : 0); }
if ($cmd === 'rovexy:health-check') { exit(is_file("$b/FAIL_HEALTH") ? 1 : 0); }
exit(0);
PHP;
    }

    /** @param array<string,string> $files */
    private function zip(string $path, array $files, string $version, bool $wrapped = false): string
    {
        $files += ['artisan' => $this->fakeArtisan(), 'config/ammp.php' => '<?php return [];', 'app/Providers/AppServiceProvider.php' => '<?php // v' . $version,
            'bootstrap/app.php' => '<?php', 'public/index.php' => '<?php', 'vendor/autoload.php' => '<?php', 'routes/api.php' => '<?php',
            'updater/updater.php' => (string) file_get_contents(base_path('updater/updater.php')),
            'version.json' => json_encode(['product' => 'rovexy', 'version' => $version, 'channel' => 'stable'])];
        $files['manifest.json'] = json_encode(['product' => 'rovexy', 'version' => $version, 'files' => array_keys($files)]);
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $body) {
            $z->addFromString(($wrapped ? 'ROVEXY/' : '') . $name, $body);
        }
        $z->close();
        return $path;
    }

    private function super()
    {
        $s = PlatformAdmin::query()->firstOrCreate(['email' => 'super@ametecs.test'], ['name' => 'Super', 'password' => 'Staff-password-1', 'role' => 'super', 'status' => 'active']);
        return $this->actingAs($s, 'platform')->withHeaders(['Referer' => 'http://localhost/']);
    }

    private function publish(string $version, ?string $min = null, string $channel = 'stable'): ProductUpdate
    {
        $zip = $this->zip("{$this->tmp}/up-{$version}.zip", ['app/new.php' => '<?php // new'], $version);
        $this->super()->post('/api/super/updates', ['file' => new UploadedFile($zip, "up-{$version}.zip", 'application/zip', null, true), 'channel' => $channel,
            'min_version' => $min, 'title' => "Release {$version}"], ['Accept' => 'application/json'])->assertCreated();
        $u = ProductUpdate::query()->where('version', $version)->firstOrFail();
        $this->super()->postJson("/api/super/updates/{$u->id}/publish", ['published' => true])->assertOk();
        return $u->fresh();
    }

    public function test_catalogue_reads_the_version_from_the_package_signs_it_and_refuses_bad_packages(): void
    {
        $u = $this->publish('1.1');
        $this->assertSame(['1.1', 'ROVEXY-Update-1.1.zip', true], [$u->version, $u->filename, $u->is_published]);
        $this->assertTrue(UpdatePackage::verify($u->signature, '1.1', $u->sha256));
        $this->assertFalse(UpdatePackage::verify($u->signature, '1.2', $u->sha256));                             // signature binds version + hash

        $wrapped = $this->zip("{$this->tmp}/wrapped.zip", [], '1.2', true);
        $this->super()->post('/api/super/updates', ['file' => new UploadedFile($wrapped, 'w.zip', 'application/zip', null, true), 'channel' => 'stable'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error', 'bad_package');
        $this->assertFileDoesNotExist(storage_path('app/updates/ROVEXY-Update-1.2.zip'));                          // a rejected upload is never kept
        $again = $this->zip("{$this->tmp}/again.zip", [], '1.1');
        $this->super()->post('/api/super/updates', ['file' => new UploadedFile($again, 'a.zip', 'application/zip', null, true), 'channel' => 'stable'], ['Accept' => 'application/json'])
            ->assertStatus(422);                                                                                     // same version, different file
        $sales = PlatformAdmin::query()->create(['email' => 'sales@ametecs.test', 'name' => 'S', 'password' => 'Staff-password-1', 'role' => 'sales', 'status' => 'active']);
        $this->actingAs($sales, 'platform')->getJson('/api/super/updates')->assertForbidden();
        $this->assertSame('1.2', BuildUpdate::bump('1.1'));
        $this->assertSame('2.0', BuildUpdate::bump('1.9'));
        $this->assertSame('1.6.0', BuildUpdate::bump('1.5.9'));
    }

    public function test_update_check_is_gated_by_licence_server_and_amc_and_downloads_are_bound_to_the_server(): void
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $l = LicenceIssuer::issue($plan, 'Acme', 'it@acme.test', null, 'perpetual', 10, 'onprem', null, 'test');
        $l->forceFill(['server_fingerprint' => self::FP])->save();
        $this->publish('1.1');
        $this->publish('2.0', '1.5');
        $check = fn (array $b = []) => $this->postJson('/api/v1/updates/check', $b + ['key' => $l->key, 'fingerprint' => self::FP, 'current_version' => '1.0']);

        $this->postJson('/api/v1/updates/check', ['key' => 'RX-AAAA-BBBB-CCCC-DDDD', 'fingerprint' => self::FP, 'current_version' => '1.0'])->assertStatus(403)->assertJsonPath('reason', 'unknown_key');
        $check(['fingerprint' => str_repeat('f', 40)])->assertStatus(403)->assertJsonPath('reason', 'server_mismatch');
        $ok = $check()->assertOk()->assertJsonPath('version', '1.1')->json();                                       // 2.0 needs 1.5 first
        $this->assertTrue(UpdatePackage::verify($ok['signature'], '1.1', $ok['package_hash']));
        $check(['current_version' => '1.1'])->assertOk()->assertJsonPath('update_available', false)
            ->assertJsonPath('message', 'Version 2.0 needs version 1.5 or later first. Contact Ametecs for the intermediate update.');
        $check(['current_version' => '1.5'])->assertOk()->assertJsonPath('version', '2.0');

        $path = parse_url($ok['download_url'], PHP_URL_PATH);
        $this->get($path, ['X-Rovexy-Server' => str_repeat('f', 40)])->assertStatus(403);                          // link from another server
        $res = $this->get($path, ['X-Rovexy-Server' => self::FP])->assertOk();
        $this->assertSame($ok['package_hash'], hash_file('sha256', $res->baseResponse->getFile()->getPathname()));
        Cache::flush();
        $this->get($path, ['X-Rovexy-Server' => self::FP])->assertStatus(410);                                       // expired token

        $l->forceFill(['amc_expires_at' => now()->subDay()])->save();
        $check()->assertStatus(403)->assertJsonPath('reason', 'amc_expired');                                        // perpetual: updates need AMC
        $l->forceFill(['amc_expires_at' => now()->addYear(), 'status' => 'suspended'])->save();
        $check()->assertStatus(403)->assertJsonPath('reason', 'licence_suspended');
    }

    private function fakeInstall(string $version = '1.0'): string
    {
        $base = "{$this->tmp}/app";
        foreach (['app', 'config', 'storage/app/updates', 'resources/licence', 'storage/framework'] as $d) {
            @mkdir("{$base}/{$d}", 0755, true);
        }
        file_put_contents("{$base}/artisan", $this->fakeArtisan());
        file_put_contents("{$base}/app/old.php", '<?php // removed in the new release');
        file_put_contents("{$base}/app/keep.php", '<?php // old');
        file_put_contents("{$base}/config/app.php", '<?php return ["old" => true];');
        file_put_contents("{$base}/version.json", json_encode(['product' => 'rovexy', 'version' => $version, 'channel' => 'beta']));
        file_put_contents("{$base}/.env", "SECRET=keep\n");
        file_put_contents("{$base}/database.sqlite", "ORIGINAL\n");
        file_put_contents("{$base}/storage/app/data.txt", 'customer data');
        copy((string) config('ammp.licence.public_key'), "{$base}/resources/licence/public.pem");
        return $base;
    }

    private function runUpdater(string $base, string $version, array $extra = [], ?string $signature = null): array
    {
        $pkg = $this->zip("{$base}/storage/app/updates/pkg.zip", ['app/keep.php' => '<?php // new', 'app/new.php' => '<?php // added', '.env' => 'HACKED=1',
            'storage/app/data.txt' => 'overwritten'] + $extra, $version);
        $sha = hash_file('sha256', $pkg);
        $job = "{$base}/storage/app/updates/job.json";
        file_put_contents($job, json_encode(['base' => $base, 'package' => $pkg, 'version' => $version, 'from_version' => '1.0', 'sha256' => $sha,
            'signature' => $signature ?? UpdatePackage::signature($version, $sha), 'php' => PHP_BINARY, 'state_file' => "{$base}/storage/app/updates/state.json",
            'db' => ['driver' => 'sqlite', 'database' => "{$base}/database.sqlite"]]));
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(base_path('updater/updater.php')) . ' ' . escapeshellarg($job) . ' 2>&1', $out, $code);
        return [$code, json_decode((string) file_get_contents("{$base}/storage/app/updates/state.json"), true), implode("\n", $out)];
    }

    public function test_updater_installs_backs_up_cleans_and_protects(): void
    {
        $base = $this->fakeInstall();
        [$code, $state, $out] = $this->runUpdater($base, '1.1');
        $this->assertSame(0, $code, $out);
        $this->assertSame(['done', '1.1'], [$state['phase'], $state['current_version']]);
        $this->assertSame('<?php // new', file_get_contents("{$base}/app/keep.php"));
        $this->assertFileExists("{$base}/app/new.php");
        $this->assertFileDoesNotExist("{$base}/app/old.php");                                                       // removed by the release (manifest)
        $this->assertSame("SECRET=keep\n", file_get_contents("{$base}/.env"));                                       // protected
        $this->assertSame('customer data', file_get_contents("{$base}/storage/app/data.txt"));
        $v = json_decode(file_get_contents("{$base}/version.json"), true);
        $this->assertSame(['1.1', 'beta'], [$v['version'], $v['channel']]);                                           // channel kept
        $this->assertStringContainsString('MIGRATED', file_get_contents("{$base}/database.sqlite"));
        $log = file_get_contents("{$base}/artisan.log");
        $this->assertStringContainsString('down', $log);
        $this->assertStringContainsString('rovexy:health-check', $log);
        $this->assertStringContainsString('up', $log);
        $this->assertCount(1, glob("{$base}/storage/app/updates/backup_*"));
        $this->assertFileExists(glob("{$base}/storage/app/updates/backup_*")[0] . '/database.sqlite');
        $this->assertFileDoesNotExist("{$base}/storage/app/updates/job.json");                                       // the DB password never lingers
    }

    public function test_updater_rolls_back_files_and_database_when_the_health_check_fails(): void
    {
        $base = $this->fakeInstall();
        [$code, $state] = $this->runUpdater($base, '1.1', ['FAIL_HEALTH' => 'x']);                                  // the new release is broken
        $this->assertSame(1, $code);
        $this->assertSame('failed', $state['phase']);
        $this->assertStringContainsString('still running its previous version', $state['message']);
        $this->assertSame('<?php // old', file_get_contents("{$base}/app/keep.php"));
        $this->assertFileExists("{$base}/app/old.php");
        $this->assertFileDoesNotExist("{$base}/app/new.php");                                                       // delete-then-restore, not merge
        $this->assertFileDoesNotExist("{$base}/FAIL_HEALTH");
        $this->assertSame("ORIGINAL\n", file_get_contents("{$base}/database.sqlite"));                              // database restored too
        $this->assertSame('1.0', json_decode(file_get_contents("{$base}/version.json"), true)['version']);
        $this->assertFileDoesNotExist("{$base}/storage/framework/down");                                            // back up
    }

    public function test_updater_refuses_a_package_not_signed_by_ametecs_before_touching_anything(): void
    {
        $base = $this->fakeInstall();
        [$code, $state] = $this->runUpdater($base, '1.1', [], 'forged.signature');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('nothing was changed', $state['message']);
        $this->assertStringContainsString('not signed by Ametecs', $state['message']);
        $this->assertFileExists("{$base}/app/old.php");
        $this->assertFileDoesNotExist("{$base}/artisan.log");                                                        // never went into maintenance
        $this->assertCount(0, glob("{$base}/storage/app/updates/backup_*"));
    }

    public function test_install_checks_signature_and_hash_before_it_will_install(): void
    {
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => 'https://central.test', 'ammp.licence.machine_id' => 'upd-machine']);
        Cache::forget('rovexy.server_fingerprint');
        @unlink(\App\Services\Updates\UpdateClient::stateFile());
        $client = app(\App\Services\Licensing\LicenceClient::class);
        $client->row()->forceFill(['licence_key' => 'RX-TEST-TEST-TEST-TEST'])->save();
        $pkg = $this->zip("{$this->tmp}/srv.zip", [], '9.1');
        $sha = hash_file('sha256', $pkg);
        $offer = fn (string $sig) => ['ok' => true, 'update_available' => true, 'version' => '9.1', 'package_hash' => $sha, 'signature' => $sig, 'size_bytes' => filesize($pkg),
            'download_url' => 'https://central.test/api/v1/updates/download/' . str_repeat('a', 48), 'notes' => 'Fixes'];
        $u = app(\App\Services\Updates\UpdateClient::class);

        \Illuminate\Support\Facades\Http::fake(['central.test/api/v1/updates/check' => \Illuminate\Support\Facades\Http::response($offer('forged.sig'))]);
        $this->expectDomainReason('bad_signature', fn () => $u->check());                                        // an unsigned offer is refused outright

        $sent = [];
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(function ($req) use (&$sent, $offer, $sha, $pkg) {
            $sent[] = $req->header('X-Rovexy-Server')[0] ?? null;
            return str_contains($req->url(), '/check') ? \Illuminate\Support\Facades\Http::response($offer(UpdatePackage::signature('9.1', $sha)))
                : \Illuminate\Support\Facades\Http::response(file_get_contents($pkg));
        });
        $this->assertSame('available', $u->check()['phase']);
        $this->assertSame('downloaded', $u->download()['phase']);
        $this->assertSame(\App\Services\Licensing\LicenceClient::fingerprint(), end($sent));                     // the download proves which server asks
        $this->assertTrue($u->status()['downloaded']);

        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(function ($req) use ($offer, $sha) {
            return str_contains($req->url(), '/check') ? \Illuminate\Support\Facades\Http::response($offer(UpdatePackage::signature('9.1', $sha)))
                : \Illuminate\Support\Facades\Http::response('tampered bytes');
        });
        $u->check();
        $this->expectDomainReason('bad_package', fn () => $u->download());                                     // hash mismatch → deleted
        $this->assertFalse($u->status()['downloaded']);
        $this->expectDomainReason('not_downloaded', fn () => $u->install());
        @unlink(\App\Services\Updates\UpdateClient::stateFile());
    }

    private function expectDomainReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$reason}");
        } catch (\App\Domain\DomainException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
