# Capability matrix

There are two views here. The **design matrix** says what each control relies on and when it can work. The **physical-device matrix** is filled in from real devices using `docs/TEST_PLAN.md` §3. None of its rows has been validated yet, because no device was available.

## 1. Design matrix

The **Enforced by** column uses these terms:

- **AMAPI** means Android Device Policy, the device-policy controller that Google installs on Android Management (AMAPI) enrolled devices.
- **App** means the employee app.
- **Server** means a backend check that the client cannot bypass.

The **Verification** column says what proves a control is in force:

- **Observed**: the app reads it back through a public Android API.
- **AMAPI report**: Google's `policyCompliant` / `nonComplianceDetails` for the current policy.

| Control (policy key) | Enforced by | Min Android | Verification | Without AMAPI | Notes |
|---|---|---|---|---|---|
| App allowlist/blocklist (`apps.mode`, `apps.packages`) | AMAPI | 9 (minSdk) | AMAPI report | Requires setup | The employee app must stay allowlisted. The conflict check enforces this. |
| Unknown-source installs (`apps.unknown_sources_allowed`) | AMAPI (`untrustedAppsPolicy`) | 9 | Observed (`no_install_unknown_sources`) | Requires setup | |
| Kiosk / approved launcher (`kiosk.enabled`, `kiosk.settings_access`) | AMAPI (`KIOSK` install type, `kioskCustomization`) | 9 | AMAPI report | Requires setup | Emergency calls stay available from the lock screen. Validate per OEM. |
| Camera blocked (`device.camera`) | AMAPI (`cameraAccess`) | 9 | Observed (`getCameraDisabled`) | Requires setup | Conflicts with camera sessions. Publication is blocked if both are set. |
| Microphone blocked (`device.microphone`) | AMAPI (`microphoneAccess`) | 12 | AMAPI report | Requires setup | Conflicts with in-app VoIP. May affect the cellular callback on some OEMs (shown as a warning). |
| Volume changes (`device.adjust_volume_disabled`) | AMAPI | 9 | Observed (`no_adjust_volume`) | Requires setup | |
| Wallpaper (`device.set_wallpaper_disabled`) | AMAPI | 9 | Observed (`no_set_wallpaper`) | Requires setup | |
| Screen capture blocked (`device.screen_capture_disabled`) | AMAPI | 9 | Observed (`getScreenCaptureDisabled`) | Requires setup | Conflicts with live view. Publication is blocked if both are set. |
| USB file transfer (`device.usb_data=no_file_transfer`) | AMAPI (`usbDataAccess`) | 12 | Observed (`no_usb_file_transfer`) | Requires setup | |
| USB data off (`device.usb_data=no_data`) | AMAPI | 12 + USB HAL 1.3 | AMAPI report | Requires setup | Reported as *Requires setup* unless the device reports data-signalling support. |
| External media (`device.external_storage_disabled`) | AMAPI | 9 | Observed (`no_physical_media`) | Requires setup | |
| Bluetooth config (`device.bluetooth_config_disabled`) | AMAPI | 9 | Observed (`no_config_bluetooth`) | Requires setup | |
| Location services forced on (`device.location_mode`) | AMAPI (`LOCATION_ENFORCED`) | 9 | Observed (`isLocationEnabled`) | Requires setup | |
| In-app speaker/mute, attachment types/size, sharing destinations | App | 9 | App (self) | Supported | Attachment type and size are re-checked on the server (MIME sniffing). |
| Calling enabled, hours, prefixes, manual dial | **Server** | n/a | Server | Supported | Enforced in `CallAuthoriser`. Hiding the keypad is only cosmetic. |
| Recording mode / on-failure | Server + FreeSWITCH | n/a | Server | Supported | A watchdog flags a recording that did not start, or terminates the call (policy). |
| Work-session location, foreground (`location.*`) | App (foreground service) | 9 | App | Supported | A visible notification is shown. It stops at sign-out. |
| Background work-session location (`location.background`) | App + AMAPI permission grant | 10 | Observed (permission) | Requires setup | Background location is granted only by AMAPI policy, never by tricking the user. |
| Live screen view (`live_view.*`) | App (MediaProjection) | 9 (per-session consent mandatory on 14+) | Session state | Supported | The OS consent prompt appears every time. Protected (`FLAG_SECURE`) content shows black. |
| Screenshots in a session (`live_view.screenshots`) | Console captures a frame of the consented stream | n/a | Audit | Supported | Only during an active session, and only if policy allows. |
| Camera sessions (`camera_session.*`) | App (camera FGS) | 9 | Session state | Supported | Visible preview, notification and system camera indicator (12+). |
| Offline lease (`offline.access_minutes`) | Server issues, app enforces | n/a | Server rejects late events | Supported | |
| Wipe | AMAPI `devices.delete` + wipe flags | 9 | AMAPI | Unsupported | Separate permission, typed confirmation, audited. Never part of revocation. |

## 2. Physical-device matrix (to be completed)

Status values: **Pass**, **Partial** (explain in notes), **Fail**, **N/A**, **Not tested**.

| Model | Android | Patch | Enrolment (QR, factory reset) | App allowlist | Camera block | Mic block | USB block | Screen-capture block | Location enforced | Work location (FG) | Background location | Callback call + 2-sided recording | Screen share (consent, indicator, stop) | Camera session | Revocation ≤ 60 s (WS) | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| e.g. Samsung Galaxy A-series | 14 | | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | |
| e.g. Google Pixel | 15/16 | | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | |
| e.g. Xiaomi/Redmi (MIUI/HyperOS) | 13/14 | | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Watch the OEM battery manager |
| e.g. Motorola / Realme / Vivo | | | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | Not tested | |

Choose the actual models from the phones the company buys. Production readiness may be claimed only for models with a full row of Pass or explained Partial results.
