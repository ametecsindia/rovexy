package ametecs.managedmobile.data

import android.content.Context
import android.content.RestrictionsManager
import ametecs.managedmobile.BuildConfig
import ametecs.managedmobile.core.ServerUrl
import ametecs.managedmobile.security.SecureStore

/**
 * Server identity. Managed configuration pushed by Android Management (server_url, company_slug, lock_server) takes
 * precedence and, when locked, cannot be edited by the employee. Changing servers after approval needs an
 * administrative workflow (revocation + new enrolment), so the field is locked once a device session exists.
 */
class ServerConfig(private val context: Context, private val store: SecureStore) {
    private fun managed() = (context.getSystemService(Context.RESTRICTIONS_SERVICE) as RestrictionsManager).applicationRestrictions

    val isManaged: Boolean get() = !managed().getString("server_url").isNullOrBlank()

    val serverOrigin: String?
        get() = managed().getString("server_url")?.takeIf { it.isNotBlank() }?.let { runCatching { ServerUrl.normalise(it) }.getOrNull() }
            ?: store.getString("server_origin")
            ?: BuildConfig.DEFAULT_SERVER_URL.takeIf { it.isNotBlank() }

    val workspace: String?
        get() = managed().getString("company_slug")?.takeIf { it.isNotBlank() } ?: store.getString("workspace")

    fun isLocked(hasApprovedIdentity: Boolean): Boolean = hasApprovedIdentity || (isManaged && managed().getBoolean("lock_server", true))

    fun save(origin: String, workspace: String?) {
        store.putString("server_origin", ServerUrl.normalise(origin, BuildConfig.ALLOW_LOCAL_HTTP))
        store.putString("workspace", workspace?.trim()?.lowercase()?.takeIf { it.isNotEmpty() })
    }
}
