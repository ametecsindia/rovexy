package ametecs.managedmobile.data

import ametecs.managedmobile.core.OfflineLease
import ametecs.managedmobile.security.DeviceKeys
import ametecs.managedmobile.security.SecureStore
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.time.OffsetDateTime

/**
 * Holds device/work tokens (encrypted at rest), refreshes them, and turns authorisation failures into app state.
 * The device token is obtained only by proving possession of the Keystore key; refresh also requires a signature.
 */
class SessionManager(private val api: ApiClient, private val store: SecureStore, private val keys: DeviceKeys) {
    private val mutex = Mutex()
    var onRevoked: (() -> Unit)? = null
    var onSignedOut: (() -> Unit)? = null

    val hasWorkSession get() = store.getString("refresh_token") != null

    val lease: OfflineLease
        get() = store.getString("lease")?.let { runCatching { OfflineLease(OffsetDateTime.parse(it).toInstant().toEpochMilli()) }.getOrNull() } ?: OfflineLease.NONE

    suspend fun deviceToken(force: Boolean = false): String = mutex.withLock {
        val cached = store.getString("device_token")
        val exp = store.getString("device_token_exp")?.toLongOrNull() ?: 0
        if (!force && cached != null && System.currentTimeMillis() < exp - 30_000) return cached
        val r = guard { api.call("POST", "/api/mobile/v1/device/session", null, ApiClient.Auth.SIGNED) }.obj()
        val t = r.str("device_token")!!
        store.putString("device_token", t)
        store.putString("device_token_exp", (System.currentTimeMillis() + (r.str("expires_in")!!.toLong() * 1000)).toString())
        return t
    }

    suspend fun device(method: String, path: String, body: JsonObject? = null) = try {
        guard { api.call(method, path, body, ApiClient.Auth.DEVICE, deviceToken()) }
    } catch (e: ApiException) {
        if (e.status == 401) guard { api.call(method, path, body, ApiClient.Auth.DEVICE, deviceToken(force = true)) } else throw e
    }

    /** [fix] = where the phone is now; the server checks it against the SmartPRS geofence (sign-in may be refused outside it). */
    suspend fun login(code: String, password: String, fix: android.location.Location? = null) {
        val r = device("POST", "/api/mobile/v1/auth/login", buildJsonObject {
            put("employee_code", code); put("password", password)
            fix?.let { put("lat", it.latitude); put("lng", it.longitude); if (it.hasAccuracy()) put("accuracy_m", it.accuracy) }
        }).obj()
        saveWork(r)
    }

    suspend fun work(method: String, path: String, body: JsonObject? = null): kotlinx.serialization.json.JsonElement {
        val used = store.getString("access_token")
        return try {
            guard { api.call(method, path, body, ApiClient.Auth.WORK, used) }.also { captureLease() }
        } catch (e: ApiException) {
            if (e.status == 401 && refresh(used)) guard { api.call(method, path, body, ApiClient.Auth.WORK, store.getString("access_token")) }.also { captureLease() }
            else throw e
        }
    }

    suspend fun workUpload(path: String, parts: () -> okhttp3.MultipartBody): kotlinx.serialization.json.JsonElement {
        val used = store.getString("access_token") ?: throw ApiException(401, "signed_out", "Signed out")
        return try {
            guard { api.multipart(path, parts(), used) }
        } catch (e: ApiException) {
            if (e.status == 401 && refresh(used)) guard { api.multipart(path, parts(), store.getString("access_token")!!) } else throw e
        }
    }

    fun accessToken(): String? = store.getString("access_token")

    /** Only one refresh at a time; if another caller already rotated the token we just reuse it. */
    private suspend fun refresh(failedAccess: String?): Boolean = mutex.withLock {
        if (store.getString("access_token") != failedAccess && store.getString("access_token") != null) return true
        val rt = store.getString("refresh_token") ?: return false
        return try {
            saveWork(api.call("POST", "/api/mobile/v1/auth/refresh", buildJsonObject { put("refresh_token", rt) }, ApiClient.Auth.SIGNED).obj())
            true
        } catch (e: ApiException) {
            if (e.status in 401..403) { clearWork(); onSignedOut?.invoke() }
            false
        }
    }

    suspend fun logout() {
        runCatching { work("POST", "/api/mobile/v1/auth/logout") }
        clearWork()
    }

    private fun saveWork(r: JsonObject) {
        store.putString("access_token", r.str("access_token"))
        store.putString("refresh_token", r.str("refresh_token"))
        r.str("offline_lease_expires_at")?.let { store.putString("lease", it) }
    }

    private fun captureLease() { api.lastLeaseHeader?.let { store.putString("lease", it) } }

    fun clearWork() {
        store.putString("access_token", null)
        store.putString("refresh_token", null)
        store.putString("lease", null)
    }

    /** Revocation/rejection: drop every credential and the key itself. Device restrictions are NOT touched. */
    fun wipeIdentity() {
        clearWork()
        store.putString("device_token", null)
        keys.reset()
    }

    private suspend fun <T> guard(block: suspend () -> T): T = try {
        block()
    } catch (e: ApiException) {
        if (e.code == "device_revoked" || e.code == "device_not_approved") onRevoked?.invoke()
        throw e
    }

}
