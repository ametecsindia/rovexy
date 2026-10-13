# Caller ⇄ SmartPRS2 ⇄ SmartEPT — field-force integration

Added 6 Oct 2026. Additive only. Nothing that already existed in any of the three systems was removed or changed in behaviour.

## Who owns what

| System | Owns | Receives from Caller |
|---|---|---|
| **SmartPRS2** | The employee's **geofence**, i.e. the allowed radius (Field Force → Geofence Rule: start point, lat/lng, radius km, Outside = strict / 1 km / 2 km). Attendance of record for payroll. | Live sign-in check. Each app login/logout becomes an IN/OUT punch in `attendance_logs` (source `caller`, with lat/lng/geo_status). |
| **SmartEPT** | Enforcement and monitoring: the gate, attendance derivation, violations, reports. | Each login/logout as an IN/OUT punch through the existing public punch API, so it is handled like a biometric punch. Each GPS fix goes to the new field trail. Leaving the fence raises a `GEOFENCE_EXIT` violation. |
| **Caller** | The phone, sign-in, location capture. | — (it is the sender) |

Employees are matched **by employee code**. The Caller User ID must equal the PRS2 Emp. Code and the SmartEPT Employee Code.

## Flow

```
Phone sign-in (GPS fix) ──► Caller server ──POST /api/v1/fieldforce/geofence/check──► SmartPRS2
                                  │◄── allowed / outside (+ fence) ─────────────────────┘
                                  │  outside + "Block sign-in" ⇒ sign-in refused, nothing recorded
                                  ▼
                          integration_outbox  (delivered right after the response, retried every minute)
                                  ├─► SmartPRS2  POST /api/v1/fieldforce/attendance   login/logout → IN/OUT
                                  ├─► SmartEPT   POST /api/v1/attendance/punches      login/logout → IN/OUT (+lat/lng/geo_status)
                                  └─► SmartEPT   POST /api/v1/fieldforce/locations    every GPS fix (+within/outside)
```

* **Auth:** each system's existing API keys (`X-Api-Key`). The tenant/company always comes from the key, never from the request body.
* **Idempotent:** every event carries an `external_id`, so a retry is reported as a duplicate and never creates a second row.
* **Outages:** if SmartPRS2 is unreachable at sign-in, the employee is **allowed** in (status `unverified`) and the attendance is delivered later. A server outage never locks out the whole field team.
* **Retries:** backoff 1 → 2 → 4 … 60 min, retried indefinitely. A batch the receiver rejects as malformed (422) is marked *rejected*. **Integrations → Send waiting data now** retries those too.

## Setup (per company)

1. **SmartPRS2**
   - Run `php artisan migrate`. This adds `attendance_logs.lat/lng/geo_status` if missing.
   - Under Time & Attendance → **API Keys**, create a key with scope **ingest**.
   - Set each field employee's rule in **Field Force → Geofence Rule**.
2. **SmartEPT**
   - Run `php artisan migrate`. This creates `field_force_locations`.
   - Under Integrations → **API keys**, create a key with scopes **ingest** and **read**.
3. **Caller**
   - Run `php artisan migrate` (creates `integration_connections` and `integration_outbox`).
   - Make sure the scheduler runs (`php artisan schedule:work`, or the OS task).
   - In the admin console, go to **Administration → Integrations (PRS / EPT)**. Enter each server URL and API key, then click **Save & test**.
   - Untick "Block sign-in outside the geofence" to record only.
4. Rebuild the admin console (`cd admin && npm run build`) and the Android app (Run ▶). The app now sends its GPS position with sign-in.

## API reference (new endpoints)

**SmartPRS2** (`api-key:ingest`)
- `POST /api/v1/fieldforce/geofence/check` `{emp_code, lat?, lng?}`
  → `{allowed, status: within|outside|no-gps|no-rule|unknown-employee, distance_km, limit_km, fence{lat,lng,radius_km,outside,limit_km}, message}`
- `POST /api/v1/fieldforce/attendance` `{events:[{external_id, emp_code, type: login|logout, at: ISO-8601 with offset, lat?, lng?, accuracy_m?}]}`
  → per event: `accepted | duplicate | unknown_employee | rejected`, plus the server-computed `geo_status`.

**SmartEPT**
- `POST /api/v1/attendance/punches` (existing) now also accepts `lat, lng, accuracy_m, geo_status` per punch. These are stored in `biometric_logs.metadata`.
- `POST /api/v1/fieldforce/locations` (`ingest`) `{locations:[{external_id, employee_code, captured_at, lat, lng, accuracy_m?, battery_pct?, geo_status?, distance_km?, limit_km?}]}`
- `GET /api/v1/fieldforce/locations?employee_code=&date=` (`read`) returns the day's trail.

**Caller admin** (session)
- `GET /api/admin/integrations`, `PUT /api/admin/integrations/{smartprs|smartept}`, `POST /api/admin/integrations/{system}/test`, `POST /api/admin/integrations/flush`

## Known limits / later

- **Double attendance in SmartPRS2.** If a company also has SmartEPT pushing attendance to SmartPRS2 (SmartEPT Integrations → target subscribed to `attendance.daily`/`attendance.punch`), SmartPRS2 receives the same login twice, under sources `caller` and `smartept`. For field staff, turn one of the two off.
- **The geofence verdict on GPS fixes** uses the fence cached at sign-in (about 14 h). A rule changed mid-day applies from the next sign-in.
- **No SmartEPT screen for the trail yet.** The trail is visible in Caller → Status & location, through the SmartEPT read API, and as violations in SmartEPT.
- **Duplicated geofence check in PRS2.** `FieldForceGeofence` duplicates `AttendanceReportController::checkGeofence`, so the live 49 KB controller was not touched. Merge them the next time that controller is edited.

## Tests

- **SmartPRS2:** `tests/Feature/CallerFieldForceTest.php`. Passed in a clean environment (30 assertions).
- **Caller:** `tests/Feature/FieldForceIntegrationTest.php`. Passed (3 tests). The two `AcceptanceTest` failures (Exotel recording, loopback workspace) were already failing before this change.
- **SmartEPT:** `tests/Feature/CallerFieldForceTest.php`. Written but not run yet; run `php artisan test --filter=CallerFieldForce`.
