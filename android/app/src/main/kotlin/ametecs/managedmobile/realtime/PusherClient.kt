package ametecs.managedmobile.realtime

import ametecs.managedmobile.core.Backoff
import ametecs.managedmobile.data.ServerConfig
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import java.util.concurrent.TimeUnit

/**
 * Minimal Pusher-protocol (Laravel Reverb) client for the device's private channel.
 * The channel is re-authorised every 60 s; if the server refuses (revoked / reassigned / signed out) the socket is
 * closed and [onAuthFailed] fires — this is the documented ≤60 s WebSocket revocation bound.
 */
class PusherClient(
    private val config: ServerConfig,
    private val wsUrl: () -> String?,           // wss://host:port/app/{key}?protocol=7 (from server bootstrap)
    private val tokenProvider: () -> String?,
    private val channel: () -> String?,          // private-company.{cid}.device.{did}
    private val onEvent: (type: String, data: JsonObject) -> Unit,
    private val onAuthFailed: () -> Unit,
) {
    private val http = OkHttpClient.Builder().pingInterval(30, TimeUnit.SECONDS).build()
    private val json = Json { ignoreUnknownKeys = true }
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    @Volatile private var ws: WebSocket? = null
    @Volatile private var running = false
    private var socketId: String? = null
    private var loop: Job? = null
    @Volatile private var attempt = 0

    fun start() {
        if (loop?.isActive == true) return
        running = true
        loop = scope.launch {
            while (isActive) {
                val url = wsUrl()
                if (url == null || tokenProvider() == null) { delay(30_000); continue }
                connect(url)
                delay(60_000)
                socketId?.let { if (!authorise(it)) { stop(); onAuthFailed(); return@launch } }
            }
        }
    }

    fun stop() {
        running = false
        loop?.cancel()
        ws?.close(1000, "bye")
        ws = null
    }

    private fun connect(url: String) {
        if (!running || ws != null) return
        ws = http.newWebSocket(Request.Builder().url(url).build(), object : WebSocketListener() {
            override fun onMessage(webSocket: WebSocket, text: String) {
                val msg = runCatching { json.parseToJsonElement(text).jsonObject }.getOrNull() ?: return
                when (msg["event"]?.jsonPrimitive?.content) {
                    "pusher:connection_established" -> {
                        attempt = 0
                        socketId = json.parseToJsonElement(msg["data"]!!.jsonPrimitive.content).jsonObject["socket_id"]!!.jsonPrimitive.content
                        scope.launch { if (!authorise(socketId!!)) { stop(); onAuthFailed() } }
                    }
                    "pusher:ping" -> webSocket.send("{\"event\":\"pusher:pong\",\"data\":{}}")
                    ".device", "device" -> {
                        val data = json.parseToJsonElement(msg["data"]!!.jsonPrimitive.content).jsonObject
                        onEvent(data["type"]!!.jsonPrimitive.content, data)
                    }
                }
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) = reconnectLater()
            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) = reconnectLater()
        })
    }

    private fun reconnectLater() {
        ws = null
        socketId = null
        if (!running) return
        scope.launch {
            delay(Backoff.reconnectDelayMillis(attempt++))
            wsUrl()?.let { connect(it) }
        }
    }

    /** POST /broadcasting/auth with the work-session bearer; then subscribe. Returns false if the server refuses. */
    private fun authorise(socket: String): Boolean {
        val ch = channel() ?: return false
        val origin = config.serverOrigin ?: return false
        val req = Request.Builder().url("$origin/api/broadcasting/auth")
            .header("Authorization", "Bearer ${tokenProvider() ?: return false}")
            .header("Accept", "application/json")
            .apply { config.workspace?.let { header("X-Workspace", it) } }
            .post(FormBody.Builder().add("socket_id", socket).add("channel_name", ch).build()).build()
        return runCatching {
            http.newCall(req).execute().use { r ->
                if (r.code == 401 || r.code == 403) return false
                if (!r.isSuccessful) return true // transient; keep current subscription
                val auth = json.parseToJsonElement(r.body!!.string()).jsonObject["auth"]!!.jsonPrimitive.content
                ws?.send(buildJsonObject {
                    put("event", "pusher:subscribe")
                    put("data", buildJsonObject { put("channel", ch); put("auth", auth) })
                }.toString())
                true
            }
        }.getOrDefault(true)
    }
}
