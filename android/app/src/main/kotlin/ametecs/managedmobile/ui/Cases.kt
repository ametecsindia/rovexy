package ametecs.managedmobile.ui

import android.annotation.SuppressLint
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.ScrollableTabRow
import androidx.compose.material3.Tab
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import ametecs.managedmobile.core.CachedCustomer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import java.text.NumberFormat
import java.time.Instant
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import java.util.Locale

// ───────────────────────────── data ─────────────────────────────

private val json = Json { ignoreUnknownKeys = true }

private fun JsonObject.s(k: String): String? = (this[k] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content?.takeIf { it != "null" && it.isNotBlank() }
private fun JsonObject.d(k: String): Double? = (this[k] as? JsonPrimitive)?.doubleOrNull
private fun JsonObject.i(k: String): Int? = (this[k] as? JsonPrimitive)?.intOrNull
private fun JsonObject.b(k: String): Boolean = (this[k] as? JsonPrimitive)?.content == "true"
private fun JsonObject.o(k: String): JsonObject? = this[k] as? JsonObject
private fun JsonObject.a(k: String): List<JsonObject> = (this[k] as? JsonArray)?.mapNotNull { it as? JsonObject } ?: emptyList()
private fun date(s: String?): LocalDate? = s?.let { runCatching { LocalDate.parse(it.take(10)) }.getOrNull() }
private fun instant(s: String?): Instant? = s?.let { runCatching { Instant.parse(it) }.getOrNull() ?: runCatching { java.time.OffsetDateTime.parse(it).toInstant() }.getOrNull() }

/** A case as shown in the list — read from what the server sent (nothing estimated on the phone). */
data class CaseRow(
    val c: CachedCustomer, val portfolio: String?, val team: String?, val pool: Boolean, val city: String?, val outstanding: Double?, val overdue: Double?,
    val emi: Double?, val dueDate: LocalDate?, val oldestDue: LocalDate?, val lastCallAt: Instant?, val lastOutcome: String?, val calls: Int, val connected: Int,
    val nextCallback: Instant?, val promiseAmount: Double?, val promiseDate: LocalDate?, val promiseStatus: String?, val verified: Double, val pending: Double,
    val lastPaymentOn: LocalDate?, val lastPaymentAmount: Double?,
    /** Map pin (server sends it only when usable) and why it is missing: "missing" | "invalid" | null. */
    val lat: Double? = null, val lng: Double? = null, val locationIssue: String? = null, val address: String? = null,
) {
    /** Days since the oldest unpaid instalment (null = not uploaded or nothing overdue). */
    val dpd: Int? get() = oldestDue?.takeIf { overdue == null || overdue > 0 }?.let { ChronoUnit.DAYS.between(it, LocalDate.now()).toInt().coerceAtLeast(0) }
    val callbackDate: LocalDate? get() = nextCallback?.atZone(ZoneId.systemDefault())?.toLocalDate()
}

fun CachedCustomer.toCase(): CaseRow {
    val o = runCatching { json.parseToJsonElement(extra).jsonObject }.getOrDefault(JsonObject(emptyMap()))
    return CaseRow(this, o.s("portfolio"), o.s("team"), o.b("pool"), o.s("city"), o.d("outstanding"), o.d("overdue_amount"), o.d("emi_amount"),
        date(o.s("due_date")), date(o.s("oldest_due_date")), instant(o.s("last_call_at")), o.s("last_outcome"), o.i("calls") ?: 0, o.i("connected") ?: 0,
        instant(o.s("next_callback_at")), o.d("promise_amount"), date(o.s("promise_date")), o.s("promise_status"), o.d("collected_verified") ?: 0.0,
        o.d("collected_pending") ?: 0.0, date(o.s("last_payment_recorded_on")), o.d("last_payment_recorded_amount"),
        o.d("lat"), o.d("lng"), o.s("location_issue"), listOfNotNull(o.s("address"), o.s("area"), o.s("city"), o.s("pincode")).joinToString(", ").ifBlank { null })
}

private val inr: NumberFormat = NumberFormat.getCurrencyInstance(Locale("en", "IN")).apply { maximumFractionDigits = 0 }
fun money(v: Double?): String = v?.let { inr.format(it) } ?: "—"
private val dFmt = DateTimeFormatter.ofPattern("d MMM yyyy")
private val dtFmt = DateTimeFormatter.ofPattern("d MMM, h:mm a")
private fun fmtDate(d: LocalDate?) = d?.format(dFmt) ?: "—"
private fun fmtWhen(i: Instant?) = i?.atZone(ZoneId.systemDefault())?.format(dtFmt) ?: "—"
private fun fmtWhen(s: String?) = fmtWhen(instant(s))

// ───────────────────────────── filters & dashboard ─────────────────────────────

data class CaseFilter(
    val query: String = "", val status: String = "", val portfolio: String = "", val dpd: String = "", val amount: String = "", val calls: String = "",
    val callback: String = "", val assigned: String = "", val tile: String = "", val sort: String = "dpd",
) {
    val active get() = listOf(status, portfolio, dpd, amount, calls, callback, assigned, tile).any { it.isNotEmpty() }
}

private fun dpdBucket(d: Int?) = when { d == null || d == 0 -> "0"; d <= 30 -> "1-30"; d <= 60 -> "31-60"; d <= 90 -> "61-90"; else -> "90+" }

fun applyCaseFilter(all: List<CaseRow>, f: CaseFilter, here: android.location.Location? = null): List<CaseRow> {
    val today = LocalDate.now()
    val q = f.query.trim()
    val digits = q.filter { it.isDigit() }
    return all.filter { r ->
        (q.isEmpty() || r.c.name.contains(q, true) || r.c.ref.contains(q, true) || (digits.length >= 4 && (r.c.phone.endsWith(digits) || r.c.altPhone?.endsWith(digits) == true))) &&
            (f.status.isEmpty() || r.c.status == f.status) &&
            (f.portfolio.isEmpty() || (r.portfolio ?: "Added by hand") == f.portfolio) &&
            (f.dpd.isEmpty() || dpdBucket(r.dpd) == f.dpd) &&
            (f.amount.isEmpty() || when (f.amount) { "none" -> (r.overdue ?: 0.0) <= 0; "lt10k" -> (r.overdue ?: 0.0) in 0.01..9999.99; "10-50k" -> (r.overdue ?: 0.0) in 10000.0..50000.0; else -> (r.overdue ?: 0.0) > 50000 }) &&
            (f.calls.isEmpty() || when (f.calls) { "none" -> r.calls == 0; "no_connect" -> r.calls > 0 && r.connected == 0; else -> r.connected > 0 }) &&
            (f.callback.isEmpty() || when (f.callback) { "today" -> r.callbackDate == today; "overdue" -> r.callbackDate?.isBefore(today) == true; "scheduled" -> r.nextCallback != null; else -> r.nextCallback == null }) &&
            (f.assigned.isEmpty() || (f.assigned == "pool") == r.pool) &&
            (f.tile.isEmpty() || when (f.tile) {
                "overdue" -> (r.dpd ?: 0) > 0
                "due_today" -> r.dueDate == today
                "upcoming" -> r.dueDate != null && !r.dueDate.isBefore(today) && !r.dueDate.isAfter(today.plusDays(7))
                "ptp_today" -> r.promiseStatus == "open" && r.promiseDate == today
                "ptp_broken" -> r.promiseStatus == "broken"
                "cb_today" -> r.callbackDate != null && !r.callbackDate!!.isAfter(today)
                "not_contacted" -> r.calls == 0
                "paid_today" -> r.lastPaymentOn == today
                "verified" -> r.verified > 0
                else -> true
            })
    }.let { list ->
        when (f.sort) {
            "amount" -> list.sortedByDescending { it.overdue ?: -1.0 }
            "outstanding" -> list.sortedByDescending { it.outstanding ?: -1.0 }
            "dpd_asc" -> list.sortedBy { it.dpd ?: Int.MAX_VALUE }
            "due" -> list.sortedBy { it.dueDate ?: LocalDate.MAX }
            "callback" -> list.sortedBy { it.nextCallback ?: Instant.MAX }
            "promise" -> list.sortedWith(compareBy<CaseRow> { if (it.promiseStatus == "open") 0 else if (it.promiseStatus == "broken") 1 else 2 }.thenBy { it.promiseDate ?: LocalDate.MAX })
            "last_call" -> list.sortedBy { it.lastCallAt ?: Instant.MIN }   // not called first, then longest since the last call
            "recent_call" -> list.sortedByDescending { it.lastCallAt ?: Instant.MIN }
            "nearest" -> list.sortedBy { r ->   // straight line from the phone's last fix; cases without a pin go last
                if (here == null || r.lat == null || r.lng == null) Float.MAX_VALUE
                else FloatArray(1).also { android.location.Location.distanceBetween(here.latitude, here.longitude, r.lat, r.lng, it) }[0]
            }
            "paid" -> list.sortedByDescending { it.lastPaymentOn ?: LocalDate.MIN }
            "name" -> list.sortedBy { it.c.name.lowercase() }
            else -> list.sortedWith(compareByDescending<CaseRow> { it.dpd ?: -1 }.thenByDescending { it.overdue ?: 0.0 })
        }
    }
}

/** Priority tiles: tap one to see those cases. */
@Composable
fun CollectionTiles(all: List<CaseRow>, f: CaseFilter, onTile: (String) -> Unit) {
    val today = LocalDate.now()
    val tiles = listOf(
        Triple("", "Assigned cases", all.size.toString()),
        Triple("overdue", "Total overdue", money(all.sumOf { if ((it.dpd ?: 0) > 0) it.overdue ?: 0.0 else 0.0 })),
        Triple("due_today", "Due today", all.count { it.dueDate == today }.toString()),
        Triple("upcoming", "Instalments in 7 days", all.count { it.dueDate != null && !it.dueDate.isBefore(today) && !it.dueDate.isAfter(today.plusDays(7)) }.toString()),
        Triple("ptp_today", "Promises due today", all.count { it.promiseStatus == "open" && it.promiseDate == today }.toString()),
        Triple("ptp_broken", "Broken promises", all.count { it.promiseStatus == "broken" }.toString()),
        Triple("cb_today", "Callbacks due", all.count { it.callbackDate != null && !it.callbackDate!!.isAfter(today) }.toString()),
        Triple("not_contacted", "Not contacted", all.count { it.calls == 0 }.toString()),
        Triple("paid_today", "Payments today", all.count { it.lastPaymentOn == today }.toString()),
        Triple("verified", "Verified collections", money(all.sumOf { it.verified })),
    )
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        tiles.forEach { (key, label, value) ->
            val on = f.tile == key && (key.isNotEmpty() || !f.active)
            val warn = key in setOf("ptp_broken", "cb_today", "due_today") && value != "0"
            Column(Modifier.width(128.dp).clip(RoundedCornerShape(16.dp)).background(if (on) Ds.Navy else Ds.Surface).border(1.dp, if (on) Ds.Navy else Ds.Line, RoundedCornerShape(16.dp))
                .clickable { onTile(key) }.padding(12.dp)) {
                Text(value, color = if (on) Color.White else if (warn) Ds.Red else Ds.Ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
                Text(label, color = if (on) Ds.GoldSoft else Ds.Muted, fontSize = 12.sp, maxLines = 2)
            }
        }
    }
    // Overdue ageing: counts per days-overdue range (tap to filter).
    val buckets = listOf("0" to "Not overdue", "1-30" to "1–30 days", "31-60" to "31–60", "61-90" to "61–90", "90+" to "90+ days")
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        buckets.forEach { (k, label) -> Pill("$label  ${all.count { dpdBucket(it.dpd) == k }}", if (k == "0") Ds.Teal else Ds.Red, f.dpd == k) { onTile("dpd:$k") } }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CaseFilterBar(all: List<CaseRow>, f: CaseFilter, onChange: (CaseFilter) -> Unit) {
    var more by remember { mutableStateOf(false) }
    Column(Modifier.padding(horizontal = 16.dp)) {
        OutlinedTextField(f.query, { onChange(f.copy(query = it)) }, placeholder = { Text("Search name, case ID or last 4+ digits") }, singleLine = true, shape = RoundedCornerShape(16.dp),
            leadingIcon = { Icon(AmmpIcons.Search, null, tint = Ds.Muted, modifier = Modifier.size(20.dp)) },
            colors = OutlinedTextFieldDefaults.colors(focusedContainerColor = Ds.Surface, unfocusedContainerColor = Ds.Surface, unfocusedBorderColor = Ds.Line, focusedBorderColor = Ds.Navy),
            modifier = Modifier.fillMaxWidth())
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            listOf("dpd" to "Oldest overdue", "amount" to "Highest overdue", "due" to "Next due date", "callback" to "Next callback", "name" to "Name").forEach { (k, l) ->
                Pill(l, Ds.Gold, f.sort == k) { onChange(f.copy(sort = k)) }
            }
        }
        Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = { more = !more }) { Text(if (more) "Hide filters" else "More filters") }
            if (f.active) TextButton(onClick = { onChange(CaseFilter(query = f.query, sort = f.sort)) }) { Text("Clear filters", color = Ds.Red) }
        }
        if (more) {
            val portfolios = all.map { it.portfolio ?: "Added by hand" }.distinct().sorted()
            FilterGroup("PORTFOLIO", f.portfolio, portfolios.map { it to it }) { onChange(f.copy(portfolio = it)) }
            FilterGroup("CASE STATUS", f.status, listOf("new" to "New", "in_progress" to "In progress", "follow_up" to "Follow-up", "closed" to "Closed")) { onChange(f.copy(status = it)) }
            FilterGroup("OVERDUE AMOUNT", f.amount, listOf("none" to "Nothing overdue", "lt10k" to "Up to ₹10k", "10-50k" to "₹10k–50k", "gt50k" to "Over ₹50k")) { onChange(f.copy(amount = it)) }
            FilterGroup("DAYS OVERDUE", f.dpd, listOf("0" to "0", "1-30" to "1–30", "31-60" to "31–60", "61-90" to "61–90", "90+" to "90+")) { onChange(f.copy(dpd = it)) }
            FilterGroup("CALL STATUS", f.calls, listOf("none" to "Not called yet", "no_connect" to "Called, not connected", "connected" to "Connected")) { onChange(f.copy(calls = it)) }
            FilterGroup("CALLBACK", f.callback, listOf("today" to "Due today", "overdue" to "Overdue", "scheduled" to "Scheduled", "none" to "None")) { onChange(f.copy(callback = it)) }
            FilterGroup("ASSIGNMENT", f.assigned, listOf("mine" to "Assigned to me", "pool" to "Group pool")) { onChange(f.copy(assigned = it)) }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun FilterGroup(title: String, current: String, opts: List<Pair<String, String>>, pick: (String) -> Unit) {
    Text(title, style = MaterialTheme.typography.labelSmall, color = Ds.Muted, modifier = Modifier.padding(top = 8.dp, bottom = 4.dp))
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        (listOf("" to "All") + opts).forEach { (k, l) -> Pill(l, Ds.Navy, current == k) { pick(k) } }
    }
}

@Composable
fun CaseCard(r: CaseRow, outcomeLabel: (String) -> String, enabled: Boolean, onOpen: () -> Unit, onCall: () -> Unit) {
    SurfaceCard(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 5.dp), onClick = onOpen) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Avatar(r.c.name, 40)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(r.c.name, fontWeight = FontWeight.SemiBold, color = Ds.Ink, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(listOfNotNull(r.c.ref, r.portfolio, r.c.phone.takeLast(4).let { "••$it" }).joinToString(" · "), style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Box(Modifier.size(44.dp).background(if (enabled) Ds.Green else Ds.Green.copy(alpha = 0.35f), CircleShape).clickable(enabled = enabled, onClick = onCall)
                    .semantics { contentDescription = "Call ${r.c.name}" }, contentAlignment = Alignment.Center) {
                    Icon(AmmpIcons.Call, null, tint = Color.White, modifier = Modifier.size(20.dp))
                }
            }
            Spacer(Modifier.height(10.dp))
            Row {
                Metric("Overdue", money(r.overdue), if ((r.dpd ?: 0) > 0) Ds.Red else Ds.Ink, Modifier.weight(1f))
                Metric("Days overdue", r.dpd?.toString() ?: "—", if ((r.dpd ?: 0) > 60) Ds.Red else Ds.Ink, Modifier.weight(1f))
                Metric("Outstanding", money(r.outstanding), Ds.Ink, Modifier.weight(1f))
            }
            Spacer(Modifier.height(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                StatusTag(r.c.status)
                Spacer(Modifier.width(8.dp))
                Text(listOfNotNull(
                    r.dueDate?.let { "Due ${it.format(dFmt)}" },
                    r.lastCallAt?.let { "Last call ${fmtWhen(it)}" + (r.lastOutcome?.let { o -> " · ${outcomeLabel(o)}" } ?: "") } ?: "Not called yet",
                ).joinToString(" · "), style = MaterialTheme.typography.bodySmall, maxLines = 2)
            }
            val flags = listOfNotNull(
                r.nextCallback?.let { (if (r.callbackDate?.isAfter(LocalDate.now()) == true) "Callback " else "Callback due ") + fmtWhen(it) to Ds.Amber },
                when (r.promiseStatus) { "broken" -> "Broken promise ${money(r.promiseAmount)} (${fmtDate(r.promiseDate)})" to Ds.Red; "open" -> "Promise ${money(r.promiseAmount)} on ${fmtDate(r.promiseDate)}" to Ds.Blue; else -> null },
                if (r.pool) "Group pool" to Ds.Muted else null,
            )
            if (flags.isNotEmpty()) Row(Modifier.padding(top = 6.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                flags.forEach { (t, c) -> Tag(t, c) }
            }
        }
    }
}

@Composable
private fun Tag(text: String, c: Color) {
    Box(Modifier.background(c.copy(alpha = 0.10f), RoundedCornerShape(6.dp)).padding(horizontal = 8.dp, vertical = 3.dp)) {
        Text(text.replace('_', ' ').replaceFirstChar { it.uppercase() }, color = c, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
    }
}

private val PlaceIcon: ImageVector = ImageVector.Builder(defaultWidth = 24.dp, defaultHeight = 24.dp, viewportWidth = 24f, viewportHeight = 24f)
    .addPath(androidx.compose.ui.graphics.vector.PathParser().parsePathString("M12,2C8.13,2 5,5.13 5,9c0,5.25 7,13 7,13s7,-7.75 7,-13c0,-3.87 -3.13,-7 -7,-7zM12,11.5c-1.38,0 -2.5,-1.12 -2.5,-2.5s1.12,-2.5 2.5,-2.5 2.5,1.12 2.5,2.5 -1.12,2.5 -2.5,2.5z").toNodes(),
        fill = androidx.compose.ui.graphics.SolidColor(Color.White)).build()

@Composable
private fun Metric(label: String, value: String, color: Color, modifier: Modifier) {
    Column(modifier) {
        Text(value, color = color, fontWeight = FontWeight.SemiBold, fontSize = 15.sp, maxLines = 1)
        Text(label, style = MaterialTheme.typography.bodySmall)
    }
}

// ───────────────────────────── profile ─────────────────────────────

private val TABS = listOf("Overview", "Details", "Loan & dues", "Payments", "Calls", "Follow-ups", "Address & map")

/** Full case page. Server copy when online; the saved summary when offline. */
@Composable
fun CustomerProfile(vm: AppViewModel, case: CaseRow, onClose: () -> Unit) {
    val p by vm.profile.collectAsState()
    val offline by vm.profileOffline.collectAsState()
    val busy by vm.busy.collectAsState()
    val call by vm.call.collectAsState()
    val err by vm.error.collectAsState()
    val codes by vm.dispositions.collectAsState()
    val label = { c: String -> codes.firstOrNull { it.code == c }?.label ?: c.replace('_', ' ').lowercase().replaceFirstChar { it.uppercase() } }
    var tab by remember { mutableStateOf(0) }
    var dialog by remember { mutableStateOf(vm.profileDialog.value.also { vm.profileDialog.value = null }) }   // "payment" when opened from the map
    val ctx = LocalContext.current
    BackHandler(onBack = onClose)
    val dues = p?.o("dues")
    val days = dues?.i("days_overdue") ?: case.dpd
    val overdueNow = dues?.d("overdue_now") ?: case.overdue
    val address = p?.let { addressOf(it) }
    val loc = p?.o("location")

    Column(Modifier.fillMaxSize().background(Ds.Bg)) {
        // Header: who, which case, how much and how late.
        Column(Modifier.fillMaxWidth().background(Ds.Hero, RoundedCornerShape(bottomStart = 28.dp, bottomEnd = 28.dp)).statusBarsPadding().padding(start = 8.dp, end = 16.dp, top = 8.dp, bottom = 16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = onClose) { Text("‹ Back", color = Color.White) }
                Spacer(Modifier.weight(1f))
                if (offline) Text("Offline · saved summary", color = Ds.GoldSoft, fontSize = 12.sp)
                TextButton(onClick = vm::reloadProfile) { Text("Refresh", color = Ds.GoldSoft) }
            }
            Row(Modifier.padding(start = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Avatar(case.c.name, 52)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(case.c.name, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(listOfNotNull(case.c.ref, p?.s("portfolio") ?: case.portfolio).joinToString(" · "), color = Color(0xFFC8D3E0), fontSize = 13.sp)
                    Text(p?.o("assigned_user")?.s("name")?.let { "Assigned to $it" + (p?.o("team")?.s("name")?.let { t -> " · $t" } ?: "") } ?: (case.team?.let { "Group: $it" } ?: ""),
                        color = Color(0xFFC8D3E0), fontSize = 12.sp)
                }
                StatusTag(p?.s("status") ?: case.c.status)
            }
            Row(Modifier.padding(start = 8.dp, top = 14.dp)) {
                HeroMetric("Overdue now", money(overdueNow), Modifier.weight(1f), alert = (days ?: 0) > 0)
                HeroMetric("Days overdue", days?.toString() ?: "—", Modifier.weight(1f), alert = (days ?: 0) > 30)
                HeroMetric("Outstanding", money(dues?.d("remaining_balance") ?: case.outstanding), Modifier.weight(1f))
            }
        }
        // Main actions stay at hand on every tab.
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ActionChip(AmmpIcons.Call, "Call", Ds.Green, Modifier.weight(1f), enabled = !busy && call == null) { if (case.c.altPhone != null) dialog = "which" else vm.placeCall(case.c, "primary") }
            ActionChip(AmmpIcons.Event, "Callback", Ds.Amber, Modifier.weight(1f)) { dialog = "callback" }
            ActionChip(AmmpIcons.Check, "Payment", Ds.Teal, Modifier.weight(1f)) { dialog = "payment" }
            ActionChip(PlaceIcon, "Directions", Ds.Blue, Modifier.weight(1f), enabled = p == null || address != null || loc != null) {
                if (p == null) vm.reloadProfile() else openDirections(ctx, loc, address)
            }
        }
        Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = { dialog = "promise" }, modifier = Modifier.weight(1f)) { Text("Promise", fontSize = 13.sp) }
            OutlinedButton(onClick = { dialog = "note" }, modifier = Modifier.weight(1f)) { Text("Note", fontSize = 13.sp) }
            OutlinedButton(onClick = { dialog = "status" }, modifier = Modifier.weight(1f)) { Text("Status", fontSize = 13.sp) }
        }
        err?.let { Text(it, color = Ds.Red, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) }
        ScrollableTabRow(selectedTabIndex = tab, containerColor = Ds.Bg, edgePadding = 12.dp) {
            TABS.forEachIndexed { i, t -> Tab(selected = tab == i, onClick = { tab = i }, text = { Text(t, fontSize = 13.sp) }) }
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            if (p == null) {
                if (offline) Notice("No connection — full details, history and the map need the server. Saved summary below.", Ds.Amber)
                else Row(verticalAlignment = Alignment.CenterVertically) { CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp); Spacer(Modifier.width(8.dp)); Text("Loading case…", style = MaterialTheme.typography.bodySmall) }
                Spacer(Modifier.height(10.dp))
                OfflineSummary(case, label)
            } else when (tab) {
                0 -> OverviewTab(p!!, label)
                1 -> DetailsTab(p!!, ctx)
                2 -> DuesTab(p!!)
                3 -> PaymentsTab(p!!)
                4 -> CallsTab(p!!, label)
                5 -> FollowUpsTab(p!!)
                else -> AddressTab(p!!, ctx, vm, case)
            }
            Spacer(Modifier.height(40.dp))
        }
    }

    when (dialog) {
        "which" -> AlertDialog(onDismissRequest = { dialog = null }, title = { Text("Which number?") }, confirmButton = {
            TextButton(onClick = { dialog = null; vm.placeCall(case.c, "primary") }) { Text("Primary") } }, dismissButton = {
            TextButton(onClick = { dialog = null; vm.placeCall(case.c, "alternate") }) { Text("Alternate") } })
        "note" -> TextDialog("Add collection note", "Note", busy, onDismiss = { dialog = null }) { vm.addCaseNote(case.c.id, it); dialog = null }
        "status" -> StatusDialog(p?.s("status") ?: case.c.status, busy, onDismiss = { dialog = null }) { s, n -> vm.setCaseStatus(case.c.id, s, n); dialog = null }
        "callback" -> CallbackDialog(busy, onDismiss = { dialog = null }) { iso, n -> vm.scheduleCallback(case.c.id, iso, n); dialog = null }
        "payment", "promise" -> EntryDialog(vm, case, dialog!!, onDismiss = { dialog = null })
    }
}

@Composable
private fun HeroMetric(label: String, value: String, modifier: Modifier, alert: Boolean = false) {
    Column(modifier) {
        Text(value, color = if (alert) Color(0xFFFFB4A8) else Color.White, fontSize = 18.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
        Text(label, color = Color(0xFFC8D3E0), fontSize = 11.sp)
    }
}

@Composable
private fun ActionChip(icon: ImageVector, text: String, tint: Color, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Ds.Surface).border(1.dp, Ds.Line, RoundedCornerShape(14.dp)).clickable(enabled = enabled, onClick = onClick)
        .padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(icon, null, tint = if (enabled) tint else Ds.Muted, modifier = Modifier.size(20.dp))
        Text(text, fontSize = 12.sp, color = if (enabled) Ds.Ink else Ds.Muted, fontWeight = FontWeight.Medium)
    }
}

@Composable
private fun Notice(text: String, tint: Color) {
    Text(text, color = tint, fontSize = 13.sp, modifier = Modifier.fillMaxWidth().background(tint.copy(alpha = 0.08f), RoundedCornerShape(12.dp)).padding(12.dp))
}

@Composable
private fun Section(title: String, content: @Composable () -> Unit) {
    Text(title.uppercase(), style = MaterialTheme.typography.labelSmall, color = Ds.Muted, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
    SurfaceCard(Modifier.fillMaxWidth()) { Column(Modifier.padding(14.dp)) { content() } }
}

/** One label/value line; a missing value says so instead of being left out silently. */
@Composable
private fun Kv(label: String, value: String?, strong: Boolean = false, color: Color = Ds.Ink) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Text(label, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
        Text(value ?: "Not available", color = if (value == null) Ds.Muted else color, fontSize = if (strong) 16.sp else 14.sp,
            fontWeight = if (strong) FontWeight.SemiBold else FontWeight.Normal, modifier = Modifier.weight(1.3f))
    }
}

@Composable
private fun OfflineSummary(r: CaseRow, label: (String) -> String) {
    Section("Saved summary") {
        Kv("Overdue (as uploaded)", r.overdue?.let(::money), strong = true, color = Ds.Red)
        Kv("Days overdue", r.dpd?.toString())
        Kv("Total outstanding (as uploaded)", r.outstanding?.let(::money))
        Kv("Next instalment", r.emi?.let { money(it) + (r.dueDate?.let { d -> " on ${fmtDate(d)}" } ?: "") })
        Kv("Last call", r.lastCallAt?.let { fmtWhen(it) + (r.lastOutcome?.let { o -> " · ${label(o)}" } ?: "") } ?: "Not called yet")
        Kv("Next callback", r.nextCallback?.let { fmtWhen(it) } ?: "None")
        Kv("Promise", r.promiseAmount?.let { "${money(it)} on ${fmtDate(r.promiseDate)} · ${r.promiseStatus}" } ?: "None")
    }
}

@Composable
private fun OverviewTab(p: JsonObject, label: (String) -> String) {
    val d = p.o("dues")
    val act = p.o("activity")
    if ((d?.d("outstanding") ?: d?.d("overdue_amount")) == null) Notice("Loan and due amounts were not in the uploaded file for this case.", Ds.Muted)
    Section("Collection status") {
        Kv("Overdue now", d?.d("overdue_now")?.let(::money), strong = true, color = Ds.Red)
        Kv("Days overdue", d?.i("days_overdue")?.toString())
        Kv("Next instalment", d?.d("emi_amount")?.let { money(it) + (d?.s("due_date")?.let { x -> " on ${fmtDate(date(x))}" } ?: "") })
        Kv("Total outstanding", d?.d("remaining_balance")?.let(::money))
        Kv("Collected (verified)", money(act?.d("collected_verified")))
        if ((act?.d("collected_pending") ?: 0.0) > 0) Kv("Waiting for office check", money(act?.d("collected_pending")), color = Ds.Amber)
    }
    Section("Next action") {
        val next = p.o("next_action")
        Kv("Callback", next?.s("due_at")?.let { fmtWhen(it) } ?: "None scheduled")
        next?.s("notes")?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
        val pr = p.a("promises").firstOrNull()
        Kv("Latest promise", pr?.let { "${money(it.d("amount"))} on ${fmtDate(date(it.s("date")))} · ${it.s("status")}" } ?: "None",
            color = when (pr?.s("status")) { "broken" -> Ds.Red; "kept" -> Ds.Green; else -> Ds.Ink })
        Kv("Last call", act?.s("last_call_at")?.let { fmtWhen(it) + (act?.s("last_outcome")?.let { o -> " · ${label(o)}" } ?: "") } ?: "Not called yet")
    }
    Section("Activity") { Timeline(p.a("timeline")) }
}

@Composable
private fun Timeline(items: List<JsonObject>) {
    if (items.isEmpty()) { Text("No activity yet.", style = MaterialTheme.typography.bodySmall); return }
    items.forEach { t ->
        val type = t.s("type") ?: ""
        val (title, tint) = when (type) {
            "call" -> ("Call · " + (t.s("outcome") ?: t.s("state")?.replace('_', ' ') ?: "")) to Ds.Blue
            "entry" -> ("Recorded without call · " + (t.s("outcome") ?: "")) to Ds.Teal
            "callback" -> "Callback scheduled for ${fmtWhen(t.s("due_at"))}${if (t.s("status") != "open") " (${t.s("status")})" else ""}" to Ds.Amber
            "note" -> "Note" to Ds.Navy
            "status" -> "Status: ${t.o("meta")?.s("from")?.replace('_', ' ')} → ${t.o("meta")?.s("to")?.replace('_', ' ')}" to Ds.Gold
            "assignment" -> (t.s("notes") ?: "Assignment") to Ds.Muted
            "location" -> "Address / map location" to Ds.Teal
            "visit" -> "Field visit · reached the customer" to Ds.Green
            else -> type to Ds.Muted
        }
        Row(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
            Box(Modifier.padding(top = 5.dp).size(8.dp).background(tint, CircleShape))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = Ds.Ink, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                val amt = t.d("amount")
                if (amt != null) Text(if (t.s("payment_mode") != null) "Payment ${money(amt)} · ${t.s("payment_mode")} · ${t.s("review_status")}" else "Promised ${money(amt)} by ${fmtDate(date(t.s("promise_date")))}",
                    color = Ds.Teal, fontSize = 13.sp)
                if (type == "call" && (t.i("talk_seconds") ?: 0) > 0) Text("Talk ${t.i("talk_seconds")!! / 60}m ${t.i("talk_seconds")!! % 60}s" +
                    (t.o("recording")?.s("status")?.takeIf { it != "not_required" }?.let { " · recording ${it.replace('_', ' ')}" } ?: ""), style = MaterialTheme.typography.bodySmall)
                if (type != "assignment") t.s("notes")?.let { Text(it, fontSize = 13.sp, color = Ds.Ink) }
                Text(listOfNotNull(fmtWhen(t.s("at")), t.s("by")).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun DetailsTab(p: JsonObject, ctx: Context) {
    Section("Customer") {
        Kv("Name", p.s("name")); Kv("Case ID", p.s("customer_ref"))
        Kv("Phone", p.s("phone")); Kv("Alternate", p.s("alt_phone")); Kv("Email", p.s("email"))
        Kv("Preferred contact time", p.s("preferred_time"))
    }
    Section("Assignment") {
        Kv("Portfolio", p.s("portfolio") ?: "Added by hand"); Kv("Campaign", p.s("campaign"))
        Kv("Assigned employee", p.o("assigned_user")?.let { "${it.s("name")} (${it.s("employee_code")})" } ?: "Group pool")
        Kv("Group", p.o("team")?.s("name")); Kv("Assigned on", p.s("assigned_at")?.let { fmtWhen(it) })
        Kv("Case status", p.s("status")?.replace('_', ' '))
    }
    Section("Address") { AddressBlock(p, ctx) }
}

@Composable
private fun DuesTab(p: JsonObject) {
    val d = p.o("dues") ?: JsonObject(emptyMap())
    Notice("Amounts are as uploaded on ${fmtDate(date(d.s("balance_as_of")))}. Only payments verified by the office are taken off; promises and unchecked payments are not.", Ds.Muted)
    Section("Overdue") {
        Kv("Overdue now", d.d("overdue_now")?.let(::money), strong = true, color = Ds.Red)
        Kv("Overdue (as uploaded)", d.d("overdue_amount")?.let(::money))
        Kv("Days overdue", d.i("days_overdue")?.toString())
        Kv("Oldest unpaid instalment", d.s("oldest_due_date")?.let { fmtDate(date(it)) })
        Kv("Overdue instalments", d.i("overdue_installments")?.toString())
    }
    Section("Next instalment") {
        Kv("Amount", d.d("emi_amount")?.let(::money), strong = true)
        Kv("Due date", d.s("due_date")?.let { fmtDate(date(it)) })
    }
    Section("Account") {
        Kv("Loan / account amount", d.d("loan_amount")?.let(::money))
        Kv("Total outstanding (as uploaded)", d.d("outstanding")?.let(::money))
        Kv("Principal outstanding", d.d("principal_os")?.let(::money))
        Kv("Interest outstanding", d.d("interest_os")?.let(::money))
        Kv("Late fees / charges", d.d("charges")?.let(::money))
        Kv("Verified payments since upload", money(d.d("verified_since_upload")))
        Kv("Remaining balance", d.d("remaining_balance")?.let(::money), strong = true)
        Kv("Last payment (from file)", d.d("last_paid_amount")?.let { money(it) + (d.s("last_paid_on")?.let { x -> " on ${fmtDate(date(x))}" } ?: "") })
    }
    Text("Instalment-wise schedule: not in the uploaded file (only the summary above).", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 10.dp))
}

@Composable
private fun PaymentsTab(p: JsonObject) {
    val pays = p.a("payments")
    val act = p.o("activity")
    Section("Totals") {
        Kv("Verified", money(act?.d("collected_verified")), strong = true, color = Ds.Green)
        Kv("Waiting for office check", money(act?.d("collected_pending")), color = Ds.Amber)
    }
    Section("Payment history") {
        if (pays.isEmpty()) Text("No payments recorded in the app yet.", style = MaterialTheme.typography.bodySmall)
        pays.forEach { x ->
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("${money(x.d("amount"))} · ${x.s("mode_label") ?: x.s("mode")}", fontWeight = FontWeight.SemiBold, color = Ds.Ink)
                    Text(listOfNotNull("Paid ${fmtDate(date(x.s("paid_on")))}", x.s("ref")?.let { "Ref $it" }, x.s("by"), if (x.b("has_slip")) "slip attached" else null).joinToString(" · "),
                        style = MaterialTheme.typography.bodySmall)
                    x.s("review_note")?.let { Text("Office: $it", style = MaterialTheme.typography.bodySmall) }
                }
                Tag(x.s("status") ?: "pending", when (x.s("status")) { "verified" -> Ds.Green; "rejected" -> Ds.Red; else -> Ds.Amber })
            }
        }
    }
    Section("Promises") {
        val pr = p.a("promises")
        if (pr.isEmpty()) Text("No promises recorded.", style = MaterialTheme.typography.bodySmall)
        pr.forEach { x ->
            Kv("${money(x.d("amount"))} by ${fmtDate(date(x.s("date")))}", (x.s("status") ?: "") + (x.s("by")?.let { " · $it" } ?: ""),
                color = when (x.s("status")) { "broken" -> Ds.Red; "kept" -> Ds.Green; else -> Ds.Blue })
        }
    }
}

@Composable
private fun CallsTab(p: JsonObject, label: (String) -> String) {
    val calls = p.a("timeline").filter { it.s("type") == "call" }
    val st = p.o("stats")
    Section("Summary") { Kv("Calls", st?.i("calls")?.toString()); Kv("Connected", st?.i("connected")?.toString()) }
    Section("Calling history") {
        if (calls.isEmpty()) Text("No calls yet.", style = MaterialTheme.typography.bodySmall)
        calls.forEach { c ->
            Column(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
                Text(fmtWhen(c.s("at")) + " · " + (c.s("outcome") ?: c.s("state")?.replace('_', ' ') ?: ""), fontWeight = FontWeight.SemiBold, color = Ds.Ink, fontSize = 14.sp)
                Text(listOfNotNull(c.s("by"), c.s("which")?.let { "$it number" }, (c.i("talk_seconds") ?: 0).let { "talk ${it / 60}m ${it % 60}s" },
                    c.o("recording")?.s("status")?.takeIf { it != "not_required" }?.let { "recording ${it.replace('_', ' ')}" }).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                c.s("notes")?.let { Text(it, fontSize = 13.sp, color = Ds.Ink) }
            }
        }
        Text("Recordings are kept by your company; supervisors with permission play them from the admin console.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 6.dp))
    }
}

@Composable
private fun FollowUpsTab(p: JsonObject) {
    Section("Callbacks") {
        val f = p.a("follow_ups")
        if (f.isEmpty()) Text("No callbacks scheduled.", style = MaterialTheme.typography.bodySmall)
        f.forEach { x ->
            val due = instant(x.s("due_at"))
            val late = x.s("status") == "open" && due?.isBefore(Instant.now()) == true
            Kv(fmtWhen(due), (if (late) "overdue" else x.s("status") ?: "") + (x.s("by")?.let { " · $it" } ?: ""), color = if (late) Ds.Red else Ds.Ink)
            x.s("notes")?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
        }
    }
    Section("Commitments") {
        val pr = p.a("promises")
        if (pr.isEmpty()) Text("No commitments recorded.", style = MaterialTheme.typography.bodySmall)
        pr.forEach { x -> Kv("${money(x.d("amount"))} by ${fmtDate(date(x.s("date")))}", x.s("status"), color = if (x.s("status") == "broken") Ds.Red else Ds.Ink) }
    }
}

@Composable
private fun AddressTab(p: JsonObject, ctx: Context, vm: AppViewModel, case: CaseRow) {
    var update by remember { mutableStateOf(false) }
    Section("Address") { AddressBlock(p, ctx) }
    val loc = p.o("location")
    Section("Map") {
        if (loc != null) {
            CaseMiniMap(vm, case.c.id, case.c.name, loc.d("lat")!!, loc.d("lng")!!) { OsmMap(loc.d("lat")!!, loc.d("lng")!!) }
            Text("Pin saved on this case. Tap the pin or Distance & route for the road route.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 6.dp))
        } else Text("Location unavailable — no map coordinates are saved for this customer. Directions use the address instead.", style = MaterialTheme.typography.bodySmall)
        Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (loc != null) Button(onClick = { vm.openFieldMap(case.c.id) }, colors = ButtonDefaults.buttonColors(containerColor = Ds.Navy)) { Text("Distance & route") }
            OutlinedButton(onClick = { update = true }) { Text(if (loc == null) "Add location" else "Update location") }
        }
    }
    Section("Address history") { AddressHistory(p.a("address_history")) }
    if (update) UpdateLocationDialog(vm, case) { update = false }
}

/** Every address / pin change on this case, newest first: previous → updated, when, how and by whom. */
@Composable
private fun AddressHistory(items: List<JsonObject>) {
    if (items.isEmpty()) { Text("No changes since this case was uploaded.", style = MaterialTheme.typography.bodySmall); return }
    items.forEachIndexed { i, h ->
        val moved = h.i("moved_m")?.let { if (it >= 1000) "%.1f km".format(it / 1000.0) else "$it m" }
        val pin = when {
            h.o("old_pin") == null && h.o("new_pin") != null -> "Pin added"
            h.o("old_pin") != null && h.o("new_pin") == null -> "Pin removed"
            moved != null -> "Pin moved $moved"
            else -> null
        }
        val title = when (h.s("what")) { "address" -> "Address changed"; "both" -> "Address changed · ${pin ?: ""}"; else -> pin ?: "Map location" }
        Column(Modifier.fillMaxWidth().padding(vertical = 6.dp).background(if (i == 0) Ds.Gold.copy(alpha = 0.08f) else Color.Transparent, RoundedCornerShape(10.dp)).padding(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(title, color = Ds.Ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                if (i == 0) Text("CURRENT", color = Ds.Gold, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
            if (h.s("what") != "pin") {
                Text("Previous: " + (h.s("old_address") ?: "no address"), color = Ds.Muted, fontSize = 13.sp)
                Text("Updated: " + (h.s("new_address") ?: "no address"), color = Ds.Ink, fontSize = 13.sp)
            }
            Text(listOfNotNull(fmtWhen(h.s("at")), h.s("source_label"), h.s("by")).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun AddressBlock(p: JsonObject, ctx: Context) {
    val a = addressOf(p)
    val loc = p.o("location")
    Text(a ?: "Address not available.", color = if (a == null) Ds.Muted else Ds.Ink, fontSize = 14.sp)
    Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(onClick = { openDirections(ctx, loc, a) }, enabled = a != null || loc != null, colors = ButtonDefaults.buttonColors(containerColor = Ds.Navy)) { Text("Get directions") }
        OutlinedButton(onClick = { copy(ctx, a ?: "") }, enabled = a != null) { Text("Copy address") }
        if (loc != null) OutlinedButton(onClick = { openMap(ctx, loc, p.s("name") ?: "") }) { Text("Open map") }
    }
}

private fun addressOf(p: JsonObject): String? =
    listOfNotNull(p.s("address"), p.s("area"), p.s("city"), p.s("state"), p.s("pincode")).joinToString(", ").takeIf { p.s("address") != null || p.s("city") != null || p.s("pincode") != null }

/** Google Maps directions from the employee's current location (Maps uses it when no origin is given). Coordinates win over the address. */
fun openDirections(ctx: Context, loc: JsonObject?, address: String?) {
    val dest = loc?.let { "${it.d("lat")},${it.d("lng")}" } ?: address ?: return
    val uri = Uri.parse("https://www.google.com/maps/dir/?api=1&travelmode=two-wheeler&destination=" + Uri.encode(dest))
    runCatching { ctx.startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

/** Any installed maps app (geo: link) with the pin. */
private fun openMap(ctx: Context, loc: JsonObject, name: String) {
    val ll = "${loc.d("lat")},${loc.d("lng")}"
    runCatching { ctx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:$ll?q=$ll(${Uri.encode(name)})")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

private fun copy(ctx: Context, text: String) {
    (ctx.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("Address", text))
    android.widget.Toast.makeText(ctx, "Address copied", android.widget.Toast.LENGTH_SHORT).show()
}

/** Interactive OpenStreetMap with a marker (no API key, no extra library). */
@SuppressLint("SetJavaScriptEnabled")
@Composable
private fun OsmMap(lat: Double, lng: Double) {
    val d = 0.006
    val url = "https://www.openstreetmap.org/export/embed.html?bbox=${lng - d},${lat - d},${lng + d},${lat + d}&layer=mapnik&marker=$lat,$lng"
    AndroidView(factory = { c -> WebView(c).apply {
            // MATCH_PARENT, or the WebView reports a 0-px-tall page and the map stays blank.
            layoutParams = android.view.ViewGroup.LayoutParams(android.view.ViewGroup.LayoutParams.MATCH_PARENT, android.view.ViewGroup.LayoutParams.MATCH_PARENT)
            settings.javaScriptEnabled = true; webViewClient = WebViewClient(); loadUrl(url) } },
        modifier = Modifier.fillMaxWidth().height(240.dp).clip(RoundedCornerShape(12.dp)))
}

// ───────────────────────────── action dialogs ─────────────────────────────

@Composable
private fun TextDialog(title: String, label: String, busy: Boolean, onDismiss: () -> Unit, onSave: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = onDismiss, title = { Text(title) },
        text = { OutlinedTextField(text, { text = it.take(2000) }, label = { Text(label) }, minLines = 3, modifier = Modifier.fillMaxWidth()) },
        confirmButton = { TextButton(enabled = text.isNotBlank() && !busy, onClick = { onSave(text.trim()) }) { Text("Save") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
}

@Composable
private fun StatusDialog(current: String, busy: Boolean, onDismiss: () -> Unit, onSave: (String, String) -> Unit) {
    var s by remember { mutableStateOf(current) }
    var note by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Update case status") }, text = {
        Column {
            listOf("new" to "New", "in_progress" to "In progress", "follow_up" to "Follow-up", "closed" to "Closed (resolved)", "do_not_call" to "Do not call").forEach { (k, l) ->
                Row(Modifier.fillMaxWidth().clickable { s = k }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(18.dp).border(2.dp, if (s == k) Ds.Navy else Ds.Line, CircleShape).padding(4.dp).background(if (s == k) Ds.Navy else Color.Transparent, CircleShape))
                    Spacer(Modifier.width(10.dp)); Text(l)
                }
            }
            OutlinedTextField(note, { note = it.take(2000) }, label = { Text("Reason (optional)") }, modifier = Modifier.fillMaxWidth())
        }
    }, confirmButton = { TextButton(enabled = s != current && !busy, onClick = { onSave(s, note.trim()) }) { Text("Save") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
}

@Composable
private fun DateTimeField(value: LocalDateTime?, placeholder: String, onPick: (LocalDateTime) -> Unit) {
    val ctx = LocalContext.current
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(12.dp)).border(1.dp, Ds.Line, RoundedCornerShape(12.dp)).clickable {
        val now = LocalDateTime.now().plusDays(1).withHour(10).withMinute(0)
        DatePickerDialog(ctx, { _, y, m, d -> TimePickerDialog(ctx, { _, hh, mm -> onPick(LocalDateTime.of(y, m + 1, d, hh, mm)) }, now.hour, now.minute, false).show() },
            now.year, now.monthValue - 1, now.dayOfMonth).apply { datePicker.minDate = System.currentTimeMillis() }.show()
    }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(AmmpIcons.Event, null, tint = Ds.Amber, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(10.dp))
        Text(value?.format(DateTimeFormatter.ofPattern("EEE d MMM, h:mm a")) ?: placeholder, color = if (value != null) Ds.Ink else Ds.Muted, fontSize = 14.sp)
    }
}

@Composable
private fun CallbackDialog(busy: Boolean, onDismiss: () -> Unit, onSave: (String, String) -> Unit) {
    var at by remember { mutableStateOf<LocalDateTime?>(null) }
    var notes by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Schedule callback") }, text = {
        Column {
            DateTimeField(at, "Pick date and time") { at = it }
            OutlinedTextField(notes, { notes = it.take(2000) }, label = { Text("Notes (optional)") }, modifier = Modifier.fillMaxWidth())
            Text("This replaces any earlier open callback for this customer.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 6.dp))
        }
    }, confirmButton = { TextButton(enabled = at != null && !busy, onClick = { onSave(at!!.atZone(ZoneId.systemDefault()).toOffsetDateTime().toString(), notes.trim()) }) { Text("Save") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
}

/** Promise (commitment) or payment recorded from the profile without a call. Same rules as call feedback. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EntryDialog(vm: AppViewModel, case: CaseRow, kind: String, onDismiss: () -> Unit) {
    val codes by vm.dispositions.collectAsState()
    val modes by vm.paymentModes.collectAsState()
    val busy by vm.busy.collectAsState()
    val err by vm.error.collectAsState()
    LaunchedEffect(Unit) { vm.ensureOutcomes() }
    val options = codes.filter { it.captures == kind }
    var code by remember(options) { mutableStateOf(options.firstOrNull()?.code ?: "") }
    var amount by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("") }
    var ref by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var paidOn by remember { mutableStateOf(LocalDate.now()) }
    var promiseAt by remember { mutableStateOf<LocalDateTime?>(null) }
    var slip by remember { mutableStateOf<Uri?>(null) }
    var photo by remember { mutableStateOf<Uri?>(null) }
    val ctx = LocalContext.current
    val pickFile = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { u -> if (u != null) slip = u }
    val takePhoto = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) slip = photo }
    val cameraPerm = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) { photo = vm.newSlipPhotoUri(); takePhoto.launch(photo!!) } }
    val amountOk = amount.toBigDecimalOrNull()?.let { it.signum() > 0 } == true
    val ok = code.isNotBlank() && amountOk && if (kind == "payment") mode.isNotBlank() else promiseAt != null
    AlertDialog(onDismissRequest = onDismiss, title = { Text(if (kind == "payment") "Record payment" else "Record commitment") }, text = {
        Column(Modifier.verticalScroll(rememberScrollState())) {
            Text(case.c.name + " · " + case.c.ref, style = MaterialTheme.typography.bodySmall)
            FlowRow(Modifier.padding(vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                options.forEach { o -> Pill(o.label, Ds.Navy, code == o.code) { code = o.code } }
            }
            OutlinedTextField(amount, { amount = it.filter { ch -> ch.isDigit() || ch == '.' }.take(11) }, label = { Text(if (kind == "payment") "Amount received (₹)" else "Promised amount (₹)") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), singleLine = true, modifier = Modifier.fillMaxWidth())
            if (kind == "promise") DateTimeField(promiseAt, "Promised payment date (required)") { promiseAt = it }
            else {
                Text("Payment mode", color = Ds.Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp, bottom = 4.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) { modes.forEach { (k, l) -> Pill(l, Ds.Navy, mode == k) { mode = k } } }
                OutlinedTextField(ref, { ref = it.take(64) }, label = { Text(if (mode == "CASH") "Receipt no. (optional)" else "Reference / UTR / cheque no.") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(12.dp)).border(1.dp, Ds.Line, RoundedCornerShape(12.dp)).clickable {
                    DatePickerDialog(ctx, { _, y, m, d -> paidOn = LocalDate.of(y, m + 1, d) }, paidOn.year, paidOn.monthValue - 1, paidOn.dayOfMonth)
                        .apply { datePicker.maxDate = System.currentTimeMillis() }.show()
                }.padding(12.dp)) { Text("Paid on " + paidOn.format(dFmt), color = Ds.Ink) }
                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (slip == null) {
                        OutlinedButton(onClick = { cameraPerm.launch(android.Manifest.permission.CAMERA) }) { Text("Take photo") }
                        OutlinedButton(onClick = { pickFile.launch(arrayOf("image/*", "application/pdf")) }) { Text("Choose file") }
                    } else { Text("Slip attached", color = Ds.Green, modifier = Modifier.weight(1f)); TextButton(onClick = { slip = null }) { Text("Remove", color = Ds.Red) } }
                }
                Text("The office checks the payment before it reduces the balance.", style = MaterialTheme.typography.bodySmall)
            }
            OutlinedTextField(notes, { notes = it.take(2000) }, label = { Text("Notes (optional)") }, modifier = Modifier.fillMaxWidth().padding(top = 6.dp))
            err?.let { Text(it, color = Ds.Red, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }
    }, confirmButton = {
        TextButton(enabled = ok && !busy, onClick = {
            vm.recordEntry(case.c.id, code, notes.trim(), promiseAt?.atZone(ZoneId.systemDefault())?.toOffsetDateTime()?.toString(), amount,
                mode.takeIf { kind == "payment" }, ref.takeIf { kind == "payment" }, paidOn.toString().takeIf { kind == "payment" }, slip.takeIf { kind == "payment" }, done = onDismiss)
        }) { Text(if (busy) "Saving…" else "Save") }
    }, dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } })
}
