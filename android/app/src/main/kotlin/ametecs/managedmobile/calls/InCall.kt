package ametecs.managedmobile.calls

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.role.RoleManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.telecom.Call
import android.telecom.CallAudioState
import android.telecom.InCallService
import android.telecom.TelecomManager
import android.telecom.VideoProfile
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.PathParser
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ametecs.managedmobile.MainActivity
import ametecs.managedmobile.ui.AmmpIcons as Glyph
import ametecs.managedmobile.R
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow

/**
 * This app as the phone's calling app (Android "default phone app" role): calls are placed and shown in our own
 * screen, and — unlike any other app — we control the audio route, so a recorded call switches to speaker and the
 * customer's side is captured. Incoming calls also show here (Answer / Decline) because Android routes every call to
 * the default phone app; emergency calls from the lock screen stay with the system.
 */
object CallSession {
    @Volatile var service: InCallService? = null
    val call = MutableStateFlow<Call?>(null)
    val state = MutableStateFlow(Call.STATE_NEW)
    val audio = MutableStateFlow<CallAudioState?>(null)
    /** Customer name for the call screen, and whether to go to speaker when connected (set for recorded company calls). */
    @Volatile var label: String? = null
    @Volatile var wantSpeaker = false
    /** Exotel click-to-call: the next incoming call from this number (within the window) is the bridge leg — answer it. */
    @Volatile private var expectFrom: String? = null
    @Volatile private var expectUntil = 0L

    fun expectCallback(from: String, windowMs: Long = 90_000) { expectFrom = from.filter(Char::isDigit).takeLast(10); expectUntil = System.currentTimeMillis() + windowMs }

    /** True once for the expected Exotel leg (last 10 digits match, inside the window). */
    fun takeExpected(c: Call): Boolean {
        val want = expectFrom ?: return false
        val got = c.details.handle?.schemeSpecificPart?.filter(Char::isDigit)?.takeLast(10) ?: return false
        if (System.currentTimeMillis() > expectUntil || got != want) return false
        expectFrom = null
        return true
    }

    private val callback = object : Call.Callback() {
        override fun onStateChanged(c: Call, s: Int) {
            state.value = s
            // Recorded calls go to speaker from the first ring, so the ringback tone and the whole conversation are captured.
            if (wantSpeaker && (s == Call.STATE_DIALING || s == Call.STATE_CONNECTING || s == Call.STATE_ACTIVE)) speaker(true)
            if (wantSpeaker && s == Call.STATE_ACTIVE) loud(true)
        }
    }

    fun isOurs() = service != null && call.value != null

    fun isDefaultDialer(ctx: Context): Boolean =
        (ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager).defaultDialerPackage == ctx.packageName

    fun roleRequestIntent(ctx: Context): Intent? =
        if (Build.VERSION.SDK_INT >= 29) (ctx.getSystemService(Context.ROLE_SERVICE) as RoleManager).createRequestRoleIntent(RoleManager.ROLE_DIALER)
        else Intent(TelecomManager.ACTION_CHANGE_DEFAULT_DIALER).putExtra(TelecomManager.EXTRA_CHANGE_DEFAULT_DIALER_PACKAGE_NAME, ctx.packageName)

    @Suppress("DEPRECATION")
    fun added(svc: InCallService, c: Call) {
        service = svc
        call.value?.unregisterCallback(callback)
        c.registerCallback(callback)
        volumeInfo = null
        call.value = c
        state.value = c.state
        if (wantSpeaker && c.state != Call.STATE_RINGING) speaker(true)
    }

    fun removed(c: Call) {
        c.unregisterCallback(callback)
        loud(false)
        if (call.value == c) { call.value = null; state.value = Call.STATE_DISCONNECTED; label = null; wantSpeaker = false }
    }

    fun speaker(on: Boolean) {
        val svc = service ?: return
        val wired = (audio.value?.supportedRouteMask ?: 0) and CallAudioState.ROUTE_WIRED_HEADSET != 0
        @Suppress("DEPRECATION")
        svc.setAudioRoute(if (on) CallAudioState.ROUTE_SPEAKER else if (wired) CallAudioState.ROUTE_WIRED_HEADSET else CallAudioState.ROUTE_EARPIECE)
    }

    /** Recorded calls: call volume to max on speaker, so the customer's voice reaches the microphones; restored after. */
    private var savedVolume: Int? = null
    @Volatile var volumeInfo: String? = null; private set
    fun loud(on: Boolean) {
        val am = service?.getSystemService(Context.AUDIO_SERVICE) as? android.media.AudioManager ?: return
        val s = android.media.AudioManager.STREAM_VOICE_CALL
        runCatching {
            if (on && savedVolume == null) { savedVolume = am.getStreamVolume(s); am.setStreamVolume(s, am.getStreamMaxVolume(s), 0); volumeInfo = "${am.getStreamVolume(s)}/${am.getStreamMaxVolume(s)}" }
            else if (!on) savedVolume?.let { am.setStreamVolume(s, it, 0); savedVolume = null }
        }.onFailure { volumeInfo = "could not raise" }
    }

    @Suppress("DEPRECATION")
    fun mute(on: Boolean) { service?.setMuted(on) }
}

class AmmpInCallService : InCallService() {
    override fun onCallAdded(call: Call) {
        CallSession.added(this, call)
        @Suppress("DEPRECATION")
        val ringing = call.state == Call.STATE_RINGING
        val bridge = ringing && CallSession.takeExpected(call)
        if (bridge) call.answer(VideoProfile.STATE_AUDIO_ONLY)   // company bridge call: connect straight to the customer
        val open = Intent(this, InCallActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        if (ringing && !bridge) notifyIncoming(call, open)   // full-screen intent works on the lock screen too
        runCatching { startActivity(open) }
    }

    override fun onCallRemoved(call: Call) {
        CallSession.removed(call)
        (getSystemService(NOTIFICATION_SERVICE) as NotificationManager).cancel(NOTIF_ID)
    }

    @Deprecated("Deprecated in Java")
    override fun onCallAudioStateChanged(audioState: CallAudioState) {
        CallSession.audio.value = audioState
        if (CallSession.wantSpeaker) CallRecordService.speakerOn = audioState.route == CallAudioState.ROUTE_SPEAKER
    }

    override fun onUnbind(intent: Intent?): Boolean {
        CallSession.service = null
        return super.onUnbind(intent)
    }

    private fun notifyIncoming(call: Call, open: Intent) {
        val nm = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "Incoming calls", NotificationManager.IMPORTANCE_HIGH))
        val pi = PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        nm.notify(NOTIF_ID, Notification.Builder(this, CHANNEL).setSmallIcon(R.drawable.ic_app).setContentTitle("Incoming call")
            .setContentText(call.details.handle?.schemeSpecificPart ?: "Unknown number").setCategory(Notification.CATEGORY_CALL)
            .setFullScreenIntent(pi, true).setContentIntent(pi).setOngoing(true).build())
    }

    companion object { const val CHANNEL = "incoming_calls"; const val NOTIF_ID = 1004 }
}

private val Ink = Color(0xFF0A1626)
private val Navy = Color(0xFF1F3A5F)
private val Gold = Color(0xFFC9A35E)
private val GoldSoft = Color(0xFFE8D3A6)
private val Muted = Color(0xFF9FB0C4)
private val Red = Color(0xFFE5484D)
private val Green = Color(0xFF22A06B)
private val Bg = Brush.verticalGradient(listOf(Color(0xFF07111F), Color(0xFF13294A), Color(0xFF0B1A2E)))

class InCallActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 27) { setShowWhenLocked(true); setTurnScreenOn(true) }
        else @Suppress("DEPRECATION") window.addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON)
        window.statusBarColor = 0xFF07111F.toInt(); window.navigationBarColor = 0xFF0B1A2E.toInt()
        setContent { MaterialTheme { CallScreen(onDone = ::done) } }
    }

    /** Call finished: back to the app, which then saves the call (duration + recording) and asks for the outcome. */
    private fun done() {
        startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT))
        finish()
    }
}

private fun initials(name: String) = name.split(" ").filter { it.firstOrNull()?.isLetter() == true }.take(2).joinToString("") { it.take(1).uppercase() }.ifEmpty { "#" }
private fun mmss(s: Int) = if (s >= 3600) "%d:%02d:%02d".format(s / 3600, s / 60 % 60, s % 60) else "%02d:%02d".format(s / 60, s % 60)

@Suppress("DEPRECATION")
@Composable
private fun CallScreen(onDone: () -> Unit) {
    val call by CallSession.call.collectAsState()
    val state by CallSession.state.collectAsState()
    val audio by CallSession.audio.collectAsState()
    var muted by remember { mutableStateOf(false) }
    var held by remember { mutableStateOf(false) }
    var keypad by remember { mutableStateOf(false) }
    var dtmf by remember { mutableStateOf("") }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(call) { if (call == null) { delay(700); onDone() } }
    LaunchedEffect(Unit) { while (true) { delay(1000); now = System.currentTimeMillis() } }
    val c = call
    val number = c?.details?.handle?.schemeSpecificPart.orEmpty()
    val name = CallSession.label ?: number.ifEmpty { "Unknown" }
    val connectAt = c?.details?.connectTimeMillis ?: 0L
    val secs = if (state == Call.STATE_ACTIVE && connectAt > 0) ((now - connectAt) / 1000).toInt() else 0
    val waiting = state in setOf(Call.STATE_NEW, Call.STATE_DIALING, Call.STATE_CONNECTING, Call.STATE_RINGING)
    val status = when (state) {
        Call.STATE_DIALING, Call.STATE_CONNECTING, Call.STATE_NEW -> "Calling…"
        Call.STATE_RINGING -> "Incoming call"
        Call.STATE_ACTIVE -> mmss(secs)
        Call.STATE_HOLDING -> "On hold"
        else -> "Call ended"
    }
    val speakerOn = audio?.route == CallAudioState.ROUTE_SPEAKER

    Box(Modifier.fillMaxSize().background(Bg)) {
        // soft gold glow behind the avatar
        Box(Modifier.align(Alignment.TopCenter).padding(top = 110.dp).size(320.dp)
            .background(Brush.radialGradient(listOf(Gold.copy(alpha = 0.16f), Color.Transparent)), CircleShape))
        Column(Modifier.fillMaxSize().padding(horizontal = 24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Spacer(Modifier.height(48.dp))
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("COMPANY CALL", color = Gold, fontSize = 12.sp, letterSpacing = 2.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                if (CallSession.wantSpeaker && state != Call.STATE_RINGING) RecBadge(secs)
            }
            Spacer(Modifier.height(if (keypad) 24.dp else 56.dp))
            if (!keypad) Avatar(initials(name), pulsing = waiting)
            Spacer(Modifier.height(24.dp))
            Text(name, color = Color.White, fontSize = if (keypad) 22.sp else 30.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
            if (CallSession.label != null && number.isNotEmpty()) Text(number, color = Muted, fontSize = 15.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(10.dp))
            Box(Modifier.background(Color.White.copy(alpha = 0.07f), RoundedCornerShape(50)).padding(horizontal = 14.dp, vertical = 6.dp)) {
                Text(status, color = if (state == Call.STATE_ACTIVE) GoldSoft else Muted, fontSize = 16.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.5.sp)
            }
            if (keypad) {
                Spacer(Modifier.height(16.dp))
                Text(dtmf.ifEmpty { " " }, color = Color.White, fontSize = 28.sp, letterSpacing = 3.sp, maxLines = 1)
                Spacer(Modifier.height(8.dp))
                DialPad(compact = true) { k -> dtmf += k; c?.playDtmfTone(k); c?.stopDtmfTone() }
            }
            Spacer(Modifier.weight(1f))
            if (state == Call.STATE_RINGING) {
                Row(Modifier.fillMaxWidth().padding(bottom = 64.dp), horizontalArrangement = Arrangement.SpaceEvenly) {
                    BigButton("Decline", Red, rotate = 135f) { c?.reject(false, null) }
                    BigButton("Answer", Green, pulse = true) { c?.answer(VideoProfile.STATE_AUDIO_ONLY) }
                }
            } else {
                Column(Modifier.fillMaxWidth().background(Color.White.copy(alpha = 0.05f), RoundedCornerShape(32.dp)).padding(vertical = 20.dp),
                    horizontalAlignment = Alignment.CenterHorizontally) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                        Action(if (muted) Glyph.MicOff else Glyph.Mic, if (muted) "Unmute" else "Mute", muted) { muted = !muted; CallSession.mute(muted) }
                        Action(Glyph.Keypad, "Keypad", keypad) { keypad = !keypad }
                        Action(Glyph.Speaker, "Speaker", speakerOn) { CallSession.speaker(!speakerOn) }
                        Action(Glyph.Pause, if (held) "Resume" else "Hold", held, enabled = state == Call.STATE_ACTIVE || state == Call.STATE_HOLDING) {
                            if (held) c?.unhold() else c?.hold(); held = !held
                        }
                    }
                    Spacer(Modifier.height(24.dp))
                    BigButton(null, Red, rotate = 135f) { c?.disconnect() }
                }
                Spacer(Modifier.height(28.dp))
            }
        }
    }
}

@Composable
private fun Avatar(text: String, pulsing: Boolean) {
    val t = rememberInfiniteTransition(label = "pulse")
    val p by t.animateFloat(0f, 1f, infiniteRepeatable(tween(1800, easing = LinearEasing)), label = "p")
    Box(Modifier.size(180.dp), contentAlignment = Alignment.Center) {
        if (pulsing) for (k in 0..1) {
            val f = (p + k * 0.5f) % 1f
            Box(Modifier.size(124.dp).graphicsLayer { scaleX = 1f + f * 0.45f; scaleY = 1f + f * 0.45f; alpha = (1f - f) * 0.5f }
                .border(2.dp, Gold, CircleShape))
        }
        Box(Modifier.size(124.dp).background(Brush.linearGradient(listOf(Color(0xFFE8D3A6), Gold, Color(0xFF8C6A2E))), CircleShape).padding(4.dp)
            .background(Brush.linearGradient(listOf(Color(0xFF2A4A75), Navy)), CircleShape), contentAlignment = Alignment.Center) {
            Text(text, color = Color.White, fontSize = 42.sp, fontWeight = FontWeight.Light, letterSpacing = 1.sp)
        }
    }
}

@Composable
private fun RecBadge(secs: Int) {
    val t = rememberInfiniteTransition(label = "rec")
    val a by t.animateFloat(1f, 0.25f, infiniteRepeatable(tween(800), RepeatMode.Reverse), label = "a")
    Row(Modifier.background(Red.copy(alpha = 0.16f), RoundedCornerShape(50)).border(1.dp, Red.copy(alpha = 0.5f), RoundedCornerShape(50))
        .padding(horizontal = 10.dp, vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(8.dp).graphicsLayer { alpha = a }.background(Red, CircleShape))
        Spacer(Modifier.width(6.dp))
        Text("REC" + if (secs > 0) "  " + mmss(secs) else "", color = Color(0xFFFFC9C9), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 1.sp)
    }
}

@Composable
private fun Action(icon: ImageVector, label: String, on: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.graphicsLayer { alpha = if (enabled) 1f else 0.35f }) {
        Box(Modifier.size(62.dp).background(if (on) Color.White else Color.White.copy(alpha = 0.10f), CircleShape)
            .border(1.dp, Color.White.copy(alpha = if (on) 0f else 0.12f), CircleShape)
            .clickable(enabled = enabled, onClick = onClick), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = label, tint = if (on) Navy else Color.White, modifier = Modifier.size(26.dp))
        }
        Text(label, color = Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
    }
}

@Composable
private fun BigButton(label: String?, color: Color, rotate: Float = 0f, pulse: Boolean = false, onClick: () -> Unit) {
    val t = rememberInfiniteTransition(label = "big")
    val s by t.animateFloat(1f, if (pulse) 1.08f else 1f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "s")
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(76.dp).graphicsLayer { scaleX = s; scaleY = s }.shadow(16.dp, CircleShape, spotColor = color)
            .background(Brush.verticalGradient(listOf(color.copy(alpha = 0.92f), color)), CircleShape).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
            Icon(Glyph.Call, contentDescription = label ?: "End call", tint = Color.White, modifier = Modifier.size(32.dp).graphicsLayer { rotationZ = rotate })
        }
        if (label != null) Text(label, color = Color.White, fontSize = 14.sp, modifier = Modifier.padding(top = 10.dp))
    }
}

private val KEYS = listOf("1" to "", "2" to "ABC", "3" to "DEF", "4" to "GHI", "5" to "JKL", "6" to "MNO", "7" to "PQRS", "8" to "TUV", "9" to "WXYZ", "*" to "", "0" to "+", "#" to "")

@Composable
private fun DialPad(compact: Boolean = false, onKey: (Char) -> Unit) {
    val size = if (compact) 64.dp else 76.dp
    Column(verticalArrangement = Arrangement.spacedBy(if (compact) 10.dp else 14.dp)) {
        KEYS.chunked(3).forEach { row ->
            Row(horizontalArrangement = Arrangement.spacedBy(if (compact) 22.dp else 26.dp)) {
                row.forEach { (k, sub) ->
                    Box(Modifier.size(size).background(Color.White.copy(alpha = 0.08f), CircleShape).clickable { onKey(k[0]) }, contentAlignment = Alignment.Center) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Text(k, color = Color.White, fontSize = if (compact) 24.sp else 30.sp, fontWeight = FontWeight.Light)
                            if (sub.isNotEmpty()) Text(sub, color = Muted, fontSize = 9.sp, letterSpacing = 1.5.sp)
                        }
                    }
                }
            }
        }
    }
}

/**
 * Dial pad. Android requires the default phone app to accept "dial" requests (a tapped phone number, the
 * emergency button). Company work calls go through the customer list; this only places the number shown.
 */
class DialerActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = 0xFF07111F.toInt(); window.navigationBarColor = 0xFF0B1A2E.toInt()
        val initial = intent?.data?.schemeSpecificPart.orEmpty().filter { it.isDigit() || it in "+*#" }
        setContent {
            MaterialTheme {
                var number by remember { mutableStateOf(initial) }
                Column(Modifier.fillMaxSize().background(Bg).padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Spacer(Modifier.height(32.dp))
                    Text("COMPANY PHONE", color = Gold, fontSize = 12.sp, letterSpacing = 2.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.weight(1f))
                    Row(Modifier.fillMaxWidth().height(64.dp), verticalAlignment = Alignment.CenterVertically) {
                        Spacer(Modifier.width(48.dp))
                        Text(number.ifEmpty { "Enter number" }, color = if (number.isEmpty()) Muted.copy(alpha = 0.5f) else Color.White,
                            fontSize = if (number.length > 12) 28.sp else 36.sp, fontWeight = FontWeight.Light, letterSpacing = 1.sp,
                            maxLines = 1, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
                        Box(Modifier.size(48.dp).clickable(enabled = number.isNotEmpty()) { number = number.dropLast(1) }, contentAlignment = Alignment.Center) {
                            if (number.isNotEmpty()) Icon(Glyph.Back, contentDescription = "Delete", tint = Muted, modifier = Modifier.size(24.dp))
                        }
                    }
                    Spacer(Modifier.height(24.dp))
                    DialPad { k -> if (number.length < 20) number += k }
                    Spacer(Modifier.height(28.dp))
                    Box(Modifier.size(76.dp).shadow(16.dp, CircleShape, spotColor = Green).background(if (number.isBlank()) Green.copy(alpha = 0.4f) else Green, CircleShape)
                        .clickable(enabled = number.isNotBlank()) { place(number) }, contentAlignment = Alignment.Center) {
                        Icon(Glyph.Call, contentDescription = "Call", tint = Color.White, modifier = Modifier.size(32.dp))
                    }
                    Spacer(Modifier.height(40.dp))
                }
            }
        }
    }

    @SuppressLint("MissingPermission")
    private fun place(n: String) {
        if (checkSelfPermission(Manifest.permission.CALL_PHONE) == android.content.pm.PackageManager.PERMISSION_GRANTED) {
            (getSystemService(TELECOM_SERVICE) as TelecomManager).placeCall(Uri.fromParts("tel", n, null), Bundle())
            finish()
        }
    }
}
