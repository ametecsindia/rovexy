package ametecs.managedmobile.core

import kotlin.random.Random

/** Approval polling: honour the server hint, grow with attempts, clamp 5–60 s, add ±20 % jitter. */
object Backoff {
    fun pollDelaySeconds(serverHint: Int?, attempt: Int, random: Random = Random.Default): Long {
        val base = Math.max(serverHint ?: 5, Math.min(60, 5 * (1 shl Math.min(attempt, 4))))
        val clamped = Math.max(5, Math.min(60, base))
        val jitter = 0.8 + random.nextDouble() * 0.4
        return Math.round(clamped * jitter)
    }

    /** Reconnect delay for WebSocket/sync failures: exponential to 5 min with full jitter. */
    fun reconnectDelayMillis(attempt: Int, random: Random = Random.Default): Long {
        val cap = Math.min(300_000L, 1000L * (1L shl Math.min(attempt, 8)))
        return 1000L + (random.nextDouble() * cap).toLong()
    }
}
