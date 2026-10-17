<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\BuildUpdate;
use App\Models\Company;
use App\Models\Platform\Plan;
use App\Models\User;
use App\Services\Licensing\LicenceAuthority;
use App\Services\Licensing\LicenceClient;
use App\Services\Licensing\LicenceSigner;
use App\Services\Platform\LicenceIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The common on-prem client package: setup on a client's server (rovexy:setup) and activation with that client's own
 * licence file (rovexy:licence import) — through the install's existing LicenceClient, same checks as the console.
 */
final class OnPremClientPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/rovexy-licence-test';
        config(['ammp.licence.private_key' => "{$dir}/private.pem", 'ammp.licence.public_key' => "{$dir}/public.pem"]);
        if (!is_file("{$dir}/private.pem")) {
            LicenceSigner::generate();
        }
        config(['ammp.licence.mode' => 'client', 'ammp.licence.server_url' => '', 'ammp.licence.machine_id' => 'client-a-server',
            'ammp.tenancy.mode' => 'fixed', 'ammp.tenancy.fixed_company_id' => 1, 'app.url' => 'https://rovexy.client-a.test']);
        Cache::forget('rovexy.server_fingerprint');
    }

    private function licenceFile(?string $fingerprint, int $users = 25): array
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $l = LicenceIssuer::issue($plan, 'Client A', 'it@client-a.test', null, 'perpetual', $users, 'onprem', null, 'test');
        $path = tempnam(sys_get_temp_dir(), 'lic');
        file_put_contents($path, LicenceAuthority::file($l, $fingerprint, 'test'));   // what Super Admin → Licence file… downloads
        return [$l, $path];
    }

    public function test_setup_creates_the_company_and_admin_once_and_activates_the_clients_licence_file(): void
    {
        [$l, $file] = $this->licenceFile(LicenceClient::fingerprint());
        $this->artisan('rovexy:setup', ['--company' => 'Client A Recoveries', '--admin-email' => 'admin@client-a.test',
            '--admin-password' => 'Client-A-password-1', '--licence' => $file])
            ->expectsOutputToContain('Licence file imported and activated')->assertSuccessful();

        $c = Company::query()->sole();
        $this->assertSame(['Client A Recoveries', 'client-a-recoveries', 1], [$c->name, $c->slug, $c->id]);
        $this->assertTrue(app(\App\Support\CurrentCompany::class)->run($c, fn () => User::query()->where('email', 'admin@client-a.test')->sole()
            ->roles()->where('key', 'company_admin')->exists()));
        $row = app(LicenceClient::class)->row();
        $this->assertSame([$l->key, 'file', 'active'], [$row->licence_key, $row->source, $row->status]);

        // running the install again changes nothing
        $this->artisan('rovexy:setup')->expectsOutputToContain('already set up: Client A Recoveries')->assertSuccessful();
        $this->assertSame(1, Company::query()->count());
    }

    public function test_a_signed_in_browser_can_reload_console_pages_on_premises(): void
    {
        // the on-prem .env keeps sessions in the database; reloading /admin after signing in used to answer 500
        config(['session.driver' => 'database']);
        $this->artisan('rovexy:setup', ['--company' => 'Client A', '--admin-email' => 'admin@client-a.test', '--admin-password' => 'Client-A-password-1'])->assertSuccessful();
        $c = Company::query()->sole();
        $id = app(\App\Support\CurrentCompany::class)->run($c, fn () => User::query()->where('email', 'admin@client-a.test')->value('id'));
        app()->forgetScopedInstances();
        $this->withSession(['login_web_' . sha1(\Illuminate\Auth\SessionGuard::class) => $id])->get('/admin/customers')->assertOk();
    }

    public function test_the_windows_installer_passes_the_admin_password_in_the_environment_not_the_command_line(): void
    {
        putenv('ROVEXY_ADMIN_PASSWORD=From-the-installer-1');
        try {
            $this->artisan('rovexy:setup', ['--company' => 'Client A', '--admin-email' => 'admin@client-a.test', '--no-interaction' => true])
                ->assertSuccessful();
        } finally {
            putenv('ROVEXY_ADMIN_PASSWORD');
        }
        $c = Company::query()->sole();
        $user = app(\App\Support\CurrentCompany::class)->run($c, fn () => User::query()->where('email', 'admin@client-a.test')->sole());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('From-the-installer-1', $user->password));
    }

    public function test_the_same_package_on_another_server_refuses_client_as_file_and_takes_its_own(): void
    {
        [, $fileA] = $this->licenceFile(LicenceClient::fingerprint());          // made for client A's server
        config(['ammp.licence.machine_id' => 'client-b-server']);                // the same package, installed at client B
        Cache::forget('rovexy.server_fingerprint');
        $idB = LicenceClient::fingerprint();
        $this->artisan('rovexy:licence', ['action' => 'id'])->expectsOutput($idB)->assertSuccessful();

        $this->artisan('rovexy:licence', ['action' => 'import', 'value' => $fileA])
            ->expectsOutputToContain('made for another server')->assertFailed();
        $this->assertNull(app(LicenceClient::class)->row()->licence_key);

        [$lB, $fileB] = $this->licenceFile($idB, 40);
        $this->artisan('rovexy:licence', ['action' => 'import', 'value' => $fileB])
            ->expectsOutputToContain('Licence file imported and activated')->assertSuccessful();
        $this->assertSame([$lB->key, 'active'], [app(LicenceClient::class)->row()->licence_key, app(LicenceClient::class)->row()->status]);
        $this->artisan('rovexy:licence', ['action' => 'import', 'value' => __FILE__])->expectsOutputToContain('not a valid ROVEXY licence file')->assertFailed();
    }

    public function test_setup_refuses_a_cloud_configuration_and_an_unset_address(): void
    {
        config(['ammp.licence.mode' => 'central']);
        $this->artisan('rovexy:setup')->expectsOutputToContain('LICENCE_MODE=client')->assertFailed();
        config(['ammp.licence.mode' => 'client', 'app.url' => 'https://rovexy.example.com']);
        $this->artisan('rovexy:setup')->expectsOutputToContain('APP_URL')->assertFailed();
        $this->assertSame(0, Company::query()->count());
    }

    public function test_package_files_are_the_release_files_without_secrets(): void
    {
        $files = BuildUpdate::files(base_path());
        $this->assertContains('artisan', $files);
        $this->assertContains('public/.htaccess', $files);
        $this->assertContains('resources/licence/public.pem', $files);
        $this->assertContains('updater/updater.php', $files);
        $this->assertSame([], preg_grep('#^(\.env|storage/|tests/)|licence-private#', $files));
    }
}
