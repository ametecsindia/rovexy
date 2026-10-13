package ametecs.managedmobile.core

import java.net.URI

/** Server URLs must be https (no credentials, query or fragment). Returns the scheme://host[:port] origin. */
object ServerUrl {
    fun normalise(input: String, allowLocalHttpForDebug: Boolean = false): String {
        val trimmed = input.trim().let { if (!it.contains("://")) "https://$it" else it }
        val uri = try { URI(trimmed) } catch (e: Exception) { throw IllegalArgumentException("Enter a valid server address") }
        val host = uri.host ?: throw IllegalArgumentException("Enter a valid server address")
        // Debug builds only: emulator loopback or a private-LAN IPv4 literal (a PC running `php artisan serve` on the same Wi-Fi).
        val local = host == "localhost" || host == "127.0.0.1" || isPrivateIpv4(host)
        if (uri.scheme != "https" && !(allowLocalHttpForDebug && local && uri.scheme == "http")) {
            throw IllegalArgumentException("The server must use https://")
        }
        if (uri.userInfo != null) throw IllegalArgumentException("Do not include credentials in the address")
        if (uri.query != null || uri.fragment != null) throw IllegalArgumentException("Remove ?… or #… from the address")
        return uri.scheme + "://" + host.lowercase() + (if (uri.port != -1) ":" + uri.port else "")
    }

    private fun isPrivateIpv4(host: String): Boolean {
        val p = host.split(".").map { it.toIntOrNull() ?: return false }
        if (p.size != 4 || p.any { it !in 0..255 }) return false
        return p[0] == 10 || (p[0] == 172 && p[1] in 16..31) || (p[0] == 192 && p[1] == 168)
    }
}
