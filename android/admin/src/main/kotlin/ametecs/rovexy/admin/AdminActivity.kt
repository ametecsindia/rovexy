package ametecs.rovexy.admin

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.ContentValues
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import android.print.PrintAttributes
import android.print.PrintManager
import android.provider.MediaStore
import android.util.Base64
import android.view.View
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.addCallback
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import ametecs.managedmobile.core.ServerUrl

/**
 * ROVEXY Admin = the company admin console (React, served by the client's own server) in a WebView.
 * The console itself is responsive; this shell only adds what a browser tab would: sign-in cookies, file pick,
 * downloads to Downloads, print/PDF, back button, and opening other sites (maps, tel:, mailto:) in their own apps.
 * ponytail: no native screens — every console change ships with the server deploy, not with an app update.
 */
class AdminActivity : ComponentActivity() {
    private val prefs by lazy { getSharedPreferences("admin", MODE_PRIVATE) }
    private var web: WebView? = null
    private var origin = ""
    private var filePick: ValueCallback<Array<Uri>>? = null
    private val picker = registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        filePick?.onReceiveValue(uri?.let { arrayOf(it) }); filePick = null   // ponytail: the console has no multi-file inputs
    }
    /** Internet calls from Messages: the console asks for the mic, Android asks the admin once, then the WebView gets it. */
    private var micAsk: PermissionRequest? = null
    private val micPerm = registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        micAsk?.let { if (ok) it.grant(arrayOf(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) else { it.deny(); micOff() } }; micAsk = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(SystemBarStyle.light(Color.WHITE, Color.BLACK), SystemBarStyle.light(Color.WHITE, Color.BLACK))
        onBackPressedDispatcher.addCallback(this) {
            val w = web
            if (w != null && w.canGoBack()) w.goBack() else finish()
        }
        val url = prefs.getString("console", null)
        if (url == null) showSetup(null) else showConsole(url, savedInstanceState)
    }

    private fun padForBars(v: View) {
        ViewCompat.setOnApplyWindowInsetsListener(v) { view, insets ->
            val b = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime())
            view.setPadding(b.left, b.top, b.right, b.bottom); insets
        }
    }

    private fun showSetup(error: String?) {
        web?.destroy(); web = null
        setContentView(R.layout.setup)
        val root = findViewById<View>(android.R.id.content); padForBars(root); root.setBackgroundColor(0xFFF4F6FA.toInt())
        val server = findViewById<EditText>(R.id.server)
        val ws = findViewById<EditText>(R.id.workspace)
        val err = findViewById<TextView>(R.id.error)
        server.setText(prefs.getString("server", BuildConfig.DEFAULT_SERVER_URL))
        ws.setText(prefs.getString("workspace", ""))
        fun fail(m: String) { err.text = m; err.visibility = View.VISIBLE }
        error?.let(::fail)
        findViewById<Button>(R.id.open).setOnClickListener {
            val o = try { ServerUrl.normalise(server.text.toString(), BuildConfig.ALLOW_LOCAL_HTTP) } catch (e: IllegalArgumentException) { fail(e.message ?: "Enter a valid server address"); return@setOnClickListener }
            val w = ws.text.toString().trim().lowercase()
            if (w.isNotEmpty() && !Regex("[a-z0-9][a-z0-9-]{1,38}[a-z0-9]").matches(w)) { fail("The workspace has only letters, numbers and dashes"); return@setOnClickListener }
            val console = if (w.isEmpty()) "$o/admin/" else "$o/w/$w/admin/"
            prefs.edit().putString("server", o).putString("workspace", w).putString("console", console).apply()
            showConsole(console, null)
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun showConsole(url: String, state: Bundle?) {
        origin = Uri.parse(url).let { "${it.scheme}://${it.authority}" }
        CookieManager.getInstance().setAcceptCookie(true)
        val w = WebView(this)
        web = w
        setContentView(w)
        padForBars(w)
        w.setBackgroundColor(Color.WHITE)
        w.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true          // org filter, menu state (localStorage)
            mediaPlaybackRequiresUserGesture = false   // recordings play on tap; live screen/camera views autoplay
            allowFileAccess = false
            allowContentAccess = false
            userAgentString = "$userAgentString RovexyAdmin/${BuildConfig.VERSION_NAME}"
            textZoom = (resources.configuration.fontScale * 100).toInt().coerceIn(85, 115)   // ponytail: >115 % breaks console cards; Android zoom still works
        }
        w.addJavascriptInterface(Bridge(), "RovexyApp")
        w.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, req: WebResourceRequest): Boolean {
                val u = req.url
                if ((u.scheme == "https" || u.scheme == "http") && "${u.scheme}://${u.authority}" == origin) return false
                openOutside(u); return true   // Google Maps directions, OpenStreetMap, tel:, mailto:, payment pages
            }
            override fun onPageFinished(view: WebView, u: String) {
                // The console's print pages call window.print(), which a WebView ignores: hand it to Android's print/PDF.
                view.evaluateJavascript("window.print=function(){RovexyApp.print()}", null)
            }
            override fun onReceivedError(view: WebView, req: WebResourceRequest, error: WebResourceError) {
                if (req.isForMainFrame) view.loadDataWithBaseURL(null, offlinePage(error.description.toString()), "text/html", "utf-8", null)
            }
        }
        w.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(v: WebView, cb: ValueCallback<Array<Uri>>, p: FileChooserParams): Boolean {
                filePick?.onReceiveValue(null)
                filePick = cb
                // ponytail: any file type — the console checks .csv/.xlsx/.zip itself, and Android maps those extensions badly.
                return try { picker.launch("*/*"); true } catch (e: ActivityNotFoundException) { filePick = null; false }
            }
            override fun onPermissionRequest(req: PermissionRequest) {
                // Only the company's own console (same host), and only the microphone (camera/screen views are receive-only).
                val audio = PermissionRequest.RESOURCE_AUDIO_CAPTURE
                if (audio !in req.resources || !req.origin.host.equals(Uri.parse(origin).host, ignoreCase = true)) {
                    req.deny(); Toast.makeText(this@AdminActivity, "Blocked ${req.resources.joinToString()} for ${req.origin}", Toast.LENGTH_LONG).show(); return
                }
                if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) req.grant(arrayOf(audio))
                else { micAsk?.deny(); micAsk = req; micPerm.launch(Manifest.permission.RECORD_AUDIO) }
            }
        }
        w.setDownloadListener { dl, ua, disposition, mime, _ ->
            if (dl.startsWith("blob:") || dl.startsWith("data:")) return@setDownloadListener   // saved by the console through RovexyApp.saveFile
            val name = URLUtil.guessFileName(dl, disposition, mime)
            val req = android.app.DownloadManager.Request(Uri.parse(dl))
                .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(dl) ?: "")
                .addRequestHeader("User-Agent", ua)
                .setTitle(name)
                .setNotificationVisibility(android.app.DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
            getSystemService(android.app.DownloadManager::class.java).enqueue(req)
            Toast.makeText(this, "Downloading $name…", Toast.LENGTH_SHORT).show()
        }
        if (state == null || w.restoreState(state) == null) w.loadUrl(url)
    }

    private fun micOff() = Toast.makeText(this, "Microphone is off for ROVEXY Admin: Settings → Apps → ROVEXY Admin → Permissions → Microphone → Allow", Toast.LENGTH_LONG).show()

    override fun onSaveInstanceState(out: Bundle) { super.onSaveInstanceState(out); web?.saveState(out) }
    override fun onPause() { super.onPause(); CookieManager.getInstance().flush() }   // stay signed in after the app is closed

    private fun openOutside(u: Uri) {
        try { startActivity(Intent(Intent.ACTION_VIEW, u)) } catch (e: ActivityNotFoundException) { Toast.makeText(this, "No app on this phone can open that link", Toast.LENGTH_SHORT).show() }
    }

    private fun offlinePage(why: String) = """<!doctype html><meta name=viewport content="width=device-width,initial-scale=1">
        <body style="font-family:sans-serif;padding:32px;color:#1f3a5f"><h2>Can't reach the console</h2>
        <p>Check the phone's internet connection.</p><p style="color:#6b7789;font-size:13px">${android.text.Html.escapeHtml(why)}</p>
        <p><button onclick="location.href='${prefs.getString("console", "")}'" style="padding:12px 20px">Try again</button>
        <button onclick="RovexyApp.changeServer()" style="padding:12px 20px">Change company</button></p></body>"""

    /** window.RovexyApp in the console. Only the company's own server is ever loaded, so only it can call these. */
    inner class Bridge {
        /** Files the console builds in the browser (CSV / Excel exports): saved to Downloads, then opened. */
        @JavascriptInterface fun saveFile(name: String, mime: String, base64: String) {
            val safe = name.replace(Regex("[\\\\/:*?\"<>|]"), "_").ifBlank { "export" }
            val bytes = Base64.decode(base64, Base64.DEFAULT)
            val item = contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, safe); put(MediaStore.Downloads.MIME_TYPE, mime.ifBlank { "application/octet-stream" })
            }) ?: return
            contentResolver.openOutputStream(item)?.use { it.write(bytes) }
            runOnUiThread {
                Toast.makeText(this@AdminActivity, "Saved to Downloads: $safe", Toast.LENGTH_LONG).show()
                try { startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(item, mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)) } catch (_: ActivityNotFoundException) { }
            }
        }
        @JavascriptInterface fun print() = runOnUiThread {
            val w = web ?: return@runOnUiThread
            getSystemService(PrintManager::class.java).print("ROVEXY", w.createPrintDocumentAdapter("ROVEXY"), PrintAttributes.Builder().build())
        }
        @JavascriptInterface fun changeServer() = runOnUiThread {
            prefs.edit().remove("console").apply()
            showSetup(null)
        }
    }
}
