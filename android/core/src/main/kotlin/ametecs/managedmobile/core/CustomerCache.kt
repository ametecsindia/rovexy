package ametecs.managedmobile.core

/** [extra] = the case as the server sent it (JSON: portfolio, dues, last call, promise…); read by the app's case screens. */
class CachedCustomer(val id: Long, val ref: String, val name: String, val phone: String, val altPhone: String?, val status: String, val updatedAt: String, val extra: String = "{}")

/**
 * Applies a server sync. visible_ids is authoritative: anything not in it (withdrawn / reassigned) is removed
 * from the local encrypted cache at the next successful sync.
 */
object CustomerCache {
    fun reconcile(cached: Map<Long, CachedCustomer>, changed: List<CachedCustomer>, visibleIds: Set<Long>): Map<Long, CachedCustomer> {
        val out = LinkedHashMap<Long, CachedCustomer>()
        for ((id, c) in cached) if (id in visibleIds) out[id] = c
        for (c in changed) if (c.id in visibleIds) out[c.id] = c
        return out
    }
}
