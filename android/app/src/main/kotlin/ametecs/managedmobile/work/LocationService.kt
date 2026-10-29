package ametecs.managedmobile.work

import android.Manifest
import android.app.Notification
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.IBinder
import android.os.Looper
import androidx.core.app.ServiceCompat
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.BuildConfig
import ametecs.managedmobile.R
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/** G19: true when this fix comes from a fake-GPS ("mock location") app. */
@Suppress("DEPRECATION")
fun android.location.Location.mocked(): Boolean = if (android.os.Build.VERSION.SDK_INT >= 31) isMock else isFromMockProvider

/**
 * Work-session location. Runs ONLY between sign-in and sign-out, as a foreground service with a persistent,
 * visible notification. Fixes go to the encrypted queue (idempotent upload). Handles denied permission by stopping.
 */
class LocationService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val callback = object : LocationCallback() {
        override fun onLocationResult(r: LocationResult) {
            r.lastLocation?.let { latest.value = it }   // the field map follows the same fixes (no second tracker)
            val g = (application as AmmpApp).graph
            val battery = (getSystemService(BATTERY_SERVICE) as android.os.BatteryManager).getIntProperty(android.os.BatteryManager.BATTERY_PROPERTY_CAPACITY)
            val net = runCatching {
                val cm = getSystemService(CONNECTIVITY_SERVICE) as android.net.ConnectivityManager
                val c = cm.getNetworkCapabilities(cm.activeNetwork)
                when { c == null -> "none"; c.hasTransport(android.net.NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"; c.hasTransport(android.net.NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"; else -> "other" }
            }.getOrDefault("unknown")
            scope.launch {
                for (l in r.locations) g.work.enqueue("location", buildJsonObject {
                    put("lat", l.latitude); put("lng", l.longitude); put("accuracy_m", l.accuracy.toDouble()); put("provider", l.provider ?: "fused"); put("battery_pct", battery)
                    if (l.mocked()) put("mock", true)   // G19: fake-GPS app — the server flags it for the admin
                })
                g.work.enqueue("activity", buildJsonObject { put("type", "heartbeat"); put("battery_pct", battery); put("network_type", net) })
                g.work.flush()   // admin sees the fix within seconds, not at the next 15-minute background sync
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        // G11: the notice says how often, from the company's policy (the same number the tracker uses below).
        val secs = (application as AmmpApp).graph.work.policy.int("location.interval_seconds", 120)
        val every = if (secs % 60 == 0) "${secs / 60} min" else "$secs s"
        val n: Notification = Notification.Builder(this, AmmpApp.CHANNEL_WORK)
            .setSmallIcon(R.drawable.ic_app).setContentTitle("Work session active")
            .setContentText("Location is shared with your company every $every while you are signed in.").setOngoing(true).build()
        // Android 14+ refuses a location foreground service it considers started from the background (e.g. the system
        // re-creating this sticky service while the dialer is in front during a call) or without location permission.
        // That refusal is an exception — uncaught, it killed and restarted the whole app. Now the service just stops;
        // it is started again when ROVEXY is opened (onResumed).
        val granted = checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        // Play build: no location FGS (would need a Play declaration video) — plain service, fixes while ROVEXY is in use.
        val inForeground = granted && (BuildConfig.PLAY || runCatching { ServiceCompat.startForeground(this, 1001, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION) }.isSuccess)
        if (!inForeground) {
            started = false
            stopSelf()
            return START_NOT_STICKY
        }
        val interval = (application as AmmpApp).graph.work.policy.int("location.interval_seconds", 120) * 1000L
        LocationServices.getFusedLocationProviderClient(this).requestLocationUpdates(
            LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, interval).setMinUpdateIntervalMillis(interval / 2).build(),
            callback, Looper.getMainLooper())
        // First fix right away (the periodic ones only start after one interval), so admin sees the phone within seconds.
        @Suppress("MissingPermission")
        LocationServices.getFusedLocationProviderClient(this).getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
            .addOnSuccessListener { l -> if (l != null) callback.onLocationResult(LocationResult.create(listOf(l))) }
        return if (BuildConfig.PLAY) START_NOT_STICKY else START_STICKY
    }

    override fun onDestroy() {
        LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(callback)
        started = false   // Android stops a plain service after the app leaves the screen; onResumed() starts it again
        super.onDestroy()
    }

    companion object {
        fun start(ctx: Context) {
            val granted = ctx.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
                ctx.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
            // Android 12+ refuses a foreground-service start while the app is not in front (e.g. launched from the
            // debugger or still starting). Never let that break sign-in/sync; onResumed() retries once we are visible.
            if (granted) started = runCatching { Intent(ctx, LocationService::class.java).let { if (BuildConfig.PLAY) ctx.startService(it) else ctx.startForegroundService(it) } }.isSuccess
            // After an app restart `latest` is empty until a fresh fix arrives (up to a minute indoors): seed it with the
            // phone's last known position so "Nearest" and the map have a position at once; real fixes replace it.
            if (granted && latest.value == null) seedLastKnown(ctx)
        }
        @Suppress("MissingPermission")
        private fun seedLastKnown(ctx: Context) = runCatching {
            LocationServices.getFusedLocationProviderClient(ctx).lastLocation.addOnSuccessListener { l -> if (l != null && latest.value == null) latest.value = l }
        }
        @Volatile var started = false; private set
        /** Newest work-session fix (null until the first one). */
        val latest = kotlinx.coroutines.flow.MutableStateFlow<android.location.Location?>(null)
        fun stop(ctx: Context) { started = false; ctx.stopService(Intent(ctx, LocationService::class.java)) }
    }
}

/** One fresh GPS fix for sign-in (geofence check), or null without permission / within 8 s. */
@Suppress("MissingPermission")
suspend fun currentFix(ctx: Context): android.location.Location? {
    if (ctx.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED &&
        ctx.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) != PackageManager.PERMISSION_GRANTED) return null
    return kotlinx.coroutines.withTimeoutOrNull(8_000) {
        kotlinx.coroutines.suspendCancellableCoroutine<android.location.Location?> { cont ->
            LocationServices.getFusedLocationProviderClient(ctx).getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
                .addOnSuccessListener { if (cont.isActive) cont.resumeWith(Result.success(it)) }
                .addOnFailureListener { if (cont.isActive) cont.resumeWith(Result.success(null)) }
        }
    }
}
