package ametecs.managedmobile.core

import java.util.UUID

class QueuedEvent(val id: String, val type: String, val occurredAt: String, val payloadJson: String, val attempts: Int = 0) {
    fun retried() = QueuedEvent(id, type, occurredAt, payloadJson, attempts + 1)
}

class SyncResult(val id: String, val result: String, val reason: String? = null)

/**
 * Pure queue semantics (persistence is done by the app's encrypted store).
 * Each event has a client UUID generated ONCE at enqueue, so resubmission after a lost response is safe:
 * the server answers "duplicate" and the event is dropped.
 */
class EventQueue(initial: List<QueuedEvent> = emptyList()) {
    private val items = LinkedHashMap<String, QueuedEvent>()

    init {
        for (e in initial) items[e.id] = e
    }

    fun enqueue(type: String, occurredAt: String, payloadJson: String): QueuedEvent {
        require(type in TYPES) { "Unknown event type $type" }
        val e = QueuedEvent(UUID.randomUUID().toString(), type, occurredAt, payloadJson)
        items[e.id] = e
        return e
    }

    fun batch(max: Int = 200): List<QueuedEvent> = items.values.take(max)

    fun size(): Int = items.size

    /** Drops accepted/duplicate and permanently rejected events; keeps unknown ones for retry. Returns rejected ones. */
    fun applyResults(results: List<SyncResult>): List<SyncResult> {
        val rejected = ArrayList<SyncResult>()
        for (r in results) {
            when (r.result) {
                "accepted", "duplicate" -> items.remove(r.id)
                "rejected" -> {
                    items.remove(r.id)
                    rejected.add(r)
                }
                else -> items[r.id]?.let { items[r.id] = it.retried() }
            }
        }
        return rejected
    }

    fun snapshot(): List<QueuedEvent> = ArrayList(items.values)

    companion object {
        val TYPES = setOf("disposition", "note", "follow_up", "activity", "location", "chat_read")
    }
}
