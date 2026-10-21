package ametecs.managedmobile

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.media.AudioAttributes
import android.media.RingtoneManager
import ametecs.managedmobile.core.PushRouting
import ametecs.managedmobile.data.ApiClient
import ametecs.managedmobile.data.ServerConfig
import ametecs.managedmobile.data.SessionManager
import ametecs.managedmobile.data.WorkRepository
import ametecs.managedmobile.media.MediaCoordinator
import ametecs.managedmobile.policy.PolicyEnforcer
import ametecs.managedmobile.realtime.PusherClient
import ametecs.managedmobile.security.DeviceKeys
import ametecs.managedmobile.security.SecureStore
import ametecs.managedmobile.work.SyncWorker
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

class AmmpApp : Application() {
    lateinit var graph: AppGraph
        private set

    override fun onCreate() {
        super.onCreate()
        // Crash note: written on the way down, sent to the server on the next start (AppViewModel.sendCrashNote), so a
        // restart on an employee's phone can be diagnosed from the console instead of guessed.
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { t, e ->
            runCatching {
                java.io.File(filesDir, "last_crash.txt").writeText("${java.time.Instant.now()} ${BuildConfig.VERSION_NAME} " +
                    "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL} Android ${android.os.Build.VERSION.RELEASE} thread=${t.name}\n" +
                    android.util.Log.getStackTraceString(e).take(12_000))
            }
            previous?.uncaughtException(t, e)
        }
        graph = AppGraph(this)
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL_WORK, getString(R.string.notif_channel_work), NotificationManager.IMPORTANCE_LOW))
        nm.createNotificationChannel(NotificationChannel(CHANNEL_MONITOR, getString(R.string.notif_channel_monitor), NotificationManager.IMPORTANCE_HIGH))
        // Supervisor messages: the phone's default notification sound + vibration.
        nm.createNotificationChannel(NotificationChannel(CHANNEL_CHAT, "Messages from supervisor", NotificationManager.IMPORTANCE_HIGH).apply {
            setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION),
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_COMMUNICATION_INSTANT).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
            enableVibration(true)
        })
        // Internet calls: the phone's ringtone at ring volume, looped by FLAG_INSISTENT until answered / declined / missed.
        nm.createNotificationChannel(NotificationChannel(CHANNEL_CALL, "Incoming internet calls", NotificationManager.IMPORTANCE_HIGH).apply {
            setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE),
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 1000, 1000)
            lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
        })
        SyncWorker.schedulePeriodic(this)
    }

    companion object {
        const val CHANNEL_WORK = "work_session"
        const val CHANNEL_MONITOR = "monitoring_sessions"
        const val CHANNEL_CHAT = "supervisor_messages"
        const val CHANNEL_CALL = "incoming_calls"
    }
}

/** Manual DI: one instance of each collaborator for the process. */
class AppGraph(app: Application) {
    val store = SecureStore(app)
    val keys = DeviceKeys(store, app.packageManager)
    val config = ServerConfig(app, store)
    val api = ApiClient(config, keys)
    val session = SessionManager(api, store, keys)
    val policy = PolicyEnforcer(app)
    val work = WorkRepository(session, store, policy, api)
    val media = MediaCoordinator(app, session)
    val pushDestination = MutableStateFlow(PushRouting.destinationFor(null))
    @Volatile var realtimeUrl: String? = null
    @Volatile var realtimeChannel: String? = null
    var onServerEvent: ((String, JsonObject) -> Unit)? = null

    val realtime = PusherClient(config, { realtimeUrl }, { session.accessToken() }, { realtimeChannel },
        onEvent = { type, data ->
            when (type) {
                "media.requested" -> media.refresh()
                "media.signal" -> media.onRemoteSignal(data["data"] as? JsonObject ?: data)
                "media.updated" -> media.onRemoteState(data["data"] as? JsonObject ?: data)
                else -> SyncWorker.syncNow(app)
            }
            onServerEvent?.invoke(type, data)
        },
        onAuthFailed = { onServerEvent?.invoke("session.revalidate", JsonObject(emptyMap())) })

    /** Sends this phone's Firebase token to the server. Does nothing if google-services.json wasn't added (Firebase off). */
    fun registerPush() = runCatching {
        com.google.firebase.messaging.FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.Dispatchers.IO).launch {
                runCatching { session.device("PUT", "/api/mobile/v1/device/push-token", buildJsonObject { put("token", token); put("provider", "fcm") }) }
                    .onSuccess { android.util.Log.i("AMMP", "Firebase push registered") }
                    .onFailure { android.util.Log.w("AMMP", "Firebase push registration failed: ${it.message}") }
            }
        }
    }.onFailure { android.util.Log.i("AMMP", "Firebase not configured (no google-services.json): ${it.message}") }

    fun onPush(reason: String?) {
        pushDestination.value = PushRouting.destinationFor(reason)
        if (reason == "media") media.refresh()
    }
}
