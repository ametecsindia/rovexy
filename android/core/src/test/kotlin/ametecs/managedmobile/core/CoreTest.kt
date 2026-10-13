package ametecs.managedmobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.security.KeyPairGenerator
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import kotlin.random.Random

class CoreTest {
    @Test
    fun canonicalMatchesServerFormat() {
        val c = RequestSigning.canonical("post", "/api/mobile/v1/device/session", 1800000000L, "nonce-abcdefghijklmnop", "{\"x\":1}".toByteArray())
        assertEquals("POST\n/api/mobile/v1/device/session\n1800000000\nnonce-abcdefghijklmnop\n" + RequestSigning.sha256Hex("{\"x\":1}".toByteArray()), c)
        assertEquals("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", RequestSigning.sha256Hex(ByteArray(0)))
        assertTrue(RequestSigning.nonce().matches(Regex("^[A-Za-z0-9_-]{24}$")))
    }

    @Test
    fun ecdsaSignatureVerifies() {
        val kpg = KeyPairGenerator.getInstance("EC")
        kpg.initialize(ECGenParameterSpec("secp256r1"))
        val kp = kpg.generateKeyPair()
        val msg = RequestSigning.canonical("GET", "/p", 1L, "n", ByteArray(0)).toByteArray()
        val s = Signature.getInstance("SHA256withECDSA")
        s.initSign(kp.private)
        s.update(msg)
        val sig = s.sign()
        val v = Signature.getInstance("SHA256withECDSA")
        v.initVerify(kp.public)
        v.update(msg)
        assertTrue(v.verify(sig))
    }

    @Test
    fun backoffBounds() {
        for (attempt in 0..20) {
            val d = Backoff.pollDelaySeconds(null, attempt, Random(attempt))
            assertTrue("$d", d in 4..72)
        }
        assertTrue(Backoff.pollDelaySeconds(60, 0, Random(1)) >= 48)
        assertTrue(Backoff.reconnectDelayMillis(50, Random(2)) <= 301_000L)
    }

    @Test
    fun serverUrlRules() {
        assertEquals("https://mdm.acme.in", ServerUrl.normalise(" mdm.ACME.in/admin "))
        assertEquals("https://mdm.acme.in:8443", ServerUrl.normalise("https://mdm.acme.in:8443"))
        for (bad in listOf("http://mdm.acme.in", "https://u:p@mdm.acme.in", "https://mdm.acme.in/?device=1", "https://mdm.acme.in/#x")) {
            try {
                ServerUrl.normalise(bad)
                throw AssertionError("accepted $bad")
            } catch (e: IllegalArgumentException) { }
        }
        assertEquals("http://10.0.2.2:8000", ServerUrl.normalise("http://10.0.2.2:8000", true))
        assertEquals("http://192.168.0.100:8000", ServerUrl.normalise("http://192.168.0.100:8000", true))
        assertTrue(runCatching { ServerUrl.normalise("http://192.168.0.100:8000") }.isFailure) // release: https only
        for (bad in listOf("http://8.8.8.8", "http://192.168.example.com", "http://172.32.0.1")) {
            assertTrue(bad, runCatching { ServerUrl.normalise(bad, true) }.isFailure)
        }
    }

    @Test
    fun leaseExpiry() {
        val l = OfflineLease(1_000_000L)
        assertTrue(l.isValid(999_999L))
        assertFalse(l.isValid(1_000_000L))
        assertFalse(OfflineLease.NONE.isValid(1L))
    }

    @Test
    fun queueIsIdempotentAndKeepsRetryables() {
        val q = EventQueue()
        val a = q.enqueue("activity", "2026-10-01T06:00:00Z", "{}")
        val b = q.enqueue("note", "2026-10-01T06:00:01Z", "{}")
        val c = q.enqueue("location", "2026-10-01T06:00:02Z", "{}")
        val rejected = q.applyResults(listOf(SyncResult(a.id, "accepted"), SyncResult(b.id, "duplicate"), SyncResult(c.id, "error")))
        assertTrue(rejected.isEmpty())
        assertEquals(1, q.size())
        assertEquals(1, q.snapshot()[0].attempts)
        q.applyResults(listOf(SyncResult(c.id, "rejected", "event_after_lease_expiry")))
        assertEquals(0, q.size())
        val same = EventQueue(listOf(QueuedEvent("x", "note", "t", "{}"), QueuedEvent("x", "note", "t", "{}")))
        assertEquals(1, same.size())
    }

    @Test
    fun withdrawnCustomersRemoved() {
        fun c(id: Long) = CachedCustomer(id, "C$id", "n", "+91", null, "new", "t")
        val out = CustomerCache.reconcile(mapOf(1L to c(1), 2L to c(2)), listOf(c(3)), setOf(1L, 3L))
        assertEquals(setOf(1L, 3L), out.keys)
    }

    @Test
    fun policyEvaluationIsHonest() {
        val controls = mapOf("device.camera" to "device", "device.usb_data" to "device", "apps.packages" to "device", "calling.enabled" to "app")
        val values = mapOf<String, Any?>("device.camera" to "blocked", "device.usb_data" to "no_file_transfer", "apps.packages" to listOf("x"), "calling.enabled" to true)
        val unmanaged = PolicyEvaluation.evaluate(values, controls, DeviceObservations(false, 34, true, null, emptySet(), true, null))
        assertEquals("requires_setup", unmanaged["device.camera"]!!.status)
        assertTrue(unmanaged["calling.enabled"]!!.verified)
        val managed = PolicyEvaluation.evaluate(values, controls, DeviceObservations(true, 34, false, null, setOf("no_usb_file_transfer"), true, null))
        assertEquals("failed", managed["device.camera"]!!.status)
        assertTrue(managed["device.usb_data"]!!.verified)
        assertFalse(managed["apps.packages"]!!.verified)
    }

    @Test
    fun staleMediaRequestsAndPushRouting() {
        assertTrue(MediaRequestGate.canPrompt("requested", 1_000L, 50_000L))
        assertFalse(MediaRequestGate.canPrompt("requested", 1_000L, 200_000L))
        assertFalse(MediaRequestGate.canPrompt("expired", 1_000L, 2_000L))
        assertTrue(MediaRequestGate.canPrompt("requested", 20_000L, 1_000L))      // phone clock 19 s behind
        assertFalse(MediaRequestGate.canPrompt("requested", 100_000L, 1_000L))
        assertEquals(Destination.HOME, PushRouting.destinationFor("https://evil.example"))
        assertEquals(Destination.LOCKED, PushRouting.destinationFor("revoked"))
    }
}
