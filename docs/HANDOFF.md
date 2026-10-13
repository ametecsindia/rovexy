# Ametecs Managed Mobile (Caller): session handoff, updated 6 Oct 2026 04:30 IST

Start here next session. Read this file, then `Smart caller/docs/INTEGRATION-PRS-EPT.md` if the work touches SmartPRS2 or SmartEPT.

## 1. Where things are

| Item | Location |
|---|---|
| Caller code | `C:\laragon\www\Smart caller`. Subfolders: `backend/` (Laravel 13), `admin/` (React + Vite, builds into backend/public/admin), `android/` (Kotlin/Compose) |
| SmartPRS2 (LIVE product) | `C:\laragon\www\smartprs2`. Additive changes only. |
| SmartEPT | `C:\laragon\www\smartept` |
| Integration report (editable doc) | https://claude.ai/code/artifact/c27d5b19-3599-4bcf-beb9-cbafd755b80d |
| Report PDF, test-case Excel, integration markdown | `Smart caller\docs\` (`Field-Force-Integration-Report.pdf`, `Field-Force-Integration-Test-Cases.xlsx`, `INTEGRATION-PRS-EPT.md`) |

## 2. How to run

- **Backend:** `cd "C:\laragon\www\Smart caller\backend"` → `php artisan serve --host=0.0.0.0 --port=8000`. There is no artisan in the project root.
- **Scheduler:** `php artisan schedule:work`. Needed for integration retries.
- **Admin console:** `cd admin` → `npm install` (already done on the PC) → `npm run build` → http://localhost:8000/admin
- **Phone server URL:** `http://<laptop Wi-Fi IPv4>:8000`. The OPPO is 192.168.0.105. The emulator uses `http://10.0.2.2:8000`.
- **Android:** Android Studio → Run ▶ installs on a connected phone. Devices: OPPO CPH2359 (#3), OnePlus CPH2707 (#4), and the emulator "Medium Phone API 37" (no SIM, so no calling tests).
- **Dev logins (local only):**
  - Admin: `admin@demo-collections.example` / `DevOnly-Passw0rd!`
  - App: `EMP001` / `DevOnly-Passw0rd!`

## 3. Decisions in force

- **Calling route:** SIM. ODialer (`com.oplus.dialer`) is the permanent, only dialer.
  - Smart caller's dialer role was removed from the manifest; `SimCalls.phoneRecorder = true` is hard-coded.
  - If ODialer is missing, the app opens Play Store; if it isn't the default, the app opens Default apps; calls stay blocked until both are done.
  - Silent install needs AMAPI device-owner enrolment.
  - ODialer exists only on OPPO, OnePlus and Realme.
- **Recording:** ODialer's own auto-record must be ON. The app uploads that file.
- **Field-force integration (6 Oct, Ejaz):** connect Caller with SmartPRS2 and SmartEPT. Additive edits to smartprs2 are approved. The SmartEPT target is `smartept`, not smartept-central.
- **Reverb:** not running. Polling fallback is used.

## 4. Field-force integration: what was built (6 Oct)

**Data ownership**
- SmartPRS2 owns the geofence (existing Field Force → Geofence Rule, table `geofence_rules`: employee_id, start home/office, lat, lng, radius_km, outside strict/1km/2km) and payroll attendance.
- SmartEPT handles enforcement and monitoring.
- Caller sends the data, through an outbox.
- Employees are matched by employee code (Caller users.employee_code = PRS emp_code = EPT employee_code, case-insensitive).

**SmartPRS2** (tests pass: 4 tests, 30 assertions)
- New `app/Services/FieldForceGeofence.php`. It duplicates `AttendanceReportController::checkGeofence` on purpose, so that live controller stays untouched.
- New `app/Http/Controllers/Api/FieldForceApiController.php`:
  - `POST /api/v1/fieldforce/geofence/check`
  - `POST /api/v1/fieldforce/attendance`, which writes `attendance_logs` rows: source `caller`, device_sn `CALLER`, in/out, lat, lng, geo_status. Times must be ISO with an offset and are converted to the app timezone. Duplicates are ignored via external_id.
- `routes/api.php`: +2 lines, behind `api-key:ingest`.
- New migration `2026_10_06_000100_attendance_logs_geo_columns`.
- New test `tests/Feature/CallerFieldForceTest.php`.

**SmartEPT** (tests pass: 2 new; the existing 18 SbbPunchIngest tests still pass)
- `PublicApiController::ingestPunches` also accepts lat, lng, accuracy_m and geo_status, stored in `biometric_logs.metadata`.
- New `FieldForceController`:
  - `POST /api/v1/fieldforce/locations` (ingest) stores to `field_force_locations`. The first fix outside creates an `employee_compliance_events` row GEOFENCE_EXIT (DEVICE/MEDIUM); the first fix back inside sets resolved_at.
  - `GET /api/v1/fieldforce/locations` (read).
- `routes/api.php`: +2 lines.
- Migration `2026_10_06_000100_create_field_force_locations_table`.
- Test `tests/Feature/CallerFieldForceTest.php`.

**Caller** (tests pass: FieldForceIntegrationTest, 3 tests; admin tsc + vite build pass)
- Tables `integration_connections` (API key encrypted) and `integration_outbox` (via migration `2026_10_06_000001`).
- `App\Services\FieldForceIntegration`:
  - `geofenceGate` runs inside `SessionService::login` after the password check. It throws `outside_geofence` (422) and fails open if PRS is unreachable.
  - `recordSession` handles login/logout: PRS attendance plus an EPT punch.
  - `recordLocation` is called from `SyncController` for each location event and sends it to EPT. Within/outside is judged against the fence cached at sign-in (14 h cache).
  - `flushCompany` runs right after each request and from the scheduler every minute (`integrations:flush`), with 1→60 min back-off. A 422 response marks the batch failed.
- Admin page **Administration → Integrations (PRS / EPT)** (`admin/src/pages/Integrations.tsx`, `Admin/IntegrationController`):
  - Permissions: view needs `system.health`; edit needs `telephony.configure`.
  - Bug fixed on the PC: MySQL 8 reserves `system`, so the query now uses `select('system','status')`. Do not put `system` in selectRaw again.
- Android sends a GPS fix at sign-in: `work/LocationService.kt currentFix()` → `SessionManager.login(code, pw, fix)`.

## 5. Status on Ejaz's PC (as of 04:10)

- [x] Admin `npm install` + `npm run build` done. The Integrations page opened; the SQL error was fixed after that.
- [ ] Refresh the Integrations page and confirm two cards show with no error.
- [ ] `php artisan migrate` in Caller backend (probably done, since the page reached the SQL error after the tables existed), smartprs2 and smartept.
- [ ] Create API keys: PRS (ingest) and EPT (ingest + read). Enter them in Caller → Save & test.
- [ ] Set geofence rules in PRS for test employees. Codes must match.
- [ ] Rebuild and install the Android app (Run ▶). It has NOT been compiled since the GPS-at-sign-in change.
- [ ] Manual test cases: 21 rows marked "Not run" in the Excel sheet.
- [ ] Earlier, still pending: ODialer build on the phones, auto-record ON, test call with both voices, chat both ways, camera/screen request, 5-min locations.

## 6. Open decisions for Ejaz

1. **Double attendance.** If SmartEPT also pushes attendance to SmartPRS2 (Integration target `attendance.daily`/`attendance.punch`), PRS gets field logins twice (sources caller + smartept). Choose one path for field staff. A comment is open in the report doc on this question.
2. **Fail-open on PRS outage.** Currently on. Make it a per-company setting only if a client wants strict mode.
3. **Later improvements:**
   - SmartEPT map screen for the trail.
   - Merge the PRS geofence code duplication.
   - A dedicated `integrations.manage` permission.

## 7. Notes for the assistant

- **Bridge depth limit:** files more than 7 levels deep can't be staged. Read Android sources from the `_src` mirror (refreshed on each Gradle sync by the dev-only `copy {}` block in `android/app/build.gradle.kts`, which must be deleted before release). Committing to the real deep path works. Verify commits by listing mtimes.
- **Android Studio is click-only.** Click the hamburger at (58,20), then MOUSE-MOVE (hover) to Build at (357,20); clicking the menu bar closes it. Build → "Assemble 'app' Run Configuration". Don't click around while Ejaz is using the emulator.
- **No device_bash on this PC.** Shell commands must be given to Ejaz to run.
- **Sandbox testing recipe** (all three suites passed this way):
  - Stage the needed files, overlay changes, then `composer install --ignore-platform-reqs --no-scripts`.
  - PRS2 and EPT: add `--prefer-source` and drop packages without a source (phpstan/larastan) from a scratch copy of the lock. GitHub dist downloads hit a rate limit.
  - Tests use SQLite and miss MySQL-only issues such as reserved words.
- **Caller pre-existing test failures (not ours):** AcceptanceTest exotel recording; loopback workspace.
- **SmartPRS2 is live at 4 clients.** Keep changes additive; never edit `AppController` or `AttendanceReportController` without being asked.
