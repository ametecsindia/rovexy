# Test plan

## 1. Automated suites

| Suite | Location | Runs where |
|---|---|---|
| Domain core (PHP, no framework) | `backend/tests/Domain` | Anywhere with PHP 8.3+ and PHPUnit |
| Laravel acceptance | `backend/tests/Feature/AcceptanceTest.php` | After `composer install` |
| Android core (Kotlin/JVM) | `android/core/src/test` | `./gradlew :core:test` or kotlinc |
| Kotlin↔PHP signature interop | `tools/interop-check.sh` | kotlinc + PHP |
| Schema DDL + backup/restore | `tools/schema-check/` | MySQL/MariaDB |
| Admin console | `admin/src/app.test.tsx` | `npm test` |
| Desktop security policy | `desktop/test` | `npm test` |

## 2. Acceptance criteria → evidence

Status uses the same marks as `STATUS.md`: ✅ verified here, 🟡 test written but not executed, ⏳ needs devices or infrastructure.

| # | Criterion | Evidence | Status |
|---|---|---|---|
| 1 | No work data before approval | AcceptanceTest `test_1_and_2…`; `ApprovalState::permitsWork` | 🟡 / ✅ (domain) |
| 2 | A device ID alone gets no credentials | `test_1_and_2…`; DeviceSecurityTest (wrong key, tampered body, replay, stale) | 🟡 / ✅ |
| 3 | Approved device + unassigned employee is blocked | `test_3…` | 🟡 |
| 4 | Company isolation | `test_4…`; TenancyTest (conflict / unresolved / fixed mode); schema rejects a NULL company and duplicate installs | 🟡 / ✅ |
| 5 | Revocation within the online bound | `test_5_and_6…` (next request = 0 s); WebSocket ≤ 60 s re-auth; physical test R-3 | 🟡 / ⏳ |
| 6 | Re-approval invalidates old credentials | `test_5_and_6…`; state machine forbids revoked→approved | 🟡 / ✅ |
| 7 | Duplicate registrations | `test_7…` | 🟡 |
| 8 | Unsupported controls reported accurately | PolicyTest capability; Kotlin `policyEvaluationIsHonest`; `test_8_and_9…` | ✅ / 🟡 |
| 9 | Policy failure blocks work | PolicyTest compliance; `test_8_and_9…` | ✅ / 🟡 |
| 10 | Call restrictions hold under manipulation | CallingTest (8 denial reasons); `test_10…`; TelephonyTest injection | ✅ / 🟡 |
| 11 | Duplicate telephony events | CallingTest idempotency; `test_11_and_12…` | ✅ / 🟡 |
| 12 | Recording failures visible and recoverable | RecordingState tests; `test_11_and_12…`; TELEPHONY.md §8 | ✅ / 🟡 / ⏳ |
| 13 | Offline sync idempotent | Kotlin `queueIsIdempotent…`; `test_13_and_14…` | ✅ / 🟡 |
| 14 | Offline authorisation expires | LifecycleMiscTest lease + `event_after_lease_expiry`; Kotlin `leaseExpiry` | ✅ |
| 15 | Screen/camera need approvals and terminate | LifecycleMiscTest media; `test_15…`; physical M-1…M-6 | ✅ / 🟡 / ⏳ |
| 16 | Push failure does not block policy | `test_16…` (no push token) | 🟡 |
| 17 | Logout keeps management | `test_17…` | 🟡 |
| 18 | Import validation and reassignment | CustomersTest; `test_18…` | ✅ / 🟡 |
| 19 | Backup restores usable records and recordings | `backup-restore-test.sh` | ✅ |
| 20 | No placeholder success in production | `test_20…`; production boot guard; simulator produces no recording | 🟡 |

## 3. Physical-device test plan

Record the results in `CAPABILITY_MATRIX.md` §2. Use at least three models from different OEMs, covering Android 13, 14 and 15/16. Emulators are not acceptable evidence for these tests.

### Enrolment and policy

- **E-1:** Factory reset, then scan the QR code. The app is force-installed with the server locked and shows the approval code. Check the code matches in the console.
- **E-2:** Approve, then sign in. For each device-level control, try the blocked action manually:
  - camera app
  - USB file transfer to a PC
  - SD card
  - wallpaper change
  - install an APK from Files

  Expected: blocked, and the console shows *Applied/verified*.
- **E-3:** Set a conflicting draft (camera blocked + camera sessions). Publication must be blocked.
- **E-4:** Kiosk mode. Only allowed apps are visible. Emergency call from the lock screen must work.
- **E-5:** Sign out. Restrictions still apply, and only the login screen is available.

### Revocation and offline

- **R-1:** Airplane mode for less than the lease. Customers remain usable, and the queued disposition syncs exactly once after reconnect.
- **R-2:** Airplane mode for longer than the lease. Work locks with the message shown, and restrictions remain.
- **R-3:** While online and signed in, revoke from the console. Time until the phone shows *Access revoked* (target ≤ 60 s; usually immediate on the next action).
- **R-4:** Revoke during an active call with `terminate`. The call drops.
- **R-5:** Reinstall the app. A new pending request appears, and the old key does not work.

### Calling (non-production FreeSWITCH)

- **C-1:** Callback call answered. Check the timings and that the recording is playable and two-sided.
- **C-2:** Busy, no answer, rejected, invalid number, and the employee not answering the callback.
- **C-3:** Mandatory recording with recording storage unavailable. Check the flag (or termination) and recovery after a retry.
- **C-4:** Tamper test. Send a manipulated `number` or another customer's ID through a proxy. Expect a server rejection.

### Location

- **L-1:** Foreground location every N seconds while signed in. The notification is visible, and collection stops at sign-out.
- **L-2:** Permission denied or location off. The console shows no fixes or stale fixes. Nothing crashes.
- **L-3:** If background location is enabled by policy, the screen is off for 30 min. Check the fixes against the OEM battery restrictions, and note the OEM.

### Media

- **M-1:** Screen request. The in-app dialog shows the purpose, the OS consent dialog appears, the notification and status-bar chip are visible, and the console shows video.
- **M-2:** Decline in-app, then deny the OS consent. Both end the session cleanly.
- **M-3:** The employee taps Stop in the notification. The session ends on both sides.
- **M-4:** Timeout. Ends automatically.
- **M-5:** Open a `FLAG_SECURE` app (e.g. a banking app) during the session. It appears black. Note it.
- **M-6:** Camera session. A visible preview, the system camera indicator (Android 12+), and Stop works.
- **M-7:** A request sent while the phone is offline, then the phone reconnects after 2 min. The request must **not** appear.

### Push

- **P-1:** FCM disabled or blocked. Approval, policy and chat still arrive through WebSocket or the periodic sync.
- **P-2:** Notification payload inspection. Only `{type:"sync", reason}`, with no customer data.
