package ametecs.managedmobile.calls

import android.content.Context
import android.net.Uri
import ametecs.managedmobile.AppGraph
import ametecs.managedmobile.data.ApiException
import ametecs.managedmobile.data.obj
import ametecs.managedmobile.data.str
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * SIM call recordings, handled in the background so the employee can save feedback and place the next call at once.
 *  - "rec_queue": recordings found on the phone, waiting to upload (one entry per call).
 *  - "rec_hunts": answered calls whose recording the Phone app had not saved yet; looked for until found (max 24 h).
 * Shared by the call screen and SyncWorker (uploads even with the app closed or after being offline). Every recording is
 * sent against its own call id, so the server ties it to that call's employee, device, customer, feedback and times.
 * 26 Nov 2026: these used to be single slots — the next call overwrote a recording still pending, which then never arrived.
 */
object RecordingUpload {
    private val lock = Mutex()
    private const val QUEUE = "rec_queue"
    private const val HUNTS = "rec_hunts"
    private const val USED = "rec_used"   // file uris already given to a call (last 50)

    private fun list(g: AppGraph, key: String): List<JsonObject> =
        g.store.getString(key)?.let { runCatching { g.api.json.parseToJsonElement(it).jsonArray.map { e -> e.jsonObject } }.getOrNull() } ?: emptyList()
    private fun save(g: AppGraph, key: String, items: List<JsonObject>) =
        g.store.putString(key, if (items.isEmpty()) null else kotlinx.serialization.json.JsonArray(items).toString())

    /** Moves the old single-slot entries (app 1.0.17 and older) into the lists. */
    private fun migrate(g: AppGraph) {
        g.store.getString("rec_hunt")?.let { old ->
            runCatching { g.api.json.parseToJsonElement(old).jsonObject }.getOrNull()?.let { addHunt(g, it) }
            g.store.putString("rec_hunt", null)
        }
        val p = g.store.getString("sim_pending")?.let { runCatching { g.api.json.parseToJsonElement(it).jsonObject }.getOrNull() }
        if (p?.str("stage") == "upload") { enqueue(g, p); g.store.putString("sim_pending", null) }
    }

    /** Queue a found recording ({id, uri, name, mime, dur?, manual?}) and stop looking for that call's file. */
    fun enqueue(g: AppGraph, entry: JsonObject) {
        val id = entry.str("id") ?: return
        save(g, QUEUE, list(g, QUEUE).filter { it.str("id") != id } + entry)
        save(g, HUNTS, list(g, HUNTS).filter { it.str("id") != id })
        entry.str("uri")?.let { u -> save(g, USED, (list(g, USED).filter { it.str("uri") != u } + buildJsonObject { put("uri", u) }).takeLast(50)) }
    }

    /** Answered call with no recording file yet ({id, number, since}). */
    fun addHunt(g: AppGraph, h: JsonObject) {
        val id = h.str("id") ?: return
        if (list(g, QUEUE).any { it.str("id") == id }) return
        save(g, HUNTS, list(g, HUNTS).filter { it.str("id") != id } + h)
    }

    /** Newest call still missing its recording (the "Select recording file" button attaches to it), or null. */
    fun latestHunt(g: AppGraph): JsonObject? { migrate(g); return list(g, HUNTS).maxByOrNull { it.str("since")?.toLongOrNull() ?: 0 } }

    fun pending(g: AppGraph): Boolean { migrate(g); return list(g, QUEUE).isNotEmpty() || list(g, HUNTS).isNotEmpty() }

    private fun used(g: AppGraph) = list(g, USED).mapNotNull { it.str("uri") }.toSet() + list(g, QUEUE).mapNotNull { it.str("uri") }

    /** Looks again for every missing recording; found ones are queued. Newest call first, so it claims the newest file.
     *  True when at least one was found. Gives up on a call after 24 h. */
    fun hunt(g: AppGraph, ctx: Context): Boolean {
        migrate(g)
        var found = false
        for (h in list(g, HUNTS).sortedByDescending { it.str("since")?.toLongOrNull() ?: 0 }) {
            val id = h.str("id") ?: continue
            val since = h.str("since")?.toLongOrNull() ?: 0
            if (System.currentTimeMillis() - since > 24 * 3_600_000L) { save(g, HUNTS, list(g, HUNTS).filter { it.str("id") != id }); continue }
            val f = SimCalls.findRecording(ctx, h.str("number") ?: continue, since, used(g)) ?: continue
            enqueue(g, buildJsonObject {
                put("id", id); put("uri", f.uri.toString()); put("name", f.name); put("mime", f.mime ?: "audio/mp4"); f.durationSeconds?.let { put("dur", it) }
            })
            found = true
        }
        if (found) { g.store.putString("rec_ok", "1"); g.store.putString("rec_block_since", null) }
        return found
    }

    /** Uploads every queued recording. Returns call id → server call JSON for each one that went up.
     *  A refused one (4xx) is dropped; one that fails for another reason stays for the next try.
     *  Throws IOException when offline (the rest wait for the connection). */
    suspend fun uploadAll(g: AppGraph, ctx: Context): Map<String, JsonObject> = lock.withLock {
        migrate(g)
        val done = mutableMapOf<String, JsonObject>()
        var retryLater: ApiException? = null
        for (p in list(g, QUEUE)) {
            val id = p.str("id") ?: continue
            val f = SimCalls.RecorderFile(Uri.parse(p.str("uri")), p.str("name") ?: "recording.m4a", p.str("mime"), p.str("dur")?.toIntOrNull(), 0)
            fun drop() {
                save(g, QUEUE, list(g, QUEUE).filter { it.str("id") != id })
                if (f.uri.scheme == "file") f.uri.path?.let { java.io.File(it).delete() }   // in-app recording: remove the local copy
            }
            val bytes = SimCalls.read(ctx, f)
            if (bytes == null) { drop(); continue }   // file gone: nothing left to send
            try {
                done[id] = g.session.workUpload("/api/mobile/v1/calls/$id/recording") {
                    okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM)
                        .apply { f.durationSeconds?.let { addFormDataPart("duration_seconds", it.toString()) } }
                        .apply { if (p.str("manual") == "true") addFormDataPart("manual", "1") }
                        .addFormDataPart("file", f.name, bytes.toRequestBody((f.mime ?: "audio/mp4").toMediaTypeOrNull())).build()
                }.obj()
                drop()
            } catch (e: ApiException) {
                if (e.status in 400..499 && e.status != 401 && e.status != 429) drop()   // refused: don't retry forever
                else retryLater = e
            }
        }
        if (done.isEmpty()) retryLater?.let { throw it }
        done
    }
}
