package ametecs.managedmobile.security

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.io.File
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * AES-256-GCM with a non-exportable Android Keystore key. Used for tokens, the offline queue, the customer cache
 * and the last-applied policy. Ciphertext only ever reaches SharedPreferences / app-private files.
 */
class SecureStore(private val context: Context) {
    private val prefs = context.getSharedPreferences("ammp_secure", Context.MODE_PRIVATE)

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getEntry(ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return gen.generateKey()
    }

    fun encrypt(plain: ByteArray): ByteArray {
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.ENCRYPT_MODE, key())
        return c.iv + c.doFinal(plain)
    }

    fun decrypt(blob: ByteArray): ByteArray {
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, blob, 0, 12))
        return c.doFinal(blob, 12, blob.size - 12)
    }

    fun putString(name: String, value: String?) {
        val e = prefs.edit()
        if (value == null) e.remove(name) else e.putString(name, Base64.encodeToString(encrypt(value.toByteArray()), Base64.NO_WRAP))
        e.apply()
    }

    fun getString(name: String): String? = prefs.getString(name, null)?.let {
        runCatching { String(decrypt(Base64.decode(it, Base64.NO_WRAP))) }.getOrNull()
    }

    /** Atomic encrypted file write (temp + rename). */
    fun writeFile(name: String, content: String) {
        val dir = File(context.filesDir, "secure").apply { mkdirs() }
        val tmp = File(dir, "$name.tmp")
        tmp.writeBytes(encrypt(content.toByteArray()))
        tmp.renameTo(File(dir, name))
    }

    fun readFile(name: String): String? {
        val f = File(File(context.filesDir, "secure"), name)
        if (!f.exists()) return null
        return runCatching { String(decrypt(f.readBytes())) }.getOrNull()
    }

    fun deleteFile(name: String) {
        File(File(context.filesDir, "secure"), name).delete()
    }

    companion object { private const val ALIAS = "ammp_storage_key" }
}
