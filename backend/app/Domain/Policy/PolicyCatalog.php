<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\DomainException;

/** The single source of truth for supported policy keys, their types, defaults and enforcement scope. */
final class PolicyCatalog
{
    /** @var array<string,Control>|null */
    private static ?array $controls = null;

    /** @return array<string,Control> */
    public static function all(): array
    {
        if (self::$controls !== null) {
            return self::$controls;
        }
        $c = [
            // ── Device-level (management layer: AMAPI) ───────────────────────────────
            new Control('apps.mode', 'enum', 'allowlist', 'device', 'Application control mode', ['allowlist', 'blocklist']),
            new Control('apps.packages', 'list', [], 'device', 'Allowed (or blocked) application packages'),
            new Control('apps.unknown_sources_allowed', 'bool', false, 'device', 'Allow installing apps from unknown sources'),
            new Control('kiosk.enabled', 'bool', false, 'device', 'Kiosk / approved launcher'),
            new Control('kiosk.settings_access', 'enum', 'blocked', 'device', 'Access to system settings in kiosk', ['allowed', 'blocked']),
            new Control('device.camera', 'enum', 'allowed', 'device', 'Device camera', ['allowed', 'blocked']),
            new Control('device.microphone', 'enum', 'allowed', 'device', 'Device microphone', ['allowed', 'blocked'], 31),
            new Control('device.adjust_volume_disabled', 'bool', false, 'device', 'Block volume changes'),
            new Control('device.set_wallpaper_disabled', 'bool', false, 'device', 'Block wallpaper changes'),
            new Control('device.screen_capture_disabled', 'bool', false, 'device', 'Block screenshots / screen capture'),
            new Control('device.usb_data', 'enum', 'no_file_transfer', 'device', 'USB data transfer', ['allowed', 'no_file_transfer', 'no_data'], 31,
                setupNote: 'Full USB data blocking needs Android 12+ and USB HAL support on the specific model.'),
            new Control('device.external_storage_disabled', 'bool', true, 'device', 'Block SD card / external media'),
            new Control('device.bluetooth_config_disabled', 'bool', false, 'device', 'Block Bluetooth configuration'),
            new Control('device.location_mode', 'enum', 'enforced', 'device', 'Location services', ['user_choice', 'enforced'], 28),

            // ── App-level (employee app) ─────────────────────────────────────────────
            new Control('app.speaker_allowed', 'bool', true, 'app', 'In-app speaker'),
            new Control('app.mute_allowed', 'bool', true, 'app', 'In-app mute'),
            new Control('attachments.allowed_types', 'list', ['image/jpeg', 'image/png', 'application/pdf'], 'app', 'Chat attachment MIME types'),
            new Control('attachments.max_mb', 'int', 10, 'app', 'Maximum attachment size (MB)', min: 1, max: 50),
            new Control('sharing.destinations', 'list', ['chat'], 'app', 'Approved in-app sharing destinations'),
            new Control('calling.enabled', 'bool', true, 'app', 'Customer calling'),
            new Control('calling.hours', 'hours', ['days' => [1, 2, 3, 4, 5, 6], 'start' => '09:00', 'end' => '19:00'], 'app', 'Calling hours (company timezone)'),
            new Control('calling.allowed_prefixes', 'list', ['+91'], 'app', 'Allowed destination prefixes (E.164)'),
            new Control('calling.manual_dial', 'bool', false, 'app', 'Manual dialling of unassigned numbers'),
            new Control('calling.agent_leg', 'enum', 'pstn_callback', 'app', 'Agent leg', ['pstn_callback', 'sip_endpoint']),
            new Control('recording.mode', 'enum', 'mandatory', 'app', 'Call recording', ['off', 'optional', 'mandatory']),
            new Control('recording.on_failure', 'enum', 'continue_flagged', 'app', 'If mandatory recording fails', ['continue_flagged', 'terminate']),
            new Control('recording.notice', 'string', 'This call may be recorded for quality and training purposes.', 'app', 'Recording notice text'),
            new Control('location.enabled', 'bool', true, 'app', 'Work-session location'),
            new Control('location.interval_seconds', 'int', 120, 'app', 'Location interval (s)', min: 60, max: 3600),
            new Control('location.background', 'bool', false, 'app', 'Collect while app in background (work session only)', minSdk: 29,
                setupNote: 'Needs ACCESS_BACKGROUND_LOCATION granted via policy and a foreground service while the work session is active.'),
            new Control('live_view.enabled', 'bool', false, 'app', 'Live screen viewing'),
            new Control('live_view.screenshots', 'bool', false, 'app', 'Screenshots within a live session'),
            new Control('live_view.quality', 'enum', 'medium', 'app', 'Stream quality', ['low', 'medium', 'high']),
            new Control('live_view.fps', 'int', 5, 'app', 'Frames per second', min: 1, max: 15),
            new Control('live_view.timeout_minutes', 'int', 10, 'app', 'Session timeout (min)', min: 1, max: 60),
            new Control('live_view.max_concurrent', 'int', 3, 'app', 'Concurrent sessions per company', min: 1, max: 50),
            new Control('camera_session.enabled', 'bool', false, 'app', 'Visible camera sessions'),
            new Control('camera_session.timeout_minutes', 'int', 5, 'app', 'Camera session timeout (min)', min: 1, max: 30),
            new Control('offline.access_minutes', 'int', 240, 'app', 'Offline access lease (min)', min: 0, max: 4320),
            new Control('revocation.active_call', 'enum', 'terminate', 'app', 'Active call on revocation', ['terminate', 'allow_finish']),
            new Control('retention.recording_days', 'int', 180, 'app', 'Recording retention (days)', min: 1, max: 3650),
            new Control('retention.chat_days', 'int', 365, 'app', 'Chat retention (days)', min: 1, max: 3650),
            new Control('retention.location_days', 'int', 90, 'app', 'Location retention (days)', min: 1, max: 3650),
            new Control('compliance.mandatory', 'list', ['calling.enabled', 'recording.mode'], 'app', 'Controls required for compliance'),
            // ponytail: app-level default because phones run app-only (no Android Management). Fully managed fleets add
            // apps.mode, apps.packages, device.usb_data, device.external_storage_disabled in Policy profiles.
        ];
        self::$controls = [];
        foreach ($c as $control) {
            self::$controls[$control->key] = $control;
        }
        return self::$controls;
    }

    /**
     * "ROVEXY Standard" — the recommended starting policy: everything a calling / field team uses, nothing extra.
     * Only app-level controls; device-level ones (Android Management) stay at catalogue defaults and are ignored on
     * app-only phones anyway. Live view and camera still ask the employee every time.
     *
     * @return array<string,mixed>
     */
    public static function standard(): array
    {
        return [
            'calling.enabled' => true,
            'recording.mode' => 'optional',          // the phone's own recorder; phones without one are not blocked
            'recording.on_failure' => 'continue_flagged',
            'location.enabled' => true,
            'location.interval_seconds' => 120,     // G11: every 2 minutes (was 5); companies' saved policies keep their own value
            'live_view.enabled' => true,
            'live_view.screenshots' => true,
            'live_view.fps' => 10,                   // what the app actually sends
            'live_view.timeout_minutes' => 15,
            'camera_session.enabled' => true,
            'camera_session.timeout_minutes' => 10,
            'compliance.mandatory' => ['calling.enabled'],
        ];
    }

    public static function get(string $key): Control
    {
        return self::all()[$key] ?? throw new DomainException('unknown_policy_key', "Unknown policy key {$key}.");
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return array_map(static fn (Control $c) => $c->default, self::all());
    }

    /** Validates and normalises a value; throws on invalid. */
    public static function validate(string $key, mixed $value): mixed
    {
        $c = self::get($key);
        $bad = static fn () => throw new DomainException('invalid_policy_value', "Invalid value for {$key}.");
        switch ($c->type) {
            case 'bool':
                is_bool($value) || $bad();
                return $value;
            case 'int':
                (is_int($value) && ($c->min === null || $value >= $c->min) && ($c->max === null || $value <= $c->max)) || $bad();
                return $value;
            case 'string':
                (is_string($value) && strlen($value) <= 1000) || $bad();
                return $value;
            case 'enum':
                in_array($value, $c->enum ?? [], true) || $bad();
                return $value;
            case 'list':
                (is_array($value) && array_is_list($value) && count($value) <= 500) || $bad();
                foreach ($value as $v) {
                    (is_string($v) && strlen($v) <= 255) || $bad();
                }
                if ($key === 'compliance.mandatory') {
                    foreach ($value as $v) {
                        isset(self::all()[$v]) || $bad();
                    }
                }
                if ($key === 'apps.packages') {
                    foreach ($value as $v) {
                        preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $v) || $bad();
                    }
                }
                return array_values(array_unique($value));
            case 'hours':
                if (!is_array($value) || !isset($value['days'], $value['start'], $value['end']) || !is_array($value['days'])) {
                    $bad();
                }
                foreach ($value['days'] as $d) {
                    (is_int($d) && $d >= 1 && $d <= 7) || $bad();
                }
                foreach (['start', 'end'] as $k) {
                    (is_string($value[$k]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value[$k])) || $bad();
                }
                return ['days' => array_values(array_unique($value['days'])), 'start' => $value['start'], 'end' => $value['end']];
        }
        return $bad();
    }
}
