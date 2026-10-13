package ametecs.managedmobile.policy

import android.Manifest
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.pm.PackageManager
import android.content.RestrictionsManager
import android.location.LocationManager
import android.os.Build
import android.os.UserManager
import ametecs.managedmobile.core.DeviceObservations
import ametecs.managedmobile.core.PolicyEvaluation
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put

/**
 * Applies app-level controls (they are enforced by reading [values] throughout the app) and observes device-level
 * controls through PUBLIC Android APIs only. Device-level enforcement itself is done by Android Device Policy (AMAPI);
 * this class never tries to bypass or simulate it.
 */
class PolicyEnforcer(private val context: Context) {

    @Volatile var values: Map<String, Any?> = emptyMap()
        private set

    fun load(policy: JsonObject) {
        values = policy["values"]!!.jsonObject.mapValues { (_, v) -> unwrap(v) }
    }

    fun observations(): DeviceObservations {
        val dpm = context.getSystemService(DevicePolicyManager::class.java)
        val um = context.getSystemService(UserManager::class.java)
        val restrictions = mutableSetOf<String>()
        val r = um.userRestrictions
        for (k in listOf(UserManager.DISALLOW_USB_FILE_TRANSFER, UserManager.DISALLOW_MOUNT_PHYSICAL_MEDIA, UserManager.DISALLOW_INSTALL_UNKNOWN_SOURCES,
            UserManager.DISALLOW_ADJUST_VOLUME, UserManager.DISALLOW_SET_WALLPAPER, UserManager.DISALLOW_CONFIG_BLUETOOTH)) {
            if (r.getBoolean(k, false)) restrictions += k
        }
        val lm = context.getSystemService(LocationManager::class.java)
        val managed = !(context.getSystemService(Context.RESTRICTIONS_SERVICE) as RestrictionsManager).applicationRestrictions.isEmpty
        return DeviceObservations(
            managedByMdm = managed,
            sdkInt = Build.VERSION.SDK_INT,
            cameraDisabled = runCatching { dpm.getCameraDisabled(null) }.getOrNull(),
            screenCaptureDisabled = runCatching { dpm.getScreenCaptureDisabled(null) }.getOrNull(),
            userRestrictions = restrictions,
            locationEnabled = if (Build.VERSION.SDK_INT >= 28) lm.isLocationEnabled else null,
            backgroundLocationGranted = context.checkSelfPermission(if (Build.VERSION.SDK_INT >= 29) Manifest.permission.ACCESS_BACKGROUND_LOCATION else Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED,
        )
    }

    /** Builds the /policy/ack payload. */
    fun acknowledgement(policy: JsonObject): JsonObject {
        val controls = policy["controls"]!!.jsonObject.mapValues { (_, v) -> v.jsonObject["scope"]!!.jsonPrimitive.content }
        val results = PolicyEvaluation.evaluate(values, controls, observations())
        return buildJsonObject {
            put("fingerprint", policy["fingerprint"]!!.jsonPrimitive.content)
            put("results", JsonObject(results.mapValues { (_, r) ->
                buildJsonObject { put("status", r.status); put("verified", r.verified); r.detail?.let { put("detail", it) } }
            }))
        }
    }

    fun bool(key: String) = values[key] as? Boolean ?: false
    fun int(key: String, default: Int) = (values[key] as? Number)?.toInt() ?: default
    fun str(key: String) = values[key] as? String
    @Suppress("UNCHECKED_CAST") fun list(key: String) = values[key] as? List<String> ?: emptyList()

    private fun unwrap(e: JsonElement): Any? = when (e) {
        is JsonNull -> null
        is JsonPrimitive -> e.booleanOrNull ?: e.intOrNull ?: e.content
        is JsonArray -> e.map { unwrap(it) }
        is JsonObject -> e.mapValues { unwrap(it.value) }
    }
}
