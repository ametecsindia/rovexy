package ametecs.managedmobile.data

import ametecs.managedmobile.core.CachedCustomer
import ametecs.managedmobile.core.CustomerCache
import ametecs.managedmobile.core.EventQueue
import ametecs.managedmobile.core.QueuedEvent
import ametecs.managedmobile.core.SyncResult
import ametecs.managedmobile.policy.PolicyEnforcer
import ametecs.managedmobile.security.SecureStore
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import kotlinx.serialization.json.put
import java.time.Instant

data class SyncStatus(val lastSyncEpochMs: Long?, val pendingEvents: Int, val online: Boolean, val error: String? = null)

/**
 * Work data on the phone: customers (encrypted cache — never the device contacts), the offline event queue
 * (encrypted, idempotent), and the last applied policy. Every sync revalidates authorisation server-side.
 */
class WorkRepository(private val session: SessionManager, private val store: SecureStore, val policy: PolicyEnforcer, private val api: ApiClient) {
    private val mutex = Mutex()
    /** v3: cases carry map pins (field map) — a new key makes every phone download its cases in full once. */
    private val SINCE_KEY = "customers_since_v3"
    private val _customers = MutableStateFlow<List<CachedCustomer>>(emptyList())
    val customers: StateFlow<List<CachedCustomer>> = _customers
    private val _status = MutableStateFlow(SyncStatus(store.getString("last_sync")?.toLongOrNull(), 0, false))
    val status: StateFlow<SyncStatus> = _status
    private var queue = EventQueue(loadQueue())

    init {
        _customers.value = loadCustomers().values.sortedBy { it.name }
        store.readFile("policy.json")?.let { runCatching { policy.load(api.json.parseToJsonElement(it).jsonObject) } }
        _status.value = _status.value.copy(pendingEvents = queue.size())
    }

    /** Downloads the effective policy, persists it as last-known, reports per-control results. */
    suspend fun syncPolicy(): JsonObject {
        val p = session.device("GET", "/api/mobile/v1/policy").obj()
        policy.load(p)
        store.writeFile("policy.json", p.toString())
        return session.device("POST", "/api/mobile/v1/policy/ack", policy.acknowledgement(p)).obj()
    }

    suspend fun enqueue(type: String, payload: JsonObject) = mutex.withLock {
        queue.enqueue(type, Instant.now().toString(), payload.toString())
        persistQueue()
    }

    /** Full sync: flush queue → refresh policy → customers. Safe to repeat. */
    suspend fun syncAll() = mutex.withLock {
        try {
            flushQueueLocked()
            syncPolicy()
            syncCustomersLocked()
            val now = System.currentTimeMillis()
            store.putString("last_sync", now.toString())
            _status.value = SyncStatus(now, queue.size(), true)
        } catch (e: ApiException) {
            _status.value = _status.value.copy(online = e.status != 0, error = e.message, pendingEvents = queue.size())
            throw e
        } catch (e: java.io.IOException) {
            _status.value = _status.value.copy(online = false, error = "Offline", pendingEvents = queue.size())
        }
    }

    /** Sends queued events now (location fixes, heartbeats) without a full sync; offline = they stay queued. */
    suspend fun flush() = mutex.withLock { runCatching { flushQueueLocked() } }

    private suspend fun flushQueueLocked() {
        while (queue.size() > 0) {
            val batch = queue.batch()
            val body = buildJsonObject {
                put("events", buildJsonArray {
                    batch.forEach { e -> add(buildJsonObject { put("id", e.id); put("type", e.type); put("occurred_at", e.occurredAt); put("payload", api.json.parseToJsonElement(e.payloadJson)) }) }
                })
            }
            val res = session.work("POST", "/api/mobile/v1/sync", body).obj()["results"]!!.jsonArray.map {
                val o = it.jsonObject
                SyncResult(o["id"]!!.jsonPrimitive.content, o["result"]!!.jsonPrimitive.content, o["reason"]?.jsonPrimitive?.content)
            }
            queue.applyResults(res)
            persistQueue()
            if (res.none { it.result in setOf("accepted", "duplicate", "rejected") }) break
        }
    }

    private suspend fun syncCustomersLocked() {
        val since = store.getString(SINCE_KEY)
        val r = session.work("GET", "/api/mobile/v1/customers" + (since?.let { "?since=" + java.net.URLEncoder.encode(it, "UTF-8") } ?: "")).obj()
        val visible = r["visible_ids"]!!.jsonArray.map { it.jsonPrimitive.long }.toSet()
        val changed = r["items"]!!.jsonArray.map { it.jsonObject.toCustomer() }
        val merged = CustomerCache.reconcile(loadCustomers(), changed, visible)
        store.writeFile("customers.json", JsonArray(merged.values.map { it.toJson() }).toString())
        store.putString(SINCE_KEY, r["server_time"]!!.jsonPrimitive.content)
        _customers.value = merged.values.sortedBy { it.name }
    }

    fun clearWorkData() {
        store.deleteFile("customers.json")
        store.putString(SINCE_KEY, null)
        _customers.value = emptyList()
    }

    private fun loadCustomers(): Map<Long, CachedCustomer> = store.readFile("customers.json")?.let { txt ->
        runCatching { api.json.parseToJsonElement(txt).jsonArray.map { it.jsonObject.toCustomer() }.associateBy { it.id } }.getOrNull()
    } ?: emptyMap()

    private fun loadQueue(): List<QueuedEvent> = store.readFile("queue.json")?.let { txt ->
        runCatching { api.json.parseToJsonElement(txt).jsonArray.map { val o = it.jsonObject; QueuedEvent(o.str("id")!!, o.str("type")!!, o.str("at")!!, o.str("p")!!, o.str("n")!!.toInt()) } }.getOrNull()
    } ?: emptyList()

    private fun persistQueue() {
        store.writeFile("queue.json", JsonArray(queue.snapshot().map { e -> buildJsonObject { put("id", e.id); put("type", e.type); put("at", e.occurredAt); put("p", e.payloadJson); put("n", e.attempts.toString()) } }).toString())
        _status.value = _status.value.copy(pendingEvents = queue.size())
    }

    private fun JsonObject.toCustomer() = CachedCustomer(
        this["id"]!!.jsonPrimitive.long, str("customer_ref") ?: "", str("name") ?: "", str("phone") ?: "", str("alt_phone"), str("status") ?: "new", str("updated_at") ?: "",
        extra = toString(),
    )

    /** The whole case as received is kept (encrypted), so the case list works offline. */
    private fun CachedCustomer.toJson() = api.json.parseToJsonElement(extra).jsonObject
}
