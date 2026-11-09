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
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Upload of a SIM call's recording, shared by the call screen and the background SyncWorker so a recording still goes up
 * when the app is closed or the phone was offline. The recording is always sent against its own call id, so the server
 * ties it to that call's employee, device, customer and times. One upload at a time (the lock stops a double upload).
 */
object RecordingUpload {
    private val lock = Mutex()

    /** Uploads the recording waiting in "sim_pending" (stage = upload) and returns the server's call JSON.
     *  Null when there is nothing to upload, or the file is gone (then the pending entry is dropped).
     *  Throws IOException when offline / ApiException when refused — the entry stays for the next try. */
    suspend fun uploadPending(g: AppGraph, ctx: Context): JsonObject? = lock.withLock {
        val p = g.store.getString("sim_pending")?.let { g.api.json.parseToJsonElement(it).jsonObject } ?: return null
        if (p.str("stage") != "upload") return null
        val id = p.str("id")!!
        val f = SimCalls.RecorderFile(Uri.parse(p.str("uri")), p.str("name")!!, p.str("mime"), p.str("dur")?.toIntOrNull(), 0)
        val bytes = SimCalls.read(ctx, f) ?: run { g.store.putString("sim_pending", null); return null }
        val r = try {
            g.session.workUpload("/api/mobile/v1/calls/$id/recording") {
                okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM)
                    .apply { f.durationSeconds?.let { addFormDataPart("duration_seconds", it.toString()) } }
                    .apply { if (p.str("manual") == "true") addFormDataPart("manual", "1") }
                    .addFormDataPart("file", f.name, bytes.toRequestBody((f.mime ?: "audio/mp4").toMediaTypeOrNull())).build()
            }.obj()
        } catch (e: ApiException) {
            if (e.status in 400..499 && e.status != 401 && e.status != 429) {   // refused: don't retry forever
                g.store.putString("sim_pending", null)
                if (f.uri.scheme == "file") f.uri.path?.let { java.io.File(it).delete() }
            }
            throw e
        }
        g.store.putString("sim_pending", null)
        if (g.store.getString("rec_hunt")?.contains(id) == true) g.store.putString("rec_hunt", null)   // selected by hand: nothing left to look for
        if (f.uri.scheme == "file") f.uri.path?.let { java.io.File(it).delete() }   // in-app recording uploaded: remove the local copy
        r
    }

    /** "rec_hunt": an answered call whose recording the Phone app had not saved yet. When it shows up, queue it for upload
     *  (sim_pending stage = upload) and return true. Gives up after 24 h. */
    fun hunt(g: AppGraph, ctx: Context): Boolean {
        if (g.store.getString("sim_pending") != null) return false
        val h = g.store.getString("rec_hunt")?.let { g.api.json.parseToJsonElement(it).jsonObject } ?: return false
        val since = h.str("since")!!.toLong()
        if (System.currentTimeMillis() - since > 24 * 3_600_000L) { g.store.putString("rec_hunt", null); return false }
        val f = SimCalls.findRecording(ctx, h.str("number")!!, since) ?: return false
        g.store.putString("rec_hunt", null)
        g.store.putString("rec_ok", "1"); g.store.putString("rec_block_since", null)
        g.store.putString("sim_pending", buildJsonObject {
            put("id", h.str("id")!!); put("number", h.str("number")!!); put("since", since.toString())
            put("stage", "upload"); put("uri", f.uri.toString()); put("name", f.name); put("mime", f.mime ?: "audio/mp4"); f.durationSeconds?.let { put("dur", it) }
        }.toString())
        return true
    }
}
