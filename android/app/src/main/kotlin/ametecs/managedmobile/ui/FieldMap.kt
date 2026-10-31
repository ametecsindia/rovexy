package ametecs.managedmobile.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material3.BottomSheetScaffold
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Surface
import androidx.compose.material3.rememberBottomSheetScaffoldState
import androidx.compose.ui.draw.clipToBounds
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import android.os.Looper
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ametecs.managedmobile.data.ApiException
import ametecs.managedmobile.work.LocationService
import ametecs.managedmobile.work.mocked
import android.annotation.SuppressLint
import android.webkit.JavascriptInterface
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.viewinterop.AndroidView
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.double
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/*
 * Field map: where am I, which of my customers are nearest by road, route there, start Google Maps navigation.
 *
 * API use is kept small on purpose:
 *  1. Straight-line distance to every case is worked out on the phone (free) and used for the radius filter and to
 *     pick the ROAD_CANDIDATES nearest.
 *  2. Road distance / time is asked from the server (Google Routes, one basic matrix call) for those few only, and
 *     ONLY when the employee taps Find nearest or Refresh — never by itself while moving, and never while the map is
 *     not on screen (each client pays Google directly; G4 cost rules). The server also caches answers for hours.
 *  3. A route line is asked only when the employee taps Route / Find nearest / Show route / a customer.
 * Positions come from the work-session tracker (LocationService) plus a fresh fix on open / Refresh — no second tracker.
 */

private val RADII = listOf(0 to "All", 1 to "1 km", 2 to "2 km", 5 to "5 km", 10 to "10 km", 25 to "25 km")
private const val ROAD_CANDIDATES = 5       // road distance asked (on tap only) for the nearest N by straight line
private const val FAR_METERS = 300_000f     // a pin this far from the employee is probably wrong (swapped lat/lng, bad file)
private const val MAX_STOPS = 10            // Google Maps navigation: 9 waypoints + destination
private const val ARRIVE_METERS = 100f      // "Check in" appears this close to the pin (server allows +50 m GPS drift)

private data class Road(val meters: Int, val seconds: Int)
/** A map point (the map itself is Google Maps JavaScript in a WebView: field-map.html on the server). */
private data class LatLng(val lat: Double, val lng: Double)
private data class RouteLine(val points: List<LatLng>, val meters: Int, val seconds: Int, val order: List<Long>, val legs: List<Road>)
private data class Near(val r: CaseRow, val straight: Float?, val road: Road?)


private fun km(m: Number): String = m.toFloat().let { if (it < 1000) "${it.toInt()} m" else String.format(java.util.Locale.US, "%.1f km", it / 1000) }
private fun eta(s: Int): String = (s / 60).coerceAtLeast(1).let { if (it < 60) "$it min" else "${it / 60} h ${it % 60} min" }
private fun straight(from: Location, r: CaseRow): Float = FloatArray(1).also { Location.distanceBetween(from.latitude, from.longitude, r.lat!!, r.lng!!, it) }[0]
private val hm = DateTimeFormatter.ofPattern("h:mm a")
private fun hasLocationPermission(ctx: Context) = ctx.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
    ctx.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

/** Live GPS while the map is open (blue dot moves, arrival shows up), into the same flow the work tracker fills. */
@Suppress("MissingPermission")
private fun liveFixes(ctx: Context, cb: LocationCallback) {
    LocationServices.getFusedLocationProviderClient(ctx).requestLocationUpdates(
        LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 4_000).setMinUpdateIntervalMillis(2_000).setMinUpdateDistanceMeters(3f).build(),
        cb, Looper.getMainLooper())
}

/** Google Maps turn-by-turn from the current location through the stops, in order (last = destination). */
private fun navigate(ctx: Context, stops: List<CaseRow>) {
    val ll = { r: CaseRow -> "${r.lat},${r.lng}" }
    val dest = stops.lastOrNull() ?: return
    val url = "https://www.google.com/maps/dir/?api=1&travelmode=two-wheeler&dir_action=navigate&destination=" + Uri.encode(ll(dest)) +
        (stops.dropLast(1).takeIf { it.isNotEmpty() }?.let { w -> "&waypoints=" + Uri.encode(w.joinToString("|") { ll(it) }) } ?: "")
    runCatching { ctx.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

private fun friendly(e: Throwable): String = when (e) {
    is ApiException -> if (e.status == 0) e.message ?: "No connection." else e.message ?: "Map service is not available right now."
    is java.io.IOException -> "No connection — straight-line distance is shown."
    else -> "Map service is not available right now."
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FieldMapScreen(vm: AppViewModel, cases: List<CaseRow>, startFilter: CaseFilter, onClose: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    val fix by LocationService.latest.collectAsState()
    val focus by vm.fieldFocus.collectAsState()
    val name by vm.employeeName.collectAsState()
    val busyCall by vm.busy.collectAsState()
    val call by vm.call.collectAsState()

    var filter by remember { mutableStateOf(startFilter.copy(tile = "", sort = "dpd")) }
    var radiusKm by remember { mutableIntStateOf(0) }
    var locState by remember { mutableStateOf(if (fix != null) "ok" else "loading") }   // loading | ok | denied | unavailable
    var road by remember { mutableStateOf<Map<Long, Road?>>(emptyMap()) }
    var roadNote by remember { mutableStateOf<String?>(null) }
    var roadBusy by remember { mutableStateOf(false) }
    var tick by remember { mutableIntStateOf(0) }
    var selected by remember { mutableStateOf<Long?>(null) }
    var route by remember { mutableStateOf<RouteLine?>(null) }
    var routeBusy by remember { mutableStateOf(false) }
    var planning by remember { mutableStateOf(false) }
    var picked by remember { mutableStateOf<List<Long>>(emptyList()) }
    var fixLocation by remember { mutableStateOf<CaseRow?>(null) }
    val list = rememberLazyListState()
    var mapKey by remember { mutableStateOf<String?>(null) }
    var mapError by remember { mutableStateOf<String?>(null) }   // null = loading / fine
    var mapStage by remember { mutableStateOf("start") }          // page → google-loading → google-loaded → map-creating → tiles
    var fitReq by remember { mutableStateOf(0 to emptyList<LatLng>()) }   // (token, points) → camera move in the WebView
    // G9: today's route published by the admin (from the saved plan — no Google call), and which stops are done.
    var plan by remember { mutableStateOf<RouteLine?>(null) }
    var planDone by remember { mutableStateOf<Set<Long>>(emptySet()) }
    LaunchedEffect(Unit) {
        try {
            vm.todayPlan()?.let { p -> plan = parseRoute(p, "stops"); planDone = p["visited"]?.jsonArray?.map { it.jsonPrimitive.long }?.toSet() ?: emptySet() }
        } catch (e: Exception) { if (e is kotlinx.coroutines.CancellationException) throw e }   // no plan shown; the map works as before
    }
    LaunchedEffect(Unit) {
        try { mapKey = vm.mapKey(); if (mapKey == null) mapError = "nokey" }
        catch (e: Exception) { if (e is kotlinx.coroutines.CancellationException) throw e; mapError = "offline" }
    }

    /** Fresh fix (falls back to the last known one). Returned directly: the collected `fix` updates a frame later. */
    suspend fun refreshFix(): Location? {
        if (!hasLocationPermission(ctx)) { locState = "denied"; return null }
        if (fix == null) locState = "loading"
        val got = vm.freshLocation() ?: fix
        locState = if (got != null) "ok" else "unavailable"
        return got
    }
    val permLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { scope.launch { refreshFix() } }
    LaunchedEffect(Unit) { refreshFix() }
    DisposableEffect(locState == "ok") {
        val cb = object : LocationCallback() { override fun onLocationResult(r: LocationResult) { r.lastLocation?.let { LocationService.latest.value = it } } }
        if (hasLocationPermission(ctx)) runCatching { liveFixes(ctx, cb) }
        onDispose { LocationServices.getFusedLocationProviderClient(ctx).removeLocationUpdates(cb) }
    }
    BackHandler { if (planning) { planning = false; picked = emptyList() } else onClose() }

    // ── what is shown ──
    val shown = remember(cases, filter) { applyCaseFilter(cases, filter) }
    val (pinned, unpinned) = remember(shown) { shown.partition { it.lat != null && it.lng != null } }
    val withStraight = remember(pinned, fix) { pinned.map { r -> r to fix?.let { straight(it, r) } } }
    val far = withStraight.filter { (_, m) -> m != null && m > FAR_METERS }.map { it.first }
    val inRadius = withStraight.filter { (_, m) -> (m == null || m <= FAR_METERS) && (radiusKm == 0 || (m != null && m <= radiusKm * 1000f)) }
    // Nearest by road first (time), then the rest by straight line.
    val nearby: List<Near> = remember(inRadius, road) {
        val (known, rest) = inRadius.map { (r, m) -> Near(r, m, road[r.c.id]) }.partition { it.road != null }
        known.sortedBy { it.road!!.seconds } + rest.sortedBy { it.straight ?: Float.MAX_VALUE }
    }
    val nearest = nearby.firstOrNull()?.takeIf { fix != null }
    val byId = remember(nearby) { nearby.associateBy { it.r.c.id } }
    val sel = selected?.let { byId[it] ?: pinned.firstOrNull { r -> r.c.id == it }?.let { r -> Near(r, fix?.let { f -> straight(f, r) }, road[r.c.id]) } }
    val candidates = remember(inRadius) { inRadius.sortedBy { it.second ?: Float.MAX_VALUE }.take(ROAD_CANDIDATES).map { it.first.c.id } }

    // ── road distances: only for the nearest few, and only when the employee taps Find nearest / Refresh ──
    suspend fun loadRoad(f: Location, ids: List<Long>, reset: Boolean) {
        if (ids.isEmpty()) return
        roadBusy = true
        try {
            val res = vm.roadDistances(f.latitude, f.longitude, ids).mapValues { (_, v) -> v?.let { Road(it.first, it.second) } }
            road = (if (reset) emptyMap() else road) + res
            roadNote = null
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            roadNote = friendly(e)
        } finally { roadBusy = false }
    }
    // Refresh (tick) is the only trigger: no request on open, on moving, or on a filter change (straight-line distance
    // is shown until then, free). Keyed on tick alone, so GPS updates can't cancel or repeat a request in flight.
    LaunchedEffect(tick) {
        if (tick == 0) return@LaunchedEffect
        val f = fix ?: return@LaunchedEffect
        loadRoad(f, candidates, reset = true)
    }

    // ── camera ──
    fun fit(points: List<LatLng>) { if (points.isNotEmpty()) fitReq = (fitReq.first + 1) to points }
    fun pinOf(r: CaseRow) = LatLng(r.lat!!, r.lng!!)
    fun here() = fix?.let { LatLng(it.latitude, it.longitude) }
    fun select(id: Long, scroll: Boolean, move: Boolean = true) {
        selected = id
        val r = pinned.firstOrNull { it.c.id == id } ?: return
        if (move) fit(listOf(pinOf(r)))
        if (scroll) scope.launch { list.animateScrollToItem(0) }
    }
    suspend fun loadRoute(ids: List<Long>, optimize: Boolean) {
        val f = fix ?: run { roadNote = "Your location is needed for a route. Tap Refresh location."; return }
        routeBusy = true
        try {
            route = parseRoute(vm.drivingRoute(f.latitude, f.longitude, ids, optimize))
            roadNote = null
            fit(listOfNotNull(here()) + route!!.points)
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            roadNote = friendly(e)
        } finally { routeBusy = false }
    }
    /** Tap on a pin or a list row: focus it and draw the road route from here with distance and time (cached 10 min on the server). */
    fun selectAndRoute(id: Long) {
        val willRoute = fix != null && !planning && route?.order != listOf(id)
        select(id, scroll = true, move = !willRoute)   // one smooth camera move to the whole route, not pin-then-route
        if (willRoute) scope.launch { loadRoute(listOf(id), optimize = false) }
    }
    fun findNearest() = scope.launch {
        val f = refreshFix() ?: return@launch
        val ids = pinned.filter { straight(f, it) <= FAR_METERS && (radiusKm == 0 || straight(f, it) <= radiusKm * 1000f) }
            .sortedBy { straight(f, it) }.take(ROAD_CANDIDATES).map { it.c.id }
        if (ids.isEmpty()) { roadNote = if (radiusKm == 0) "No customers with a map location." else "No nearby customers found within $radiusKm km."; return@launch }
        loadRoad(f, ids, reset = true)
        val best = ids.mapNotNull { id -> road[id]?.let { id to it } }.minByOrNull { it.second.seconds }?.first ?: ids.first()
        select(best, scroll = true)
        loadRoute(listOf(best), optimize = false)
    }

    // Opened from a profile while the map is already up: focus that case.
    LaunchedEffect(focus) {
        val f = focus?.let { id -> pinned.firstOrNull { it.c.id == id } } ?: return@LaunchedEffect
        selected = f.c.id; fit(listOf(pinOf(f))); vm.fieldFocus.value = null
    }

    // ── layout: map fills the screen; search + radius float on top; customer card / list in a bottom sheet ──
    val sheet = rememberBottomSheetScaffoldState()
    var mapGen by remember { mutableIntStateOf(0) }   // bump = reload the map page (Retry)
    val hero: Near? = sel ?: nearest
    // ── arrival: within ARRIVE_METERS of the customer on the card → Check in (verified again on the server) ──
    val arrived = hero?.straight?.let { it <= ARRIVE_METERS } == true
    var visits by remember { mutableStateOf<Map<Long, String>>(emptyMap()) }   // case id → check-in time (this session)
    var checkBusy by remember { mutableStateOf(false) }
    val haptic = LocalHapticFeedback.current
    LaunchedEffect(hero?.r?.c?.id, arrived) { if (arrived) haptic.performHapticFeedback(HapticFeedbackType.LongPress) }
    fun checkIn(r: CaseRow) = scope.launch {
        val f = refreshFix() ?: return@launch
        checkBusy = true
        try {
            vm.checkIn(r.c.id, f.latitude, f.longitude, if (f.hasAccuracy()) f.accuracy else null, f.mocked())
            visits = visits + (r.c.id to LocalTime.now().format(hm))
            planDone = planDone + r.c.id
            roadNote = null
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            roadNote = friendly(e)
        } finally { checkBusy = false }
    }
    BottomSheetScaffold(
        scaffoldState = sheet,
        sheetPeekHeight = 300.dp,
        sheetContainerColor = Ds.Bg,
        sheetShadowElevation = 16.dp,
        sheetShape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp),
        sheetContent = {
            LazyColumn(Modifier.fillMaxWidth().fillMaxHeight(0.86f), state = list) {
                // 1. The customer that matters now: the one tapped, else the nearest.
                item(key = "hero") {
                    if (hero != null) CustomerSheetCard(hero, label = if (sel != null) (if (hero.r.c.id == nearest?.r?.c?.id) "NEAREST · SELECTED" else "SELECTED") else "NEAREST CUSTOMER",
                        route = route?.takeIf { it.order == listOf(hero.r.c.id) }, routeBusy = routeBusy, callEnabled = !busyCall && call == null,
                        onNavigate = { navigate(ctx, listOf(hero.r)) }, onRoute = { selected = hero.r.c.id; scope.launch { loadRoute(listOf(hero.r.c.id), false) } },
                        onCall = { vm.placeCall(hero.r.c, "primary") }, onCollect = { vm.openProfileFromMap(hero.r.c.id, "payment") },
                        onView = { vm.openProfileFromMap(hero.r.c.id) }, onFixLocation = { fixLocation = hero.r },
                        arrived = arrived, checkedIn = visits[hero.r.c.id], checkBusy = checkBusy, onCheckIn = { checkIn(hero.r) },
                        onClose = if (sel != null) ({ selected = null; route = null }) else null)
                    else LocationPrompt(locState, pinned.isEmpty(), cases.isEmpty(), onAllow = {
                        permLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                    }, onRefresh = { scope.launch { refreshFix(); tick++ } })
                }
                // 2. Me + quick actions.
                item(key = "me") { MeRow(name, fix, locState, busy = roadBusy || routeBusy, onNearest = { findNearest() }, onRefresh = { scope.launch { refreshFix(); tick++ } }) }
                roadNote?.let { n -> item(key = "note") { NoteLine(n) } }
                plan?.let { p -> item(key = "today") {
                    TodayRoute(p, pinned, planDone, onShow = { selected = null; route = p; fit(listOfNotNull(here()) + p.points.ifEmpty { p.order.mapNotNull { id -> pinned.firstOrNull { it.c.id == id }?.let { r -> pinOf(r) } } }) },
                        onNavigate = { navigate(ctx, p.order.filter { it !in planDone }.mapNotNull { id -> pinned.firstOrNull { it.c.id == id } }.take(MAX_STOPS)) })
                } }
                // 3. Status filter + multi-stop route.
                item(key = "status") {
                    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        (listOf("" to "All cases") + CASE_STATUSES).forEach { (k, l) -> Pill(l, Ds.Navy, filter.status == k) { filter = filter.copy(status = k) } }
                    }
                }
                item(key = "plan") {
                    Column(Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(if (planning) "Tick up to $MAX_STOPS customers below" else "Customers ${if (radiusKm == 0) "by distance" else "within $radiusKm km"} · ${nearby.size}",
                                color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 15.sp, modifier = Modifier.weight(1f))
                            TextButton(onClick = { planning = !planning; if (!planning) picked = emptyList() }) { Text(if (planning) "Cancel" else "Plan a route", color = Ds.Navy) }
                        }
                        if (planning) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(enabled = picked.isNotEmpty() && !routeBusy, onClick = { selected = null; scope.launch { loadRoute(picked, optimize = true) } },
                                colors = ButtonDefaults.buttonColors(containerColor = Ds.Navy), shape = RoundedCornerShape(12.dp)) { Text("Show route (${picked.size})") }
                            val r = route
                            if (r != null && r.order.size > 1) Button(onClick = { navigate(ctx, r.order.mapNotNull { id -> pinned.firstOrNull { it.c.id == id } }) },
                                colors = ButtonDefaults.buttonColors(containerColor = Ds.Gold), shape = RoundedCornerShape(12.dp)) { Text("Navigate all", color = Ds.NavyInk) }
                        }
                        route?.takeIf { it.order.size > 1 }?.let { r -> RouteSummary(r, pinned) }
                    }
                }
                // 4. The list (synced with the map: tap a row = focus its pin; tap a pin = this row + card above).
                if (nearby.isEmpty()) item(key = "empty") {
                    Text(when {
                        cases.isEmpty() -> "No customers are assigned to you yet."
                        pinned.isEmpty() -> "No customers have valid location information."
                        fix == null && radiusKm > 0 -> "Your current location could not be detected. Please enable location permission."
                        radiusKm > 0 -> "No nearby customers found within $radiusKm km."
                        else -> "No customers match the search."
                    }, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp))
                }
                itemsIndexed(nearby, key = { _, n -> n.r.c.id }) { i, n ->
                    NearRow(i + 1, n, highlighted = n.r.c.id == selected, nearest = n.r.c.id == nearest?.r?.c?.id, planning = planning, picked = n.r.c.id in picked,
                        onPick = { on -> picked = if (on) (picked + n.r.c.id).take(MAX_STOPS) else picked - n.r.c.id }) { selectAndRoute(n.r.c.id) }
                }
                // 5. Never silently dropped: cases the map can't place.
                if (unpinned.isNotEmpty() || far.isNotEmpty()) item(key = "missing") {
                    Column(Modifier.padding(horizontal = 16.dp, vertical = 10.dp)) {
                        Text("Location unavailable · ${unpinned.size + far.size}", color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
                        (unpinned.map { it to if (it.locationIssue == "invalid") "Wrong coordinates in the file" else "No map location" } +
                            far.map { it to "Pin is over ${(FAR_METERS / 1000).toInt()} km away — check it" }).forEach { (r, why) ->
                            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                                Box(Modifier.size(8.dp).background(Ds.Amber, CircleShape))
                                Spacer(Modifier.width(10.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(r.c.name, color = Ds.Ink, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(listOfNotNull(why, r.address).joinToString(" · "), style = MaterialTheme.typography.bodySmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                }
                                TextButton(onClick = { fixLocation = r }) { Text("Set pin", color = Ds.Navy) }
                            }
                        }
                    }
                }
                item(key = "foot") { Spacer(Modifier.height(40.dp)) }
            }
        },
    ) {
        Box(Modifier.fillMaxSize().background(Color(0xFFE8ECF1))) {
            val k = mapKey
            if (k != null && mapError == null) key(mapGen) {
                // What the map shows, as JSON for field-map.html (normal pins cluster; nearest / selected / route stops stand out).
                val view = remember(nearby, sel, nearest, route, fix, arrived) {
                    val stops = route?.order ?: emptyList()
                    buildJsonObject {
                        fix?.let { f -> put("me", buildJsonObject { put("lat", f.latitude); put("lng", f.longitude); put("acc", if (f.hasAccuracy()) f.accuracy.toDouble() else 0.0) }) }
                        put("pins", buildJsonArray {
                            (nearby.map { it.r } + listOfNotNull(sel?.r)).distinctBy { it.c.id }.forEach { r ->
                                val id = r.c.id
                                add(buildJsonObject {
                                    put("id", id); put("lat", r.lat); put("lng", r.lng); put("t", r.c.name)
                                    put("k", when { id == sel?.r?.c?.id -> "selected"; id == nearest?.r?.c?.id -> "nearest"; id in stops -> "stop"; else -> "normal" })
                                    if (id in stops && stops.size > 1) put("label", (stops.indexOf(id) + 1).toString())
                                })
                            }
                        })
                        hero?.r?.let { h -> put("arrive", buildJsonObject { put("lat", h.lat); put("lng", h.lng); put("r", ARRIVE_METERS.toDouble()); put("inside", arrived) }) }
                        route?.let { rt -> put("route", buildJsonArray { rt.points.forEach { p -> add(buildJsonArray { add(JsonPrimitive(p.lat)); add(JsonPrimitive(p.lng)) }) } }) }
                    }.toString()
                }
                JsMap(vm.serverUrl, k, view, fitReq, onMarker = { selectAndRoute(it); scope.launch { sheet.bottomSheetState.partialExpand() } }, onReady = {
                    focus?.let { id -> pinned.firstOrNull { it.c.id == id } }?.let { f -> selected = f.c.id; fit(listOf(pinOf(f))); vm.fieldFocus.value = null }
                        ?: fit(listOfNotNull(here()) + inRadius.take(30).map { pinOf(it.first) })
                }, onError = { mapError = it }, onStatus = { mapStage = it })
                // Until Google has drawn the map: say which step it is on (a stuck step tells us what is wrong).
                if (mapStage !in setOf("map-creating", "tiles")) MapProblem(null, vm.serverUrl, Modifier.align(Alignment.TopCenter).padding(top = 150.dp, start = 24.dp, end = 24.dp), stage = mapStage) {}
            } else MapProblem(mapError, vm.serverUrl, Modifier.align(Alignment.TopCenter).padding(top = 150.dp, start = 24.dp, end = 24.dp)) {
                if (mapKey != null) { mapError = null; mapStage = "start"; mapGen++ }
            }

            // Floating search + radius on top of the map.
            Column(Modifier.fillMaxWidth().statusBarsPadding().padding(top = 8.dp)) {
                Row(Modifier.padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    RoundIconButton(AmmpIcons.ArrowBack, "Back to cases", onClose)
                    Spacer(Modifier.width(8.dp))
                    Surface(shape = RoundedCornerShape(28.dp), color = Color.White, shadowElevation = 6.dp, modifier = Modifier.weight(1f).height(52.dp)) {
                        Row(Modifier.padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(AmmpIcons.Search, null, tint = Ds.Muted, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(10.dp))
                            Box(Modifier.weight(1f)) {
                                if (filter.query.isEmpty()) Text("Search customer", color = Ds.Muted, fontSize = 15.sp)
                                BasicTextField(filter.query, { filter = filter.copy(query = it) }, singleLine = true,
                                    textStyle = MaterialTheme.typography.bodyLarge.copy(color = Ds.Ink, fontSize = 15.sp), modifier = Modifier.fillMaxWidth())
                            }
                            if (filter.query.isNotEmpty()) Icon(AmmpIcons.Close, "Clear search", tint = Ds.Muted, modifier = Modifier.size(20.dp).clickable { filter = filter.copy(query = "") })
                            if (roadBusy || routeBusy) CircularProgressIndicator(Modifier.padding(start = 8.dp).size(16.dp), color = Ds.Gold, strokeWidth = 2.dp)
                        }
                    }
                }
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    RADII.forEach { (k, l) -> FloatChip(if (k == 0) "All" else "Within $l", radiusKm == k) { radiusKm = k } }
                }
            }
            // Map controls (right side, above the sheet).
            Column(Modifier.align(Alignment.TopEnd).padding(top = 170.dp, end = 12.dp).statusBarsPadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                RoundIconButton(AmmpIcons.MyLocation, "My location") { scope.launch { refreshFix()?.let { fit(listOf(LatLng(it.latitude, it.longitude))) }; tick++ } }
                RoundIconButton(AmmpIcons.FitAll, "Show all customers") { selected = null; route = null; fit(listOfNotNull(here()) + inRadius.map { pinOf(it.first) }) }
                if (route != null) RoundIconButton(AmmpIcons.Close, "Clear route") { route = null }
            }
        }
    }
    fixLocation?.let { r -> UpdateLocationDialog(vm, r) { fixLocation = null; tick++ } }
}

private val CASE_STATUSES = listOf("new" to "New", "in_progress" to "In progress", "follow_up" to "Follow-up", "closed" to "Closed")

private fun JsonObject.n(k: String): Int = (this[k] as? JsonPrimitive)?.intOrNull ?: 0

/** A route answer from the server (field/route, or today's plan with its "stops") → what the map draws. */
private fun parseRoute(o: JsonObject, orderKey: String = "order"): RouteLine = RouteLine(
    o["points"]?.jsonArray?.map { p -> p.jsonArray.let { LatLng(it[0].jsonPrimitive.double, it[1].jsonPrimitive.double) } } ?: emptyList(),
    o.n("distance_m"), o.n("duration_s"), o[orderKey]?.jsonArray?.map { it.jsonPrimitive.long } ?: emptyList(),
    o["legs"]?.jsonArray?.map { l -> (l as JsonObject).let { Road(it.n("distance_m"), it.n("duration_s")) } } ?: emptyList())

@Composable
private fun RoundIconButton(icon: ImageVector, label: String, onClick: () -> Unit) {
    Surface(shape = CircleShape, color = Color.White, shadowElevation = 6.dp, modifier = Modifier.size(48.dp).clickable(onClick = onClick).semantics { contentDescription = label }) {
        Box(contentAlignment = Alignment.Center) { Icon(icon, null, tint = Ds.Navy, modifier = Modifier.size(22.dp)) }
    }
}

@Composable
private fun FloatChip(text: String, on: Boolean, onClick: () -> Unit) {
    Surface(shape = RoundedCornerShape(20.dp), color = if (on) Ds.Navy else Color.White, shadowElevation = 4.dp, modifier = Modifier.clickable(onClick = onClick)) {
        Text(text, color = if (on) Color.White else Ds.Ink, fontSize = 13.sp, fontWeight = FontWeight.Medium, modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp))
    }
}

/** Why the map isn't showing, in words the employee (and admin) can act on. */
@Composable
private fun MapProblem(err: String?, origin: String, modifier: Modifier, stage: String? = null, onRetry: () -> Unit) {
    val (title, body) = when {
        err == null -> "Loading map…" to stage?.let { "Step: " + when (it) {
            "start" -> "opening the map page"; "page" -> "page opened"; "google-loading" -> "downloading Google Maps"
            "google-loaded" -> "Google Maps downloaded, checking the key"; "map-creating" -> "drawing the map"; else -> it } }
        err.startsWith("timeout:") -> "Map could not load" to "Stopped at step “${err.removePrefix("timeout:")}”. Check the internet connection and the Google key, then try again."
        err.startsWith("js:") -> "Map could not load" to "Page error: ${err.removePrefix("js:")}"
        err == "nokey" -> "Map not set up" to "Your admin adds the map display key in the admin console: Integrations → Google Maps."
        err == "offline" || err == "timeout" -> "Map could not load" to "Check the internet connection and try again."
        err == "g:ApiNotActivatedMapError" || err == "g:ApiTargetBlockedMapError" -> "Map blocked by Google" to "The key is not allowed to use “Maps JavaScript API”. Admin: enable it in Google Cloud and add it to the key's API restrictions."
        err == "g:RefererNotAllowedMapError" -> "Map blocked by Google" to "The key's website restriction doesn't include this server. Admin: add ${origin.trimEnd('/')}/* to the key's websites (or remove that restriction)."
        err == "g:BillingNotEnabledMapError" -> "Map blocked by Google" to "Billing is not enabled on the Google Cloud project."
        err == "g:InvalidKeyMapError" || err == "key" -> "Map blocked by Google" to "The Google Maps key was refused. Admin: check it in Integrations → Google Maps."
        else -> "Map blocked by Google" to "Google error: ${err.removePrefix("g:")}. Admin: check the key in Google Cloud."
    }
    Surface(shape = RoundedCornerShape(20.dp), color = Color.White, shadowElevation = 8.dp, modifier = modifier.fillMaxWidth()) {
        Column(Modifier.padding(18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            if (err == null) CircularProgressIndicator(Modifier.size(28.dp), color = Ds.Navy, strokeWidth = 3.dp) else IconTile(AmmpIcons.Place, Ds.Amber, 44)
            Text(title, color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 16.sp, modifier = Modifier.padding(top = 10.dp))
            body?.let { Text(it, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 4.dp)) }
            if (err != null && err != "nokey") TextButton(onClick = onRetry) { Text("Try again", color = Ds.Navy) }
            if (err != null) Text("Distances, list and navigation below still work.", color = Ds.Muted, fontSize = 12.sp)
        }
    }
}

@Composable
private fun LocationPrompt(state: String, noPins: Boolean, noCases: Boolean, onAllow: () -> Unit, onRefresh: () -> Unit) {
    SheetCard {
        Text(when {
            state == "denied" -> "Turn on location"
            state == "unavailable" -> "Location not found"
            noCases -> "No customers yet"
            noPins -> "No customer has a map location"
            else -> "Finding your location…"
        }, color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
        Text(when {
            state == "denied" -> "Allow location so the app can show where you are and which customers are nearest."
            state == "unavailable" -> "Your current location could not be detected. Turn on GPS and try again."
            noCases -> "Customers appear here once your supervisor assigns them."
            noPins -> "Open a customer below and use Set pin when you are at their address."
            else -> "This takes a few seconds outdoors."
        }, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 4.dp))
        if (state == "denied") Button(onClick = onAllow, modifier = Modifier.padding(top = 12.dp).fillMaxWidth(), shape = RoundedCornerShape(14.dp),
            colors = ButtonDefaults.buttonColors(containerColor = Ds.Navy)) { Text("Allow location") }
        else if (state == "unavailable") OutlinedButton(onClick = onRefresh, modifier = Modifier.padding(top = 12.dp).fillMaxWidth(), shape = RoundedCornerShape(14.dp)) { Text("Try again") }
    }
}

@Composable
private fun SheetCard(border: Color = Ds.Line, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp).clip(RoundedCornerShape(20.dp)).background(Color.White)
        .border(1.5.dp, border, RoundedCornerShape(20.dp)).padding(16.dp)) { content() }
}

@Composable
private fun MeRow(name: String, fix: Location?, state: String, busy: Boolean, onNearest: () -> Unit, onRefresh: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(10.dp).background(if (fix != null) Ds.Blue else Ds.Muted, CircleShape))
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(name.ifBlank { "You" }, color = Ds.Ink, fontWeight = FontWeight.Medium, fontSize = 14.sp, maxLines = 1)
            Text(fix?.let { f ->
                val at = Instant.ofEpochMilli(f.time).atZone(ZoneId.systemDefault())
                String.format(java.util.Locale.US, "%.5f, %.5f", f.latitude, f.longitude) + (if (f.hasAccuracy()) " · ±${f.accuracy.toInt()} m" else "") +
                    " · " + at.format(hm) + (if (at.toLocalDate() != LocalDate.now()) " ${at.toLocalDate()}" else "")
            } ?: when (state) { "denied" -> "Location permission is off"; "unavailable" -> "Location not found"; else -> "Getting location…" },
                color = if (fix == null && state != "loading") Ds.Red else Ds.Muted, fontSize = 12.sp, maxLines = 1)
        }
        TextButton(onClick = onRefresh) { Text("Refresh", color = Ds.Navy, fontSize = 13.sp) }
        Button(onClick = onNearest, enabled = !busy && state != "denied", shape = RoundedCornerShape(12.dp), contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp),
            colors = ButtonDefaults.buttonColors(containerColor = Ds.Gold)) { Text("Find nearest", color = Ds.NavyInk, fontSize = 13.sp, fontWeight = FontWeight.SemiBold) }
    }
}

@Composable
private fun NoteLine(text: String) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp).background(Ds.Amber.copy(alpha = 0.10f), RoundedCornerShape(12.dp)).padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically) {
        Icon(AmmpIcons.Warning, null, tint = Ds.Amber, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(8.dp))
        Text(text, color = Ds.Amber, fontSize = 12.sp)
    }
}

@Composable
private fun DistChip(text: String, strong: Boolean) {
    Text(text, color = if (strong) Ds.Navy else Ds.Muted, fontSize = 13.sp, fontWeight = if (strong) FontWeight.SemiBold else FontWeight.Normal,
        modifier = Modifier.background(if (strong) Ds.Navy.copy(alpha = 0.08f) else Ds.Bg, RoundedCornerShape(8.dp)).padding(horizontal = 10.dp, vertical = 5.dp))
}

@Composable
private fun CustomerSheetCard(n: Near, label: String, route: RouteLine?, routeBusy: Boolean, callEnabled: Boolean, onNavigate: () -> Unit, onRoute: () -> Unit, onCall: () -> Unit,
                              onCollect: () -> Unit, onView: () -> Unit, onFixLocation: () -> Unit,
                              arrived: Boolean, checkedIn: String?, checkBusy: Boolean, onCheckIn: () -> Unit, onClose: (() -> Unit)?) {
    val r = n.r
    val today = LocalDate.now()
    SheetCard(border = if (label.startsWith("NEAREST")) Ds.Gold else Ds.Blue) {
        Row(verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f)) {
                Text(label, style = MaterialTheme.typography.labelSmall, color = if (label.startsWith("NEAREST")) Ds.Gold else Ds.Blue, fontWeight = FontWeight.SemiBold)
                Text(r.c.name, fontWeight = FontWeight.SemiBold, fontSize = 18.sp, color = Ds.Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(r.c.ref, r.portfolio).joinToString(" · ").ifBlank { "—" }, style = MaterialTheme.typography.bodySmall, maxLines = 1)
            }
            StatusTag(r.c.status)
            if (onClose != null) Icon(AmmpIcons.Close, "Close", tint = Ds.Muted, modifier = Modifier.padding(start = 8.dp).size(22.dp).clickable(onClick = onClose))
        }
        Row(Modifier.padding(top = 10.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            val rt = route?.let { Road(it.meters, it.seconds) } ?: n.road
            if (rt != null) DistChip("🏍 ${km(rt.meters)} · ${eta(rt.seconds)}", strong = true)
            n.straight?.let { DistChip("${km(it)} straight line", strong = false) }
            if (rt == null && n.straight == null) DistChip("Distance needs your location", strong = false)
        }
        Row(Modifier.padding(top = 12.dp)) {
            MapKv("Overdue", money(r.overdue), Modifier.weight(1f), if ((r.dpd ?: 0) > 0) Ds.Red else Ds.Ink)
            MapKv("Outstanding", money(r.outstanding), Modifier.weight(1f))
            MapKv("Collected today", if (r.paidToday > 0) money(r.paidToday) else "—", Modifier.weight(1f))
        }
        Text(listOfNotNull(
            r.lastCallAt?.let { "Last call " + it.atZone(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d MMM, h:mm a")) } ?: "Not called yet",
            if (r.promiseStatus == "open") "Promise ${money(r.promiseAmount)} on ${r.promiseDate}" else null,
        ).joinToString(" · "), style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 6.dp), maxLines = 1, overflow = TextOverflow.Ellipsis)
        if (checkedIn != null) Row(Modifier.padding(top = 14.dp).fillMaxWidth().background(Ds.Green.copy(alpha = 0.10f), RoundedCornerShape(14.dp)).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically) {
            Icon(AmmpIcons.Check, null, tint = Ds.Green, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(10.dp))
            Column {
                Text("Checked in at $checkedIn", color = Ds.Green, fontWeight = FontWeight.SemiBold)
                Text("Collect payment or record the outcome below", style = MaterialTheme.typography.bodySmall)
            }
        } else if (arrived) Button(onClick = onCheckIn, enabled = !checkBusy, modifier = Modifier.padding(top = 14.dp).fillMaxWidth().height(56.dp), shape = RoundedCornerShape(14.dp),
            colors = ButtonDefaults.buttonColors(containerColor = Ds.Green)) {
            Icon(AmmpIcons.Place, null, modifier = Modifier.size(20.dp)); Spacer(Modifier.width(8.dp))
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(if (checkBusy) "Checking in…" else "I've reached · Check in", fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                n.straight?.let { Text("You are ${km(it)} from the customer", fontSize = 11.sp) }
            }
        }
        if (checkedIn == null && !arrived) Button(onClick = onNavigate, modifier = Modifier.padding(top = 14.dp).fillMaxWidth().height(50.dp), shape = RoundedCornerShape(14.dp),
            colors = ButtonDefaults.buttonColors(containerColor = Ds.Navy)) {
            Icon(AmmpIcons.Navigate, null, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)); Text("Start navigation", fontWeight = FontWeight.SemiBold)
        }
        Row(Modifier.padding(top = 10.dp).fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            SheetAction(AmmpIcons.Route, if (routeBusy) "Routing…" else "Route", Modifier.weight(1f), enabled = !routeBusy, onClick = onRoute)
            SheetAction(AmmpIcons.Call, "Call", Modifier.weight(1f), enabled = callEnabled, onClick = onCall)
            SheetAction(AmmpIcons.Check, "Collect", Modifier.weight(1f), onClick = onCollect)
            SheetAction(AmmpIcons.People, "Details", Modifier.weight(1f), onClick = onView)
        }
        Text("Pin in the wrong place? Set pin", color = Ds.Navy, fontSize = 12.sp, modifier = Modifier.padding(top = 10.dp).clickable(onClick = onFixLocation))
    }
}

@Composable
private fun SheetAction(icon: ImageVector, label: String, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Ds.Bg).clickable(enabled = enabled, onClick = onClick).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(icon, null, tint = if (enabled) Ds.Navy else Ds.Muted, modifier = Modifier.size(20.dp))
        Text(label, fontSize = 12.sp, color = if (enabled) Ds.Ink else Ds.Muted, fontWeight = FontWeight.Medium, modifier = Modifier.padding(top = 2.dp))
    }
}

@Composable
private fun MapKv(label: String, value: String, modifier: Modifier, color: Color = Ds.Ink) {
    Column(modifier) {
        Text(value, color = color, fontWeight = FontWeight.SemiBold, fontSize = 15.sp, maxLines = 1)
        Text(label, style = MaterialTheme.typography.bodySmall, maxLines = 1)
    }
}

@Composable
private fun NearRow(no: Int, n: Near, highlighted: Boolean, nearest: Boolean, planning: Boolean, picked: Boolean, onPick: (Boolean) -> Unit, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp).clip(RoundedCornerShape(16.dp))
        .background(if (highlighted) Ds.Blue.copy(alpha = 0.08f) else Color.White).border(1.dp, if (highlighted) Ds.Blue else if (nearest) Ds.Gold else Ds.Line, RoundedCornerShape(16.dp))
        .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        if (planning) Checkbox(checked = picked, onCheckedChange = onPick)
        else Box(Modifier.size(30.dp).background(if (nearest) Ds.Gold else Ds.Navy.copy(alpha = 0.08f), CircleShape), contentAlignment = Alignment.Center) {
            Text("$no", color = if (nearest) Color.White else Ds.Navy, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(n.r.c.name, fontWeight = FontWeight.SemiBold, color = Ds.Ink, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("Overdue ${money(n.r.overdue)}", style = MaterialTheme.typography.bodySmall, maxLines = 1)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(n.road?.let { km(it.meters) } ?: n.straight?.let { "≈ ${km(it)}" } ?: "—", color = Ds.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            Text(n.road?.let { eta(it.seconds) } ?: if (n.straight != null) "straight line" else "", color = Ds.Muted, fontSize = 12.sp)
        }
    }
}

/** G9: the admin's plan for today — stops in order, ticked when checked in; navigation through the stops still to do. */
@Composable
private fun TodayRoute(r: RouteLine, pinned: List<CaseRow>, done: Set<Long>, onShow: () -> Unit, onNavigate: () -> Unit) {
    SheetCard(border = Ds.Gold) {
        Text("TODAY'S ROUTE · ${done.count { it in r.order }} OF ${r.order.size} DONE", style = MaterialTheme.typography.labelSmall, color = Ds.Gold, fontWeight = FontWeight.SemiBold)
        Text(if (r.meters > 0) "${km(r.meters)} · ${eta(r.seconds)} by bike" else "Visit in this order", fontWeight = FontWeight.SemiBold, color = Ds.Ink, fontSize = 16.sp)
        r.order.forEachIndexed { i, id ->
            val ok = id in done
            Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(22.dp).background(if (ok) Ds.Green else Ds.Gold, CircleShape), contentAlignment = Alignment.Center) { Text(if (ok) "✓" else "${i + 1}", color = Color.White, fontSize = 11.sp) }
                Spacer(Modifier.width(8.dp))
                Text(pinned.firstOrNull { it.c.id == id }?.c?.name ?: "#$id", fontSize = 14.sp, color = if (ok) Ds.Muted else Ds.Ink, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = onShow, shape = RoundedCornerShape(12.dp)) { Text("Show on map", color = Ds.Navy) }
            if (r.order.any { it !in done }) Button(onClick = onNavigate, colors = ButtonDefaults.buttonColors(containerColor = Ds.Gold), shape = RoundedCornerShape(12.dp)) { Text("Navigate", color = Ds.NavyInk) }
        }
    }
}

@Composable
private fun RouteSummary(r: RouteLine, pinned: List<CaseRow>) {
    SheetCard(border = Ds.Blue) {
        Text("ROUTE · ${r.order.size} STOPS", style = MaterialTheme.typography.labelSmall, color = Ds.Blue, fontWeight = FontWeight.SemiBold)
        Text("${km(r.meters)} · ${eta(r.seconds)} by bike", fontWeight = FontWeight.SemiBold, color = Ds.Ink, fontSize = 16.sp)
        r.order.forEachIndexed { i, id ->
            val leg = r.legs.getOrNull(i)
            Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(22.dp).background(Color(0xFF7C3AED), CircleShape), contentAlignment = Alignment.Center) { Text("${i + 1}", color = Color.White, fontSize = 11.sp) }
                Spacer(Modifier.width(8.dp))
                Text(pinned.firstOrNull { it.c.id == id }?.c?.name ?: "#$id", fontSize = 14.sp, color = Ds.Ink, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                leg?.let { Text("+${km(it.meters)} · ${eta(it.seconds)}", fontSize = 12.sp, color = Ds.Muted) }
            }
        }
        Text("Order chosen by Google for the shortest drive; the farthest customer is last.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp))
    }
}

/** Set a case's map pin: GPS at the customer's door, or Google's match for the case address (confirmed before saving). */
@Composable
fun UpdateLocationDialog(vm: AppViewModel, case: CaseRow, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var msg by remember { mutableStateOf<String?>(null) }
    var found by remember { mutableStateOf<Triple<Double, Double, String>?>(null) }   // lat, lng, label
    var source by remember { mutableStateOf("gps") }
    var accuracy by remember { mutableStateOf<Float?>(null) }
    fun run(block: suspend () -> Unit) = scope.launch {
        busy = true; msg = null
        try { block() } catch (e: Exception) { if (e is kotlinx.coroutines.CancellationException) throw e; msg = friendly(e) } finally { busy = false }
    }
    AlertDialog(onDismissRequest = onDone, title = { Text("Update location · ${case.c.name}") }, text = {
        Column {
            Text("Use your GPS when you are at the customer's door, or look up the case address.", style = MaterialTheme.typography.bodySmall)
            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(enabled = !busy, onClick = { run {
                    val f = vm.freshLocation() ?: throw ApiException(0, "no_fix", "Your location could not be detected. Turn on GPS and try again.")
                    source = "gps"; accuracy = if (f.hasAccuracy()) f.accuracy else null
                    found = Triple(f.latitude, f.longitude, "Your position" + (accuracy?.let { " (±${it.toInt()} m)" } ?: ""))
                    if ((accuracy ?: 0f) > 100f) msg = "GPS is weak (±${accuracy!!.toInt()} m). Step outside and try again for a better pin."
                } }) { Text("My location") }
                OutlinedButton(enabled = !busy && case.address != null, onClick = { run {
                    val g = vm.geocodeCase(case.c.id)
                    source = "address"; accuracy = null
                    found = Triple(g["lat"]!!.jsonPrimitive.double, g["lng"]!!.jsonPrimitive.double, (g["formatted_address"]?.jsonPrimitive?.content ?: "Address match"))
                    if (g["precise"]?.jsonPrimitive?.booleanOrNull == false) msg = "Google matched only the area, not the building. Prefer your GPS at the door."
                } }) { Text("From address") }
            }
            if (busy) CircularProgressIndicator(Modifier.padding(top = 10.dp).size(18.dp), strokeWidth = 2.dp)
            found?.let { (la, lo, label) ->
                Text("$label\n" + String.format(java.util.Locale.US, "%.5f, %.5f", la, lo), fontSize = 13.sp, color = Ds.Ink, modifier = Modifier.padding(top = 10.dp))
            }
            msg?.let { Text(it, color = Ds.Amber, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
        }
    }, confirmButton = {
        TextButton(enabled = found != null && !busy, onClick = { run { found?.let { (la, lo, _) -> vm.saveCaseLocation(case.c.id, la, lo, source, accuracy) }; onDone() } }) { Text("Save pin") }
    }, dismissButton = { TextButton(onClick = onDone) { Text("Cancel") } })
}

/**
 * Google Maps JavaScript in a WebView. The page (backend/public/field-map.html) is loaded from this company's server,
 * so the admin's key — set only in the admin console — can be restricted to that site. Kotlin → page: FM.render / FM.fit;
 * page → Kotlin: marker taps, ready, key / network errors.
 */
/** Small Google map with one customer pin (case profile → Address & map). Tap = open the field map on this case. Null key → [fallback]. */
@Composable
fun CaseMiniMap(vm: AppViewModel, id: Long, name: String, lat: Double, lng: Double, fallback: @Composable () -> Unit) {
    var key by remember { mutableStateOf<String?>(null) }
    var failed by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        try { key = vm.mapKey(); if (key == null) failed = true }
        catch (e: Exception) { if (e is kotlinx.coroutines.CancellationException) throw e; failed = true }
    }
    val fix by LocationService.latest.collectAsState()
    val k = key
    if (failed) { fallback(); return }
    Box(Modifier.fillMaxWidth().height(240.dp).clip(RoundedCornerShape(12.dp)).background(Color(0xFFE8ECF1))) {
        if (k != null) {
            val view = remember(fix) {
                buildJsonObject {
                    fix?.let { f -> put("me", buildJsonObject { put("lat", f.latitude); put("lng", f.longitude); put("acc", 0.0) }) }
                    put("pins", buildJsonArray { add(buildJsonObject { put("id", id); put("lat", lat); put("lng", lng); put("t", name); put("k", "selected") }) })
                }.toString()
            }
            var fitReq by remember { mutableStateOf(0 to emptyList<LatLng>()) }
            JsMap(vm.serverUrl, k, view, fitReq, onMarker = { vm.openFieldMap(id) }, onReady = { fitReq = 1 to listOf(LatLng(lat, lng)) },
                onError = { failed = true }, onStatus = {}, pad = "{top: 30, bottom: 30, left: 30, right: 30}")
        }
    }
}

@SuppressLint("SetJavaScriptEnabled", "JavascriptInterface")
@Composable
private fun JsMap(origin: String, key: String, view: String, fitReq: Pair<Int, List<LatLng>>, onMarker: (Long) -> Unit, onReady: () -> Unit, onError: (String) -> Unit,
                  onStatus: (String) -> Unit, pad: String = "{top: 150, bottom: 320, left: 30, right: 80}") {
    val tap by rememberUpdatedState(onMarker)
    val ready by rememberUpdatedState(onReady)
    val err by rememberUpdatedState(onError)
    val stat by rememberUpdatedState(onStatus)
    var stage by remember { mutableStateOf("start") }
    var web by remember { mutableStateOf<WebView?>(null) }
    var loaded by remember { mutableStateOf(false) }
    // clipToBounds: a WebView inside Compose otherwise paints outside its box (it covered the header / status bar).
    AndroidView(modifier = Modifier.fillMaxSize().clipToBounds(), factory = { c ->
        WebView(c).apply {
            // Without MATCH_PARENT the WebView tells the page its height is 0, and the map is drawn 0 px tall (blank).
            layoutParams = android.view.ViewGroup.LayoutParams(android.view.ViewGroup.LayoutParams.MATCH_PARENT, android.view.ViewGroup.LayoutParams.MATCH_PARENT)
            setBackgroundColor(android.graphics.Color.rgb(0xE8, 0xEC, 0xF1))
            WebView.setWebContentsDebuggingEnabled(true)   // chrome://inspect on the PC can open this page while the phone is on USB
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            addJavascriptInterface(object {
                @JavascriptInterface fun onMarker(id: String) { post { id.toLongOrNull()?.let(tap) } }
                @JavascriptInterface fun onReady() { post { loaded = true; ready() } }
                @JavascriptInterface fun onError(kind: String) { post { if (stage != "tiles" || kind.startsWith("g:") || kind == "key") err(kind) } }
                @JavascriptInterface fun onStatus(s: String) { post { stage = s; stat(s) } }
            }, "Android")
            webViewClient = object : WebViewClient() {
                override fun onPageFinished(v: WebView, url: String?) { v.evaluateJavascript("FM.init(" + JsonPrimitive(key) + ")", null) }
                override fun onReceivedError(v: WebView, req: android.webkit.WebResourceRequest, e: android.webkit.WebResourceError) { if (req.isForMainFrame) post { err("offline") } }
                override fun onReceivedHttpError(v: WebView, req: android.webkit.WebResourceRequest, r: android.webkit.WebResourceResponse) { if (req.isForMainFrame) post { err("offline") } }
            }
            // Normal HTTP caching: Google's map script, the clusterer and map tiles come from the phone's cache after the
            // first open. Only the page itself is re-checked every time ("no-cache" = a tiny 304 while it is unchanged),
            // so a new field-map.html on the server still shows at once. (It was LOAD_NO_CACHE + ?v=time, which
            // re-downloaded Google's script and everything else on every open.)
            settings.cacheMode = android.webkit.WebSettings.LOAD_DEFAULT
            loadUrl(origin.trimEnd('/') + "/field-map.html", mapOf("Cache-Control" to "no-cache"))
            web = this
        }
    }, onRelease = { it.destroy() })
    // The map object exists from "map-creating" on: from then the map is shown even if Google's "drawn" event is slow.
    LaunchedEffect(Unit) { kotlinx.coroutines.delay(25_000); if (stage !in setOf("map-creating", "tiles")) err("timeout:$stage") }
    LaunchedEffect(loaded, view) { if (loaded) web?.evaluateJavascript("FM.render($view)", null) }
    LaunchedEffect(loaded, fitReq.first) {
        if (loaded && fitReq.second.isNotEmpty()) web?.evaluateJavascript("FM.fit(" + buildJsonArray { fitReq.second.forEach { p -> add(buildJsonArray { add(JsonPrimitive(p.lat)); add(JsonPrimitive(p.lng)) }) } } +
            ", $pad)", null)
    }
}
