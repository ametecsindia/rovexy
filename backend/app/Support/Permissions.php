<?php

declare(strict_types=1);

namespace App\Support;

/** Canonical permission keys and the system role templates seeded for every company. */
final class Permissions
{
    public const ALL = [
        'devices.view' => 'View managed devices',
        'devices.approve' => 'Approve/reject device requests and assign devices',
        'devices.revoke' => 'Revoke device access',
        'devices.wipe' => 'Request a factory reset (wipe) of a managed device',
        'policies.view' => 'View policy profiles',
        'policies.edit' => 'Edit draft policies',
        'policies.publish' => 'Publish/roll back policies',
        'employees.view' => 'View employees and teams',
        'employees.manage' => 'Create/edit employees, teams and roles',
        'customers.view' => 'View customers',
        'customers.import' => 'Import customers',
        'customers.assign' => 'Assign, reassign and withdraw customers',
        'calls.view' => 'View call history',
        'payments.verify' => 'Verify payment slips and e-mail them',
        'recordings.play' => 'Play call recordings',
        'recordings.download' => 'Download/export call recordings',
        'location.view' => 'View employee location',
        'media.screen' => 'Request live screen sessions',
        'media.screenshot' => 'Take screenshots within a live session',
        'media.camera' => 'Request visible camera sessions',
        'chat.use' => 'Use employee chat',
        'reports.export' => 'Export reports',
        'branding.manage' => 'Manage company branding',
        'settings.storage' => 'Manage storage settings',
        'settings.retention' => 'Plan and approve retention jobs',
        'telephony.configure' => 'Configure calling and recording',
        'audit.view' => 'View audit logs',
        'system.health' => 'View system health and integrations',
        'scope.all_teams' => 'See all teams (otherwise only supervised teams)',
        'mobile.work' => 'Use the employee mobile app',
        'calls.make' => 'Place customer calls from the mobile app',
    ];

    public const ROLES = [
        'company_admin' => ['name' => 'Company Administrator', 'permissions' => '*'],
        'it_admin' => ['name' => 'IT / Device Administrator', 'permissions' => [
            'devices.view', 'devices.approve', 'devices.revoke', 'devices.wipe', 'policies.view', 'policies.edit', 'policies.publish',
            'employees.view', 'branding.manage', 'settings.storage', 'audit.view', 'system.health', 'scope.all_teams', 'telephony.configure',
        ]],
        'supervisor' => ['name' => 'Supervisor', 'permissions' => [
            'devices.view', 'employees.view', 'customers.view', 'customers.import', 'customers.assign', 'calls.view', 'payments.verify', 'recordings.play', 'location.view',
            'media.screen', 'media.screenshot', 'media.camera', 'chat.use', 'reports.export',
        ]],
        'employee' => ['name' => 'Employee', 'permissions' => ['mobile.work', 'calls.make', 'chat.use']],
        'auditor' => ['name' => 'Read-only Auditor', 'permissions' => [
            'devices.view', 'policies.view', 'employees.view', 'customers.view', 'calls.view', 'audit.view', 'system.health', 'scope.all_teams',
        ]],
    ];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        $p = self::ROLES[$role]['permissions'];
        return $p === '*' ? array_values(array_diff(array_keys(self::ALL), ['mobile.work', 'calls.make'])) : $p;
    }
}
