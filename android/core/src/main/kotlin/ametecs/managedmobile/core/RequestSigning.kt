package ametecs.managedmobile.core

import java.security.MessageDigest

/**
 * Canonical string signed with the Android Keystore EC P-256 key (SHA256withECDSA, DER, base64).
 * Must match backend App\Domain\Security\RequestSignature::canonical exactly.
 */
object RequestSigning {
    fun canonical(method: String, pathWithQuery: String, timestamp: Long, nonce: String, body: ByteArray): String =
        method.uppercase() + "\n" + pathWithQuery + "\n" + timestamp + "\n" + nonce + "\n" + sha256Hex(body)

    fun sha256Hex(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { String.format("%02x", it.toInt() and 0xff) }

    private val NONCE_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_"

    fun nonce(random: java.security.SecureRandom = java.security.SecureRandom()): String {
        val sb = StringBuilder()
        for (i in 0 until 24) sb.append(NONCE_CHARS[random.nextInt(NONCE_CHARS.length)])
        return sb.toString()
    }
}
