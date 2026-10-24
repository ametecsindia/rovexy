package ametecs.managedmobile.ui

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.animateIntAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import java.time.LocalDate
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import ametecs.managedmobile.R
import ametecs.managedmobile.BuildConfig
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ametecs.managedmobile.core.CachedCustomer
import ametecs.managedmobile.media.MediaRequest
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

@Composable
fun AppRoot(vm: AppViewModel, requestScreenConsent: () -> Unit, requestCameraConsent: () -> Unit, requestMicConsent: () -> Unit, requestCallPermissions: () -> Unit, requestDialerRole: () -> Unit) {
    val ask by vm.permissionAsk.collectAsState()
    LaunchedEffect(ask) { if (ask > 0) requestCallPermissions() }
    val roleAsk by vm.dialerRoleAsk.collectAsState()
    LaunchedEffect(roleAsk) { if (roleAsk > 0) requestDialerRole() }
    val screen by vm.screen.collectAsState()
    val pending by vm.pendingMedia.collectAsState()
    val active by vm.activeMedia.collectAsState()
    val consent by vm.consentNeeded.collectAsState()
    val mediaError by vm.mediaError.collectAsState()
    // OS consent is launched from the UI thread of the CURRENT activity, after the employee accepted in-app.
    LaunchedEffect(consent) {
        consent?.let { kind -> vm.consentLaunched(); when (kind) { "screen" -> requestScreenConsent(); "voice" -> requestMicConsent(); else -> requestCameraConsent() } }
    }
    Column(Modifier.fillMaxSize().background(Ds.Bg).navigationBarsPadding()) {
        active?.let { MonitoringBanner(it.sessionId, voice = it.kind == "voice", onStop = vm::stopMedia) }   // always-visible indicator in-app
        mediaError?.let { Text(it, color = Ds.Red, modifier = Modifier.padding(12.dp)) }
        when (val s = screen) {
            Screen.Connect -> AuthLayout(vm, mode = "connect")
            is Screen.Pending -> PendingScreen(vm, s.code)
            is Screen.Ended -> EndedScreen(s.state, vm::startNewRegistration)
            Screen.Login -> AuthLayout(vm, mode = "login")
            is Screen.Policy -> PolicyScreen(s, vm::retryPolicy, vm::logout)
            Screen.Dashboard -> Dashboard(vm)
            is Screen.Locked -> CenterMessage(AmmpIcons.Shield, Ds.Amber, "Locked", s.reason) { PrimaryButton("Retry", onClick = vm::refresh) }
        }
    }
    pending?.let { MediaRequestDialog(it, onAccept = vm::acceptMedia, onDecline = vm::declineMedia) }
    val routeAsk by vm.routeAsk.collectAsState()
    val options by vm.routeOptions.collectAsState()
    val numbers by vm.c2cNumbers.collectAsState()
    val defaultNumber by vm.c2cDefault.collectAsState()
    routeAsk?.let { (c, which) ->
        // Only the options this employee is allowed (Calling configuration); Click2Call may also need a company number.
        var pick by remember(c.id) { mutableStateOf(defaultNumber?.takeIf { it in numbers } ?: numbers.firstOrNull()) }
        AlertDialog(onDismissRequest = vm::cancelRouteAsk, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), icon = { IconTile(AmmpIcons.Call, Ds.Green, 48) },
            title = { Text("Call ${c.name}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    if ("sim" in options) Text("SIM call: dials from this phone's SIM.", fontSize = 14.sp)
                    if ("server" in options) Text("Click2Call: this phone rings first; answer it and the customer is connected from the company number. Recorded by the company.", fontSize = 14.sp)
                    if (vm.c2cPicker()) {
                        Text("Company number for Click2Call", fontSize = 13.sp, color = Ds.Muted)
                        numbers.forEach { n ->
                            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { pick = n }.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                                RadioButton(selected = pick == n, onClick = { pick = n })
                                Text("+91 $n", fontSize = 15.sp)
                            }
                        }
                    }
                }
            },
            confirmButton = { if ("server" in options) TextButton(onClick = { vm.placeCall(c, which, "server", pick) }) { Text("Click2Call") } },
            dismissButton = { if ("sim" in options) TextButton(onClick = { vm.placeCall(c, which, "sim") }) { Text("SIM call") } else TextButton(onClick = vm::cancelRouteAsk) { Text("Cancel") } })
    }
}

// ───────────────────────────── shared pieces ─────────────────────────────

@Composable
private fun PrimaryButton(text: String, modifier: Modifier = Modifier, busy: Boolean = false, enabled: Boolean = true, color: Color = Ds.Navy, onClick: () -> Unit) {
    Button(onClick = onClick, enabled = enabled && !busy, shape = RoundedCornerShape(14.dp), modifier = modifier.height(52.dp),
        colors = ButtonDefaults.buttonColors(containerColor = color, disabledContainerColor = color.copy(alpha = 0.4f))) {
        if (busy) CircularProgressIndicator(Modifier.size(20.dp), color = Color.White, strokeWidth = 2.dp)
        else Text(text, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
    }
}

@Composable
private fun Field(value: String, onChange: (String) -> Unit, label: String, enabled: Boolean = true, password: Boolean = false, type: KeyboardType = KeyboardType.Text, leading: ImageVector? = null,
                  reveal: Boolean = false, trailing: (@Composable () -> Unit)? = null) {
    OutlinedTextField(value, onChange, label = { Text(label) }, enabled = enabled, singleLine = true, shape = RoundedCornerShape(14.dp),
        leadingIcon = leading?.let { { Icon(it, null, tint = Ds.Muted, modifier = Modifier.size(20.dp)) } },
        trailingIcon = trailing,
        visualTransformation = if (password && !reveal) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
        keyboardOptions = KeyboardOptions(keyboardType = if (password) KeyboardType.Password else type),
        colors = OutlinedTextFieldDefaults.colors(focusedContainerColor = Ds.Surface, unfocusedContainerColor = Ds.Surface, disabledContainerColor = Ds.Bg,
            unfocusedBorderColor = Ds.Line, focusedBorderColor = Ds.Navy),
        modifier = Modifier.fillMaxWidth().padding(vertical = 5.dp))
}

/** Uploaded company logo, or a neutral placeholder. No brand mark is ever invented. */
@Composable
fun Logo(b: Branding, size: Int = 72) {
    if (b.logo != null) Box(Modifier.size(size.dp).background(Color.White, RoundedCornerShape(18.dp)).padding(8.dp), contentAlignment = Alignment.Center) {
        Image(b.logo.asImageBitmap(), contentDescription = "${b.name} logo")
    }
    else Box(Modifier.size(size.dp).background(Color.White.copy(alpha = 0.12f), RoundedCornerShape(18.dp)).border(1.dp, Ds.Gold.copy(alpha = 0.6f), RoundedCornerShape(18.dp))
        .semantics { contentDescription = "Logo placeholder" }, contentAlignment = Alignment.Center) {
        Text(initialsOf(b.name), color = Ds.GoldSoft, fontWeight = FontWeight.SemiBold, fontSize = (size * 0.33f).sp)
    }
}

private fun mmss(sec: Int) = if (sec >= 3600) "%dh %02dm".format(sec / 3600, sec / 60 % 60) else "%d:%02d".format(sec / 60, sec % 60)

// ───────────────────────────── sign-in / connect ─────────────────────────────

/** Company logo, Server URL, User ID, Password, Sign In. Before approval: Connect/Register.
 *  "Classic split" (chosen 13 Oct): navy brand panel with logo, headline and three points; white form sheet below. */
@Composable
fun AuthLayout(vm: AppViewModel, mode: String) {
    val b by vm.branding.collectAsState()
    val busy by vm.busy.collectAsState()
    val err by vm.error.collectAsState()
    var server by remember { mutableStateOf(vm.serverUrl) }
    var workspace by remember { mutableStateOf(vm.workspace) }
    var user by remember { mutableStateOf("") }
    var pass by remember { mutableStateOf("") }
    var reveal by remember { mutableStateOf(false) }
    val locked = mode == "login" || vm.serverLocked
    val login = mode == "login"
    val navy = Color(0xFF072654); val navyBrand = Color(0xFF0A2F6B)
    val gold = Color(0xFFC89B2D); val goldHi = Color(0xFFE0B84D)
    BoxWithConstraints(Modifier.fillMaxSize().background(navy)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).heightIn(min = maxHeight).imePadding(), verticalArrangement = Arrangement.SpaceBetween) {
            Column(Modifier.statusBarsPadding().padding(start = 24.dp, end = 24.dp, top = 22.dp, bottom = 24.dp)) {
                Image(painterResource(R.drawable.rovexy_logo_dark), contentDescription = "ROVEXY — Your Workforce. In Motion.", modifier = Modifier.width(200.dp))
                Spacer(Modifier.height(30.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.width(22.dp).height(2.dp).background(goldHi))
                    Spacer(Modifier.width(8.dp))
                    Text(if (login) "FIELD STAFF" else "DEVICE SETUP", color = goldHi, fontSize = 11.sp, letterSpacing = 2.sp, fontWeight = FontWeight.Bold)
                }
                Spacer(Modifier.height(10.dp))
                Text("Your workforce,", color = Color.White, fontSize = 26.sp, lineHeight = 30.sp, fontWeight = FontWeight.ExtraBold)
                Text("always on record.", color = goldHi, fontSize = 26.sp, lineHeight = 30.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.height(14.dp))
                listOf("Calls recorded with their outcome", "Visits verified at the customer's address").forEach { point ->
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 4.dp)) {
                        Box(Modifier.size(22.dp).background(goldHi.copy(alpha = 0.15f), RoundedCornerShape(6.dp)), contentAlignment = Alignment.Center) {
                            Text("\u2713", color = goldHi, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                        Spacer(Modifier.width(10.dp))
                        Text(point, color = Color(0xFFC9D4E3), fontSize = 13.sp)
                    }
                }
            }
            Column(Modifier.fillMaxWidth().background(Ds.Surface, RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)).navigationBarsPadding()
                .padding(start = 22.dp, end = 22.dp, top = 22.dp, bottom = 18.dp)) {
                Box(Modifier.align(Alignment.CenterHorizontally).width(56.dp).height(4.dp).background(Brush.horizontalGradient(listOf(gold, goldHi)), RoundedCornerShape(2.dp)))
                Spacer(Modifier.height(16.dp))
                // Which company this phone signs in to (uploaded logo or initials).
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().background(Ds.Bg, RoundedCornerShape(14.dp)).padding(10.dp)) {
                    Box(Modifier.size(40.dp).background(navyBrand, RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) { Logo(b, size = 40) }
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(if (login) "Signing in to" else "Connecting to", color = Ds.Muted, fontSize = 11.sp)
                        Text(b.name, color = Ds.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
                Spacer(Modifier.height(14.dp))
                Text(if (login) "Welcome back" else "Connect this phone", color = navy, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold)
                Text(if (login) "Use the User ID and password from your office." else "Enter the server address your office gave you.",
                    color = Ds.Muted, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp, bottom = 8.dp))
                Field(server, { server = it }, "Server URL", enabled = !locked, type = KeyboardType.Uri, leading = AmmpIcons.Shield)
                if (mode == "connect" && !locked) Field(workspace, { workspace = it }, "Company workspace (if given)", leading = AmmpIcons.People)
                if (login) {
                    Field(user, { user = it }, "User ID", leading = AmmpIcons.People)
                    Field(pass, { pass = it }, "Password", password = true, leading = AmmpIcons.Shield, reveal = reveal,
                        trailing = { TextButton(onClick = { reveal = !reveal }) { Text(if (reveal) "Hide" else "Show", color = navyBrand, fontWeight = FontWeight.SemiBold, fontSize = 13.sp) } })
                }
                AnimatedVisibility(err != null) {
                    Text(err.orEmpty(), color = Ds.Red, fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)
                        .background(Ds.Red.copy(alpha = 0.07f), RoundedCornerShape(10.dp)).padding(horizontal = 12.dp, vertical = 10.dp))
                }
                Spacer(Modifier.height(16.dp))
                PrimaryButton(if (login) "Sign in  \u2192" else "Connect / Register  \u2192", Modifier.fillMaxWidth(), busy = busy, color = navyBrand) {
                    if (login) vm.login(user, pass) else vm.connect(server, workspace)
                }
                Spacer(Modifier.height(14.dp))
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                    Icon(AmmpIcons.Shield, null, tint = gold, modifier = Modifier.size(14.dp))
                    Spacer(Modifier.width(6.dp))
                    Text("Company-managed device \u00b7 activity is visible to you under Monitoring", color = Ds.Muted, fontSize = 11.sp)
                }
            }
        }
    }
}

@Composable
fun PendingScreen(vm: AppViewModel, code: String) {
    val b by vm.branding.collectAsState()
    CenterMessage(AmmpIcons.Clock, Ds.Gold, "Waiting for approval", "Read this code to your administrator. It only matches this phone to the request; it is not a password.") {
        Box(Modifier.background(Ds.Bg, RoundedCornerShape(16.dp)).border(1.dp, Ds.Gold.copy(alpha = 0.6f), RoundedCornerShape(16.dp)).padding(horizontal = 22.dp, vertical = 14.dp)) {
            Text(code, fontFamily = FontFamily.Monospace, fontSize = 32.sp, fontWeight = FontWeight.Bold, letterSpacing = 4.sp, color = Ds.Navy)
        }
        Spacer(Modifier.height(16.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp, color = Ds.Navy)
            Spacer(Modifier.width(8.dp))
            Text("${b.name} · checking automatically", style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
fun EndedScreen(state: String, onNew: () -> Unit) {
    var confirm by remember { mutableStateOf(false) }
    CenterMessage(AmmpIcons.Warning, Ds.Red, if (state == "rejected") "Request rejected" else "Access revoked",
        "This phone can no longer access work data. Company device restrictions remain in place. Contact your IT administrator.") {
        OutlinedButton(onClick = { confirm = true }, shape = RoundedCornerShape(14.dp)) { Text("Register again (administrator asked me to)") }
    }
    if (confirm) AlertDialog(onDismissRequest = { confirm = false }, confirmButton = { TextButton(onClick = { confirm = false; onNew() }) { Text("Start new registration") } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text("Cancel") } }, title = { Text("New registration") },
        text = { Text("A new identity will be created and must be approved again.") })
}

@Composable
fun PolicyScreen(s: Screen.Policy, retry: () -> Unit, logout: () -> Unit) {
    CenterMessage(AmmpIcons.Shield, Ds.Amber, "Device policy not yet applied", "Work features stay locked until all required company settings are applied and verified (state: ${s.state.replace('_', ' ')}).") {
        if (s.failing.isNotEmpty()) Text("Pending: " + s.failing.joinToString(", "), style = MaterialTheme.typography.bodySmall)
        Spacer(Modifier.height(14.dp))
        Row { PrimaryButton("Check again", onClick = retry); Spacer(Modifier.size(10.dp)); OutlinedButton(onClick = logout, shape = RoundedCornerShape(14.dp), modifier = Modifier.height(52.dp)) { Text("Sign out") } }
    }
}

@Composable
fun CenterMessage(icon: ImageVector, tint: Color, title: String, body: String, content: @Composable () -> Unit = {}) {
    Column(Modifier.fillMaxSize().padding(22.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        SurfaceCard(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(24.dp).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                IconTile(icon, tint, 56)
                Spacer(Modifier.height(14.dp))
                Text(title, style = MaterialTheme.typography.headlineSmall, color = Ds.Ink)
                Spacer(Modifier.height(8.dp))
                Text(body, style = MaterialTheme.typography.bodyMedium, color = Ds.Muted)
                Spacer(Modifier.height(18.dp))
                content()
            }
        }
    }
}

// ───────────────────────────── dashboard ─────────────────────────────

private fun greeting(): String = when (LocalDateTime.now().hour) { in 5..11 -> "Good morning"; in 12..16 -> "Good afternoon"; else -> "Good evening" }

@Composable
fun Dashboard(vm: AppViewModel) {
    val customers by vm.customers.collectAsState()
    val status by vm.sync.collectAsState()
    val call by vm.call.collectAsState()
    val err by vm.error.collectAsState()
    val busy by vm.busy.collectAsState()
    val today by vm.today.collectAsState()
    val disclosure by vm.disclosure.collectAsState()
    val b by vm.branding.collectAsState()
    val name by vm.employeeName.collectAsState()
    val missing by vm.missingPermissions.collectAsState()
    val unread by vm.chatUnread.collectAsState()
    val chatOpen by vm.chatOpen.collectAsState()
    val codes by vm.dispositions.collectAsState()
    val openId by vm.profileId.collectAsState()
    val fieldOpen by vm.fieldMap.collectAsState()
    var filter by remember { mutableStateOf(CaseFilter()) }
    var showDisclosure by remember { mutableStateOf(false) }
    var showFilters by remember { mutableStateOf(false) }
    var menu by remember { mutableStateOf(false) }
    val cases = remember(customers) { customers.map { it.toCase() } }
    val here by ametecs.managedmobile.work.LocationService.latest.collectAsState()
    val shown = remember(cases, filter, here) { applyCaseFilter(cases, filter, here) }
    val outcomeLabel = { c: String -> codes.firstOrNull { it.code == c }?.label ?: c.replace('_', ' ').lowercase().replaceFirstChar { it.uppercase() } }
    val openCase = openId?.let { id -> cases.firstOrNull { it.c.id == id } }
    val worked = customers.count { it.status != "new" }
    val progress by animateFloatAsState(if (customers.isEmpty()) 0f else worked.toFloat() / customers.size, tween(900), label = "progress")

    if (openCase != null) CustomerProfile(vm, openCase, vm::closeProfile)
    else if (fieldOpen) FieldMapScreen(vm, cases, filter, vm::closeFieldMap)
    else LazyColumn(Modifier.fillMaxSize().background(Ds.Bg)) {
        item(key = "hero") {
            Box(Modifier.fillMaxWidth().background(Ds.Hero, RoundedCornerShape(bottomStart = 32.dp, bottomEnd = 32.dp)).statusBarsPadding()
                .padding(start = 20.dp, end = 12.dp, top = 12.dp, bottom = 56.dp)) {
                Column {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Logo(b, 36)
                        Spacer(Modifier.width(10.dp))
                        Text(b.name.uppercase(), color = Ds.Gold, style = MaterialTheme.typography.labelSmall, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                        HeroIcon(AmmpIcons.Place, "Field map") { vm.openFieldMap() }
                        HeroIcon(AmmpIcons.Refresh, "Sync", enabled = !busy, onClick = vm::refresh)
                        Box {
                            HeroIcon(AmmpIcons.People, "Menu") { menu = true }
                            DropdownMenu(menu, onDismissRequest = { menu = false }) {
                                DropdownMenuItem(text = { Text("Messages") }, leadingIcon = { Icon(AmmpIcons.People, null, tint = Ds.Navy, modifier = Modifier.size(20.dp)) },
                                    onClick = { menu = false; vm.openChat() })
                                DropdownMenuItem(text = { Text("What my company can see") }, leadingIcon = { Icon(AmmpIcons.Shield, null, tint = Ds.Navy, modifier = Modifier.size(20.dp)) },
                                    onClick = { menu = false; showDisclosure = true })
                                DropdownMenuItem(text = { Column {
                                        Text("Calls: phone dialer + its recorder")
                                        Text("Tap to set up the Phone app and recording", style = MaterialTheme.typography.bodySmall, color = Ds.Muted)
                                    } },
                                    leadingIcon = { Icon(AmmpIcons.Call, null, tint = Ds.Navy, modifier = Modifier.size(20.dp)) },
                                    onClick = { menu = false; vm.askPermissions() })
                                DropdownMenuItem(text = { Text("Sign out") }, leadingIcon = { Icon(AmmpIcons.Logout, null, tint = Ds.Red, modifier = Modifier.size(20.dp)) },
                                    onClick = { menu = false; vm.logout() })
                            }
                        }
                    }
                    Spacer(Modifier.height(18.dp))
                    Text("${greeting()},", color = Color(0xFFC8D3E0), fontSize = 15.sp)
                    Text(name.ifBlank { "there" }.split(" ").first(), color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(10.dp))
                    val age = status.lastSyncEpochMs?.let { (System.currentTimeMillis() - it) / 60000 }
                    Row(Modifier.background(Color.White.copy(alpha = 0.09f), RoundedCornerShape(50)).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(8.dp).background(if (status.online) Color(0xFF4ADE80) else Color(0xFF94A3B8), CircleShape))
                        Spacer(Modifier.width(8.dp))
                        Text((if (status.online) "Online" else "Offline") + " · synced " + (age?.let { if (it < 1) "just now" else "$it min ago" } ?: "never") +
                            (if (status.pendingEvents > 0) " · ${status.pendingEvents} waiting" else ""), color = Color.White, fontSize = 12.sp)
                    }
                    Spacer(Modifier.height(18.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Portfolio worked", color = Color(0xFFC8D3E0), fontSize = 12.sp, modifier = Modifier.weight(1f))
                        Text("$worked of ${customers.size}", color = Ds.GoldSoft, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                    }
                    Spacer(Modifier.height(6.dp))
                    Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(3.dp)).background(Color.White.copy(alpha = 0.12f))) {
                        Box(Modifier.fillMaxWidth(progress).height(6.dp).background(Brush.horizontalGradient(listOf(Ds.GoldSoft, Ds.Gold)), RoundedCornerShape(3.dp)))
                    }
                }
            }
        }
        item(key = "stats") {
            TodayCard(today ?: Today(0, 0, 0, 0), Modifier.offset(y = (-36).dp).padding(horizontal = 16.dp))
        }
        item(key = "alerts") {
            Column(Modifier.offset(y = (-24).dp).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (missing.isNotEmpty()) Banner(AmmpIcons.Warning, Ds.Amber, "Finish call setup", "Still needed: ${missing.joinToString(", ")}", "Fix", vm::askPermissions)
                err?.let { Banner(AmmpIcons.Warning, Ds.Red, "Something went wrong", it, null, null) }
                if (unread) Banner(AmmpIcons.People, Ds.Blue, "New message", "Your supervisor sent you a message.", "Open", vm::openChat)
            }
        }
        item(key = "map") {
            val pinned = cases.count { it.lat != null }
            MapShortcut(if (pinned > 0) "$pinned of ${cases.size} cases on the map · road distance & navigation" else "Road distance & navigation to your customers") { vm.openFieldMap() }
        }
        item(key = "tiles") {
            Column(Modifier.padding(top = 20.dp)) {
                Row(Modifier.padding(horizontal = 16.dp, vertical = 0.dp).padding(bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Today's priorities", color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 17.sp, modifier = Modifier.weight(1f))
                    Text("Tap to filter", color = Ds.Muted, fontSize = 12.sp)
                }
                PriorityTiles(cases, filter) { key -> filter = filter.copy(tile = if (filter.tile == key) "" else key) }
            }
        }
        item(key = "search") {
            Column(Modifier.padding(top = 20.dp)) {
                SearchFilterBar(filter, shown.size, cases.size, onChange = { filter = it }, onOpenFilters = { showFilters = true })
            }
        }
        if (shown.isEmpty()) item(key = "empty") {
            Column(Modifier.fillMaxWidth().padding(36.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                IconTile(AmmpIcons.People, Ds.Muted, 56)
                Spacer(Modifier.height(12.dp))
                Text(if (customers.isEmpty()) "No cases yet" else "No matches", style = MaterialTheme.typography.titleMedium, color = Ds.Ink)
                Text(if (customers.isEmpty()) "Tap Sync after your supervisor assigns cases." else "Try another name or clear the filters.", style = MaterialTheme.typography.bodySmall)
                if (customers.isNotEmpty() && (filter.active || filter.query.isNotBlank())) TextButton(onClick = { filter = CaseFilter(sort = filter.sort) }) { Text("Clear search and filters", color = Ds.Navy) }
            }
        }
        items(shown, key = { it.c.id }) { r ->
            CaseCard(r, outcomeLabel, enabled = !busy && call == null, onOpen = { vm.openProfile(r.c.id) }, onCall = { vm.placeCall(r.c, "primary") })
        }
        item(key = "foot") { Spacer(Modifier.height(32.dp)) }
    }

    call?.let { CallPanel(it, vm) }
    val setup by vm.recorderSetup.collectAsState()
    if (setup) RecorderSetupDialog(vm::openRecorderSettings, vm::closeRecorderSetup)
    val recGate by vm.recordingGate.collectAsState()
    if (recGate) RecordingGateDialog(vm::openCallSettings, vm::closeRecordingGate)
    if (showDisclosure) disclosure?.let { d -> DisclosureDialog(d) { showDisclosure = false } }
    if (showFilters) FilterSheet(cases, filter, onApply = { filter = it; showFilters = false }, onDismiss = { showFilters = false })
    chatOpen?.let { ChatDialog(vm, it) }
}

@Composable
private fun HeroIcon(icon: ImageVector, label: String, enabled: Boolean = true, onClick: () -> Unit) {
    Box(Modifier.padding(start = 6.dp).size(40.dp).background(Color.White.copy(alpha = 0.10f), CircleShape).clickable(enabled = enabled, onClick = onClick)
        .semantics { contentDescription = label }, contentAlignment = Alignment.Center) {
        Icon(icon, contentDescription = null, tint = Color.White.copy(alpha = if (enabled) 1f else 0.4f), modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun StatCard(icon: ImageVector, tint: Color, label: String, value: Int, sub: String?, modifier: Modifier, format: (Int) -> String = { it.toString() }) {
    val shown by animateIntAsState(value, tween(800), label = label)
    SurfaceCard(modifier) {
        Column(Modifier.padding(16.dp)) {
            IconTile(icon, tint)
            Spacer(Modifier.height(12.dp))
            Text(format(shown), color = Ds.Ink, fontSize = 26.sp, fontWeight = FontWeight.SemiBold, letterSpacing = (-0.5).sp)
            Text(label, style = MaterialTheme.typography.bodySmall)
            if (sub != null) Text(sub, color = tint, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

@Composable
private fun RecorderSetupDialog(open: () -> Unit, close: () -> Unit) {
    AlertDialog(onDismissRequest = close, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), icon = { IconTile(AmmpIcons.Mic, Ds.Red, 48) },
        title = { Text("Switch on the call recorder") }, text = {
            Text("Company calls are recorded. Android only lets this app hear the call after you switch its recorder on — once:\n\n" +
                "1. Tap Open settings.\n2. Open Downloaded apps (or Installed apps).\n3. Tap Company call recorder → On → Allow.\n4. Come back and tap Call again.\n\n" +
                "Switch greyed out? Settings → Apps → ROVEXY → ⋮ → Allow restricted settings.", style = MaterialTheme.typography.bodyMedium)
        }, confirmButton = { PrimaryButton("Open settings", onClick = open) }, dismissButton = { TextButton(onClick = close) { Text("Not now") } })
}

@Composable
private fun RecordingGateDialog(open: () -> Unit, close: () -> Unit) {
    AlertDialog(onDismissRequest = close, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), icon = { IconTile(AmmpIcons.Mic, Ds.Red, 48) },
        title = { Text("Turn on call recording") }, text = {
            Text("Company calls must be recorded. Calls are blocked until your Phone app records them.\n\n" +
                "1. Tap Open call settings.\n2. Open Record calls (Samsung: Record calls → Auto record calls; OPPO/OnePlus: Call recording → Auto-record all calls).\n" +
                "3. Switch auto-recording ON for all calls.\n4. Make one short test call from your Phone app (any number that answers).\n5. Come back and tap Call again.\n\n" +
                "Calling unlocks as soon as the recording of that test call is found on the phone.", style = MaterialTheme.typography.bodyMedium)
        }, confirmButton = { PrimaryButton("Open call settings", onClick = open) }, dismissButton = { TextButton(onClick = close) { Text("Not now") } })
}

@Composable
private fun DisclosureDialog(d: Disclosure, close: () -> Unit) {
    AlertDialog(onDismissRequest = close, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), icon = { IconTile(AmmpIcons.Shield, Ds.Navy, 48) },
        confirmButton = { TextButton(onClick = close) { Text("OK") } }, title = { Text("What your company can see") },
        text = { Text(listOfNotNull(
            if (d.recorded) "Calls are recorded. ${d.notice}" else "Calls are not recorded.",
            if (BuildConfig.PLAY) "Call duration comes from the call recording; recorded calls are uploaded to your company."
            else "Call duration comes from this phone's call log; recorded calls are uploaded to your company.",
            d.location?.let { "Location: $it." } ?: "Location is not collected.",
            if (d.liveView && !BuildConfig.PLAY) "Screen sharing can be requested; you must accept and Android asks for permission each time." else null,
            if (d.camera) "Camera sessions can be requested; you must accept and the camera indicator is shown." else null,
            "Nothing is captured without your knowledge: a ROVEXY notification is shown whenever location, a call or a session is active.").joinToString("\n\n"), style = MaterialTheme.typography.bodyMedium) })
}

// ───────────────────────────── chat ─────────────────────────────

@Composable
private fun ChatDialog(vm: AppViewModel, open: Long) {
    val convs by vm.chatConvs.collectAsState()
    val msgs by vm.chatMsgs.collectAsState()
    var text by remember { mutableStateOf("") }
    val list = androidx.compose.foundation.lazy.rememberLazyListState()
    LaunchedEffect(msgs.size) { if (msgs.isNotEmpty()) list.animateScrollToItem(msgs.size - 1) }
    AlertDialog(onDismissRequest = vm::closeChat, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp),
        title = { Text(if (open > 0) "Messages" else "Conversations") },
        confirmButton = { TextButton(onClick = vm::closeChat) { Text("Close") } },
        text = {
            if (open <= 0) {
                if (convs.isEmpty()) Text("No messages yet. Your supervisor can start a conversation from the admin console.")
                LazyColumn { items(convs) { c -> TextButton(onClick = { vm.openConv(c.id) }) { Text(c.subject ?: "Supervisor chat #${c.id}") } } }
            } else Column {
                LazyColumn(Modifier.height(360.dp).fillMaxWidth(), state = list) {
                    items(msgs) { m ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), horizontalArrangement = if (m.mine) Arrangement.End else Arrangement.Start) {
                            Column(Modifier.background(if (m.mine) Ds.Navy else Ds.Bg, RoundedCornerShape(14.dp)).padding(10.dp)) {
                                Text(m.body, color = if (m.mine) Color.White else Ds.Ink)
                                Text(m.at.take(16).replace('T', ' ') + if (m.mine && m.read) " · read" else "", style = MaterialTheme.typography.labelSmall,
                                    color = if (m.mine) Ds.GoldSoft else Ds.Muted)
                            }
                        }
                    }
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(text, { text = it }, Modifier.weight(1f), placeholder = { Text("Type a message") }, maxLines = 4)
                    TextButton(enabled = text.isNotBlank(), onClick = { vm.sendChat(text.trim()); text = "" }) { Text("Send") }
                }
            }
        })
}

// ───────────────────────────── call outcome ─────────────────────────────

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CallPanel(c: CallUi, vm: AppViewModel) {
    val codes by vm.dispositions.collectAsState()
    val modes by vm.paymentModes.collectAsState()
    val busy by vm.busy.collectAsState()
    val err by vm.error.collectAsState()
    val ended = c.state in AppViewModel.TERMINAL
    var code by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var followUp by remember { mutableStateOf<LocalDateTime?>(null) }
    // Promise / payment details (shown only for outcomes that ask for them).
    var amount by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("") }
    var ref by remember { mutableStateOf("") }
    var paidOn by remember { mutableStateOf(LocalDate.now()) }
    var slip by remember { mutableStateOf<android.net.Uri?>(null) }
    var slipLabel by remember { mutableStateOf("") }
    var photoTarget by remember { mutableStateOf<android.net.Uri?>(null) }
    val pickFile = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { u -> if (u != null) { slip = u; slipLabel = "File attached" } }
    val takePhoto = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok -> if (ok) { slip = photoTarget; slipLabel = "Photo attached" } }
    val cameraPerm = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) { photoTarget = vm.newSlipPhotoUri(); takePhoto.launch(photoTarget!!) }
    }
    val selected = codes.firstOrNull { it.code == code }
    val captures = selected?.captures ?: "none"
    val amountOk = amount.toBigDecimalOrNull()?.let { it.signum() > 0 } == true
    val canSave = code.isNotBlank() && when (captures) {
        "promise" -> amountOk && followUp != null
        "payment" -> amountOk && mode.isNotBlank()
        else -> selected?.needsFollowUp != true || followUp != null
    }
    val ctx = LocalContext.current
    val title = c.customer?.name ?: "Call"
    val headline = when (c.state) {
        "dialing" -> "Calling…"; "on_sim_call" -> "On call"; "saving" -> "Saving the call…"; "ask_answered" -> "Did the customer answer?"
        "initiated", "agent_ringing" -> if (c.agentLeg == "pstn_callback") "Your work phone will ring — answer it" else "Connecting…"
        "agent_connected", "ringing" -> "Ringing the customer…"; "answered" -> "Connected · ${mmss(c.talk)}"
        "completed" -> "Call completed · ${mmss(c.talk)}"; "no_answer" -> "Not answered"
        else -> c.state.replace('_', ' ').replaceFirstChar { it.uppercase() } + if (c.talk > 0) " · ${mmss(c.talk)}" else ""
    }
    AlertDialog(onDismissRequest = {}, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), title = null, text = {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState())) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Avatar(title, 48)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(title, style = MaterialTheme.typography.titleMedium, color = Ds.Ink)
                    Text(headline, color = if (c.state == "completed") Ds.Green else Ds.Muted, fontSize = 13.sp, fontWeight = FontWeight.Medium)
                }
                if (!ended && c.state != "ask_answered") CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = Ds.Navy)
            }
            c.note?.let { Text(it, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp)) }
            if (c.recording.isNotBlank() && c.recording != "not_required") {
                Spacer(Modifier.height(12.dp))
                val (icon, tint, text) = when (c.recording) {
                    "available" -> Triple(AmmpIcons.Check, Ds.Green, "Recording saved")
                    "failed" -> Triple(AmmpIcons.Warning, Ds.Red, "Recording not saved — auto call recording is OFF. Calls are blocked until it is switched on.")
                    else -> Triple(AmmpIcons.Mic, Ds.Amber, "Recording: " + c.recording.replace('_', ' '))
                }
                Row(Modifier.fillMaxWidth().background(tint.copy(alpha = 0.08f), RoundedCornerShape(12.dp)).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(8.dp))
                    Text(text, color = tint, fontSize = 13.sp, fontWeight = FontWeight.Medium)
                }
            }
            if (ended) {
                Spacer(Modifier.height(16.dp))
                Text("CALL FEEDBACK", style = MaterialTheme.typography.labelSmall, color = Ds.Muted)
                Spacer(Modifier.height(8.dp))
                codes.forEach { (k, label) ->
                    val on = code == k
                    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(12.dp))
                        .background(if (on) Ds.Navy.copy(alpha = 0.06f) else Color.Transparent).border(1.dp, if (on) Ds.Navy else Ds.Line, RoundedCornerShape(12.dp))
                        .clickable { code = k }.padding(horizontal = 12.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(18.dp).border(2.dp, if (on) Ds.Navy else Ds.Line, CircleShape).padding(4.dp).background(if (on) Ds.Navy else Color.Transparent, CircleShape))
                        Spacer(Modifier.width(10.dp))
                        Text(label, color = Ds.Ink, fontSize = 14.sp)
                    }
                }
                if (captures == "promise") {
                    Spacer(Modifier.height(10.dp))
                    Text("PROMISE", style = MaterialTheme.typography.labelSmall, color = Ds.Muted)
                    Field(amount, { amount = it.filter { ch -> ch.isDigit() || ch == '.' }.take(11) }, "Promised amount (₹)", type = KeyboardType.Decimal)
                    Text("Pick the promise date below.", color = Ds.Muted, fontSize = 12.sp)
                }
                if (captures == "payment") {
                    Spacer(Modifier.height(10.dp))
                    Text("PAYMENT", style = MaterialTheme.typography.labelSmall, color = Ds.Muted)
                    Field(amount, { amount = it.filter { ch -> ch.isDigit() || ch == '.' }.take(11) }, "Amount received (₹)", type = KeyboardType.Decimal)
                    Text("Payment mode", color = Ds.Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp, bottom = 4.dp))
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        modes.forEach { (k, label) -> Pill(label, Ds.Navy, selected = mode == k) { mode = k } }
                    }
                    Field(ref, { ref = it.take(64) }, if (mode == "CASH") "Receipt no. (optional)" else "Reference / UTR / cheque no.")
                    Row(Modifier.fillMaxWidth().padding(top = 4.dp).clip(RoundedCornerShape(12.dp)).border(1.dp, Ds.Line, RoundedCornerShape(12.dp)).clickable {
                        DatePickerDialog(ctx, { _, y, m, d -> paidOn = LocalDate.of(y, m + 1, d) }, paidOn.year, paidOn.monthValue - 1, paidOn.dayOfMonth)
                            .apply { datePicker.maxDate = System.currentTimeMillis() }.show()
                    }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(AmmpIcons.Event, null, tint = Ds.Navy, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(10.dp))
                        Text("Paid on " + paidOn.format(DateTimeFormatter.ofPattern("d MMM yyyy")), color = Ds.Ink, fontSize = 14.sp)
                    }
                    Text("Payment slip / receipt", color = Ds.Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 10.dp, bottom = 4.dp))
                    if (slip != null) {
                        Row(Modifier.fillMaxWidth().background(Ds.Green.copy(alpha = 0.08f), RoundedCornerShape(12.dp)).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(AmmpIcons.Check, null, tint = Ds.Green, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(8.dp))
                            Text(slipLabel, color = Ds.Green, fontSize = 13.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
                            Text("Remove", color = Ds.Red, fontSize = 13.sp, modifier = Modifier.clickable { slip = null })
                        }
                    } else {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(onClick = { cameraPerm.launch(android.Manifest.permission.CAMERA) }) { Text("Take photo") }
                            OutlinedButton(onClick = { pickFile.launch(arrayOf("image/*", "application/pdf")) }) { Text("Choose file") }
                        }
                        Text("Optional, but the office checks payments against the slip.", color = Ds.Muted, fontSize = 12.sp)
                    }
                }
                Field(notes, { notes = it }, "Notes (optional)")
                val fmt = DateTimeFormatter.ofPattern("EEE d MMM, h:mm a")
                Row(Modifier.fillMaxWidth().padding(top = 4.dp).clip(RoundedCornerShape(12.dp)).border(1.dp, Ds.Line, RoundedCornerShape(12.dp)).clickable {
                    val now = LocalDateTime.now().plusDays(1).withHour(10).withMinute(0)
                    DatePickerDialog(ctx, { _, y, m, d ->
                        TimePickerDialog(ctx, { _, hh, mm -> followUp = LocalDateTime.of(y, m + 1, d, hh, mm) }, now.hour, now.minute, false).show()
                    }, now.year, now.monthValue - 1, now.dayOfMonth).apply { datePicker.minDate = System.currentTimeMillis() }.show()
                }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(AmmpIcons.Event, null, tint = Ds.Amber, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(10.dp))
                    Text(followUp?.format(fmt) ?: when { captures == "promise" -> "Promise date (required)"; selected?.needsFollowUp == true -> "Follow-up date (required)"; else -> "Add follow-up (optional)" }, color = if (followUp != null) Ds.Ink else Ds.Muted, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    if (followUp != null) Text("Clear", color = Ds.Red, fontSize = 13.sp, modifier = Modifier.clickable { followUp = null })
                }
            }
        }
    }, confirmButton = {
        if (ended) Column(horizontalAlignment = Alignment.End) {
            err?.let { Text(it, color = Ds.Red, fontSize = 13.sp, modifier = Modifier.padding(bottom = 6.dp)) }
            PrimaryButton("Save feedback", enabled = canSave, busy = busy) {
                vm.submitDisposition(code, notes, followUp?.atZone(ZoneId.systemDefault())?.toOffsetDateTime()?.toString(),
                    amount = amount.takeIf { captures != "none" }, mode = mode.takeIf { captures == "payment" }, ref = ref.takeIf { captures == "payment" },
                    paidOn = paidOn.toString().takeIf { captures == "payment" }, slip = slip.takeIf { captures == "payment" })
            }
        }
        else if (c.state == "ask_answered") Row {
            TextButton(onClick = { vm.answered(false) }) { Text("No answer") }
            TextButton(onClick = { vm.answered(true) }) { Text("Yes, answered") }
        }
        else if (c.route == "sim") TextButton(onClick = vm::onResumed) { Text("Finish call") }
    }, dismissButton = {
        if (!ended && c.route == "sim" && c.state != "dialing") TextButton(onClick = vm::dismissCall) { Text("Close") }
        else if (!ended) TextButton(onClick = vm::hangup) { Text("Hang up") }
    })
}

// ───────────────────────────── supervision ─────────────────────────────

@Composable
fun MediaRequestDialog(r: MediaRequest, onAccept: () -> Unit, onDecline: () -> Unit) {
    if (r.kind == "voice") {
        AlertDialog(onDismissRequest = onDecline, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), icon = { IconTile(AmmpIcons.Call, Ds.Green, 48) },
            title = { Text("Incoming call") }, text = { Text("${r.requestedBy} is calling you over the internet (no SIM charges).") },
            confirmButton = { PrimaryButton("Answer", color = Ds.Green, onClick = onAccept) }, dismissButton = { TextButton(onClick = onDecline) { Text("Decline", color = Ds.Red) } })
        return
    }
    AlertDialog(onDismissRequest = onDecline, containerColor = Ds.Surface, shape = RoundedCornerShape(28.dp), icon = { IconTile(AmmpIcons.Shield, Ds.Navy, 48) },
        title = { Text(if (r.kind == "screen") "Screen sharing request" else "Camera session request") },
        text = { Text("${r.requestedBy} asks to see your ${if (r.kind == "screen") "screen" else "camera"}.\n\nPurpose: ${r.purpose}\n\nIf you accept, Android will also ask for permission. A notification and indicator stay visible while sharing, and you can stop at any time.") },
        confirmButton = { PrimaryButton("Accept", onClick = onAccept) }, dismissButton = { TextButton(onClick = onDecline) { Text("Decline") } })
}

@Composable
fun MonitoringBanner(sessionId: String, voice: Boolean = false, onStop: () -> Unit) {
    Row(Modifier.fillMaxWidth().background(Ds.Red).statusBarsPadding().padding(horizontal = 14.dp, vertical = 8.dp).semantics { contentDescription = "Sharing active $sessionId" },
        verticalAlignment = Alignment.CenterVertically) {
        Text(if (voice) "● On a call with your supervisor" else "● Sharing with your supervisor", color = Color.White, modifier = Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
        TextButton(onClick = onStop) { Text(if (voice) "HANG UP" else "STOP", color = Color.White, fontWeight = FontWeight.Bold) }
    }
}
