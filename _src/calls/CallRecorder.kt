package ametecs.managedmobile.calls

import android.Manifest
import android.accessibilityservice.AccessibilityService
import android.app.Notification
import android.app.Service
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.media.MediaRecorder
import android.os.Build
import android.os.IBinder
import android.provider.Settings
import android.telephony.TelephonyManager
import android.view.accessibility.AccessibilityEvent
import androidx.core.app.ServiceCompat
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.R
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.io.File

/**
 * Does nothing but exist. Android lets an app whose accessibility service is switched on keep capturing audio during a
 * phone call (other apps get silence). It reads no screen content (canRetrieveWindowContent=false).
 */
class RecorderAccessibilityService : AccessibilityService() {
    override fun onServiceConnected() { connected = true }
    override fun onAccessibilityEvent(event: AccessibilityEvent?) {}
    override fun onInterrupt() {}
    override fun onUnbind(intent: Intent?): Boolean { connected = false; return super.onUnbind(intent) }
    override fun onDestroy() { connected = false; super.onDestroy() }
    companion object { @Volatile var connected = false; private set }
}

/**
 * Records one SIM call while a visible "Recording this call" notification is shown. Started by the app (in the
 * foreground) just before it dials; stops by itself when the phone goes back to idle after the call, or after 60 s if
 * the call never started. Writes 16 kHz mono WAV.
 *
 * Audio source: tries the call stream itself first (both sides, allowed on some phones), then the least-processed
 * microphone. While the call is connected it checks the captured audio; if a source only delivers silence (Android
 * mutes it, or an OEM noise gate zeroes it) it switches to the next one automatically. Platform noise suppression /
 * echo cancellation are switched off (they treat the customer's voice from the speaker as noise), and our own gain
 * control lifts the quiet phone-mic level.
 */
class CallRecordService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    @Volatile private var recording = false
    private var thread: Thread? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val n: Notification = Notification.Builder(this, AmmpApp.CHANNEL_WORK).setSmallIcon(R.drawable.ic_app)
            .setContentTitle("Recording this call").setContentText("Company call recording is on for this customer call.").setOngoing(true).build()
        ServiceCompat.startForeground(this, 1003, n, if (Build.VERSION.SDK_INT >= 30) ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE else 0)
        val path = intent?.getStringExtra("path")
        if (path == null || checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED || recording) {
            if (!recording) { lastError = if (path == null) "no output file" else "microphone permission missing"; finish(false) }
            return START_NOT_STICKY
        }
        val out = File(path)
        val first = openAll().ifEmpty { lastError = "Microphone could not be opened"; finish(false); return START_NOT_STICKY }
        recording = true; running = true; lastFile = out; lastError = null; speakerOn = null; lastInfo = null
        thread = Thread({ capture(first, out) }, "call-recorder").apply { start() }
        scope.launch { watchCall() }
        return START_NOT_STICKY
    }

    private class Opened(val record: android.media.AudioRecord, val index: Int, val effects: List<android.media.audiofx.AudioEffect>)

    private val sources: List<Pair<Int, String>> by lazy {
        val am = getSystemService(Context.AUDIO_SERVICE) as android.media.AudioManager
        val unprocessed = Build.VERSION.SDK_INT >= 24 && am.getProperty(android.media.AudioManager.PROPERTY_SUPPORT_AUDIO_SOURCE_UNPROCESSED) == "true"
        buildList {
            add(MediaRecorder.AudioSource.VOICE_CALL to "call")
            if (unprocessed) add(MediaRecorder.AudioSource.UNPROCESSED to "unprocessed")
            // Camcorder mic: usually a second microphone tuned for video, without the call echo canceller — so the
            // customer's voice from the loudspeaker is kept. Voice-recognition mic is last: OEM echo cancellation on it
            // removes the speaker audio (only the employee's side survives).
            add(MediaRecorder.AudioSource.CAMCORDER to "camcorder")
            add(MediaRecorder.AudioSource.MIC to "mic")
            add(MediaRecorder.AudioSource.VOICE_RECOGNITION to "voice_recognition")
        }
    }

    /** Opens source [idx] if this phone allows it (VOICE_CALL normally refuses without system privileges). */
    @android.annotation.SuppressLint("MissingPermission")
    private fun openOne(idx: Int): Opened? {
        val min = android.media.AudioRecord.getMinBufferSize(RATE, android.media.AudioFormat.CHANNEL_IN_MONO, android.media.AudioFormat.ENCODING_PCM_16BIT)
        val r = runCatching { android.media.AudioRecord(sources[idx].first, RATE, android.media.AudioFormat.CHANNEL_IN_MONO, android.media.AudioFormat.ENCODING_PCM_16BIT, maxOf(min, RATE) * 2) }.getOrNull()
        if (r != null && r.state == android.media.AudioRecord.STATE_INITIALIZED && runCatching { r.startRecording() }.isSuccess
            && r.recordingState == android.media.AudioRecord.RECORDSTATE_RECORDING) return Opened(r, idx, disableEffects(r.audioSessionId))
        runCatching { r?.release() }
        return null
    }

    /** Every source at once: OEMs gate different microphones during a call, so each one may carry a different side. */
    private fun openAll(): List<Opened> = sources.indices.mapNotNull { openOne(it) }

    private fun disableEffects(session: Int): List<android.media.audiofx.AudioEffect> = buildList {
        runCatching { if (android.media.audiofx.NoiseSuppressor.isAvailable()) android.media.audiofx.NoiseSuppressor.create(session)?.let { it.enabled = false; add(it) } }
        runCatching { if (android.media.audiofx.AcousticEchoCanceler.isAvailable()) android.media.audiofx.AcousticEchoCanceler.create(session)?.let { it.enabled = false; add(it) } }
        runCatching { if (android.media.audiofx.AutomaticGainControl.isAvailable()) android.media.audiofx.AutomaticGainControl.create(session)?.let { it.enabled = false; add(it) } }
    }

    private fun close(o: Opened) {
        o.effects.forEach { runCatching { it.release() } }
        runCatching { o.record.stop() }; runCatching { o.record.release() }
    }

    /**
     * PCM → WAV. All microphones that opened are captured together and mixed: on many phones one source carries the
     * employee (voice_recognition) and another the loudspeaker, i.e. the customer (camcorder / mic / unprocessed).
     * After 5 s of a connected call, a source that is ≥95 % exact zeros or near-silent is dropped so it adds no hiss.
     * Gain control on the mix: fast attack, slow release, up to 32x, hard limiter.
     */
    private fun capture(opened: List<Opened>, out: File) {
        class Src(val o: Opened) { val buf = ShortArray(RATE / 50); var n = 0L; var zeros = 0L; var sumSq = 0.0; var dropped = false
            fun db() = if (n > 0 && sumSq > 0) (20 * kotlin.math.log10(kotlin.math.sqrt(sumSq / n) / 32768)).toInt() else -99
            fun zp() = if (n > 0) 100 * zeros / n else 100 }
        val src = opened.map { Src(it) }
        val pacer = src.first()   // blocking reads on one source keep real-time pace; the others are read non-blocking
        val mix = IntArray(RATE / 50)
        val bytes = ByteArray(mix.size * 2)
        var gain = 6.0
        var total = 0L
        var connectedSamples = 0L; var checked = false
        java.io.RandomAccessFile(out, "rw").use { f ->
            f.setLength(0); f.write(ByteArray(44))
            while (recording) {
                val n = pacer.o.record.read(pacer.buf, 0, pacer.buf.size)
                if (n <= 0) { Thread.sleep(5); continue }
                val connected = CallSession.state.value == android.telecom.Call.STATE_ACTIVE || callOffhook
                java.util.Arrays.fill(mix, 0)
                for (s in src) {
                    if (s.dropped) continue
                    val got = if (s === pacer) n else maxOf(0, s.o.record.read(s.buf, 0, n, android.media.AudioRecord.READ_NON_BLOCKING))
                    for (i in 0 until got) {
                        val v = s.buf[i].toInt()
                        mix[i] += v
                        if (connected) { s.n++; if (v == 0) s.zeros++; s.sumSq += v.toDouble() * v }
                    }
                }
                if (connected) connectedSamples += n
                if (connected && !checked && connectedSamples >= RATE * 5) {
                    checked = true
                    val alive = src.filter { it.zp() < 95 && it.db() > -80 }
                    if (alive.isNotEmpty()) src.filter { it !in alive && it !== pacer }.forEach { it.dropped = true; close(it.o) }
                }
                var peak = 1
                for (i in 0 until n) peak = maxOf(peak, kotlin.math.abs(mix[i]))
                val want = minOf(32.0, 18_000.0 / maxOf(peak, 250))
                gain = if (want < gain) want else gain + (want - gain) * 0.008
                for (i in 0 until n) {
                    val v = (mix[i] * gain).toInt().coerceIn(-32767, 32767)
                    bytes[2 * i] = (v and 0xFF).toByte(); bytes[2 * i + 1] = (v shr 8 and 0xFF).toByte()
                }
                f.write(bytes, 0, n * 2); total += n * 2
            }
            f.seek(0); f.write(wavHeader(total))
        }
        src.filter { !it.dropped }.forEach { close(it.o) }
        lastInfo = "mixed " + src.joinToString(", ") { "${sources[it.o.index].second} ${it.db()} dBFS zeros ${it.zp()}%${if (it.dropped) " (dropped)" else ""}" } +
            ", call volume ${CallSession.volumeInfo ?: "n/a"}"
    }

    private fun wavHeader(dataLen: Long): ByteArray = java.nio.ByteBuffer.allocate(44).order(java.nio.ByteOrder.LITTLE_ENDIAN).apply {
        put("RIFF".toByteArray()); putInt((36 + dataLen).toInt()); put("WAVE".toByteArray())
        put("fmt ".toByteArray()); putInt(16); putShort(1); putShort(1); putInt(RATE); putInt(RATE * 2); putShort(2); putShort(16)
        put("data".toByteArray()); putInt(dataLen.toInt())
    }.array()

    @Suppress("DEPRECATION")
    private suspend fun watchCall() {
        val tm = getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager
        val am = getSystemService(Context.AUDIO_SERVICE) as android.media.AudioManager
        val started = System.currentTimeMillis()
        var sawCall = false
        while (true) {
            delay(700)
            val state = runCatching { tm.callState }.getOrDefault(TelephonyManager.CALL_STATE_IDLE)
            if (state == TelephonyManager.CALL_STATE_OFFHOOK && speakerOn == null && !CallSession.isOurs()) {
                // Only when another app is the phone app; our own InCallService switches the speaker itself (reliably).
                delay(1500)
                speakerOn = runCatching { am.isSpeakerphoneOn = true; am.isSpeakerphoneOn }.getOrDefault(false)
            }
            if (state != TelephonyManager.CALL_STATE_IDLE) sawCall = true
            callOffhook = state == TelephonyManager.CALL_STATE_OFFHOOK && !CallSession.isOurs()
            val age = System.currentTimeMillis() - started
            if ((sawCall && state == TelephonyManager.CALL_STATE_IDLE) || (!sawCall && age > 60_000) || age > 3 * 3_600_000) break
        }
        finish(sawCall)
    }

    private fun finish(ok: Boolean) {
        recording = false
        callOffhook = false
        runCatching { thread?.join(3000) }
        thread = null
        if (!ok) lastFile?.delete()
        running = false
        ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        recording = false
        runCatching { thread?.join(3000) }
        running = false
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        @Volatile var running = false; private set
        @Volatile var lastFile: File? = null; private set
        @Volatile var lastError: String? = null; private set
        /** Whether the speaker could be switched on for the last call (null = call never connected). */
        @Volatile var speakerOn: Boolean? = null
        /** Which audio source(s) were used and the raw input level — sent with the call for diagnosis. */
        @Volatile var lastInfo: String? = null; private set
        @Volatile private var callOffhook = false
        private const val RATE = 16_000

        /**
         * Is our recorder switch on? Trusts the running service first, then the system's own list, then the raw
         * setting (entries may be stored in full or short ".calls.X" form, so they are parsed, not string-compared).
         */
        fun accessibilityOn(ctx: Context): Boolean {
            if (RecorderAccessibilityService.connected) return true
            val cls = RecorderAccessibilityService::class.java.name
            val am = ctx.getSystemService(Context.ACCESSIBILITY_SERVICE) as android.view.accessibility.AccessibilityManager
            if (am.getEnabledAccessibilityServiceList(android.accessibilityservice.AccessibilityServiceInfo.FEEDBACK_ALL_MASK)
                    .any { it.resolveInfo?.serviceInfo?.let { si -> si.packageName == ctx.packageName && si.name == cls } == true }) return true
            val raw = Settings.Secure.getString(ctx.contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES).orEmpty()
            return raw.split(':').mapNotNull { ComponentName.unflattenFromString(it.trim()) }
                .any { it.packageName == ctx.packageName && it.className == cls }
        }

        fun openAccessibilitySettings(ctx: Context) =
            ctx.startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))

        /** Call while the app is in the foreground (Android only lets a foreground app start microphone capture). */
        fun start(ctx: Context, callId: String): File? {
            if (ctx.checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) return null
            val f = File(File(ctx.filesDir, "call-rec").apply { mkdirs() }, "$callId.wav")
            lastFile = f
            ctx.startForegroundService(Intent(ctx, CallRecordService::class.java).putExtra("path", f.path))
            return f
        }
    }
}
