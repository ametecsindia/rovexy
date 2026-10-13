package ametecs.managedmobile.core

class ControlResult(val status: String, val verified: Boolean, val detail: String? = null)

/** What the app can observe about device-level enforcement (read via public Android APIs). */
class DeviceObservations(
    val managedByMdm: Boolean,
    val sdkInt: Int,
    val cameraDisabled: Boolean?,
    val screenCaptureDisabled: Boolean?,
    val userRestrictions: Set<String>,
    val locationEnabled: Boolean?,
    val backgroundLocationGranted: Boolean?
)

/**
 * Produces the per-control report sent to /policy/ack. Rules:
 *  - App-level controls are enforced by this app itself → applied + verified.
 *  - Device-level controls are only "applied+verified" when the observation proves it.
 *  - Unobservable device-level controls are reported "applied" but NOT verified; the server relies on the Android
 *    Management compliance report for those. Without MDM, device-level controls are "requires_setup".
 */
object PolicyEvaluation {
    private val RESTRICTION_FOR = mapOf(
        "device.adjust_volume_disabled" to "no_adjust_volume",
        "device.set_wallpaper_disabled" to "no_set_wallpaper",
        "device.external_storage_disabled" to "no_physical_media",
        "device.bluetooth_config_disabled" to "no_config_bluetooth",
        "apps.unknown_sources_allowed" to "no_install_unknown_sources"
    )

    fun evaluate(values: Map<String, Any?>, controls: Map<String, String>, obs: DeviceObservations): Map<String, ControlResult> {
        val out = LinkedHashMap<String, ControlResult>()
        for ((key, scope) in controls) {
            val v = values[key]
            out[key] = if (scope == "app") ControlResult("applied", true)
            else if (!obs.managedByMdm) ControlResult("requires_setup", false, "Device is not enrolled in Android Management")
            else deviceControl(key, v, obs)
        }
        return out
    }

    private fun deviceControl(key: String, v: Any?, obs: DeviceObservations): ControlResult {
        when (key) {
            "device.camera" -> {
                val want = v == "blocked"
                return verify(obs.cameraDisabled, want)
            }
            "device.screen_capture_disabled" -> return verify(obs.screenCaptureDisabled, v == true)
            "device.location_mode" -> return if (v == "enforced") verify(obs.locationEnabled, true) else ControlResult("applied", true)
            "device.usb_data" -> {
                if (v == "allowed") return ControlResult("applied", !obs.userRestrictions.contains("no_usb_file_transfer"))
                return verify(obs.userRestrictions.contains("no_usb_file_transfer"), true)
            }
        }
        val restriction = RESTRICTION_FOR[key]
        if (restriction != null) {
            val wantRestricted = if (key == "apps.unknown_sources_allowed") v != true else v == true
            return verify(obs.userRestrictions.contains(restriction), wantRestricted)
        }
        return ControlResult("applied", false, "Not observable on device; verified by Android Management report")
    }

    private fun verify(observed: Boolean?, wanted: Boolean): ControlResult =
        if (observed == null) ControlResult("applied", false, "Not observable on this Android version")
        else if (observed == wanted) ControlResult("applied", true)
        else ControlResult("failed", false, "Device reports a different setting")
}
