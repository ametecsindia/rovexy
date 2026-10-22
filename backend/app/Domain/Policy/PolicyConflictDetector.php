<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/** Detects contradictory settings before a policy is published. Errors block publication; warnings need acknowledgement. */
final class PolicyConflictDetector
{
    /**
     * @param array<string,mixed> $v effective values
     * @return list<array{code:string,severity:string,keys:list<string>,message:string}>
     */
    public static function detect(array $v, string $employeeAppPackage): array
    {
        $out = [];
        $add = static function (string $code, string $sev, array $keys, string $msg) use (&$out): void {
            $out[] = ['code' => $code, 'severity' => $sev, 'keys' => $keys, 'message' => $msg];
        };

        if ($v['device.camera'] === 'blocked' && $v['camera_session.enabled']) {
            $add('camera_blocked_vs_sessions', 'error', ['device.camera', 'camera_session.enabled'],
                'The device camera is blocked, so camera sessions cannot run. Allow the camera or disable camera sessions.');
        }
        if ($v['device.microphone'] === 'blocked' && $v['calling.enabled'] && $v['calling.agent_leg'] === 'sip_endpoint') {
            $add('mic_blocked_vs_voip', 'error', ['device.microphone', 'calling.enabled', 'calling.agent_leg'],
                'In-app VoIP needs the microphone, but the device microphone is blocked.');
        }
        if ($v['device.microphone'] === 'blocked' && $v['calling.enabled'] && $v['calling.agent_leg'] === 'pstn_callback') {
            $add('mic_blocked_vs_callback', 'warning', ['device.microphone'],
                'The microphone block may also silence the cellular callback leg on some models. Validate on the target device.');
        }
        if ($v['device.screen_capture_disabled'] && $v['live_view.enabled']) {
            $add('screen_capture_vs_live_view', 'error', ['device.screen_capture_disabled', 'live_view.enabled'],
                'Screen capture is disabled on the device, so live view would show a black screen.');
        }
        if ($v['live_view.screenshots'] && !$v['live_view.enabled']) {
            $add('screenshots_without_live_view', 'error', ['live_view.screenshots'], 'Screenshots are only possible inside a live-view session.');
        }
        // (No "employee app must be in the allowlist" check: AmapiPolicyTranslator always force-installs the employee app,
        //  so that error was a false alarm that blocked every new profile with the default allowlist.)
        if ($v['apps.mode'] === 'blocklist' && in_array($employeeAppPackage, $v['apps.packages'], true)) {
            $add('app_blocklisted', 'error', ['apps.packages'], 'The employee app cannot be blocklisted.');
        }
        if ($v['kiosk.enabled'] && $v['apps.mode'] !== 'allowlist') {
            $add('kiosk_requires_allowlist', 'error', ['kiosk.enabled', 'apps.mode'], 'Kiosk mode requires an application allowlist.');
        }
        if ($v['recording.mode'] === 'mandatory' && !$v['calling.enabled']) {
            $add('recording_without_calling', 'warning', ['recording.mode'], 'Recording is mandatory, but calling is disabled.');
        }
        if ($v['calling.enabled'] && ($v['calling.hours']['days'] === [] || $v['calling.hours']['start'] === $v['calling.hours']['end'])) {
            $add('empty_calling_hours', 'error', ['calling.hours'], 'Calling is enabled, but the calling window is empty.');
        }
        if ($v['calling.enabled'] && $v['calling.allowed_prefixes'] === []) {
            $add('no_allowed_prefixes', 'error', ['calling.allowed_prefixes'], 'Calling is enabled, but no destination prefixes are allowed.');
        }
        if ($v['location.background'] && !$v['location.enabled']) {
            $add('background_location_without_location', 'error', ['location.background'], 'Background location needs work-session location enabled.');
        }
        if ($v['location.enabled'] && $v['device.location_mode'] === 'user_choice') {
            $add('location_user_can_disable', 'warning', ['device.location_mode'], 'Employees can switch off location services, so fixes may be missing.');
        }
        return $out;
    }

    public static function hasErrors(array $conflicts): bool
    {
        foreach ($conflicts as $c) {
            if ($c['severity'] === 'error') {
                return true;
            }
        }
        return false;
    }
}
