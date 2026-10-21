package ametecs.managedmobile.ui

import android.app.Application
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.BuildConfig
import ametecs.managedmobile.calls.CallRecordService
import ametecs.managedmobile.calls.CallSession
import ametecs.managedmobile.calls.SimCalls
import ametecs.managedmobile.core.Backoff
import ametecs.managedmobile.core.CachedCustomer
import ametecs.managedmobile.core.RequestSigning
import ametecs.managedmobile.data.ApiClient
import ametecs.managedmobile.data.ApiException
import ametecs.managedmobile.data.obj
import ametecs.managedmobile.data.str
import ametecs.managedmobile.work.LocationService
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File

sealed class Screen {
    object Connect : Screen()
    data class Pending(val code: String) : Screen()
    data class Ended(val state: String) : Screen()           // rejected | revoked
    object Login : Screen()
    data class Policy(val state: String, val failing: List<String>) : Screen()
    object Dashboard : Screen()
    data class Locked(val reason: String) : Screen()
}

data class Branding(val name: String, val primary: String, val logo: Bitmap?)
data class CallUi(val id: String, val state: String, val talk: Int, val ring: Int?, val recording: String, val agentLeg: String, val customer: CachedCustomer?,
                  val error: String? = null, val route: String = "server", val note: String? = null)
data class Today(val calls: Int, val connected: Int, val talkSeconds: Int, val followUpsDue: Int)
data class ChatConv(val id: Long, val subject: String?, val lastAt: String?)
data class ChatMsg(val id: Long, val mine: Boolean, val body: String, val at: String, val read: Boolean)
/** Call feedback option. captures: none | promise (amount + date) | payment (amount, mode, reference, date, slip). */
data class Outcome(val code: String, val label: String, val captures: String = "none", val needsFollowUp: Boolean = false)
data class Disclosure(val recorded: Boolean, val notice: String, val location: String?, val liveView: Boolean, val camera: Boolean)

class AppViewModel(app: Application) : AndroidViewModel(app) {
    private val g = (app as AmmpApp).graph
    val screen = MutableStateFlow<Screen>(Screen.Connect)
    val branding = MutableStateFlow(Branding(BuildConfig.PRODUCT_NAME, "#1F3A5F", null))
    val busy = MutableStateFlow(false)
    val error = MutableStateFlow<String?>(null)
    val call = MutableStateFlow<CallUi?>(null)
    val disclosure = MutableStateFlow<Disclosure?>(null)
    val dispositions = MutableStateFlow<List<Outcome>>(emptyList())
    val paymentModes = MutableStateFlow<List<Pair<String, String>>>(emptyList())
    val customers: StateFlow<List<CachedCustomer>> = g.work.customers
    val sync = g.work.status
    val pendingMedia = g.media.pending
    val activeMedia = g.media.active
    val consentNeeded = g.media.consentNeeded
    val mediaError = g.media.lastError
    val serverLocked get() = g.config.isLocked(screen.value !is Screen.Connect)
    val serverUrl get() = g.config.serverOrigin.orEmpty()
    val workspace get() = g.config.workspace.orEmpty()
    private var poller: Job? = null
    val today = MutableStateFlow<Today?>(null)
    val employeeName = MutableStateFlow("")
    /** Incremented when the UI must ask Android for the SIM-calling permissions. */
    val permissionAsk = MutableStateFlow(0)
    /** Incremented when the UI must ask Android to make this the default phone app (own call screen + speaker control). */
    val dialerRoleAsk = MutableStateFlow(0)
    /** SIM-calling permissions still missing (shown as a banner on the dashboard). */
    val missingPermissions = MutableStateFlow<List<String>>(emptyList())
    private var callingRoute = "server"
    /** Routes the company offers: "sim" (this phone's SIM) and/or "server" (Click2Call). Two = the employee picks per call. */
    private var callingRoutes = listOf("server")
    /** Set when the employee must choose SIM call or Click2Call for this call. */
    val routeAsk = MutableStateFlow<Pair<CachedCustomer, String>?>(null)
    fun cancelRouteAsk() { routeAsk.value = null }
    /** Calling options this employee may use (server decides from Calling configuration); shown in the call dialog. */
    val routeOptions = MutableStateFlow(listOf("server"))
    /** Click2Call: company numbers this employee may call from (picked in the dialog), their default, and whether
     *  Smart31 picks the number automatically (then no picker). */
    val c2cNumbers = MutableStateFlow<List<String>>(emptyList())
    val c2cDefault = MutableStateFlow<String?>(null)
    private var c2cSmart31 = false
    /** True while the "switch on the call recorder" guide is shown (recording is required and Accessibility is off). */
    val recorderSetup = MutableStateFlow(false)
    fun openRecorderSettings() { CallRecordService.openAccessibilitySettings(getApplication()) }
    fun closeRecorderSetup() { recorderSetup.value = false }

    /** Recorded calls need the Phone app's auto-record ON. Proof = a call-recording file on the phone (see SimCalls.latestCallRecordingMs). */
    val recordingGate = MutableStateFlow(false)
    fun openCallSettings() { SimCalls.openCallSettings(getApplication()) }
    fun closeRecordingGate() { recordingGate.value = false }

    /** True while calls must stay blocked. Strict: every phone starts blocked and unlocks only when a call recording made
     *  AFTER that moment is found (a test call from the Phone app). An answered call without a recording blocks again. */
    private fun recordingBlocked(ctx: Application): Boolean {
        if (!SimCalls.phoneRecorder || disclosure.value?.recorded != true) return false
        val blockedSince = g.store.getString("rec_block_since")?.toLongOrNull()
        if (blockedSince == null && g.store.getString("rec_ok") == "1") return false
        if (blockedSince != null && SimCalls.latestCallRecordingMs(ctx, blockedSince) != null) {
            g.store.putString("rec_ok", "1"); g.store.putString("rec_block_since", null); return false
        }
        if (blockedSince == null) g.store.putString("rec_block_since", System.currentTimeMillis().toString())   // a test call from now on unlocks
        return true
    }
    private var finishing = false
    init {
        g.session.onRevoked = { viewModelScope.launch { refreshStatus() } }
        g.session.onSignedOut = { viewModelScope.launch { screen.value = Screen.Login; stopWork() } }
        g.onServerEvent = { type, _ ->
            if (type == "session.revoked" || type == "session.ended" || type == "session.revalidate") viewModelScope.launch { refreshStatus() }
            if (type.startsWith("chat") || type.startsWith("message")) viewModelScope.launch { checkChat() }
        }
        viewModelScope.launch { startup() }
    }

    private suspend fun startup() = runCatching { startupInner() }.onFailure { error.value = "Could not start: ${it.message}" }

    private suspend fun startupInner() {
        if (g.config.serverOrigin == null || g.store.getString("registered") == null) { screen.value = Screen.Connect; loadBranding(); return }
        loadBranding()
        refreshStatus()
    }

    /** Steps 3–8: verify server, fetch public branding, generate identity, register, show code. */
    fun connect(url: String, workspace: String) = act {
        if (!g.config.isLocked(false)) g.config.save(url, workspace)
        loadBranding(throwOnError = true)
        g.keys.ensureKey()
        val r = g.api.call("POST", "/api/mobile/v1/devices/register", buildJsonObject {
            put("installation_id", g.keys.installationId)
            put("public_key", g.keys.publicKeyB64())
            put("manufacturer", Build.MANUFACTURER); put("model", Build.MODEL)
            put("os_version", Build.VERSION.RELEASE); put("sdk_int", Build.VERSION.SDK_INT)
            put("app_version", BuildConfig.VERSION_NAME)
        }, ApiClient.Auth.NONE).obj()
        g.store.putString("registered", "1")
        applyStatus(r)
    }

    private suspend fun refreshStatus() {
        val status = try {
            g.api.call("GET", "/api/mobile/v1/device/status", null, ApiClient.Auth.SIGNED).obj()
        } catch (e: ApiException) {
            // 401 on the SIGNED status call means the server does not know this key: register again.
            if (e.status == 401) { g.store.putString("registered", null); screen.value = Screen.Connect } else error.value = e.message
            return
        } catch (e: java.io.IOException) {
            offlineFallback()
            return
        }
        try {
            applyStatus(status)
        } catch (e: ApiException) {
            // Work-session problems (expired / signed out elsewhere) lead back to Login, never to re-registration.
            if (e.status in 401..403 && e.code != "device_not_compliant") { g.session.clearWork(); screen.value = Screen.Login } else error.value = e.message
        } catch (e: java.io.IOException) {
            offlineFallback()
        }
    }

    private suspend fun applyStatus(r: JsonObject) {
        when (r.str("state")) {
            "pending" -> { screen.value = Screen.Pending(r.str("approval_code") ?: ""); pollApproval() }
            "approved" -> if (g.session.hasWorkSession) enterWork() else screen.value = Screen.Login
            else -> { stopWork(); g.session.clearWork(); g.work.clearWorkData(); screen.value = Screen.Ended(r.str("state") ?: "revoked") }
        }
    }

    /** Signed polling with server hint + exponential backoff + jitter. */
    private fun pollApproval() {
        if (poller?.isActive == true) return
        poller = viewModelScope.launch {
            var attempt = 0
            var hint: Int? = null
            while (screen.value is Screen.Pending) {
                delay(Backoff.pollDelaySeconds(hint, attempt++) * 1000)
                try {
                    val s = g.api.call("GET", "/api/mobile/v1/device/status", null, ApiClient.Auth.SIGNED).obj()
                    hint = s.str("poll_after_seconds")?.toIntOrNull()
                    if (s.str("state") != "pending") { refreshStatus(); return@launch }
                } catch (e: ApiException) {
                    if (e.status == 401) { refreshStatus(); return@launch }   // unknown key → back to Connect
                } catch (e: Exception) {
                    // offline: keep polling with backoff
                }
            }
        }
    }

    fun login(user: String, password: String) = act {
        g.session.login(user.trim(), password, ametecs.managedmobile.work.currentFix(getApplication()))
        enterWork()
    }

    /** Steps 12–14: verify, apply policy, report, then open the dashboard only if compliant. */
    private suspend fun enterWork() {
        val ack = g.work.syncPolicy()
        val me = g.session.work("GET", "/api/mobile/v1/me").obj()
        applyMe(me)
        sendCrashNote()
        me["realtime"]?.let { rt -> if (rt is JsonObject) { g.realtimeUrl = rt.str("ws_url"); g.realtimeChannel = rt.str("channel") } }
        me["disclosure"]?.jsonObject?.let { d ->
            disclosure.value = Disclosure(d.str("calls_recorded") == "true", d.str("recording_notice") ?: "",
                d["location"]?.let { if (it is JsonObject) "Every ${it.str("interval_seconds")} s while signed in" else null }, d.str("live_view_possible") == "true", d.str("camera_sessions_possible") == "true")
        }
        if (ack.str("compliant") != "true") {
            screen.value = Screen.Policy(ack.str("state") ?: "failed", me["device"]?.jsonObject?.get("failing_mandatory")?.jsonArray?.map { it.toString().trim('"') } ?: emptyList())
            return
        }
        screen.value = Screen.Dashboard
        g.realtime.start()
        g.registerPush()   // Firebase: lets the server wake this phone for messages / calls even when the app is closed
        watchChat()
        if (g.policy.bool("location.enabled")) LocationService.start(getApplication())   // no-op if permission denied (reported to server via policy ack)
        runCatching { g.work.syncAll() }
        loadOutcomes()
        g.media.refresh()
        onResumed()   // a SIM call placed before the app was closed is reported now
    }

    /** Uploads the crash note left by the previous run (see AmmpApp), once. */
    private fun sendCrashNote() = viewModelScope.launch {
        val f = File(getApplication<Application>().filesDir, "last_crash.txt")
        if (!f.exists()) return@launch
        runCatching { g.session.work("POST", "/api/mobile/v1/diagnostics/crash", buildJsonObject { put("report", f.readText().take(12_000)) }) }
            .onSuccess { f.delete() }
    }

    private fun applyMe(me: JsonObject) {
        callingRoute = me.str("calling_route") ?: "server"
        callingRoutes = (me["calling_routes"] as? JsonArray)?.mapNotNull { it.toString().trim('"').takeIf { r -> r == "sim" || r == "server" } }
            ?: listOf(callingRoute)   // empty list = no calling option allowed (server says so on Call)
        routeOptions.value = callingRoutes
        (me["click2call"] as? JsonObject).let { o ->
            c2cNumbers.value = (o?.get("numbers") as? JsonArray)?.mapNotNull { (it as? JsonObject)?.str("number") } ?: emptyList()
            c2cDefault.value = o?.str("default")
            c2cSmart31 = o?.str("smart31") == "true"
        }
        me["employee"]?.jsonObject?.str("name")?.let { employeeName.value = it }
        me["employee"]?.jsonObject?.str("id")?.toLongOrNull()?.let { myUserId = it }
        me["today"]?.jsonObject?.let { t -> today.value = Today(t.str("calls")?.toIntOrNull() ?: 0, t.str("connected")?.toIntOrNull() ?: 0, t.str("talk_seconds")?.toIntOrNull() ?: 0, t.str("follow_ups_due")?.toIntOrNull() ?: 0) }
    }

    private suspend fun refreshToday() { runCatching { applyMe(g.session.work("GET", "/api/mobile/v1/me").obj()) }; checkChat() }

    // ───────── chat with supervisor (polled every 4 s while open; realtime events also trigger a check) ─────────
    private var myUserId = 0L
    val chatConvs = MutableStateFlow<List<ChatConv>>(emptyList())
    val chatOpen = MutableStateFlow<Long?>(null)
    val chatMsgs = MutableStateFlow<List<ChatMsg>>(emptyList())
    val chatUnread = MutableStateFlow(false)
    private var chatPoll: Job? = null

    private suspend fun loadConvs() = runCatching {
        g.session.work("GET", "/api/mobile/v1/conversations").jsonArray.map { it.jsonObject.let { o -> ChatConv(o.str("id")!!.toLong(), o.str("subject"), o.str("last_message_at")) } }
    }.getOrNull()?.also { chatConvs.value = it }

    /** New message since the employee last opened chat → banner + phone notification; fetching also marks it delivered. */
    suspend fun checkChat() {
        val convs = loadConvs() ?: return
        val seen = g.store.getString("chat_seen") ?: ""
        val fresh = convs.filter { (it.lastAt ?: "") > seen }
        chatUnread.value = fresh.isNotEmpty()
        // ponytail: re-reads up to 100 messages per new conversation to set "delivered"; fine for supervisor chat volumes.
        val lastText = fresh.associate { c -> c.id to runCatching {
            g.session.work("GET", "/api/mobile/v1/conversations/${c.id}/messages").jsonArray.map { it.jsonObject }
                .lastOrNull { it.str("sender_user_id")?.toLongOrNull() != myUserId }?.let { it.str("body") ?: "📎 Attachment" }
        }.getOrNull() }
        // Sound + notification for EVERY new message (not just the first unread one), showing the message itself.
        val top = fresh.maxByOrNull { it.lastAt ?: "" }
        val newest = top?.lastAt
        val notified = g.store.getString("chat_notified") ?: ""   // shared with Firebase push, so one message never notifies twice
        if (top != null && newest != null && newest > maxOf(notified, seen) && chatOpen.value == null) {
            g.store.putString("chat_notified", newest)
            ametecs.managedmobile.push.NotificationActions.showChat(getApplication(), top.id, top.subject, lastText[top.id], newest)
        }
    }

    private fun messageBeep() = runCatching {
        val ctx = getApplication<Application>()
        android.media.RingtoneManager.getRingtone(ctx, android.media.RingtoneManager.getDefaultUri(android.media.RingtoneManager.TYPE_NOTIFICATION))?.play()
    }

    private var chatWatch: Job? = null
    /** Without realtime (Reverb) configured, chat is polled every 20 s while signed in. */
    private fun watchChat() {
        if (chatWatch?.isActive == true) return
        chatWatch = viewModelScope.launch { while (true) { checkChat(); delay(20_000) } }
        g.media.watchRequests()
    }

    fun openChat() = viewModelScope.launch {
        val convs = loadConvs() ?: run { error.value = "Messages could not be loaded. Check the connection and try again."; return@launch }
        if (convs.size == 1) openConv(convs[0].id) else chatOpen.value = -1L   // -1 = list
        markSeen()
    }

    fun openConv(id: Long) {
        chatOpen.value = id; chatMsgs.value = emptyList()
        chatPoll?.cancel()
        chatPoll = viewModelScope.launch { while (true) { pullMessages(id); delay(4000) } }
    }

    private suspend fun pullMessages(id: Long) {
        val after = chatMsgs.value.lastOrNull()?.id ?: 0
        val fresh = runCatching { g.session.work("GET", "/api/mobile/v1/conversations/$id/messages?after=$after").jsonArray.map { it.jsonObject.let { o ->
            ChatMsg(o.str("id")!!.toLong(), o.str("sender_user_id")?.toLongOrNull() == myUserId, o.str("body") ?: "📎 ${o["attachment"]?.jsonObject?.str("name") ?: "attachment"}",
                o.str("created_at") ?: "", o.str("read") == "true") } } }.getOrNull() ?: return
        if (fresh.isEmpty()) return
        if (chatMsgs.value.isNotEmpty() && fresh.any { !it.mine }) messageBeep()   // chat is open: short sound instead of a notification
        chatMsgs.value = chatMsgs.value + fresh
        val theirs = fresh.filter { !it.mine }.map { it.id }
        if (theirs.isNotEmpty()) {
            g.work.enqueue("chat_read", buildJsonObject { put("message_ids", kotlinx.serialization.json.JsonArray(theirs.map { kotlinx.serialization.json.JsonPrimitive(it) })) })
            g.work.flush()
        }
        markSeen()
    }

    private suspend fun markSeen() {
        loadConvs()?.mapNotNull { it.lastAt }?.maxOrNull()?.let { g.store.putString("chat_seen", it) }   // same format as checkChat compares
        chatUnread.value = false
    }

    fun sendChat(text: String) = act {
        val id = chatOpen.value?.takeIf { it > 0 } ?: return@act
        g.session.work("POST", "/api/mobile/v1/conversations/$id/messages", buildJsonObject { put("body", text); put("client_message_id", java.util.UUID.randomUUID().toString()) })
        pullMessages(id)
    }

    fun closeChat() { chatPoll?.cancel(); chatPoll = null; chatOpen.value = null }

    fun retryPolicy() = act { enterWork() }

    private fun offlineFallback() {
        // Offline: work functions only within the server-issued lease; device restrictions are unaffected either way.
        screen.value = if (g.session.hasWorkSession && g.session.lease.isValid(System.currentTimeMillis())) Screen.Dashboard
        else if (g.session.hasWorkSession) Screen.Locked("Offline access period has ended. Connect to the internet to continue.")
        else Screen.Login
    }

    fun refresh() = act { g.work.syncAll(); g.media.refresh(); refreshToday() }

    fun logout() = act {
        stopWork()
        g.session.logout()
        g.work.clearWorkData()
        screen.value = Screen.Login
    }

    /** After revocation/rejection, an administrator may ask the employee to register again: needs a brand-new key. */
    fun startNewRegistration() {
        g.session.wipeIdentity()
        g.store.putString("registered", null)
        screen.value = Screen.Connect
    }

    /** Runtime permissions first; once those are granted, the one switch Android keeps in Accessibility settings. */
    fun askPermissions() {
        val ctx = getApplication<Application>()
        if (SimCalls.missing(ctx).isNotEmpty()) permissionAsk.value++
        else if (SimCalls.phoneRecorder) { if (SimCalls.ownDialerBlocking(ctx)) SimCalls.openDefaultApps(ctx) }
        else if (!CallSession.isDefaultDialer(ctx)) dialerRoleAsk.value++
        else if (!CallRecordService.accessibilityOn(ctx)) CallRecordService.openAccessibilitySettings(ctx)
    }

    /** True when the employee picks the Click2Call company number in the dialog (several numbers, Smart31 not deciding). */
    fun c2cPicker() = "server" in callingRoutes && !c2cSmart31 && c2cNumbers.value.size > 1

    fun placeCall(c: CachedCustomer, which: String, route: String? = null, vmn: String? = null) = act {
        routeAsk.value = null
        if (callingRoutes.isEmpty()) throw ApiException(0, "no_calling_option", "No calling option is enabled for you. Ask your administrator.")
        // Ask when there is a choice: SIM or Click2Call, and/or which company number Click2Call uses.
        if ((route == null && (callingRoutes.size > 1 || c2cPicker())) || (route == "server" && vmn == null && c2cPicker())) { routeAsk.value = c to which; return@act }
        val callingRoute = route ?: callingRoutes.first()
        if (!g.session.lease.isValid(System.currentTimeMillis()) && !sync.value.online) throw ApiException(0, "offline", "Calling needs a connection to the company server.")
        val ctx = getApplication<Application>()
        if (callingRoute == "sim" && SimCalls.missing(ctx).isNotEmpty()) {
            permissionAsk.value++
            throw ApiException(0, "permissions", "Allow ${SimCalls.missingLabels(ctx).joinToString(", ")} permissions, then tap Call again.")
        }
        if (callingRoute == "sim" && SimCalls.ownDialerBlocking(ctx)) {
            SimCalls.openDefaultApps(ctx)
            throw ApiException(0, "dialer", when {
                !SimCalls.odialerSupported(ctx) -> "Choose a default Phone app in Default apps and turn on its auto call recording, then tap Call again."
                SimCalls.odialerInstalled(ctx) -> "Set ODialer as the default Phone app (it records the call), then tap Call again."
                else -> "Install ODialer from Play Store, then tap Call again."
            })
        }
        if (callingRoute == "sim" && !SimCalls.phoneRecorder && !CallSession.isDefaultDialer(ctx)) {
            dialerRoleAsk.value++   // calls go only through our own call screen — never hand off to another dialer app
            throw ApiException(0, "dialer", "Set ROVEXY as the default Phone app, then tap Call again.")
        }
        if (callingRoute == "sim" && !SimCalls.phoneRecorder && disclosure.value?.recorded == true && !CallRecordService.accessibilityOn(ctx)) {
            recorderSetup.value = true   // recorded calls are not placed until the recorder can actually hear the call
            return@act
        }
        if (callingRoute == "sim" && recordingBlocked(ctx)) {
            recordingGate.value = true   // no call goes out until the Phone app is proven to record
            return@act
        }
        if (g.store.getString("sim_pending") != null) throw ApiException(0, "pending", "The previous call is still being saved. Tap \"Finish call\" first.")
        val r = g.session.work("POST", "/api/mobile/v1/calls", buildJsonObject { put("customer_id", c.id); put("which", which); put("route", callingRoute); if (callingRoute == "server" && vmn != null) put("vmn", vmn) }).obj()
        val id = r.str("call_id")!!
        val dial = r.str("dial")
        if (r.str("route") == "sim" && dial != null) {
            g.store.putString("sim_pending", buildJsonObject {
                put("id", id); put("number", dial); put("since", System.currentTimeMillis()); put("customer", c.id)
                put("rec", r.str("recording_status") == "pending"); put("stage", "result")
            }.toString())
            call.value = CallUi(id, "dialing", 0, null, r.str("recording_status") ?: "", "sim", c, route = "sim")
            CallSession.label = c.name
            val ownRec = r.str("recording_status") == "pending" && !SimCalls.phoneRecorder
            CallSession.wantSpeaker = ownRec   // speaker on when connected, so the customer is recorded
            if (ownRec) CallRecordService.start(ctx, id)   // must start while this app is in front
            trackSimCall()
            SimCalls.dial(ctx, dial)
            return@act
        }
        r.str("callback_from")?.let { CallSession.label = c.name; CallSession.wantSpeaker = false; CallSession.expectCallback(it) }
        call.value = CallUi(id, r.str("state")!!, 0, null, r.str("recording_status") ?: "", r.str("agent_leg") ?: "", c)
        watchCall()
    }

    // Off-hook window of the current SIM call, polled from dialling (ring + talk; the app can't tell them apart without the call log).
    @Volatile private var offhookFrom: Long? = null
    @Volatile private var offhookTo: Long? = null
    /** Play build, no recorder file: the employee's answer to "Did the customer answer?" (null = not asked yet). */
    @Volatile private var answeredOverride: Boolean? = null
    private var tracker: kotlinx.coroutines.Job? = null

    private fun trackSimCall() {
        tracker?.cancel(); offhookFrom = null; offhookTo = null; answeredOverride = null
        val ctx = getApplication<Application>()
        tracker = viewModelScope.launch {
            val start = System.currentTimeMillis()
            while (System.currentTimeMillis() - start < 3 * 3_600_000) {
                val now = System.currentTimeMillis()
                if (SimCalls.inCall(ctx)) { if (offhookFrom == null) offhookFrom = now; offhookTo = now }
                else if (offhookTo != null && now - offhookTo!! > 5_000) break
                delay(1000)
            }
        }
    }

    /** Employee's Yes/No when no recording file shows the call was answered. */
    fun answered(yes: Boolean) { answeredOverride = yes; onResumed() }

    /** Activity resumed (back from the dialer) or the employee tapped "Finish call": report the SIM call once. */
    fun onResumed() {
        if (recorderSetup.value && CallRecordService.accessibilityOn(getApplication())) recorderSetup.value = false
        if (recordingGate.value && !recordingBlocked(getApplication())) recordingGate.value = false
        if ("sim" in callingRoutes) missingPermissions.value = SimCalls.missingLabels(getApplication())
        if (screen.value is Screen.Dashboard && !LocationService.started && g.policy.bool("location.enabled")) LocationService.start(getApplication())
        if (finishing || g.store.getString("sim_pending") == null || screen.value !is Screen.Dashboard) return
        finishing = true
        viewModelScope.launch { try { finishSimCall() } finally { finishing = false } }
    }

    private suspend fun finishSimCall() {
        android.util.Log.i("AMMP", "finishSimCall: ${g.store.getString("sim_pending")}")
        val p = g.api.json.parseToJsonElement(g.store.getString("sim_pending") ?: return).jsonObject
        val id = p.str("id")!!; val number = p.str("number")!!; val since = p.str("since")!!.toLong()
        val ctx = getApplication<Application>()
        val customer = customers.value.firstOrNull { it.id.toString() == p.str("customer") }
        fun show(state: String, note: String? = null) {
            call.value = (call.value?.takeIf { it.id == id } ?: CallUi(id, state, 0, null, "", "sim", customer, route = "sim")).copy(state = state, note = note)
        }
        if (System.currentTimeMillis() - since < 2000) return            // resumed before the dialer took over
        try {
            var stage = p.str("stage")
            if (stage == "result") {
                if (answeredOverride == null && call.value?.state == "ask_answered") return   // waiting for the employee's Yes/No
                // The dialer may not have connected yet: wait up to 20 s for the call to show up before judging it.
                while (offhookFrom == null && !SimCalls.inCall(ctx) && System.currentTimeMillis() - since < 20_000) { show("on_sim_call", "Calling…"); delay(1000) }
                while (SimCalls.inCall(ctx) || (offhookTo != null && System.currentTimeMillis() - offhookTo!! < 3_000)) { show("on_sim_call", "On call. Come back here after hanging up."); delay(2000) }
                val offhook = offhookFrom?.let { from -> offhookTo?.let { ((it - from) / 1000).toInt() } }
                show("saving", "Reading the call log…")
                var log: SimCalls.LogEntry? = null
                if (BuildConfig.PLAY) {
                    val ans = answeredOverride
                    if (ans != null) log = SimCalls.LogEntry(if (ans) maxOf(offhook ?: 1, 1) else 0, since)
                    else {
                        show("saving", "Looking for the call recording…")
                        val found = SimCalls.logFromRecording(ctx, number, since)
                        log = found
                        // No recorder file is not proof of "not answered" (auto-record off, or recording not used): ask.
                        if (found.talkSeconds == 0) { show("ask_answered", "No call recording was found on this phone."); return }
                    }
                }
                else for (i in 0 until 8) { log = SimCalls.callLog(ctx, number, since); if (log != null) break; delay(1500) }
                val missing = SimCalls.missingLabels(ctx)
                val stale = System.currentTimeMillis() - since > 30 * 60_000
                if (log == null && !stale) {
                    // Never guess "not answered": the call may still be running, or the call log is not readable yet.
                    if (missing.isNotEmpty()) permissionAsk.value++
                    show("on_sim_call", if (missing.isNotEmpty()) "Allow ${missing.joinToString(", ")} in the app permissions, then tap Finish call."
                        else "This call is not in the call log yet. When the call is over, tap Finish call.")
                    return
                }
                val talk = log?.talkSeconds ?: 0
                var file: SimCalls.RecorderFile? = null
                var bytes: ByteArray? = null
                val own = java.io.File(java.io.File(ctx.filesDir, "call-rec"), "$id.wav")
                // The recorder runs from the moment of dialling, so even an unanswered call has its ringing recorded.
                val keepRecording = p.str("rec") == "true" && (talk > 0 || log != null)
                if (keepRecording) {
                    show("saving", "Saving the call recording…")
                    for (i in 0 until 15) { if (!CallRecordService.running) break; delay(700) }
                    // Without the accessibility switch Android feeds silence to the mic during calls — never upload that as a recording.
                    if (!SimCalls.phoneRecorder && own.length() > 2_000 && CallRecordService.accessibilityOn(ctx)) file = SimCalls.RecorderFile(android.net.Uri.fromFile(own), own.name, "audio/wav", talk, own.length())
                    else if (talk > 0 && answeredOverride == null) for (i in 0 until 10) { file = SimCalls.findRecording(ctx, number, log?.startedAtMs ?: since); if (file != null) break; delay(2000) }  // OEM recorders save the file a few seconds after hang-up
                    bytes = file?.let { SimCalls.read(ctx, it) }
                    // An answered call with no recorder file means auto-record is off: block the next call until a recording shows up.
                    if (SimCalls.phoneRecorder && talk > 0) {
                        if (bytes == null) { g.store.putString("rec_ok", null); g.store.putString("rec_block_since", System.currentTimeMillis().toString()) }
                        else { g.store.putString("rec_ok", "1"); g.store.putString("rec_block_since", null) }
                    }
                } else own.delete()
                val r = g.session.work("POST", "/api/mobile/v1/calls/$id/sim-result", buildJsonObject {
                    put("talk_seconds", talk)
                    put("diagnostics", buildJsonObject {
                        put("call_log_entry", log != null)
                        put("talk_source", when { !BuildConfig.PLAY -> "call_log"; answeredOverride == true -> "employee_yes_offhook"; answeredOverride == false -> "employee_no"; else -> "recording_file" })
                        offhook?.let { put("offhook_seconds", it) }
                        put("missing_permissions", missing.joinToString(","))
                        put("phone", "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL} / Android ${android.os.Build.VERSION.RELEASE}")
                        file?.let { put("recording_file", it.name) }
                        put("in_app_recorder", when { SimCalls.phoneRecorder -> "off (phone's own recorder mode)"; !CallRecordService.accessibilityOn(ctx) -> "accessibility_off"; own.length() > 2_000 -> "recorded ${own.length() / 1024} KB, speaker ${when (CallRecordService.speakerOn) { true -> "on"; false -> "could not switch on"; null -> "n/a" }}, ${CallRecordService.lastInfo ?: "no level info"}"; else -> CallRecordService.lastError ?: "no_audio" })
                        if (talk > 0 && file == null) put("audio_folders_since_call", SimCalls.recentAudioFolders(ctx, log?.startedAtMs ?: since).joinToString(" | ").ifEmpty { "none" })
                    })
                    log?.let { put("started_at", java.time.Instant.ofEpochMilli(it.startedAtMs).toString()) }
                    if (keepRecording) put("recording_found", bytes != null)
                }).obj()
                applyCall(id, r, customer)
                if (file?.uri?.scheme != "file") own.delete()
                if (bytes != null && file != null) {
                    // Keep the file reference so a failed upload is retried at the next resume.
                    g.store.putString("sim_pending", buildJsonObject {
                        p.forEach { (k, v) -> if (k != "stage") put(k, v) }
                        put("stage", "upload"); put("uri", file.uri.toString()); put("name", file.name); put("mime", file.mime ?: "audio/mp4"); file.durationSeconds?.let { put("dur", it) }
                    }.toString())
                    stage = "upload"
                } else {
                    g.store.putString("sim_pending", null)
                    return
                }
            }
            if (stage == "upload") {
                val current = g.api.json.parseToJsonElement(g.store.getString("sim_pending")!!).jsonObject
                show(call.value?.state ?: "completed", "Uploading the call recording…")
                val f = SimCalls.RecorderFile(android.net.Uri.parse(current.str("uri")), current.str("name")!!, current.str("mime"), current.str("dur")?.toIntOrNull(), 0)
                val bytes = SimCalls.read(ctx, f) ?: run { g.store.putString("sim_pending", null); show(call.value?.state ?: "completed", "The recording file is no longer readable."); return }
                val r = g.session.workUpload("/api/mobile/v1/calls/$id/recording") {
                    okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM)
                        .apply { f.durationSeconds?.let { addFormDataPart("duration_seconds", it.toString()) } }
                        .addFormDataPart("file", f.name, bytes.toRequestBody((f.mime ?: "audio/mp4").toMediaTypeOrNull())).build()
                }.obj()
                g.store.putString("sim_pending", null)
                if (f.uri.scheme == "file") f.uri.path?.let { java.io.File(it).delete() }   // in-app recording uploaded: remove the local copy
                applyCall(id, r, customer)
            }
            refreshToday()
        } catch (e: ApiException) {
            if (e.status in 400..499 && e.status != 401 && e.status != 429) g.store.putString("sim_pending", null)   // server refused: do not retry forever
            show(call.value?.state ?: "saving", "Could not save the call: ${e.message}")
        } catch (e: java.io.IOException) {
            show(call.value?.state ?: "saving", "No connection. The call will be saved when you are back online — tap Finish call.")
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            android.util.Log.e("AMMP", "finishSimCall failed", e)
            show(call.value?.state ?: "saving", "Could not save the call (${e.javaClass.simpleName}: ${e.message}). Tap Finish call to retry.")
        }
    }

    /** Close the call panel without saving (e.g. a call that never connected). The call stays logged on the server. */
    fun dismissCall() {
        g.store.putString("sim_pending", null)
        CallRecordService.lastFile?.takeIf { it.exists() && !CallRecordService.running }?.delete()
        call.value = null
    }

    private fun applyCall(id: String, r: JsonObject, customer: CachedCustomer?) {
        call.value = CallUi(id, r.str("state") ?: "completed", r.str("talk_seconds")?.toIntOrNull() ?: 0, r.str("ring_seconds")?.toIntOrNull(),
            r.str("recording_status") ?: "", "sim", call.value?.customer ?: customer, route = "sim")
    }

    private fun watchCall() = viewModelScope.launch {
        while (true) {
            val cur = call.value ?: return@launch
            delay(2000)
            val r = runCatching { g.session.work("GET", "/api/mobile/v1/calls/${cur.id}").obj() }.getOrNull() ?: continue
            val state = r.str("state") ?: continue
            call.value = cur.copy(state = state, talk = r.str("talk_seconds")?.toIntOrNull() ?: 0, ring = r.str("ring_seconds")?.toIntOrNull(), recording = r.str("recording_status") ?: "")
            if (r.str("state") in TERMINAL) return@launch
        }
    }

    fun hangup() = act { call.value?.let { g.session.work("POST", "/api/mobile/v1/calls/${it.id}/hangup") } }

    private suspend fun loadOutcomes() = runCatching {
        val r = g.session.work("GET", "/api/mobile/v1/dispositions?full=1").obj()
        dispositions.value = r["codes"]!!.jsonArray.map { val o = it.jsonObject
            Outcome(o.str("code")!!, o.str("label")!!, o.str("captures") ?: "none", o.str("requires_follow_up") == "true") }
        paymentModes.value = r["payment_modes"]?.jsonObject?.map { (k, v) -> k to (v.toString().trim('"')) } ?: emptyList()
    }

    /**
     * Feedback without a slip goes through the offline queue (idempotent) so it is never lost on a bad connection.
     * With a payment slip it is sent directly (multipart) — that needs a connection; on failure the dialog stays open.
     */
    fun submitDisposition(code: String, notes: String, followUpIso: String?, amount: String? = null, mode: String? = null,
                          ref: String? = null, paidOn: String? = null, slip: android.net.Uri? = null) = act {
        val c = call.value ?: return@act
        if (slip != null) {
            val (bytes, name, mime) = readSlip(slip) ?: throw IllegalStateException("The slip file could not be read. Choose it again.")
            g.session.workUpload("/api/mobile/v1/calls/${c.id}/disposition") {
                okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM).apply {
                    addFormDataPart("code", code); addFormDataPart("notes", notes)
                    followUpIso?.let { addFormDataPart("follow_up_at", it) }; amount?.let { addFormDataPart("amount", it) }
                    mode?.let { addFormDataPart("payment_mode", it) }; ref?.takeIf { it.isNotBlank() }?.let { addFormDataPart("payment_ref", it) }
                    paidOn?.let { addFormDataPart("paid_on", it) }
                    addFormDataPart("slip", name, bytes.toRequestBody(mime.toMediaTypeOrNull()))
                }.build()
            }
        } else {
            g.work.enqueue("disposition", buildJsonObject { put("call_id", c.id); put("code", code); put("notes", notes); followUpIso?.let { put("follow_up_at", it) }
                amount?.let { put("amount", it) }; mode?.let { put("payment_mode", it) }; ref?.takeIf { it.isNotBlank() }?.let { put("payment_ref", it) }; paidOn?.let { put("paid_on", it) } })
        }
        call.value = null
        runCatching { g.work.syncAll() }
        refreshToday()
        profileId.value?.let { loadProfile(it) }
    }

    // ───────────── case profile ─────────────
    /** Case open in the profile screen; [profile] is the server copy (null while loading / offline). */
    val profileId = MutableStateFlow<Long?>(null)
    val profile = MutableStateFlow<JsonObject?>(null)
    val profileOffline = MutableStateFlow(false)

    fun openProfile(id: Long) {
        profileId.value = id; profile.value = null; profileOffline.value = false
        viewModelScope.launch {
            runCatching { g.work.enqueue("activity", buildJsonObject { put("type", "customer_opened"); put("customer_id", id) }) }
            loadProfile(id)
        }
    }

    fun closeProfile() { profileId.value = null; profile.value = null }

    fun reloadProfile() { profileId.value?.let { id -> viewModelScope.launch { loadProfile(id) } } }

    private suspend fun loadProfile(id: Long) {
        try {
            val p = g.session.work("GET", "/api/mobile/v1/customers/$id").obj()
            if (profileId.value == id) { profile.value = p; profileOffline.value = false }
        } catch (e: ApiException) {
            if (e.status == 404) { closeProfile(); error.value = "This case is no longer assigned to you." } else profileOffline.value = true
        } catch (e: java.io.IOException) { profileOffline.value = true }
    }

    /** Note, status and callback go through the offline queue (never lost); the profile refreshes when online. */
    fun addCaseNote(id: Long, text: String) = act {
        g.work.enqueue("note", buildJsonObject { put("customer_id", id); put("text", text) }); afterCaseAction(id)
    }

    fun setCaseStatus(id: Long, status: String, note: String) = act {
        g.work.enqueue("status", buildJsonObject { put("customer_id", id); put("status", status); if (note.isNotBlank()) put("notes", note) }); afterCaseAction(id)
    }

    fun scheduleCallback(id: Long, dueIso: String, notes: String) = act {
        g.work.enqueue("follow_up", buildJsonObject { put("customer_id", id); put("due_at", dueIso); put("notes", notes) }); afterCaseAction(id)
    }

    /** Promise or payment recorded on the case without a call. Needs a connection (the office reviews it with the slip). */
    fun recordEntry(id: Long, code: String, notes: String, followUpIso: String?, amount: String, mode: String?, ref: String?, paidOn: String?,
                    slip: android.net.Uri?, done: () -> Unit) = act {
        val file = slip?.let { readSlip(it) ?: throw IllegalStateException("The slip file could not be read. Choose it again.") }
        g.session.workUpload("/api/mobile/v1/customers/$id/entry") {
            okhttp3.MultipartBody.Builder().setType(okhttp3.MultipartBody.FORM).apply {
                addFormDataPart("code", code); addFormDataPart("notes", notes); addFormDataPart("amount", amount)
                followUpIso?.let { addFormDataPart("follow_up_at", it) }; mode?.let { addFormDataPart("payment_mode", it) }
                ref?.takeIf { it.isNotBlank() }?.let { addFormDataPart("payment_ref", it) }; paidOn?.let { addFormDataPart("paid_on", it) }
                file?.let { (bytes, name, mime) -> addFormDataPart("slip", name, bytes.toRequestBody(mime.toMediaTypeOrNull())) }
            }.build()
        }
        done()
        afterCaseAction(id)
    }

    // ───────────── field map ─────────────
    /** Field map open, and the case to focus when it opens (from a case profile). */
    val fieldMap = MutableStateFlow(false)
    val fieldFocus = MutableStateFlow<Long?>(null)
    /** Dialog the next case profile opens with ("payment" from the map's Collect button). */
    val profileDialog = MutableStateFlow<String?>(null)

    fun openFieldMap(focus: Long? = null) { fieldFocus.value = focus; closeProfile(); fieldMap.value = true }
    fun closeFieldMap() { fieldMap.value = false; fieldFocus.value = null }
    fun openProfileFromMap(id: Long, dialog: String? = null) { profileDialog.value = dialog; openProfile(id) }

    /** A fresh GPS fix (≤ 8 s), shared with the work-session tracker's latest fix. Null = no permission / no fix. */
    suspend fun freshLocation(): android.location.Location? =
        ametecs.managedmobile.work.currentFix(getApplication())?.also { LocationService.latest.value = it }

    /** Road distance (m) and time (s) per case from here; null = no road route. Throws ApiException when maps are down. */
    suspend fun roadDistances(lat: Double, lng: Double, ids: List<Long>): Map<Long, Pair<Int, Int>?> {
        val r = g.session.work("POST", "/api/mobile/v1/field/distances", buildJsonObject {
            put("lat", lat); put("lng", lng); put("customer_ids", kotlinx.serialization.json.buildJsonArray { ids.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
        }).obj()
        return r["items"]!!.jsonArray.associate { e ->
            val o = e.jsonObject
            val m = o.str("distance_m")?.toIntOrNull(); val t = o.str("duration_s")?.toIntOrNull()
            o.str("id")!!.toLong() to (if (m != null && t != null) m to t else null)
        }
    }

    /** Driving route from here through the cases (optimised order for 2+). */
    suspend fun drivingRoute(lat: Double, lng: Double, ids: List<Long>, optimize: Boolean): JsonObject =
        g.session.work("POST", "/api/mobile/v1/field/route", buildJsonObject {
            put("lat", lat); put("lng", lng); put("optimize", optimize)
            put("customer_ids", kotlinx.serialization.json.buildJsonArray { ids.forEach { add(kotlinx.serialization.json.JsonPrimitive(it)) } })
        }).obj()

    /** Map key set by the admin (Integrations → Google Maps); null when not set. */
    suspend fun fieldConfig(): JsonObject = g.session.work("GET", "/api/mobile/v1/field/config").obj()

    /**
     * The map key, kept on the phone (per server) so a map opens at once instead of waiting for the server every time.
     * A cached key is returned straight away and re-checked in the background (a key the admin changed is used next time).
     * Throws only when nothing is cached and the server can't be reached.
     */
    suspend fun mapKey(): String? {
        val cached = g.store.getString("map_key")?.split("\n")?.takeIf { it.size == 2 && it[0] == serverUrl }?.get(1)
            ?: return refreshMapKey()
        viewModelScope.launch { runCatching { refreshMapKey() } }
        return cached
    }

    private suspend fun refreshMapKey(): String? =
        (fieldConfig()["map_key"] as? kotlinx.serialization.json.JsonPrimitive)?.takeIf { it.isString }?.content
            .also { g.store.putString("map_key", it?.let { k -> serverUrl + "\n" + k }) }

    /** Suggested pin from the case address (not saved). */
    suspend fun geocodeCase(id: Long): JsonObject = g.session.work("POST", "/api/mobile/v1/customers/$id/geocode").obj()

    /** Saves the case pin, then refreshes the case list so the map shows it. */
    suspend fun saveCaseLocation(id: Long, lat: Double, lng: Double, source: String, accuracy: Float?) {
        g.session.work("POST", "/api/mobile/v1/customers/$id/location", buildJsonObject {
            put("lat", lat); put("lng", lng); put("source", source); accuracy?.let { put("accuracy_m", it.toDouble()) }
        })
        runCatching { g.work.syncAll() }
        if (profileId.value == id) loadProfile(id)
    }

    /** "Reached the customer": the server checks the GPS is within 100 m (+ drift) of the pin and logs a visit on the timeline. */
    suspend fun checkIn(id: Long, lat: Double, lng: Double, accuracy: Float?) {
        g.session.work("POST", "/api/mobile/v1/customers/$id/visit", buildJsonObject {
            put("lat", lat); put("lng", lng); accuracy?.let { put("accuracy_m", it.toDouble()) }
        })
        if (profileId.value == id) loadProfile(id)
    }

    /** Outcome codes for "record without a call": promises / payments only. */
    suspend fun ensureOutcomes() { if (dispositions.value.isEmpty()) loadOutcomes() }

    private suspend fun afterCaseAction(id: Long) {
        runCatching { g.work.syncAll() }
        if (profileId.value == id) loadProfile(id)
        refreshToday()
    }

    /** Slip bytes for upload. Photos are scaled to ≤1600 px JPEG (a phone photo is several MB); PDFs are sent as they are. */
    private fun readSlip(uri: android.net.Uri): Triple<ByteArray, String, String>? {
        val cr = getApplication<Application>().contentResolver
        val mime = cr.getType(uri) ?: "image/jpeg"
        val raw = runCatching { cr.openInputStream(uri)?.use { it.readBytes() } }.getOrNull() ?: return null
        if (mime == "application/pdf") return if (raw.size <= 10 * 1024 * 1024) Triple(raw, "slip.pdf", mime) else throw IllegalStateException("The PDF is larger than 10 MB.")
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }.also { BitmapFactory.decodeByteArray(raw, 0, raw.size, it) }
        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= 1600) sample *= 2
        val bmp = BitmapFactory.decodeByteArray(raw, 0, raw.size, BitmapFactory.Options().apply { inSampleSize = sample }) ?: return null
        val out = java.io.ByteArrayOutputStream()
        bmp.compress(Bitmap.CompressFormat.JPEG, 82, out)
        bmp.recycle()
        return Triple(out.toByteArray(), "slip.jpg", "image/jpeg")
    }

    /** Camera target for "Take photo" (FileProvider, app cache — removed with the app's cache). */
    fun newSlipPhotoUri(): android.net.Uri {
        val ctx = getApplication<Application>()
        val dir = File(ctx.cacheDir, "slips").apply { mkdirs() }
        val f = File(dir, "slip_${System.currentTimeMillis()}.jpg")
        return androidx.core.content.FileProvider.getUriForFile(ctx, ctx.packageName + ".files", f)
    }

    fun acceptMedia() { pendingMedia.value?.let { g.media.accept(it) } }
    fun consentLaunched() = g.media.consentLaunched()
    fun declineMedia() { pendingMedia.value?.let { g.media.decline(it) } }
    fun mediaConsent(projection: android.content.Intent?, kind: String) {
        if (kind == "screen") { if (projection == null) g.media.osConsentDenied() else g.media.startScreen(projection) }
        else if (kind == "voice") { if (projection == null) g.media.osConsentDenied() else g.media.startVoice() }
        else { if (projection == null) g.media.osConsentDenied() else g.media.startCamera() }
    }
    fun stopMedia() = g.media.endActive("employee_stopped")

    private fun stopWork() {
        chatWatch?.cancel(); chatWatch = null
        g.realtime.stop()
        LocationService.stop(getApplication())
        g.media.endActive("employee_stopped")
    }

    private suspend fun loadBranding(throwOnError: Boolean = false) {
        try {
            val b = g.api.call("GET", "/api/mobile/v1/bootstrap", null, ApiClient.Auth.NONE).obj()["branding"]!!.jsonObject
            g.store.writeFile("branding.json", b.toString())
            applyBranding(b, fetchLogo(b))
        } catch (e: Exception) {
            if (throwOnError) throw (e as? ApiException ?: ApiException(0, "unreachable", "Could not reach the server. Check the address and your connection."))
            g.store.readFile("branding.json")?.let { applyBranding(g.api.json.parseToJsonElement(it).jsonObject, cachedLogo()) }
        }
    }

    private fun applyBranding(b: JsonObject, logo: Bitmap?) {
        branding.value = Branding(b.str("display_name") ?: BuildConfig.PRODUCT_NAME, b.str("primary_color") ?: "#1F3A5F", logo)
    }

    /** Logo is accepted only if its SHA-256 matches what the server's branding record says. */
    private suspend fun fetchLogo(b: JsonObject): Bitmap? {
        val url = b.str("logo_url") ?: return null
        val sha = b.str("logo_sha256") ?: return null
        val origin = g.config.serverOrigin ?: return null
        if (!url.startsWith("$origin/")) return null   // only from our own server; never send the workspace header elsewhere
        val bytes = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
            runCatching { okhttp3.OkHttpClient().newCall(okhttp3.Request.Builder().url(url).apply { g.config.workspace?.let { header("X-Workspace", it) } }.build()).execute().use { it.body?.bytes() } }.getOrNull()
        } ?: return cachedLogo()
        if (RequestSigning.sha256Hex(bytes) != sha) return null
        File(getApplication<Application>().filesDir, "branding_logo").writeBytes(bytes)
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
    }

    private fun cachedLogo(): Bitmap? = File(getApplication<Application>().filesDir, "branding_logo").takeIf { it.exists() }?.let { BitmapFactory.decodeFile(it.path) }

    private fun act(block: suspend () -> Unit) = viewModelScope.launch {
        busy.value = true
        error.value = null
        try { block() } catch (e: ApiException) { error.value = e.message } catch (e: java.io.IOException) { error.value = "No connection to the server." }
        catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { error.value = e.message ?: "Unexpected error" }
        finally { busy.value = false }
    }

    companion object { const val ACTION_OPEN_CHAT = ametecs.managedmobile.push.NotificationActions.ACTION_OPEN_CHAT; val TERMINAL = setOf("completed", "disconnected", "busy", "no_answer", "rejected", "failed", "cancelled", "agent_unanswered") }
}
