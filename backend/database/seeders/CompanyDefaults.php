<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyBranding;
use App\Models\DispositionCode;
use App\Models\Permission;
use App\Models\PolicyAssignment;
use App\Models\PolicyProfile;
use App\Models\PolicyVersion;
use App\Models\Role;
use App\Support\Permissions;

/** Idempotent per-company defaults: system roles, disposition codes, a published baseline policy, neutral branding. */
final class CompanyDefaults
{
    public static function apply(Company $company): void
    {
        foreach (Permissions::ALL as $key => $desc) {
            Permission::query()->firstOrCreate(['key' => $key], ['description' => $desc]);
        }
        foreach (Permissions::ROLES as $key => $def) {
            $role = Role::query()->firstOrCreate(['key' => $key], ['name' => $def['name'], 'system' => true]);
            $role->permissions()->sync(Permission::query()->whereIn('key', Permissions::forRole($key))->pluck('id'));
        }
        foreach (\App\Support\Outcomes::CODES as [$code, $label, $fu, $captures]) {
            DispositionCode::query()->firstOrCreate(['code' => $code], ['label' => $label, 'requires_follow_up' => $fu, 'active' => true, 'captures' => $captures]);
        }
        CompanyBranding::query()->firstOrCreate([], ['display_name' => $company->name]); // no logo: apps show a neutral placeholder
        $profile = PolicyProfile::query()->firstOrCreate(['name' => 'Company baseline'], ['description' => 'Default for all devices']);
        if (!PolicyVersion::query()->where('profile_id', $profile->id)->exists()) {
            PolicyVersion::query()->create(['profile_id' => $profile->id, 'version' => 1, 'status' => 'published', 'published_at' => now(),
                'settings' => ['apps.packages' => [config('ammp.employee_app_package')]], 'notes' => 'Initial baseline']);
            PolicyAssignment::query()->firstOrCreate(['target_type' => 'company', 'target_id' => 0], ['profile_id' => $profile->id]);
        }
    }
}
