<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** rovexy.app/admin with no workspace (the Play reviewer leaves the box empty): email + password find it. */
final class AdminFindWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_is_found_only_with_the_right_password(): void
    {
        config(['ammp.sessions.admin_mfa_required' => false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $c = Company::query()->create(['slug' => 'review', 'name' => 'Review', 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($c, function () use ($c) {
            CompanyDefaults::apply($c);
            $u = User::query()->create(['employee_code' => 'admin', 'name' => 'Admin', 'email' => 'reviewsup@rovexy.app', 'password' => 'Secret-123456', 'status' => 'active']);
            $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $c->id]);
        });
        $h = ['Referer' => 'http://localhost/'];
        $this->postJson('/api/admin/auth/find-workspace', ['email' => 'reviewsup@rovexy.app', 'password' => 'wrong-pass'], $h)->assertStatus(422)->assertJson(['error' => 'invalid_credentials']);
        $this->postJson('/api/admin/auth/find-workspace', ['email' => 'nobody@rovexy.app', 'password' => 'Secret-123456'], $h)->assertStatus(422)->assertJson(['error' => 'invalid_credentials']);
        $this->postJson('/api/admin/auth/find-workspace', ['email' => 'ReviewSup@rovexy.app', 'password' => 'Secret-123456'], $h)->assertOk()->assertExactJson(['workspace' => 'review']);
        // …and the normal sign-in then works inside that workspace.
        $this->postJson('/api/admin/auth/login', ['email' => 'reviewsup@rovexy.app', 'password' => 'Secret-123456'], $h + ['X-Workspace' => 'review'])->assertOk()->assertJson(['mfa' => 'none']);
    }
}
