package ametecs.managedmobile

import android.Manifest
import android.app.Activity
import android.content.Context
import android.media.projection.MediaProjectionManager
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import ametecs.managedmobile.ui.AmmpTheme
import ametecs.managedmobile.calls.CallSession
import ametecs.managedmobile.calls.SimCalls
import ametecs.managedmobile.ui.AppRoot
import ametecs.managedmobile.ui.AppViewModel

class MainActivity : ComponentActivity() {
    private val vm: AppViewModel by viewModels()

    // The OS MediaProjection consent dialog is always shown to the employee; it is never auto-approved.
    private val screenConsent = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        vm.mediaConsent(if (r.resultCode == Activity.RESULT_OK) r.data else null, "screen")
    }
    private val cameraConsent = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        vm.mediaConsent(if (granted) android.content.Intent() else null, "camera")   // non-null = permission granted
    }
    private val micConsent = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        vm.mediaConsent(if (granted) android.content.Intent() else null, "voice")
    }
    private val dialerRole = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { vm.onResumed() }
    private var askedOnce = false
    private val notifPermission = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        // Permanently denied (Android no longer shows its dialog): send the employee straight to this app's settings
        // instead of tapping a button that silently does nothing.
        val blocked = result.filter { (p, ok) -> !ok && p in SimCalls.required && !shouldShowRequestPermissionRationale(p) }
        if (askedOnce && blocked.isNotEmpty()) startActivity(android.content.Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
            android.net.Uri.fromParts("package", packageName, null)))
        askedOnce = true
        vm.onResumed()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(statusBarStyle = SystemBarStyle.dark(android.graphics.Color.TRANSPARENT))
        val prefs = getSharedPreferences("rovexy_ui", MODE_PRIVATE)
        val askAll = { notifPermission.launch(arrayOf(Manifest.permission.POST_NOTIFICATIONS, Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION) + SimCalls.required) }
        if (prefs.getBoolean("disclosure_ok", false)) askAll()
        handle(intent)
        setContent {
            AmmpTheme {
                // Play "prominent disclosure": shown before the first permission prompt; nothing is asked until Continue.
                var disclosed by remember { mutableStateOf(prefs.getBoolean("disclosure_ok", false)) }
                if (!disclosed) AlertDialog(onDismissRequest = {}, title = { Text("Before you start") },
                    text = { Text("ROVEXY is a work app managed by your employer. It collects:\n\n" +
                        "\u2022 Location \u2014 to show your position on your company's field map and verify customer visits, " +
                        "including while the app is closed or not in use during your work session. A ROVEXY notification is always shown while this is on.\n\n" +
                        "\u2022 Phone and call recordings \u2014 to place customer calls from your phone and upload the recording your phone's dialer makes of those calls.\n\n" +
                        "\u2022 Notifications \u2014 for messages and call requests from your supervisor.\n\n" +
                        "This data is sent only to your company's ROVEXY workspace. Camera, microphone and screen sharing are asked separately, each time.") },
                    confirmButton = { TextButton(onClick = { prefs.edit().putBoolean("disclosure_ok", true).apply(); disclosed = true; askAll() }) { Text("Continue") } },
                    dismissButton = { TextButton(onClick = { disclosed = true }) { Text("Not now") } })
                AppRoot(vm,
                    requestScreenConsent = { runCatching { screenConsent.launch(screenCaptureIntent()) }.onFailure { vm.mediaConsent(null, "screen") } },
                    requestCameraConsent = { runCatching { cameraConsent.launch(Manifest.permission.CAMERA) }.onFailure { vm.mediaConsent(null, "camera") } },
                    requestMicConsent = { runCatching { micConsent.launch(Manifest.permission.RECORD_AUDIO) }.onFailure { vm.mediaConsent(null, "voice") } },
                    requestCallPermissions = { if (prefs.getBoolean("disclosure_ok", false)) notifPermission.launch(SimCalls.required) else disclosed = false },
                    requestDialerRole = { CallSession.roleRequestIntent(this)?.let { runCatching { dialerRole.launch(it) } } })
            }
        }
    }

    /** Android 14+: offer ONLY "Entire screen". "A single app" opened an app picker and moved the employee into another
     *  app (looked like ROVEXY closing), and the supervisor then saw just that one app. */
    private fun screenCaptureIntent(): android.content.Intent {
        val mpm = getSystemService(Context.MEDIA_PROJECTION_SERVICE) as MediaProjectionManager
        return if (android.os.Build.VERSION.SDK_INT >= 34) mpm.createScreenCaptureIntent(android.media.projection.MediaProjectionConfig.createConfigForDefaultDisplay())
        else mpm.createScreenCaptureIntent()
    }

    // Buttons/taps on our notifications: Answer an internet call, open the chat.
    private fun handle(i: android.content.Intent?) {
        when (i?.action) {
            ametecs.managedmobile.media.MediaCoordinator.ACTION_ANSWER ->
                i.getStringExtra(ametecs.managedmobile.media.MediaCoordinator.EXTRA_ID)?.let { (application as AmmpApp).graph.media.answer(it) }
            AppViewModel.ACTION_OPEN_CHAT -> vm.openChat()
        }
        i?.action = null   // don't repeat on rotation
    }

    override fun onNewIntent(intent: android.content.Intent) {
        super.onNewIntent(intent)
        handle(intent)
    }

    override fun onResume() {
        super.onResume()
        vm.onResumed()   // back from the dialer: report the SIM call
    }
}
