package ametecs.managedmobile.core

import java.security.KeyPairGenerator
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.util.Base64

/** Emits a signed request fixture exactly as the Android app would send it; verified by tools/interop-check.sh in PHP. */
object InteropFixture {
    @JvmStatic
    fun main(args: Array<String>) {
        val kpg = KeyPairGenerator.getInstance("EC")
        kpg.initialize(ECGenParameterSpec("secp256r1"))
        val kp = kpg.generateKeyPair()
        val body = "{\"refresh_token\":\"wsr_x\"}".toByteArray()
        val ts = System.currentTimeMillis() / 1000
        val nonce = RequestSigning.nonce()
        val s = Signature.getInstance("SHA256withECDSA")
        s.initSign(kp.private)
        s.update(RequestSigning.canonical("POST", "/api/mobile/v1/auth/refresh?x=1", ts, nonce, body).toByteArray())
        val b64 = Base64.getEncoder()
        println("{\"public_key\":\"" + b64.encodeToString(kp.public.encoded) + "\",\"method\":\"POST\",\"path\":\"/api/mobile/v1/auth/refresh?x=1\",\"ts\":\"" + ts +
            "\",\"nonce\":\"" + nonce + "\",\"body\":\"" + b64.encodeToString(body) + "\",\"signature\":\"" + b64.encodeToString(s.sign()) + "\"}")
    }
}
