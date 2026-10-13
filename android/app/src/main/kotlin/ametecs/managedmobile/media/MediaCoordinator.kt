package ametecs.managedmobile.media

import android.app.Notification
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.MainActivity
import ametecs.managedmobile.R
import ametecs.managedmobile.push.NotificationActions
import android.content.Intent
import androidx.core.app.NotificationManagerCompat
import ametecs.managedmobile.core.MediaRequestGate
import ametecs.managedmobile.data.SessionManager
import ametecs.managedmobile.data.obj
import ametecs.managedmobile.data.str
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import org.webrtc.PeerConnection
import java.time.OffsetDateTime

data class MediaRequest(val id: String, val kind: String, val purpose: String, val requestedBy: String, val requestedAtMs: Long, val state: String)

/**
 * Employee-side flow: show request → employee accepts/declines → OS consent → visible foreground session.
 * Refuses to accept when notifications are disabled, because the persistent notification is part of the
 * "always visible" guarantee.
 */
class MediaCoordinator(private val context: Context, private val session: SessionManager) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO + CoroutineExceptionHandler { _, _ -> })
    private val _pending = MutableStateFlow<MediaRequest?>(null)
    val pending: StateFlow<MediaRequest?> = _pending
    private val _active = MutableStateFlow<WebRtcSession?>(null)
    val active: StateFlow<WebRtcSession?> = _active
    /** Kind ("screen"/"camera") whose OS consent the Activity must launch now (collected on the main thread). */
    private val _consentNeeded = MutableStateFlow<String?>(null)
    val consentNeeded: StateFlow<String?> = _consentNeeded
    val lastError = MutableStateFlow<String?>(null)
    @Volatile private var accepted: MediaRequest? = null
    @Volatile private var cancelledId: String? = null
    private val outbound = Channel<Pair<String, JsonObject>>(Channel.UNLIMITED)

    /** Requests already answered/declined here. A poll that was in flight while Answer was tapped must not bring the
     *  dialog (and the ringing) back — tapping Answer again then failed with "Cannot accept a session in state accepted". */
    private val handled = java.util.Collections.synchronizedSet(mutableSetOf<String>())

    /** Set by the notification's Answer button when the request hasn't been loaded yet (app was in the background). */
    @Volatile private var autoAnswerId: String? = null

    init {
        // One consumer keeps signalling in order (offer before candidates).
        scope.launch { for ((id, body) in outbound) runCatching { session.work("POST", "/api/mobile/v1/media/$id/signal", body) } }
        // Ring while an internet call is waiting; stops the moment it is answered, declined, cancelled or expires.
        // A call that stops ringing without Answer/Decline here (supervisor hung up, or it timed out) → "Missed call".
        scope.launch {
            var ringing: MediaRequest? = null
            _pending.collect { r ->
                if (r?.kind == "voice") { ring(r); ringing = r }
                else { stopRing(); ringing?.let { if (it.id !in handled) missed(it) }; ringing = null }
                if (r != null && r.id == autoAnswerId) { autoAnswerId = null; accept(r) }
            }
        }
    }

    private val nm get() = context.getSystemService(NotificationManager::class.java)

    /** Heads-up "Incoming call" notification; FLAG_INSISTENT loops the channel's ringtone until it is cancelled. */
    private fun ring(r: MediaRequest) {
        val open = PendingIntent.getActivity(context, 9, Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP), PendingIntent.FLAG_IMMUTABLE)
        val n = Notification.Builder(context, AmmpApp.CHANNEL_CALL)
            .setSmallIcon(R.drawable.ic_app)
            .setContentTitle("Incoming call · ${r.requestedBy}")
            .setContentText("Internet call from your supervisor. Tap to answer.")
            .setCategory(Notification.CATEGORY_CALL)
            .setContentIntent(open)
            .setFullScreenIntent(open, true)
            .setOngoing(true)
            .addAction(Notification.Action.Builder(null, "Decline", NotificationActions.pending(context, NotificationActions.DECLINE, r.id)).build())
            .addAction(Notification.Action.Builder(null, "Answer", PendingIntent.getActivity(context, 10, Intent(context, MainActivity::class.java)
                .setAction(ACTION_ANSWER).putExtra(EXTRA_ID, r.id).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)).build())
            .build()
        n.flags = n.flags or Notification.FLAG_INSISTENT
        runCatching { nm.notify(RING_ID, n) }
    }

    private fun stopRing() = runCatching { nm.cancel(RING_ID) }

    private fun missed(r: MediaRequest) = runCatching {
        val open = PendingIntent.getActivity(context, 11, Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP), PendingIntent.FLAG_IMMUTABLE)
        nm.notify(MISSED_ID, Notification.Builder(context, AmmpApp.CHANNEL_CHAT)
            .setSmallIcon(R.drawable.ic_app)
            .setContentTitle("Missed call · ${r.requestedBy}")
            .setContentText("Your supervisor tried to call you at ${java.text.DateFormat.getTimeInstance(java.text.DateFormat.SHORT).format(java.util.Date(r.requestedAtMs))}.")
            .setCategory(Notification.CATEGORY_MISSED_CALL)
            .setContentIntent(open).setAutoCancel(true).build())
    }

    /** Answer tapped on the ringing notification: accept now, or as soon as the request is loaded. */
    fun answer(id: String) {
        val r = _pending.value
        if (r?.id == id) accept(r) else { autoAnswerId = id; refresh() }
    }

    /** Polled on sync / triggered by WebSocket/push. Expired or stale requests are dropped, never executed. */
    fun refresh() = scope.launch {
        val now = System.currentTimeMillis()
        _pending.value = runCatching {
            session.work("GET", "/api/mobile/v1/media/pending").jsonArray.map { it.jsonObject }.map {
                MediaRequest(it.str("uuid")!!, it.str("kind")!!, it.str("purpose") ?: "", it.str("requested_by_name") ?: "Supervisor",
                    OffsetDateTime.parse(it.str("requested_at")!!).toInstant().toEpochMilli(), it.str("state")!!)
            }.firstOrNull { it.id !in handled && MediaRequestGate.canPrompt(it.state, it.requestedAtMs, now) }
        }.getOrElse { return@launch }   // network blip: keep ringing instead of flashing a "missed call"
    }

    fun decline(r: MediaRequest) = declineId(r.id)

    fun declineId(id: String) = scope.launch {
        handled += id
        if (_pending.value?.id == id) _pending.value = null
        runCatching { session.work("POST", "/api/mobile/v1/media/$id/decline") }
    }

    fun accept(r: MediaRequest) = scope.launch {
        if (!NotificationManagerCompat.from(context).areNotificationsEnabled()) {
            lastError.value = "Turn on notifications for this app first: an indicator must stay visible during sharing."
            return@launch
        }
        if (!handled.add(r.id)) return@launch   // double tap
        _pending.value = null                    // dialog + ringing stop at once
        runCatching { session.work("POST", "/api/mobile/v1/media/${r.id}/accept") }.onSuccess {
            accepted = r
            cancelledId = null
            lastError.value = null
            _consentNeeded.value = r.kind
        }.onFailure { handled -= r.id; lastError.value = it.message }
    }

    fun consentLaunched() { _consentNeeded.value = null }

    fun osConsentDenied() = scope.launch {
        accepted?.let { runCatching { session.work("POST", "/api/mobile/v1/media/${it.id}/end", buildJsonObject { put("reason", "os_consent_denied") }) } }
        accepted = null
    }

    fun startScreen(projection: Intent) = ScreenShareService.start(context, projection)
    fun startCamera() = CameraSessionService.start(context)
    fun startVoice() = VoiceCallService.start(context)

    /** Called by the visible foreground service once it is in the foreground. */
    fun onForegroundReady(kind: String, projection: Intent?) {
        val r = accepted ?: return
        scope.launch {
            try {
                val ice = session.work("GET", "/api/mobile/v1/media/${r.id}/ice").obj()["ice_servers"]!!.jsonArray.map { s ->
                    val o = s.jsonObject
                    val urls = o["urls"]!!.jsonArray.map { it.jsonPrimitive.content }
                    PeerConnection.IceServer.builder(urls).apply { o.str("username")?.let { setUsername(it) }; o.str("credential")?.let { setPassword(it) } }.createIceServer()
                }
                if (cancelledId == r.id) { finish(r.id, "employee_stopped"); return@launch }   // Stop tapped while connecting
                if (kind == "screen" && projection == null) { finish(r.id, "os_consent_denied"); return@launch }
                val s = WebRtcSession(context, r.id, kind, ice,
                    sendSignal = { type, payload -> outbound.trySend(r.id to buildJsonObject { put("type", type); put("payload", toJson(payload)) }) },
                    onEnded = { reason -> scope.launch { finish(r.id, reason) } })
                session.work("POST", "/api/mobile/v1/media/${r.id}/start")
                s.start(projection, 1280, 720, 5)
                _active.value = s
                pollSignals(s)
            } catch (e: Exception) {
                finish(r.id, "connection_failed")
            }
        }
    }

    /** Fallback when WebSockets are not running: fetch the supervisor's answer/candidates and the session state over HTTP. */
    private fun pollSignals(s: WebRtcSession) = scope.launch {
        var after = 0L
        while (_active.value === s) {
            runCatching {
                val r = session.work("GET", "/api/mobile/v1/media/${s.sessionId}/signals?after=$after").obj()
                for (m in r["signals"]!!.jsonArray.map { it.jsonObject }) {
                    after = m.str("id")!!.toLong()
                    onRemoteSignal(buildJsonObject { put("session_id", s.sessionId); put("type", m.str("type")!!); put("payload", m["payload"]!!) })
                }
                onRemoteState(buildJsonObject { put("session_id", s.sessionId); put("state", r.str("state") ?: "") })
            }
            kotlinx.coroutines.delay(500)
        }
    }

    /** New requests are found by polling every few seconds while signed in (WebSocket/push make it instant when available). */
    fun watchRequests() {
        if (watching) return
        watching = true
        // ponytail: 2 s poll so an internet call rings fast without Reverb/FCM; push makes it instant once those run.
        scope.launch { while (true) { if (_active.value == null && accepted == null) refresh().join(); kotlinx.coroutines.delay(2000) } }
    }
    @Volatile private var watching = false

    fun onRemoteSignal(data: JsonObject) {
        val s = _active.value ?: return
        if (data.str("session_id") != s.sessionId) return
        @Suppress("UNCHECKED_CAST")
        runCatching { s.onRemoteSignal(data.str("type")!!, fromJson(data["payload"]!!) as Map<String, Any?>) }
    }

    fun onRemoteState(data: JsonObject) {
        val s = _active.value
        if (s != null && data.str("session_id") == s.sessionId && data.str("state") in setOf("ended", "expired")) s.end("supervisor_stopped")
        if (s == null && accepted?.id == data.str("session_id") && data.str("state") in setOf("ended", "expired")) cancelledId = accepted?.id
    }

    fun endActive(reason: String) {
        val s = _active.value
        if (s != null) s.end(reason) else accepted?.let { cancelledId = it.id }
    }

    private suspend fun finish(id: String, reason: String) {
        if (reason in setOf("employee_stopped", "connection_failed", "os_consent_denied")) {
            runCatching { session.work("POST", "/api/mobile/v1/media/$id/end", buildJsonObject { put("reason", reason) }) }
        }
        _active.value = null
        accepted = null
        context.stopService(Intent(context, ScreenShareService::class.java))
        context.stopService(Intent(context, CameraSessionService::class.java))
        context.stopService(Intent(context, VoiceCallService::class.java))
    }

    private fun toJson(v: Any?): JsonElement = when (v) {
        null -> JsonNull
        is Map<*, *> -> JsonObject(v.entries.associate { it.key.toString() to toJson(it.value) })
        is Number -> JsonPrimitive(v)
        is Boolean -> JsonPrimitive(v)
        else -> JsonPrimitive(v.toString())
    }

    private fun fromJson(e: JsonElement): Any? = when (e) {
        is JsonNull -> null
        is JsonObject -> e.mapValues { fromJson(it.value) }
        is JsonPrimitive -> if (e.isString) e.content else e.content.toIntOrNull() ?: e.content
        else -> null
    }

    companion object {
        private const val RING_ID = 1009
        private const val MISSED_ID = 1010
        const val ACTION_ANSWER = "ammp.call.ANSWER"
        const val EXTRA_ID = "id"
    }
}
