# Implementation status, verification and known limitations

Last updated: 2026-10-01. This file says plainly what works, what was verified, and how.

## Legend

| Mark | Meaning |
|---|---|
| ✅ Verified | Executed in this build environment by automated tests or scripts |
| 🟡 Written, not executed | Complete source exists, but it could not be built or run here (see Blockers) |
| 🧪 Dev simulator | A development-only stand-in. It refuses to run in production |
| ⏳ External | Needs credentials, accounts or physical devices that were not available |
| ❌ Not implemented | Deliberately out of scope, or not done |

## Blockers in this build environment

The organisation's egress policy for the cloud build sandbox blocked these hosts, each with a 403:

- Packagist / Composer (`repo.packagist.org`, `packagist.org`)
- GitHub (`github.com`, `codeload.github.com`, `api.github.com`)
- Google Maven (`dl.google.com`, `maven.google.com`)
- Maven Central (`repo.maven.apache.org`)
- Gradle (`services.gradle.org`, `plugins.gradle.org`)

npm, PyPI and Ubuntu apt worked. As a result:

1. **Laravel could not be installed.** The Laravel HTTP/service layer is syntax-checked only. `tests/Feature/AcceptanceTest.php` is written but has not been executed.
2. **The Android APK could not be built in the sandbox**: it was built on the developer PC instead (see Test results).
3. **The Windows installer could not be built.** The Electron binary and the NSIS tooling download from GitHub.

To unblock: allow the hosts above (Admin settings → Capabilities → network access), or run the build steps in `docs/BUILD.md` on a developer PC.

## Test results (executed 2026-10-01)

| Suite | Command | Result |
|---|---|---|
| Backend domain core (tenancy, signatures, TOTP, policy engine, conflicts, compliance, AMAPI mapping, phone/import validation, call authorisation, call lifecycle, recording states, offline lease, media consent, retention, ESL protocol + **real-socket ESL client**) | `cd backend && phpunit -c phpunit.domain.xml` | ✅ 40 tests, 402 assertions, all passed (PHPUnit 9.6, PHP 8.4) |
| Laravel syntax | `php -l` on every PHP file | ✅ no errors |
| Database migrations, executed as DDL on **MariaDB 10.11** through a schema shim (`tools/schema-check`): FKs, indexes, ordering, rollback | `php tools/schema-check/run.php` | ✅ 52 tables, 109 foreign keys, clean rollback and re-apply. Not the same as running `artisan migrate`. |
| Backup → restore → verify, on the real schema with fixture rows and a real recording file. Also checks that the schema rejects a duplicate device and a NULL company, and that a tampered backup is rejected | `tools/schema-check/backup-restore-test.sh` | ✅ passed |
| Cross-language signatures: a request signed by the Kotlin client code is verified by the PHP server code | `tools/interop-check.sh` | ✅ passed |
| Android core logic (canonical signing, ECDSA, backoff, server URL rules, lease, idempotent queue, customer withdrawal, honest policy evaluation, stale media requests, push routing) | kotlinc + JUnit 4 (see BUILD.md) | ✅ 9 tests passed |
| Admin console: typecheck, build, unit/UI tests (CSRF header, no web storage, permission-filtered navigation, MFA enrolment flow, neutral logo placeholder) | `cd admin && npm run typecheck && npm test && npm run build` | ✅ 5 tests passed, build OK |
| Desktop shell security policy (sandbox/contextIsolation/no nodeIntegration, https-only server, navigation lock, safe downloads, permission denial) | `cd desktop && npm test` | ✅ 5 tests passed |
| Laravel feature/acceptance tests (Laravel 13.34, PHP 8.3.30, Windows/Laragon, SQLite in-memory) | `cd backend && composer test` | ✅ all passed on 2026-10-01 (54 tests in total incl. domain; 2 fork-based ESL tests skip on Windows) |
| Live end-to-end on Laragon (`php artisan serve`, MySQL, simulator): admin sign-in + TOTP enrolment, all console API routes, device register → wrong/right code approve → signed session → employee login → honest non-compliance (app_only) → policy publish → compliant → tampered number rejected → simulated call (completed, 6 s ring / 20 s talk, recording correctly *Failed*) → disposition + follow-up → revoke → 401 on next request | browser-driven | ✅ 2026-10-01 |
| Android debug APK build (AGP 8.11.1, Kotlin 2.2.0, Gradle 8.14.3 on JDK 21, Android Studio 2026.1.4, Windows) | Android Studio → Build → Assemble 'app' | ✅ BUILD SUCCESSFUL 2026-10-01 → `app/build/outputs/apk/debug/app-debug.apk` (78 MB). Only fix needed: `toUpperCase/toLowerCase` → `uppercase/lowercase` (error-level deprecation in Kotlin 2.2) |
| SIM calling route (`CALLING_ROUTE=sim`, default): server authorises + logs the call, phone dials from its SIM, reports talk time from the call log, uploads the phone's own call-recorder file (m4a/mp3/aac/amr/wav/ogg/3gp, ≤50 MB, SHA-256) | APK build + `test_sim_route_call_result_and_phone_recording_upload` | ✅ APK builds (2026-10-01); 🟡 feature test written, run `composer test`; ⏳ not yet tried on a real phone (needs the OEM dialer's auto-record ON) |
| Admin polish: dashboard (KPIs, 7-day chart, per-employee productivity), calls filters + inline player, customer page, follow-up names | `npm test && npm run build`; live in Chrome against Laragon | ✅ 2026-10-01 |
| In-app calling (default phone app + own call screen) and in-app recorder (accessibility-enabled capture, source auto-select VOICE_CALL → UNPROCESSED → MIC → VOICE_RECOGNITION with silence detection, effects off, AGC) | OPPO CPH2359 / Android 14 | 🟡 2026-10-02: earlier build recorded speech but with ~40 % zero samples (OEM gate on VOICE_RECOGNITION) at −42 dBFS; new source logic built, awaiting device test — each call reports source + input level in its diagnostics |
| Android app on emulator/device, instrumented tests | install APK, connect to `http://10.0.2.2:8000` | 🟡 not executed |

Two independent code reviews of the unexecuted Laravel and Android layers ran on 2026-10-01. Their findings were fixed:

- Laravel: middleware priority, Sanctum session detection, broadcasting middleware, lease check order, supervisor scoping on customer moves and device views, cross-company team/profile IDs, per-company rate-limit keys.
- Android: foreground-service start order, reconnect loop, token refresh race, consent launch thread, signal ordering, notification-required media, logo origin.

## Progress checklist

### Stage 1 — Inspect and validate

- ✅ Workspace inspected (empty: new project). Architecture: `docs/ARCHITECTURE.md`.
- ✅ Management choice verified against current Google documentation:
  - AMAPI is for commercial EMMs.
  - The first 500 devices need a business justification.
  - Custom-DPC provisioning is constrained.
  - See `docs/ENROLMENT.md`.
- ✅ Capability matrix: `docs/CAPABILITY_MATRIX.md`.
- ⏳ Physical-device feasibility spikes (management, calling, recording, screen sharing). The plan is in `docs/TEST_PLAN.md` §3. Not run: no devices.

### Stage 2 — Foundation

- ✅🟡 Company isolation:
  - Resolver ✅
  - Fail-closed global scope, company-prefixed storage, channel checks 🟡
- 🟡 Users, granular permissions, the 6 roles, supervisor team scoping
- 🟡 Admin authentication. TOTP itself is ✅.
- ✅🟡 Device registration, signed approval polling, approval/reject/revoke/re-register, epoch-based credential invalidation:
  - Signature and state machine ✅
  - Endpoints 🟡
- 🟡 Branding, one config for console and app, neutral placeholder. The UI is ✅.
- ✅ Admin console foundation (18 modules)
- ✅ Electron shell
- 🟡 Android connect → wait → login → policy → dashboard

### Stage 3 — Core calling

- ✅🟡 CSV/XLSX import:
  - Validation ✅
  - Upload/preview/background/commit 🟡
- 🟡 Assignment, reassignment, withdrawal, sync removal. The removal rule is ✅.
- ✅🟡 Telephony adapter:
  - FreeSWITCH ESL client ✅ against a fake socket
  - Originate and injection guards ✅
  - Event mapping ✅
  - Adapter wiring 🟡
- 🧪 Simulator adapter (local/testing only)
- ✅🟡 Call lifecycle, idempotent events, ring/talk/total durations:
  - Logic ✅
  - Persistence 🟡
- 🟡 Recording pipeline: fetch → checksum → transcode → available; watchdog; reconciliation; retry; signed playback; audit
- 🟡 Dispositions and follow-ups (online, and through the offline queue)
- ⏳ Real calls on an existing FreeSWITCH. This needs a non-production FreeSWITCH, a gateway and test numbers.

### Stage 4 — Device policies

- ✅ Versioned policies, precedence, conflict detection, capability reporting, compliance verdict, AMAPI policy mapping
- 🟡 Draft/publish/rollback/assign, effective policy view, acknowledgements, AMAPI sync job, Pub/Sub status ingestion
- ⏳ AMAPI enterprise and service account
- ⏳ Validation on real devices

### Stage 5 — Supervision

- 🟡 Activity/presence (observed signals only), work-session location with a visible foreground service
- 🟡 Chat with receipts, attachment checks, offline resend
- 🟡 Screen sharing (MediaProjection consent, foreground notification, in-app banner), screenshots inside a session, camera sessions, timeouts, end on revoke. The state machine is ✅.
- ⏳ TURN server, plus validation on real devices

### Stage 6 — Release readiness

- ✅ Backup/restore scripts verified
- ✅ Deployment, build, enrolment, telephony, API and user documentation
- ⏳ Security verification on a deployed instance
- ⏳ Physical-device validation
- ⏳ Signed APK and Windows installer

## Known limitations and outstanding dependencies

1. **Laravel layer:** the acceptance tests pass, but run on SQLite. Run them once against MySQL 8 too, and do a manual end-to-end pass in a browser. Resolved versions: Laravel 13.34, Sanctum 4.3, Reverb 1.12, PHPUnit 12.5.
2. **Android/Gradle versions** in `gradle/libs.versions.toml` were chosen offline. Update them to current stable before building. `io.github.webrtc-sdk:android` is a third-party libwebrtc build: pin it after the device spike.
3. **Android Management API**:
   - Requires a Google Cloud project, an enterprise per company, and a service account.
   - Requires eligibility as a commercial EMM.
   - Device-level controls on `app_only` devices are reported as *Requires additional setup*, never as applied.
4. **In-app VoIP agent leg (`sip_endpoint`)** is defined server-side, but the Android SIP/WebRTC media client is not implemented. The working mode is `pstn_callback`: the server rings the employee's company SIM, then bridges. Two-sided audio is recorded at FreeSWITCH.
5. **SIM calling** is implemented as the default route. The recording comes from the phone's own dialer recorder: it works only where the OEM offers auto-record and stores files in shared storage (Xiaomi/Redmi, Samsung, OPPO/Realme/OnePlus, Vivo typically do; Google Pixel does not). If no file is found the recording is marked Failed with that reason, never faked. AMR/3GP files cannot play in browsers (download instead). READ_CALL_LOG is a restricted permission on public Google Play; distribute as a private/managed app or sideload.
6. **Protected content** (`FLAG_SECURE` apps) appears black in screen sessions. It cannot be detected reliably. The console and the employee disclosure say so.
7. **On-prem TLS**: the app trusts system CAs only. On-prem servers need a publicly trusted certificate, or a per-customer build with the company CA pinned.
8. **Sanctum stateful domains**: every company host (wildcard subdomains plus verified custom domains) must be listed in `SANCTUM_STATEFUL_DOMAINS`.
9. **AMAPI field and enum names** (e.g. `playStoreMode` values, `usbDataAccess`) follow the public reference. Confirm them against a sandbox enterprise.
10. **FreeSWITCH bridge-outcome variables** (`originate_disposition`, `last_bridge_hangup_cause`) must be confirmed on the target FreeSWITCH build.
11. **Location view** lists fixes with OpenStreetMap links. There is no embedded map, to avoid third-party tile dependencies.
12. **Platform Administrator** (shared SaaS operator) has a data model (`platform_admins`) and CLI provisioning (`ammp:create-company`). There is no web console for it yet.
13. **Legal**: recording notices and disclosures are configurable text. No legal or regulatory compliance is claimed.
