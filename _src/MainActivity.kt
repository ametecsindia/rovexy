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
        notifPermission.launch(arrayOf(Manifest.permission.POST_NOTIFICATIONS, Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION) + SimCalls.required)
        handle(intent)
        setContent {
            AmmpTheme {
                AppRoot(vm,
                    requestScreenConsent = { screenConsent.launch((getSystemService(Context.MEDIA_PROJECTION_SERVICE) as MediaProjectionManager).createScreenCaptureIntent()) },
                    requestCameraConsent = { cameraConsent.launch(Manifest.permission.CAMERA) },
                    requestMicConsent = { micConsent.launch(Manifest.permission.RECORD_AUDIO) },
                    requestCallPermissions = { notifPermission.launch(SimCalls.required) },
                    requestDialerRole = { CallSession.roleRequestIntent(this)?.let { runCatching { dialerRole.launch(it) } } })
            }
        }
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
