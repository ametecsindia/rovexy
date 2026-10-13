package ametecs.managedmobile.calls

import android.Manifest
import android.annotation.SuppressLint
import android.content.ContentUris
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.CallLog
import android.provider.MediaStore
import android.telecom.TelecomManager
import androidx.core.content.ContextCompat

/**
 * SIM calling route: the phone dials from its own SIM. Afterwards the app reads the duration from the system call log
 * and looks for the file written by the phone's OWN call recorder (MIUI/HyperOS, One UI, ColorOS/OxygenOS, Funtouch…).
 * The app never records audio itself; if the dialer's auto-record is off (or the OEM keeps recordings private, e.g. Pixel),
 * nothing is found and the server marks the recording Failed with that reason.
 */
object SimCalls {
    data class LogEntry(val talkSeconds: Int, val startedAtMs: Long)
    data class RecorderFile(val uri: Uri, val name: String, val mime: String?, val durationSeconds: Int?, val size: Long)

    val audioPermission = if (Build.VERSION.SDK_INT >= 33) Manifest.permission.READ_MEDIA_AUDIO else Manifest.permission.READ_EXTERNAL_STORAGE
    /** Permanently true: the phone's dialer (ODialer where supported, else the default Phone app) places every SIM call and its recorder's file is uploaded. Smart caller's own dialer is retired. */
    @Volatile var phoneRecorder = true

    val required = arrayOf(Manifest.permission.CALL_PHONE, Manifest.permission.READ_CALL_LOG, Manifest.permission.READ_PHONE_STATE, audioPermission, Manifest.permission.RECORD_AUDIO)

    fun missing(ctx: Context) = required.filter { !(phoneRecorder && it == Manifest.permission.RECORD_AUDIO) && ContextCompat.checkSelfPermission(ctx, it) != PackageManager.PERMISSION_GRANTED }

    /** Phone-recorder mode uses OPPO/OnePlus/Realme ODialer — its built-in recorder captures both voices. */
    const val ODIALER = "com.oplus.dialer"
    private fun defaultDialer(ctx: Context) = (ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager).defaultDialerPackage
    fun odialerInstalled(ctx: Context) = runCatching { ctx.packageManager.getPackageInfo(ODIALER, 0) }.isSuccess

    /** ODialer runs only on OPPO/OnePlus/Realme; on other brands Play says "Your device isn't compatible".
     *  ponytail: brand list, not a Play query — an app can't ask Play about compatibility. Add a brand if ODialer ships there. */
    fun odialerSupported(ctx: Context) = odialerInstalled(ctx) || Build.MANUFACTURER.lowercase() in setOf("oppo", "oneplus", "realme")

    /** ODialer phones: ODialer must be the default Phone app. Other brands (Samsung, Xiaomi, Vivo, Pixel…): whatever Phone app
     *  is default places the call and ITS recorder's file is uploaded — only "no default Phone app at all" blocks. */
    fun ownDialerBlocking(ctx: Context) = phoneRecorder &&
        if (odialerSupported(ctx)) defaultDialer(ctx) != ODIALER else defaultDialer(ctx).isNullOrEmpty()

    /** Next setup step: Play Store page if ODialer is supported but missing, else Android's default-apps screen.
     *  ponytail: a normal app can't silently install or set another app as default — needs device-owner (AMAPI) enrolment. */
    fun openDefaultApps(ctx: Context) {
        val i = if (odialerSupported(ctx) && !odialerInstalled(ctx))
            Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=$ODIALER")).takeIf { it.resolveActivity(ctx.packageManager) != null }
                ?: Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/apps/details?id=$ODIALER"))
        else Intent(android.provider.Settings.ACTION_MANAGE_DEFAULT_APPS_SETTINGS)
        ctx.startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    /** ACTION_CALL places the call directly; without CALL_PHONE fall back to the dialer with the number filled in. */
    fun dial(ctx: Context, number: String) {
        if (!phoneRecorder && CallSession.isDefaultDialer(ctx)) {   // our own call screen: no other app opens
            @SuppressLint("MissingPermission")
            fun place() = (ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager).placeCall(Uri.fromParts("tel", number, null), android.os.Bundle())
            if (runCatching { place() }.isSuccess) return
        }
        val granted = ContextCompat.checkSelfPermission(ctx, Manifest.permission.CALL_PHONE) == PackageManager.PERMISSION_GRANTED
        ctx.startActivity(Intent(if (granted) Intent.ACTION_CALL else Intent.ACTION_DIAL, Uri.fromParts("tel", number, null)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    /** Off-hook or ringing, per Telecom or TelephonyManager (both need READ_PHONE_STATE; false if unknown). */
    @SuppressLint("MissingPermission")
    @Suppress("DEPRECATION")
    fun inCall(ctx: Context): Boolean =
        runCatching { (ctx.getSystemService(Context.TELECOM_SERVICE) as TelecomManager).isInCall }.getOrDefault(false) ||
            runCatching { (ctx.getSystemService(Context.TELEPHONY_SERVICE) as android.telephony.TelephonyManager).callState != android.telephony.TelephonyManager.CALL_STATE_IDLE }.getOrDefault(false)

    /** Permission names the employee still has to allow, in plain words. */
    fun missingLabels(ctx: Context) = missing(ctx).map {
        when (it) { Manifest.permission.CALL_PHONE, Manifest.permission.READ_PHONE_STATE -> "Phone"; Manifest.permission.READ_CALL_LOG -> "Call logs"
            Manifest.permission.RECORD_AUDIO -> "Microphone"; else -> "Music and audio" }
    }.distinct() + when {
        phoneRecorder && !odialerSupported(ctx) -> if (defaultDialer(ctx).isNullOrEmpty()) listOf("Choose a default Phone app") else emptyList()
        phoneRecorder -> when { !odialerInstalled(ctx) -> listOf("Install ODialer"); defaultDialer(ctx) != ODIALER -> listOf("Set ODialer as default Phone app"); else -> emptyList() }
        else -> (if (CallSession.isDefaultDialer(ctx)) emptyList() else listOf("Default phone app")) +
            (if (CallRecordService.accessibilityOn(ctx)) emptyList() else listOf("Company call recorder (Accessibility)"))
    }

    /** Folders of audio files added since [sinceMs] — sent with the result so an admin can see where this phone's recorder saves. */
    fun recentAudioFolders(ctx: Context, sinceMs: Long): List<String> {
        if (ContextCompat.checkSelfPermission(ctx, audioPermission) != PackageManager.PERMISSION_GRANTED || Build.VERSION.SDK_INT < 29) return emptyList()
        return runCatching {
            ctx.contentResolver.query(MediaStore.Audio.Media.EXTERNAL_CONTENT_URI, arrayOf(MediaStore.Audio.Media.RELATIVE_PATH),
                "${MediaStore.Audio.Media.DATE_ADDED} >= ?", arrayOf(((sinceMs - 5_000) / 1000).toString()), null)?.use { c ->
                buildList { while (c.moveToNext()) add(c.getString(0).orEmpty()) }.distinct().take(5)
            } ?: emptyList()
        }.getOrDefault(emptyList())
    }

    private fun last10(n: String) = n.filter(Char::isDigit).takeLast(10)

    /** Newest outgoing call-log entry to [number] since [sinceMs]. DURATION is talk time (0 = not answered). */
    fun callLog(ctx: Context, number: String, sinceMs: Long): LogEntry? {
        if (ContextCompat.checkSelfPermission(ctx, Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) return null
        val want = last10(number)
        ctx.contentResolver.query(CallLog.Calls.CONTENT_URI, arrayOf(CallLog.Calls.NUMBER, CallLog.Calls.DURATION, CallLog.Calls.DATE),
            "${CallLog.Calls.TYPE} = ? AND ${CallLog.Calls.DATE} >= ?", arrayOf(CallLog.Calls.OUTGOING_TYPE.toString(), (sinceMs - 15_000).toString()),
            "${CallLog.Calls.DATE} DESC")?.use { c ->
            while (c.moveToNext()) {
                if (last10(c.getString(0).orEmpty()) == want) return LogEntry(c.getInt(1), c.getLong(2))
            }
        }
        return null
    }

    /** Newest audio file created since the call started that looks like a call recording of this number. */
    fun findRecording(ctx: Context, number: String, sinceMs: Long): RecorderFile? {
        if (ContextCompat.checkSelfPermission(ctx, audioPermission) != PackageManager.PERMISSION_GRANTED) return null
        val digits = number.filter(Char::isDigit).takeLast(7)
        val base = MediaStore.Audio.Media.EXTERNAL_CONTENT_URI
        val cols = mutableListOf(MediaStore.Audio.Media._ID, MediaStore.Audio.Media.DISPLAY_NAME, MediaStore.Audio.Media.MIME_TYPE,
            MediaStore.Audio.Media.DURATION, MediaStore.Audio.Media.SIZE, MediaStore.Audio.Media.DATA)
        if (Build.VERSION.SDK_INT >= 29) cols += MediaStore.Audio.Media.RELATIVE_PATH
        ctx.contentResolver.query(base, cols.toTypedArray(), "${MediaStore.Audio.Media.DATE_ADDED} >= ?", arrayOf(((sinceMs - 5_000) / 1000).toString()),
            "${MediaStore.Audio.Media.DATE_ADDED} DESC")?.use { c ->
            while (c.moveToNext()) {
                val name = c.getString(1).orEmpty()
                val where = (c.getString(5).orEmpty() + "/" + (if (cols.size > 6) c.getString(6).orEmpty() else "")).lowercase()
                // OEM folders: MIUI/sound_recorder/call_rec, Recordings/Call, Music/Recordings/Call Recordings, Record/Call …
                val looksLikeCall = where.contains("call") || name.lowercase().contains("call") || name.filter(Char::isDigit).contains(digits)
                if (looksLikeCall && c.getLong(4) > 0) {
                    return RecorderFile(ContentUris.withAppendedId(base, c.getLong(0)), name, c.getString(2), (c.getLong(3) / 1000).toInt().takeIf { it > 0 }, c.getLong(4))
                }
            }
        }
        return null
    }

    /** Time (ms) of the newest call-recorder file — any number — added after [sinceMs], or null.
     *  Android gives apps no way to read another app's "auto record calls" switch, so this file IS the proof it is on. */
    fun latestCallRecordingMs(ctx: Context, sinceMs: Long): Long? {
        if (ContextCompat.checkSelfPermission(ctx, audioPermission) != PackageManager.PERMISSION_GRANTED) return null
        val cols = mutableListOf(MediaStore.Audio.Media.DISPLAY_NAME, MediaStore.Audio.Media.DATE_ADDED, MediaStore.Audio.Media.SIZE, MediaStore.Audio.Media.DATA)
        if (Build.VERSION.SDK_INT >= 29) cols += MediaStore.Audio.Media.RELATIVE_PATH
        return runCatching {
            ctx.contentResolver.query(MediaStore.Audio.Media.EXTERNAL_CONTENT_URI, cols.toTypedArray(), "${MediaStore.Audio.Media.DATE_ADDED} >= ?",
                arrayOf((sinceMs / 1000).toString()), "${MediaStore.Audio.Media.DATE_ADDED} DESC")?.use { c ->
                while (c.moveToNext()) {
                    val where = (c.getString(3).orEmpty() + "/" + (if (cols.size > 4) c.getString(4).orEmpty() else "") + "/" + c.getString(0).orEmpty()).lowercase()
                    if (where.contains("call") && c.getLong(2) > 0) return@use c.getLong(1) * 1000
                }
                null
            }
        }.getOrNull()
    }

    /** The default Phone app's own call settings (where "Record calls → Auto record" lives); default-apps screen as a fallback. */
    fun openCallSettings(ctx: Context) {
        val ok = runCatching { ctx.startActivity(Intent(TelecomManager.ACTION_SHOW_CALL_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
        if (!ok) ctx.startActivity(Intent(android.provider.Settings.ACTION_MANAGE_DEFAULT_APPS_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    fun read(ctx: Context, f: RecorderFile): ByteArray? = runCatching { ctx.contentResolver.openInputStream(f.uri)?.use { it.readBytes() } }.getOrNull()
}
