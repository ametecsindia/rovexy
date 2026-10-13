package ametecs.managedmobile.security

import android.content.pm.PackageManager
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.security.keystore.StrongBoxUnavailableException
import android.util.Base64
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.util.UUID

/**
 * Device identity for THIS installation: random installation ID + non-exportable EC P-256 Keystore key.
 * Not a hardware identity: reinstalling creates a new identity that must be approved again.
 */
class DeviceKeys(private val store: SecureStore, private val pm: PackageManager) {

    val installationId: String
        get() = store.getString(ID_KEY) ?: UUID.randomUUID().toString().also { store.putString(ID_KEY, it) }

    private fun ks() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }

    fun ensureKey() {
        if (ks().containsAlias(ALIAS)) return
        val strongBox = Build.VERSION.SDK_INT >= 28 && pm.hasSystemFeature(PackageManager.FEATURE_STRONGBOX_KEYSTORE)
        try {
            generate(strongBox)
        } catch (e: StrongBoxUnavailableException) {
            generate(false)
        }
    }

    private fun generate(strongBox: Boolean) {
        val kpg = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore")
        val spec = KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
        if (strongBox) spec.setIsStrongBoxBacked(true)
        kpg.initialize(spec.build())
        kpg.generateKeyPair()
    }

    /** X.509 SubjectPublicKeyInfo DER, base64 — the format the server's DevicePublicKey expects. */
    fun publicKeyB64(): String {
        ensureKey()
        return Base64.encodeToString(ks().getCertificate(ALIAS).publicKey.encoded, Base64.NO_WRAP)
    }

    fun sign(data: ByteArray): String {
        ensureKey()
        val s = Signature.getInstance("SHA256withECDSA")
        s.initSign(ks().getKey(ALIAS, null) as PrivateKey)
        s.update(data)
        return Base64.encodeToString(s.sign(), Base64.NO_WRAP)
    }

    /** After revocation/rejection: discard identity so a fresh registration (new key) is required. */
    fun reset() {
        ks().deleteEntry(ALIAS)
        store.putString(ID_KEY, null)
    }

    companion object {
        private const val ALIAS = "ammp_device_key"
        private const val ID_KEY = "installation_id"
    }
}
