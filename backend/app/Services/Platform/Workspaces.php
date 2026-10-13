<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Domain\DomainException;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\CompanyDefaults;

/** Creates a cloud workspace (company + defaults + its first administrator) — used by the trial signup and by paid orders. */
final class Workspaces
{
    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    /** Names that are web addresses of ROVEXY itself (or would be confusing as a client's sub-domain). */
    public const RESERVED = ['admin', 'super', 'api', 'www', 'app', 'buy', 'pay', 'accounts', 'password', 'mail', 'smtp', 'ftp', 'support', 'help', 'status', 'static', 'cdn', 'rovexy', 'ametecs'];

    public static function slugFrom(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-') ?: 'company';
        $base = rtrim(substr($base, 0, 30), '-');
        if (strlen($base) < 3 || in_array($base, self::RESERVED, true)) {
            $base = rtrim(substr($base . '-team', 0, 30), '-');
        }
        $slug = $base;
        for ($i = 2; Company::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }
        return strlen($slug) < 3 ? $slug . '-sc' : $slug;
    }

    /** $password: plain text or an existing bcrypt hash (User casts password as hashed and leaves hashes alone). */
    public static function create(string $slug, string $name, string $adminEmail, string $adminName, string $password): Company
    {
        if (!preg_match(self::SLUG, $slug)) {
            throw new DomainException('bad_workspace', 'Workspace name: 3–40 lower-case letters, numbers or dashes.');
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw new DomainException('bad_workspace', "\"{$slug}\" is kept for ROVEXY's own web addresses. Choose another workspace name, e.g. {$slug}-team.");
        }
        if (Company::query()->where('slug', $slug)->exists()) {
            throw new DomainException('workspace_taken', 'That workspace name is taken. Choose another.');
        }
        $company = Company::query()->create(['slug' => $slug, 'name' => $name, 'timezone' => 'Asia/Kolkata', 'default_country' => 'IN']);
        app(CurrentCompany::class)->run($company, function () use ($company, $adminEmail, $adminName, $password) {
            CompanyDefaults::apply($company);
            $u = User::query()->create(['employee_code' => 'admin', 'email' => strtolower($adminEmail), 'name' => $adminName, 'password' => $password, 'status' => 'active']);
            $u->roles()->attach(Role::query()->where('key', 'company_admin')->value('id'), ['company_id' => $company->id]);
        });
        return $company;
    }

    /**
     * Where the company's admins sign in. With TENANCY_BASE_DOMAIN set (and a wildcard DNS record *.<base> pointing at
     * the server): its own sub-domain https://<slug>.<base>/admin. Without it: a path on this server, /w/<slug>/admin,
     * which works straight away (the console sends the workspace with every request).
     */
    public static function consoleUrl(Company $c): string
    {
        $base = config('ammp.tenancy.base_domain');
        return $base ? "https://{$c->slug}.{$base}/admin" : url("/w/{$c->slug}/admin");
    }
}
