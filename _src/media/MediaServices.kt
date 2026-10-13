package ametecs.managedmobile.media

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.IBinder
import androidx.core.app.ServiceCompat
import androidx.core.content.IntentCompat
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.R

/**
 * Foreground services make every capture visible: a persistent notification with a Stop action is shown for the
 * whole session (plus Android's own screen-sharing indicator / camera privacy indicator).
 */
abstract class VisibleMediaService(private val kind: String, private val fgsType: Int) : Service() {
    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val g = (application as AmmpApp).graph
        if (intent?.action == ACTION_STOP) {
            g.media.endActive("employee_stopped")
            stopSelf()
            return START_NOT_STICKY
        }
        val stop = PendingIntent.getService(this, 0, Intent(this, javaClass).setAction(ACTION_STOP), PendingIntent.FLAG_IMMUTABLE)
        val n: Notification = Notification.Builder(this, AmmpApp.CHANNEL_MONITOR)
            .setSmallIcon(R.drawable.ic_app)
            .setContentTitle(when (kind) { "screen" -> "Your screen is being shared"; "voice" -> "On a call with your supervisor"; else -> "Your camera is being shared" })
            .setContentText(if (kind == "voice") "Internet call. Tap Hang up to end." else "Shared with your supervisor. Tap Stop to end at any time.")
            .setOngoing(true)
            .addAction(Notification.Action.Builder(null, if (kind == "voice") "Hang up" else "Stop", stop).build())
            .build()
        // Must be in the foreground BEFORE MediaProjection is obtained (Android 14+).
        ServiceCompat.startForeground(this, when (kind) { "screen" -> 2001; "voice" -> 2003; else -> 2002 }, n, fgsType)
        val projection: Intent? = intent?.let { IntentCompat.getParcelableExtra(it, EXTRA_PROJECTION, Intent::class.java) }
        g.media.onForegroundReady(kind, projection)
        return START_NOT_STICKY
    }

    companion object {
        const val ACTION_STOP = "ammp.media.STOP"
        const val EXTRA_PROJECTION = "projection"
    }
}

class ScreenShareService : VisibleMediaService("screen", ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION) {
    companion object {
        fun start(ctx: Context, projectionData: Intent) =
            ctx.startForegroundService(Intent(ctx, ScreenShareService::class.java).putExtra(EXTRA_PROJECTION, projectionData))
    }
}

class VoiceCallService : VisibleMediaService("voice", ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE) {
    companion object {
        fun start(ctx: Context) = ctx.startForegroundService(Intent(ctx, VoiceCallService::class.java))
    }
}

class CameraSessionService : VisibleMediaService("camera", ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA) {
    companion object {
        fun start(ctx: Context) = ctx.startForegroundService(Intent(ctx, CameraSessionService::class.java))
    }
}
