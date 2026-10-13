package ametecs.managedmobile.core

/**
 * Offline authorisation lease issued by the server (X-Offline-Lease-Expires). Protected work functions are usable
 * offline only before expiry; device restrictions are unaffected by expiry.
 */
class OfflineLease(val expiresAtEpochMs: Long) {
    fun isValid(nowEpochMs: Long): Boolean = nowEpochMs < expiresAtEpochMs

    fun remainingMinutes(nowEpochMs: Long): Long = Math.max(0, (expiresAtEpochMs - nowEpochMs) / 60_000)

    companion object {
        val NONE = OfflineLease(0)
    }
}
