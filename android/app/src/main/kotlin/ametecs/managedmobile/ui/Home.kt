package ametecs.managedmobile.ui

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
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import java.time.LocalDate

/*
 * Home screen building blocks (10 Oct redesign): one compact "Today" card, priority tiles that are plain filters,
 * one search + Filter button, a filter sheet, and removable chips that always show what is filtered.
 * All filtering still goes through applyCaseFilter (Cases.kt) — one set of rules for tiles, sheet and chips.
 */

/** Today's numbers in one card instead of four big ones. */
@Composable
fun TodayCard(t: Today, modifier: Modifier = Modifier) {
    fun mmss(s: Int) = "%d:%02d".format(s / 60, s % 60)
    Surface(shape = RoundedCornerShape(20.dp), color = Color.White, shadowElevation = 6.dp, modifier = modifier.fillMaxWidth()) {
        Row(Modifier.padding(vertical = 14.dp)) {
            listOf(
                Triple(t.calls.toString(), "Calls", Ds.Blue),
                Triple(t.connected.toString() + if (t.calls > 0) " · ${100 * t.connected / t.calls}%" else "", "Connected", Ds.Green),
                Triple(mmss(t.talkSeconds), "Talk time", Ds.Teal),
                Triple(t.followUpsDue.toString(), "Follow-ups", if (t.followUpsDue > 0) Ds.Amber else Ds.Ink),
            ).forEachIndexed { i, (v, l, c) ->
                if (i > 0) Box(Modifier.width(1.dp).height(36.dp).background(Ds.Line).align(Alignment.CenterVertically))
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(v, color = c, fontSize = 18.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
                    Text(l, color = Ds.Muted, fontSize = 11.sp, maxLines = 1)
                }
            }
        }
    }
}

/** Shortcut tiles: each is just a filter (tap again to clear). Counts are over all assigned cases. */
@Composable
fun PriorityTiles(all: List<CaseRow>, f: CaseFilter, onTile: (String) -> Unit) {
    val today = LocalDate.now()
    val tiles = listOf(
        Triple("overdue", "Overdue", all.count { (it.dpd ?: 0) > 0 }),
        Triple("due_today", "Due today", all.count { it.dueDate == today }),
        Triple("ptp_today", "Promises today", all.count { it.promiseStatus == "open" && it.promiseDate == today }),
        Triple("cb_today", "Callbacks due", all.count { it.callbackDate != null && !it.callbackDate!!.isAfter(today) }),
        Triple("ptp_broken", "Broken promises", all.count { it.promiseStatus == "broken" }),
        Triple("not_contacted", "Not contacted", all.count { it.calls == 0 }),
        Triple("upcoming", "Due in 7 days", all.count { it.dueDate != null && !it.dueDate.isBefore(today) && !it.dueDate.isAfter(today.plusDays(7)) }),
        Triple("paid_today", "Paid today", all.count { it.lastPaymentOn == today }),
    )
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        tiles.forEach { (key, label, n) ->
            val on = f.tile == key
            val warn = key in setOf("ptp_broken", "cb_today", "due_today", "ptp_today") && n > 0
            Column(Modifier.width(112.dp).clip(RoundedCornerShape(16.dp)).background(if (on) Ds.Navy else Color.White)
                .border(1.dp, if (on) Ds.Navy else if (warn) Ds.Amber.copy(alpha = 0.5f) else Ds.Line, RoundedCornerShape(16.dp))
                .clickable { onTile(key) }.padding(horizontal = 12.dp, vertical = 10.dp)) {
                Text(n.toString(), color = if (on) Color.White else if (warn) Ds.Amber else Ds.Ink, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
                Text(label, color = if (on) Ds.GoldSoft else Ds.Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

private val SORTS = listOf(
    "dpd" to "Most days overdue", "dpd_asc" to "Least days overdue", "amount" to "Highest overdue", "outstanding" to "Highest outstanding",
    "due" to "Next due date", "callback" to "Next callback", "promise" to "Promise date", "last_call" to "Not called longest",
    "recent_call" to "Called most recently", "paid" to "Latest payment", "nearest" to "Nearest to me", "name" to "Name A–Z")
private val TILE_LABELS = mapOf("overdue" to "Overdue", "due_today" to "Due today", "upcoming" to "Due in 7 days", "ptp_today" to "Promises today",
    "ptp_broken" to "Broken promises", "cb_today" to "Callbacks due", "not_contacted" to "Not contacted", "paid_today" to "Paid today", "verified" to "Verified collections")
private val STATUS = listOf("new" to "New", "in_progress" to "In progress", "follow_up" to "Follow-up", "closed" to "Closed")
private val DPD = listOf("0" to "Not overdue", "1-30" to "1–30 days", "31-60" to "31–60 days", "61-90" to "61–90 days", "90+" to "90+ days")
private val AMOUNT = listOf("none" to "Nothing overdue", "lt10k" to "Up to ₹10k", "10-50k" to "₹10k–50k", "gt50k" to "Over ₹50k")
private val CALLS = listOf("none" to "Not called", "no_connect" to "Called, no answer", "connected" to "Spoke to customer")
private val CALLBACK = listOf("today" to "Due today", "overdue" to "Overdue", "scheduled" to "Scheduled", "none" to "No callback")
private val ASSIGN = listOf("mine" to "Assigned to me", "pool" to "Group pool")

/** Every active filter as a removable chip — what you see is exactly what is filtered. */
fun activeChips(f: CaseFilter): List<Pair<String, CaseFilter>> = listOfNotNull(
    f.tile.takeIf { it.isNotEmpty() }?.let { (TILE_LABELS[it] ?: it) to f.copy(tile = "") },
    f.status.takeIf { it.isNotEmpty() }?.let { "Status: " + (STATUS.toMap()[it] ?: it) to f.copy(status = "") },
    f.dpd.takeIf { it.isNotEmpty() }?.let { (DPD.toMap()[it] ?: it) to f.copy(dpd = "") },
    f.amount.takeIf { it.isNotEmpty() }?.let { (AMOUNT.toMap()[it] ?: it) to f.copy(amount = "") },
    f.calls.takeIf { it.isNotEmpty() }?.let { (CALLS.toMap()[it] ?: it) to f.copy(calls = "") },
    f.callback.takeIf { it.isNotEmpty() }?.let { "Callback: " + (CALLBACK.toMap()[it] ?: it) to f.copy(callback = "") },
    f.portfolio.takeIf { it.isNotEmpty() }?.let { "Portfolio: $it" to f.copy(portfolio = "") },
    f.assigned.takeIf { it.isNotEmpty() }?.let { (ASSIGN.toMap()[it] ?: it) to f.copy(assigned = "") },
)

/** Search box + Filter button (with count) + sort, then the active-filter chips. */
@Composable
fun SearchFilterBar(f: CaseFilter, shown: Int, total: Int, onChange: (CaseFilter) -> Unit, onOpenFilters: () -> Unit) {
    var sortOpen by remember { mutableStateOf(false) }
    val chips = activeChips(f)
    Column(Modifier.padding(horizontal = 16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = RoundedCornerShape(16.dp), color = Color.White, shadowElevation = 3.dp, modifier = Modifier.weight(1f).height(50.dp)) {
                Row(Modifier.padding(horizontal = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(AmmpIcons.Search, null, tint = Ds.Muted, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(10.dp))
                    Box(Modifier.weight(1f)) {
                        if (f.query.isEmpty()) Text("Name, case ID or last 4 digits", color = Ds.Muted, fontSize = 14.sp, maxLines = 1)
                        BasicTextField(f.query, { onChange(f.copy(query = it)) }, singleLine = true,
                            textStyle = MaterialTheme.typography.bodyLarge.copy(color = Ds.Ink, fontSize = 15.sp), modifier = Modifier.fillMaxWidth())
                    }
                    if (f.query.isNotEmpty()) Icon(AmmpIcons.Close, "Clear search", tint = Ds.Muted, modifier = Modifier.size(20.dp).clickable { onChange(f.copy(query = "")) })
                }
            }
            Spacer(Modifier.width(10.dp))
            Surface(shape = RoundedCornerShape(16.dp), color = if (chips.isNotEmpty()) Ds.Navy else Color.White, shadowElevation = 3.dp,
                modifier = Modifier.height(50.dp).clickable(onClick = onOpenFilters)) {
                Row(Modifier.padding(horizontal = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(AmmpIcons.Filter, null, tint = if (chips.isNotEmpty()) Color.White else Ds.Navy, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(if (chips.isEmpty()) "Filter" else "Filter · ${chips.size}", color = if (chips.isNotEmpty()) Color.White else Ds.Navy, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                }
            }
        }
        if (chips.isNotEmpty()) Row(Modifier.padding(top = 10.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically) {
            chips.forEach { (label, without) ->
                Row(Modifier.clip(RoundedCornerShape(50)).background(Ds.Navy.copy(alpha = 0.08f)).clickable { onChange(without) }.padding(start = 12.dp, end = 8.dp, top = 6.dp, bottom = 6.dp),
                    verticalAlignment = Alignment.CenterVertically) {
                    Text(label, color = Ds.Navy, fontSize = 13.sp, fontWeight = FontWeight.Medium)
                    Spacer(Modifier.width(4.dp))
                    Icon(AmmpIcons.Close, "Remove $label", tint = Ds.Navy, modifier = Modifier.size(14.dp))
                }
            }
            Text("Clear all", color = Ds.Red, fontSize = 13.sp, fontWeight = FontWeight.Medium,
                modifier = Modifier.clickable { onChange(CaseFilter(query = f.query, sort = f.sort)) }.padding(6.dp))
        }
        Row(Modifier.fillMaxWidth().padding(top = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(if (chips.isEmpty() && f.query.isBlank()) "My cases" else "Matching cases", color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            Text("  $shown of $total", color = Ds.Muted, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Box {
                Row(Modifier.clip(RoundedCornerShape(10.dp)).clickable { sortOpen = true }.padding(horizontal = 8.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Sort: " + (SORTS.toMap()[f.sort] ?: ""), color = Ds.Navy, fontSize = 13.sp, fontWeight = FontWeight.Medium)
                    Text(" ▾", color = Ds.Navy, fontSize = 13.sp)
                }
                androidx.compose.material3.DropdownMenu(sortOpen, onDismissRequest = { sortOpen = false }) {
                    SORTS.forEach { (k, l) ->
                        androidx.compose.material3.DropdownMenuItem(text = { Text(l, fontWeight = if (f.sort == k) FontWeight.SemiBold else FontWeight.Normal) },
                            onClick = { sortOpen = false; onChange(f.copy(sort = k)) })
                    }
                }
            }
        }
    }
}

/** All filters in one sheet; the button shows how many cases will remain before applying. */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun FilterSheet(all: List<CaseRow>, start: CaseFilter, onApply: (CaseFilter) -> Unit, onDismiss: () -> Unit) {
    var f by remember { mutableStateOf(start) }
    val count = remember(f, all) { applyCaseFilter(all, f, ametecs.managedmobile.work.LocationService.latest.value).size }
    val portfolios = remember(all) { all.map { it.portfolio ?: "Added by hand" }.distinct().sorted().map { it to it } }
    @Composable
    fun Group(title: String, current: String, opts: List<Pair<String, String>>, pick: (String) -> Unit) {
        Text(title, color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.padding(top = 16.dp, bottom = 8.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            opts.forEach { (k, l) ->
                val on = current == k
                Text(l, color = if (on) Color.White else Ds.Ink, fontSize = 13.sp, fontWeight = FontWeight.Medium,
                    modifier = Modifier.clip(RoundedCornerShape(50)).background(if (on) Ds.Navy else Color.White)
                        .border(1.dp, if (on) Ds.Navy else Ds.Line, RoundedCornerShape(50)).clickable { pick(if (on) "" else k) }   // tap again = off
                        .padding(horizontal = 14.dp, vertical = 8.dp))
            }
        }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Ds.Bg) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding()) {
            Row(Modifier.padding(horizontal = 20.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("Filter cases", color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 20.sp, modifier = Modifier.weight(1f))
                Text("Reset", color = Ds.Red, fontWeight = FontWeight.Medium, modifier = Modifier.clickable { f = CaseFilter(query = f.query, sort = f.sort) }.padding(8.dp))
            }
            Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp)) {
                Group("Sort by", f.sort, SORTS) { f = f.copy(sort = it.ifEmpty { "dpd" }) }
                Group("Priority", f.tile, TILE_LABELS.toList()) { f = f.copy(tile = it) }
                Group("Case status", f.status, STATUS) { f = f.copy(status = it) }
                Group("Days overdue", f.dpd, DPD) { f = f.copy(dpd = it) }
                Group("Overdue amount", f.amount, AMOUNT) { f = f.copy(amount = it) }
                Group("Calls", f.calls, CALLS) { f = f.copy(calls = it) }
                Group("Callback", f.callback, CALLBACK) { f = f.copy(callback = it) }
                if (portfolios.size > 1) Group("Portfolio", f.portfolio, portfolios) { f = f.copy(portfolio = it) }
                Group("Assignment", f.assigned, ASSIGN) { f = f.copy(assigned = it) }
                Spacer(Modifier.height(16.dp))
            }
            Row(Modifier.padding(horizontal = 20.dp, vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedButton(onClick = onDismiss, modifier = Modifier.weight(1f).height(50.dp), shape = RoundedCornerShape(14.dp)) { Text("Cancel", color = Ds.Ink) }
                Button(onClick = { onApply(f) }, modifier = Modifier.weight(2f).height(50.dp), shape = RoundedCornerShape(14.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = Ds.Navy)) { Text("Show $count case${if (count == 1) "" else "s"}", fontWeight = FontWeight.SemiBold) }
            }
        }
    }
}

/** Big, obvious entry to the field map. */
@Composable
fun MapShortcut(nearbyText: String, onClick: () -> Unit) {
    Row(Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(RoundedCornerShape(18.dp))
        .background(androidx.compose.ui.graphics.Brush.horizontalGradient(listOf(Ds.Gold, Color(0xFFD9B878)))).clickable(onClick = onClick)
        .padding(horizontal = 16.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(40.dp).background(Color.White.copy(alpha = 0.35f), CircleShape), contentAlignment = Alignment.Center) {
            Icon(AmmpIcons.Place, null, tint = Ds.NavyInk, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text("Customers near me", color = Ds.NavyInk, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
            Text(nearbyText, color = Ds.NavyInk.copy(alpha = 0.75f), fontSize = 12.sp)
        }
        Text("Open map ›", color = Ds.NavyInk, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
    }
}
