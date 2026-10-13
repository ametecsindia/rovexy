package ametecs.managedmobile.push

import android.app.NotificationManager
import android.app.PendingIntent
import android.app.RemoteInput
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.MainActivity
import ametecs.managedmobile.R
import ametecs.managedmobile.data.str
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/** Buttons on notifications that work without opening the app: Decline a call, Reply to / Mark read a message. */
class NotificationActions : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) {
        val g = (ctx.applicationContext as AmmpApp).graph
        val id = intent.getStringExtra(EXTRA_ID) ?: return
        val nm = ctx.getSystemService(NotificationManager::class.java)
        when (intent.action) {
            DECLINE -> g.media.declineId(id)
            MARK_READ -> { intent.getStringExtra(EXTRA_AT)?.let { g.store.putString("chat_seen", it) }; nm.cancel(CHAT_NOTIF_ID) }
            REPLY -> {
                val text = RemoteInput.getResultsFromIntent(intent)?.getCharSequence(KEY_TEXT)?.toString()?.trim().orEmpty()
                if (text.isEmpty()) return
                val done = goAsync()
                CoroutineScope(Dispatchers.IO).launch {
                    val ok = runCatching {
                        g.session.work("POST", "/api/mobile/v1/conversations/$id/messages",
                            buildJsonObject { put("body", text); put("client_message_id", java.util.UUID.randomUUID().toString()) })
                    }.isSuccess
                    // Android keeps a spinner on the notification until it is updated or removed.
                    if (ok) {
                        nm.cancel(CHAT_NOTIF_ID)
                        // Our own reply is now the newest message: mark it seen so it doesn't come back as "new".
                        runCatching { g.session.work("GET", "/api/mobile/v1/conversations").jsonArray
                            .mapNotNull { it.jsonObject.str("last_message_at") }.maxOrNull()?.let { g.store.putString("chat_seen", it) } }
                    }
                    else nm.notify(CHAT_NOTIF_ID, android.app.Notification.Builder(ctx, AmmpApp.CHANNEL_CHAT).setSmallIcon(R.drawable.ic_app)
                        .setContentTitle("Reply not sent").setContentText("No connection. Open the app and try again.").setAutoCancel(true).build())
                    done.finish()
                }
            }
        }
    }

    companion object {
        const val DECLINE = "ammp.call.DECLINE"
        const val REPLY = "ammp.chat.REPLY"
        const val MARK_READ = "ammp.chat.READ"
        const val EXTRA_ID = "id"
        const val EXTRA_AT = "at"
        const val KEY_TEXT = "reply"
        const val CHAT_NOTIF_ID = 1007

        const val ACTION_OPEN_CHAT = "ammp.chat.OPEN"

        /** "Message from supervisor" notification with the text, Reply (typed in the notification) and Mark read. Tap opens the chat.
         *  Used by the app while it is running AND by Firebase push when the app is closed. */
        fun showChat(ctx: Context, convId: Long, title: String?, text: String?, lastAt: String?) = runCatching {
            val open = PendingIntent.getActivity(ctx, 7, Intent(ctx, MainActivity::class.java)
                .setAction(ACTION_OPEN_CHAT).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP), PendingIntent.FLAG_IMMUTABLE)
            val reply = android.app.Notification.Action.Builder(null, "Reply", pending(ctx, REPLY, convId.toString()))
                .addRemoteInput(RemoteInput.Builder(KEY_TEXT).setLabel("Reply to supervisor").build()).build()
            val read = android.app.Notification.Action.Builder(null, "Mark read", pending(ctx, MARK_READ, convId.toString(), lastAt)).build()
            val body = text ?: "Your supervisor sent you a message. Tap to read."
            ctx.getSystemService(NotificationManager::class.java).notify(CHAT_NOTIF_ID,
                android.app.Notification.Builder(ctx, AmmpApp.CHANNEL_CHAT).setSmallIcon(R.drawable.ic_app)
                    .setCategory(android.app.Notification.CATEGORY_MESSAGE).setContentTitle(title?.takeIf { it.isNotBlank() } ?: "Message from supervisor")
                    .setContentText(body).setStyle(android.app.Notification.BigTextStyle().bigText(body))
                    .addAction(reply).addAction(read).setContentIntent(open).setAutoCancel(true).build())
        }

        /** REPLY needs a mutable PendingIntent so Android can attach the typed text. */
        fun pending(ctx: Context, action: String, id: String, at: String? = null): PendingIntent =
            PendingIntent.getBroadcast(ctx, (action + id).hashCode(),
                Intent(ctx, NotificationActions::class.java).setAction(action).putExtra(EXTRA_ID, id).putExtra(EXTRA_AT, at),
                PendingIntent.FLAG_UPDATE_CURRENT or if (action == REPLY) PendingIntent.FLAG_MUTABLE else PendingIntent.FLAG_IMMUTABLE)
    }
}
