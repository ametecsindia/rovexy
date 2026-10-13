package ametecs.managedmobile.push

import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.data.str
import ametecs.managedmobile.work.SyncWorker
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.ProcessLifecycleOwner
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/**
 * FCM is only a wake-up hint. Payloads carry {type:"sync", reason} — no customer data — and never a URL.
 * The app then pulls state over authenticated HTTPS.
 *   reason "media" → load the pending request → the phone rings (MediaCoordinator), even if the app was closed.
 *   reason "chat"  → app open: the screen refreshes; app closed: fetch the newest message and show it as a notification.
 */
class PushService : FirebaseMessagingService() {
    override fun onMessageReceived(message: RemoteMessage) {
        if (message.data["type"] != "sync") return
        val g = (application as AmmpApp).graph
        val reason = message.data["reason"]
        SyncWorker.syncNow(applicationContext)
        g.onPush(reason)
        if (reason == "chat") {
            val foreground = ProcessLifecycleOwner.get().lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)
            val ui = g.onServerEvent
            if (foreground && ui != null) ui("chat.push", JsonObject(emptyMap()))
            // onMessageReceived runs on a background thread and has ~10 s; finish the fetch inside it.
            else runBlocking { withTimeoutOrNull(8000) { notifyNewestMessage(g) } }
        }
    }

    private suspend fun notifyNewestMessage(g: ametecs.managedmobile.AppGraph) = runCatching {
        val seen = g.store.getString("chat_seen") ?: ""
        val notified = g.store.getString("chat_notified") ?: ""
        val top = g.session.work("GET", "/api/mobile/v1/conversations").jsonArray.map { it.jsonObject }
            .maxByOrNull { it.str("last_message_at") ?: "" } ?: return@runCatching
        val at = top.str("last_message_at") ?: return@runCatching
        if (at <= maxOf(seen, notified)) return@runCatching
        val id = top.str("id")!!.toLong()
        val last = g.session.work("GET", "/api/mobile/v1/conversations/$id/messages").jsonArray.lastOrNull()?.jsonObject
        g.store.putString("chat_notified", at)
        NotificationActions.showChat(applicationContext, id, top.str("subject"), last?.let { it.str("body") ?: "📎 Attachment" }, at)
    }

    override fun onNewToken(token: String) {
        val g = (application as AmmpApp).graph
        CoroutineScope(Dispatchers.IO).launch {
            runCatching { g.session.device("PUT", "/api/mobile/v1/device/push-token", buildJsonObject { put("token", token); put("provider", "fcm") }) }
        }
    }
}
