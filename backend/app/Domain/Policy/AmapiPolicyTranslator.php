<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * Maps effective device-level controls onto an Android Management API Policy resource.
 * Field names follow https://developers.google.com/android/management/reference/rest/v1/enterprises.policies
 * (verify enum values against a sandbox enterprise before production).
 */
final class AmapiPolicyTranslator
{
    /**
     * @param array<string,mixed> $v effective values
     * @param array{package:string, server_url:string, company_slug:string, enrolment_ref?:string} $app
     */
    public static function translate(array $v, array $app): array
    {
        $permissionGrants = [
            ['permission' => 'android.permission.ACCESS_FINE_LOCATION', 'policy' => $v['location.enabled'] ? 'GRANT' : 'PROMPT'],
            ['permission' => 'android.permission.POST_NOTIFICATIONS', 'policy' => 'GRANT'],
        ];
        if ($v['location.background']) {
            $permissionGrants[] = ['permission' => 'android.permission.ACCESS_BACKGROUND_LOCATION', 'policy' => 'GRANT'];
        }
        if ($v['calling.agent_leg'] === 'sip_endpoint' && $v['device.microphone'] !== 'blocked') {
            $permissionGrants[] = ['permission' => 'android.permission.RECORD_AUDIO', 'policy' => 'GRANT'];
        }
        if ($v['camera_session.enabled']) {
            // Camera sessions remain employee-initiated; PROMPT keeps the OS dialog visible.
            $permissionGrants[] = ['permission' => 'android.permission.CAMERA', 'policy' => 'PROMPT'];
        }

        $employeeApp = [
            'packageName' => $app['package'],
            'installType' => $v['kiosk.enabled'] ? 'KIOSK' : 'FORCE_INSTALLED',
            'defaultPermissionPolicy' => 'PROMPT',
            'permissionGrants' => $permissionGrants,
            'managedConfiguration' => array_filter([
                'server_url' => $app['server_url'],
                'company_slug' => $app['company_slug'],
                'enrolment_ref' => $app['enrolment_ref'] ?? null,
                'lock_server' => true,
            ], static fn ($x) => $x !== null),
        ];

        $applications = [$employeeApp];
        foreach ($v['apps.packages'] as $pkg) {
            if ($pkg === $app['package']) {
                continue;
            }
            $applications[] = ['packageName' => $pkg, 'installType' => $v['apps.mode'] === 'allowlist' ? 'AVAILABLE' : 'BLOCKED'];
        }

        $policy = [
            'applications' => $applications,
            'playStoreMode' => $v['apps.mode'] === 'allowlist' ? 'WHITELIST' : 'BLACKLIST',
            'advancedSecurityOverrides' => [
                'untrustedAppsPolicy' => $v['apps.unknown_sources_allowed'] ? 'ALLOW_INSTALL_DEVICE_WIDE' : 'DISALLOW_INSTALL',
                'developerSettings' => 'DEVELOPER_SETTINGS_DISABLED',
            ],
            'cameraAccess' => $v['device.camera'] === 'blocked' ? 'CAMERA_ACCESS_DISABLED' : 'CAMERA_ACCESS_USER_CHOICE',
            'microphoneAccess' => $v['device.microphone'] === 'blocked' ? 'MICROPHONE_ACCESS_DISABLED' : 'MICROPHONE_ACCESS_USER_CHOICE',
            'adjustVolumeDisabled' => $v['device.adjust_volume_disabled'],
            'setWallpaperDisabled' => $v['device.set_wallpaper_disabled'],
            'screenCaptureDisabled' => $v['device.screen_capture_disabled'],
            'mountPhysicalMediaDisabled' => $v['device.external_storage_disabled'],
            'bluetoothConfigDisabled' => $v['device.bluetooth_config_disabled'],
            'deviceConnectivityManagement' => [
                'usbDataAccess' => match ($v['device.usb_data']) {
                    'allowed' => 'ALLOW_USB_DATA_TRANSFER',
                    'no_file_transfer' => 'DISALLOW_USB_FILE_TRANSFER',
                    'no_data' => 'DISALLOW_USB_DATA_TRANSFER',
                },
            ],
            'locationMode' => $v['device.location_mode'] === 'enforced' ? 'LOCATION_ENFORCED' : 'LOCATION_USER_CHOICE',
            'statusReportingSettings' => [
                'applicationReportsEnabled' => true,
                'deviceSettingsEnabled' => true,
                'softwareInfoEnabled' => true,
                'networkInfoEnabled' => true,
                'powerManagementEventsEnabled' => true,
                'hardwareStatusEnabled' => true,
            ],
            // Never block emergency calling: no keyguard/phone restrictions are set by this translator.
        ];
        if ($v['kiosk.enabled']) {
            $policy['kioskCustomization'] = [
                'deviceSettings' => $v['kiosk.settings_access'] === 'allowed' ? 'SETTINGS_ACCESS_ALLOWED' : 'SETTINGS_ACCESS_BLOCKED',
                'statusBar' => 'NOTIFICATIONS_AND_SYSTEM_INFO_ENABLED',
                'powerButtonActions' => 'POWER_BUTTON_AVAILABLE',
                'systemNavigation' => 'NAVIGATION_ENABLED',
            ];
        }
        return $policy;
    }
}
