package ametecs.managedmobile.core

/** Never act on a stale screen/camera request (e.g. received after reconnecting). Mirrors the server's 90 s window. */
object MediaRequestGate {
    const val CONSENT_WINDOW_MS = 90_000L
    const val CLOCK_SKEW_MS = 30_000L   // tolerate a phone clock slightly behind the server

    fun canPrompt(state: String, requestedAtEpochMs: Long, nowEpochMs: Long): Boolean =
        state == "requested" && nowEpochMs - requestedAtEpochMs in -CLOCK_SKEW_MS..CONSENT_WINDOW_MS
}

/** Push payloads may only select one of these internal screens; anything else opens Home. */
enum class Destination { HOME, APPROVAL, POLICY, CUSTOMERS, CHAT, MEDIA_REQUEST, LOCKED }

object PushRouting {
    fun destinationFor(reason: String?): Destination = when (reason) {
        "approval" -> Destination.APPROVAL
        "revoked", "reassigned" -> Destination.LOCKED
        "policy", "command" -> Destination.POLICY
        "customers" -> Destination.CUSTOMERS
        "chat" -> Destination.CHAT
        "media" -> Destination.MEDIA_REQUEST
        else -> Destination.HOME
    }
}
