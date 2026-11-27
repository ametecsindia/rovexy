# Prompt for the SmartDCM side: ROVEXY integration

> Give this whole document to the developer (or to Claude) working in the **live SmartDCM code base (Laravel 6.5, multi-tenant, one CRM ID per client)**.
> ROVEXY's side is already built (ROVEXY → Administration → SmartDCM). This document is the contract SmartDCM must implement so that both work together.
> Written 23 Nov 2026.

---

## 1. Goal

A SmartDCM client who also uses **ROVEXY** (Ametecs' field-force and calling app: FOS in the field, telecallers at a desk) connects the two systems once, then:

| Direction | What moves | How |
|---|---|---|
| SmartDCM → ROVEXY | Teams and branches, users (telecallers, FOS, team leaders, managers), portfolios, cases with their allocation, the client's calling account (CRM ID, OBD gateway secret, company numbers/VMNs with their LC voucher code, Smart31 pool) | ROVEXY **reads** SmartDCM's API every 5 minutes, and immediately when SmartDCM sends a signed "something changed" webhook |
| ROVEXY → SmartDCM | Outcomes (dispositions) of SIM and Click2Call calls and field visits, with PTP, payment and call details; GPS check-ins at the customer; remarks; status changes; re-allocations made in ROVEXY; payment verified/rejected | ROVEXY **posts** batches to SmartDCM's API (retried until SmartDCM answers 2xx) |
| SmartDCM → ROVEXY | Call recordings of ROVEXY calls (for SmartDCM's case history) | SmartDCM downloads them from ROVEXY with the ROVEXY secret |

**SmartDCM remains the master for cases, allocation and users.** ROVEXY never deletes anything in SmartDCM.

---

## 2. Ground rules (read first)

1. **Do not change existing behaviour.** Everything below is additive: new routes under `/api/rovexy/v1`, new tables prefixed `rovexy_`, new settings page. Existing screens, APIs, dialer, C2C, Smart31 and reports must work exactly as before for clients who never enable ROVEXY.
2. **PHP 7.2+/Laravel 6.5 syntax only** (no `match`, no named arguments, no typed properties, no `?->`, no constructor promotion). Use `Illuminate\Support\Facades\*` as in the rest of the code.
3. **Tenant isolation is the most important rule.** An API key belongs to exactly one CRM ID. Every query in these endpoints must run on that tenant's data/connection only — resolve the tenant from the key, then switch the database connection the same way the app does for a logged-in user of that CRM ID (the `smartdcm_tenants.tenants` lookup by `crm_id`). A key of CRM 1133 must never see CRM 510's data, even by changing an id in the URL.
4. **Secrets:** API keys are stored only as SHA-256 hashes; shown once when created. The ROVEXY secret (for webhook signing and recording download) is stored encrypted (`encrypt()`), never logged. Never put secrets in URLs or logs. No `APP_DEBUG=true` on servers answering this API.
5. **All times** in ISO-8601 with offset, in the tenant's time zone (e.g. `2026-11-22T09:01:00+05:30`). **All money** as numbers (not strings with commas). **Phone numbers** as digits (10-digit Indian mobiles are fine; ROVEXY normalises them).
6. **JSON only.** Every endpoint answers `Content-Type: application/json`. Errors: `{"message": "..."}` with a proper HTTP status (401 bad key, 403 feature off, 404 not found, 422 invalid body, 429 rate limit, 500).
7. **Idempotency:** every item ROVEXY posts has an `external_id`. Store it; a repeated `external_id` must be accepted (2xx) and **not** create a second row (ROVEXY retries after timeouts).
8. **Never block on ROVEXY.** Webhooks to ROVEXY are queued jobs with short timeouts and retries; if ROVEXY is down, SmartDCM must work normally.
9. Rate limit the API per key (e.g. 120 requests/minute; ROVEXY stays well below).

---

## 3. SmartDCM admin screen: Settings → Integrations → ROVEXY

Visible to the tenant's admin only. Fields:

| Field | Notes |
|---|---|
| **Enable ROVEXY integration** | Off by default. When off, all `/api/rovexy/v1` calls for this CRM answer `403 {"message":"ROVEXY integration is off"}` and no webhooks are sent. |
| **Create API key** | Generates a random 40+ character key, shows it **once** with a Copy button, stores `sha256(key)`. List of keys with created date, last used date, **Revoke**. The client pastes the key into ROVEXY → Administration → SmartDCM. |
| **ROVEXY webhook URL** | Pasted from ROVEXY (looks like `https://rovexy.app/api/integrations/smartdcm/<uuid>/webhook`). |
| **Recording URL** | Pasted from ROVEXY (looks like `https://rovexy.app/api/integrations/smartdcm/<uuid>/recordings/{call_id}`). |
| **ROVEXY secret** | Pasted from ROVEXY (48 characters). Stored encrypted. Used to sign webhooks and to download recordings. |
| **Share login passwords with ROVEXY** | If on, `GET /users` includes `password_hash` (the bcrypt hash SmartDCM already stores) so people use the same password in ROVEXY. Off = ROVEXY users get their own password. |
| **Accept allocations made in ROVEXY** | If on, `allocation` / `withdrawn` events from ROVEXY change the case allocation in SmartDCM. If off they are only written to the case history. |
| **Accept status changes from ROVEXY** | Same, for `status` events. |
| **Send results as** | The SmartDCM user/source shown on rows created from ROVEXY (default: the matching SmartDCM user; source label "ROVEXY"). |
| **Disposition code mapping** | Table: ROVEXY code → SmartDCM disposition. Pre-fill with codes that match by name (PTP, RTP, CB, RNR, PAID, PART_PAID, NC, WN…). Unmapped codes are saved with the ROVEXY label as text. |
| **Test ROVEXY** | Sends a signed `ping` webhook and shows ROVEXY's answer (202 = OK). |
| **Log** | Last 200 API calls/webhooks: time, direction, endpoint, status, item count, error. |

Store settings in a new table (one row per tenant) — see §9.

---

## 4. Authentication for `/api/rovexy/v1/*`

* ROVEXY sends **`X-Api-Key: <key>`** (and `X-Client: ROVEXY`) on every request.
* Middleware `RovexyApiKey`:
  1. Find the key by `sha256(header)` in `rovexy_api_keys` where `revoked_at IS NULL`; else **401**.
  2. Load the tenant by the key's `crm_id`; if the tenant is inactive or the integration is off → **403**.
  3. Switch to that tenant's DB connection/context exactly as the normal app does; bind the tenant for the request.
  4. Update `last_used_at` (at most once a minute).
  5. Throttle per key.
* Base URL is the client's normal SmartDCM address (e.g. `https://abc.smartdcm.app`). ROVEXY calls `https://abc.smartdcm.app/api/rovexy/v1/...`. If one host serves many tenants, the key alone selects the tenant.

---

## 5. Lists ROVEXY reads (paged with a cursor)

**Paging contract (users, portfolios, cases):**

```
GET /api/rovexy/v1/cases?updated_since=2026-11-22T09:01:00+05:30&after_id=9002&limit=500
```

* Return rows where `updated_at > updated_since` **OR** (`updated_at = updated_since` AND `id > after_id`), **ordered by `updated_at ASC, id ASC`**, at most `limit` rows (max 500, default 200).
* No `updated_since` = from the beginning.
* Answer: `{"data": [ ... ], "next": {"updated_since": "<updated_at of the last row>", "after_id": <id of the last row>}}` when there may be more rows, or `"next": null` when this page reached the end.
* Every row carries its `id` and `updated_at`. **`updated_at` must change whenever anything ROVEXY receives changes** — including allocation changes, withdrawal and deactivation (touch the row when you allocate/withdraw/deactivate).
* Deleted/withdrawn rows must still be returned (with `withdrawn: true` / `active: false`) — ROVEXY never learns about a removal otherwise.

### 5.1 `GET /ping`

```json
{"ok": true, "company_name": "Sunrise Credit Services", "crm_id": "1133", "timezone": "Asia/Kolkata", "case_url_template": "https://abc.smartdcm.app/cases/{id}"}
```

### 5.2 `GET /teams` (not paged; all teams of the tenant)

```json
{"data": [
  {"id": 7, "name": "Hyderabad Recovery", "branch": {"id": 2, "name": "Hyderabad"}},
  {"id": 8, "name": "Field Team East", "branch": null}
]}
```

### 5.3 `GET /users` (paged)

```json
{"data": [
  {"id": 101, "employee_code": "EMP1", "name": "Ravi Teja", "email": "ravi@agency.in", "mobile": "9811111111",
   "role": "telecaller", "team_ids": [7], "active": true, "c2c_agent_number": "9811111111",
   "password_hash": "$2y$10$....", "updated_at": "2026-11-20T10:00:00+05:30"}
], "next": null}
```

* `role`: one of `admin`, `manager`, `team_leader`, `telecaller`, `fos` (map SmartDCM's own role names to these). ROVEXY creates telecaller/fos as employees and team_leader/manager as supervisors of their teams; `admin` is only linked, never created.
* `employee_code` must be the same code the client uses in ROVEXY (ROVEXY matches by employee code, then e-mail, then mobile).
* `password_hash`: only when "Share login passwords" is on, and only bcrypt (`$2y$...`). Otherwise omit or `null`.
* `active: false` for users who left; ROVEXY then suspends them.
* `c2c_agent_number`: the number the Click2Call gateway rings first for this user (if SmartDCM keeps one).

### 5.4 `GET /portfolios` (paged)

```json
{"data": [
  {"id": 55, "name": "HDFC Bank · Nov-26 · B2", "bank": "HDFC Bank", "product": "Personal loan", "status": "active",
   "uploaded_at": "2026-11-01T11:00:00+05:30", "updated_at": "2026-11-21T09:00:00+05:30"}
], "next": null}
```

### 5.5 `GET /cases` (paged; the big one)

```json
{"data": [
  {"id": 9001, "portfolio_id": 55, "account_no": "LN001", "customer_name": "Ravi Kumar",
   "mobile": "9876543210", "alt_mobile": "9876500000", "email": null,
   "address": "5-1-23, Lake Road", "city": "Hyderabad", "state": "Telangana", "pincode": "500084",
   "loan_amount": 250000, "pos": 12500, "principal_os": 11000, "interest_os": 1200, "charges": 300,
   "overdue_amount": 3000, "emi_amount": 1500, "due_date": "2026-11-05", "dpd": 45, "bucket": "B2",
   "last_paid_on": "2026-09-05", "last_paid_amount": 1500,
   "product": "Personal loan", "bank": "HDFC Bank",
   "assigned_user_id": 101, "team_id": 7, "status": "open", "withdrawn": false,
   "extra": {"Ref 1": "R-1", "Employer": "ABC Pvt Ltd"},
   "updated_at": "2026-11-22T09:00:00+05:30"}
], "next": {"updated_since": "2026-11-22T09:00:00+05:30", "after_id": 9001}}
```

* `account_no` is ROVEXY's customer ID; it must be unique within the tenant.
* `status`: SmartDCM's case status in lower case; ROVEXY understands `open/new`, `ptp/promise/callback/follow_up/broken_ptp` (→ follow-up), `closed/paid/settled/resolved/foreclosed/surrendered` (→ closed), `dnc/do_not_call`.
* `assigned_user_id` / `team_id`: SmartDCM ids from §5.3 / §5.2 (or `null` when unallocated).
* `withdrawn: true` when the case was withdrawn/returned to the bank or deleted — keep returning it so ROVEXY removes it from phones.
* `extra`: the bank file's other columns as label → value (max 100), shown in ROVEXY like an uploaded file's kept columns.
* Performance: index `(updated_at, id)` on the cases table if not present; select only the needed columns; first sync of a large tenant is read 500 rows per request.

### 5.6 `GET /cases/{id}/trail` (live, for ROVEXY's customer page)

```json
{"case_url": "https://abc.smartdcm.app/cases/9001",
 "data": [
   {"at": "2026-11-12T16:05:00+05:30", "type": "call", "code": "PTP", "amount": 5000, "ptp_date": "2026-11-20",
    "by": "Naveen", "notes": "Promised after salary", "recording_url": null}
 ]}
```

Newest first, max 200 entries: calls/dispositions, PTPs, payments, field visits, notes, allocation changes. 404 if the case is not this tenant's.

### 5.7 `GET /telephony` — the client's calling account

```json
{"crm_id": "1133",
 "c2c": {"enabled": true, "gateway_secret": "<OBD gateway secret used by click2call_data>", "lc_secret": "<CRM Secret-Key SmartDCM uses with the LC Manager>",
         "voucher_code": "74fe352484374b634b2f8a43a42b8f9b", "voucher_status": "active", "voucher_expiry": "2026-12-26"},
 "numbers": [
   {"number": "918040001111", "click2call": true, "obd": false, "tc_verified": true, "team_ids": [7], "expires_on": "2027-03-31",
    "voucher_code": "74fe352484374b634b2f8a43a42b8f9b", "voucher_status": "active", "voucher_expiry": "2026-12-26"}
 ],
 "smart31": {"enabled": true,
   "numbers": [{"number": "8040002222", "group": "Pool A", "team_ids": [7], "expires_on": null, "c2c": true}]}}
```

* Fill from the tables SmartDCM already uses for its C2C/dialer: the tenant's CRM ID, the OBD gateway secret, the active voucher numbers (click2call / obd DIDs with expiry and the teams/groups they are given to), the Smart31 groups/numbers.
* **`voucher_code` is required on every number (27 Nov 2026)** — the LC voucher the number came from (SmartDCM `obd_credits_history.voucher_code`). ROVEXY checks it with the Ametecs LC Manager itself (`did_voucher_validate/{code}/{crm_id}`, before calls and every 5 minutes). A number without a voucher code, or whose voucher the LC Manager has revoked or expired, is **not used** for calls in ROVEXY, and SmartDCM's next sync does not bring it back. ROVEXY's Smart31 numbers from SmartDCM are used only if they are on the LC Manager's Smart31 list for the day (`smart31-details/{crm_id}`).
* **The client does not enter the voucher again in ROVEXY (27 Nov 2026).** With "SmartDCM licence" chosen in ROVEXY (Company numbers → Licence), ROVEXY uses SmartDCM's CRM id, `lc_secret` and voucher as they are. Send:
  * `voucher_status` — `active`, `revoked` or `expired`, from SmartDCM's own LC checks. `revoked` / `expired` stops the voucher in ROVEXY at once; a stopped voucher is never brought back (a renewed licence is a new voucher code).
  * `voucher_expiry` (`YYYY-MM-DD`) — past that day ROVEXY stops it.
  * `lc_secret` — the CRM Secret-Key SmartDCM uses for `did_voucher_validate`. With it ROVEXY also asks the LC Manager itself every 5 minutes, so a revocation stops calls within minutes, not at SmartDCM's next daily check. Without it ROVEXY goes by `voucher_status` only (re-sent every sync; not re-confirmed within 24 hours = stopped).
  * Per number or once in `c2c` (the per-number value wins). A voucher code SmartDCM no longer sends is stopped in ROVEXY.
* With "ROVEXY licence" chosen, ROVEXY ignores SmartDCM's calling account (the client's own CRM id, key and voucher are used) — SmartDCM's numbers are not used for calls.
* Smart31 groups whose name contains "Web RTC" are WebRTC Smart31 and are not used (the LC Manager marks C2C Smart31 groups `webrtc=1` too, so the name decides).
* ROVEXY offers **Click2Call and Click2Call Smart31 only — no WebRTC**. WebRTC-only numbers (`click2call` and `obd` false) and Smart31 numbers with `"c2c": false` are ignored; the `webrtc` block and the `/webrtc/calls` endpoints (§ WebRTC) are no longer used.
* ROVEXY **replaces** its company numbers with `numbers` on every sync, and replaces only the Smart31 numbers that came from SmartDCM. Omit a key (`numbers`, `smart31.numbers`) to leave ROVEXY's list unchanged.
* This is why both systems show one telephony account: ROVEXY's Click2Call already uses the Telozar `c2c_call` API and the OBD gateway `click2call_data/{ref_id}/{vmn}` with `ref_id = {uuid}_d_{crm_id}_{user_id}_1` — the same format as SmartDCM/LeadGER — so ROVEXY calls appear under this CRM ID in the gateway and in SmartDCM's C2C logs.

---

## 6. What ROVEXY posts (results) — `POST`, body `{"items": [ ... ]}`, max 200 items

Answer **200** with `{"accepted": n, "duplicates": n, "rejected": [{"external_id": "...", "reason": "unknown case"}]}`.
Per-item problems go in `rejected` (still 200) — ROVEXY does not retry those. Answer **422** only if the whole body is malformed. Any other non-2xx = ROVEXY retries the same batch later (1, 2, 4 … 60 minutes), so processing must be idempotent by `external_id`.

Store every item in `rovexy_inbox` first (raw JSON + external_id unique), then apply it in the same transaction.

### 6.1 `POST /dispositions` — every outcome recorded in ROVEXY

```json
{"items": [{
  "external_id": "rovexy-disp-5521",
  "case_id": "9001", "account_no": "LN001",
  "user_id": "101", "employee_code": "EMP1",
  "channel": "c2c",
  "code": "PTP", "label": "Promise to pay", "notes": "Will pay on Friday",
  "at": "2026-11-23T11:42:10+05:30", "follow_up_at": "2026-11-27T10:00:00+05:30",
  "ptp": {"amount": 3000, "date": "2026-11-27"},
  "payment": null,
  "call": {"id": "6f1c…-uuid", "number": "primary", "caller_number": "8040002222", "talk_seconds": 95,
           "started_at": "2026-11-23T11:40:20+05:30", "state": "completed",
           "recording_url": "https://rovexy.app/api/integrations/smartdcm/<uuid>/recordings/6f1c…-uuid"}
}]}
```

* `channel`: `sim` (FOS/telecaller's own SIM), `c2c` (Click2Call), `webrtc` (browser call through SmartDCM), `field_visit` (outcome entered at the customer without a call).
* Find the case by `case_id` (SmartDCM id); fall back to `account_no`. Unknown → `rejected`.
* Create the same rows SmartDCM creates when its own agent submits feedback: disposition/feedback row (mapped code, remarks, next follow-up), PTP row when `ptp` is present, payment row when `payment` is present (`{"amount", "mode", "reference", "paid_on", "review_status": "pending|verified|rejected"}` — create it as *pending verification* unless verified), and a call-log row when `call` is present (mark source "ROVEXY", channel, talk time, caller number).
* **Recording:** if `call.recording_url` is present, queue a job that downloads it with header `X-Api-Key: <ROVEXY secret>` (404 = not ready yet → retry every 10 minutes for 24 h; 410 = no recording → stop), stores it where SmartDCM stores recordings, and links it to the call-log row. Do not hot-link ROVEXY's URL in the browser (it needs the secret).
* For `channel = webrtc` the call was placed through SmartDCM itself (§8): link the disposition to that CDR (same `call.id` = `ref`) instead of creating a second call-log row; SmartDCM already has the recording.
* Update the case's last disposition / next follow-up / PTP fields exactly as SmartDCM does for its own feedback, and touch `updated_at`.

### 6.2 `POST /visits` — GPS check-in at the customer

```json
{"items": [{"external_id": "rovexy-visit-812", "case_id": "9001", "user_id": "102", "employee_code": "FOS9",
  "at": "2026-11-23T12:10:00+05:30", "lat": 17.4405123, "lng": 78.3504456, "distance_m": 18, "accuracy_m": 8,
  "notes": "Reached the customer (18 m from the pin, ±8 m GPS)."}]}
```

Save to the case's field-visit history (create a `rovexy_visits` table if SmartDCM has no field-visit table).

### 6.3 `POST /case-events` — remarks, status, re-allocation, payment review

```json
{"items": [
  {"external_id": "rovexy-ev-77", "type": "note", "case_id": "9001", "user_id": "101", "employee_code": "EMP1", "at": "…", "notes": "Customer shifted to Pune"},
  {"external_id": "rovexy-ev-78", "type": "status", "case_id": "9001", "status": "closed", "previous_status": "follow_up", "user_id": "101", "at": "…", "notes": null},
  {"external_id": "rovexy-alloc-91", "type": "allocation", "case_id": "9001", "assigned_user_id": "102", "assigned_employee_code": "FOS9", "team_id": "7", "by_user_id": "103", "at": "…"},
  {"external_id": "rovexy-alloc-92", "type": "withdrawn", "case_id": "9001", "by_user_id": "103", "at": "…"},
  {"external_id": "rovexy-review-5521-verified", "type": "payment_review", "case_id": "9001", "disposition_external_id": "rovexy-disp-5521", "review_status": "verified", "notes": "UTR matched", "at": "…"}
]}
```

* `note` → case remark. `status` → change the case status only if "Accept status changes from ROVEXY" is on (ROVEXY statuses: `new`, `in_progress`, `follow_up`, `closed`, `do_not_call` — map to SmartDCM's); always write it to the case history.
* `allocation` / `withdrawn` → apply only if "Accept allocations made in ROVEXY" is on (find the user by `assigned_user_id`, else by `assigned_employee_code`); always write to the history. **Do not send a webhook back to ROVEXY for a change that came from ROVEXY** (prevents ping-pong; ROVEXY ignores echoes anyway).
* `payment_review` → set the payment created from `disposition_external_id` to verified/rejected.

---

## 7. Webhook SmartDCM → ROVEXY ("something changed")

Send when, for a tenant with the integration on and a webhook URL set:

| Event | When |
|---|---|
| `case.changed` | cases uploaded, allocated, re-allocated, withdrawn, edited (one event per batch/upload, not per case) |
| `portfolio.changed` | portfolio created/renamed/closed |
| `user.changed` | user created/edited/deactivated, team changes |
| `telephony.changed` | voucher added, numbers/teams changed, Smart31 changed |
| `ping` | the "Test ROVEXY" button |

Request (queued job, timeout 10 s, 3 retries):

```
POST <ROVEXY webhook URL>
Content-Type: application/json
X-Rovexy-Timestamp: 1795412345              (unix seconds, now)
X-Rovexy-Signature: <hex HMAC-SHA256 of "<timestamp>.<raw body>" with the ROVEXY secret>

{"event": "case.changed", "crm_id": "1133", "ids": [9001, 9002], "at": "2026-11-23T12:00:00+05:30"}
```

PHP:

```php
$body = json_encode($payload);
$ts   = (string) time();
$sig  = hash_hmac('sha256', $ts . '.' . $body, $rovexySecret);
// Guzzle / Http: send $body as the raw body with the two headers.
```

ROVEXY answers **202**; it then reads the lists (§5) itself — the body is only a hint, so never put secrets or personal data in it beyond ids. 401 = wrong secret/clock (server clock must be within 5 minutes: run NTP). Debounce: at most one webhook per tenant per 20 seconds is enough.

---

## 8. WebRTC calls placed by ROVEXY through SmartDCM — NO LONGER USED (27 Nov 2026: ROVEXY offers Click2Call and Smart31 only; do not build this section)

ROVEXY telecallers press **"In browser"** on a customer. Their browser runs JsSIP and talks to **SmartDCM's existing Telozar WSS** (`webrtc.wss_url` from §5.7). Telecallers must never see the customer's full number, so the browser dials a **one-time code**, not the number.

### 8.1 `POST /webrtc/calls`

Request from ROVEXY:

```json
{"ref": "6f1c2d3e-…-uuid", "user_id": "101", "employee_code": "EMP1",
 "customer_number": "+919876543210", "caller_id": "8040002222", "case_id": "9001"}
```

SmartDCM must:
1. Check the user belongs to the tenant, has WebRTC/C2C rights, and the tenant's WebRTC is on (else 403 with a message — ROVEXY shows it).
2. Check `caller_id` is one of the tenant's own active numbers (else use the tenant's default WebRTC number).
3. Apply SmartDCM's own calling rules if any (DNC list, calling hours, per-number daily limit) — refuse with 422 and a clear `message`.
4. Create a row in `rovexy_webrtc_calls`: `ref` (unique), `crm_id`, `user_id`, `case_id`, `customer_number` (encrypted), `caller_id`, `dial_code`, `code_expires_at` (now + 120 s), `state = 'created'`.
5. `dial_code`: a random 7–8 digit code unique among unexpired codes, **single use**.
6. Answer:

```json
{"dial": "70012345",
 "sip": {"wss_url": "wss://sip.telozar.com:7443", "domain": "sip.telozar.com",
         "username": "1133_101", "password": "<this user's SIP password>", "display_name": "Ravi Teja",
         "ice_servers": [{"urls": "stun:stun.l.google.com:19302"}]},
 "expires_at": "2026-11-23T11:42:10+05:30"}
```

   Use the same SIP extension SmartDCM's own WebRTC dialer gives this user (create one if the user has none). If possible issue a short-lived password/token instead of the permanent one.

### 8.2 FreeSWITCH / Telozar dial plan

* An INVITE from extension `1133_101` to `70012345` (header `X-Rovexy-Ref: <ref>` is also sent):
  1. Look up `rovexy_webrtc_calls` by `dial_code`, not expired, not used, **and the calling extension belongs to the same user** — else reject (404/403).
  2. Mark the code used; `state = 'ringing'`.
  3. Set the outbound caller id to `caller_id`, start recording with the file name/uuid = `ref`, bridge to `customer_number` through the tenant's gateway — the same path SmartDCM's dialer uses.
  4. On answer: `state = 'answered'`, `answered_at`. On hang-up: `state = 'completed'` (talk > 0) or `no_answer` / `busy` / `failed`, `talk_seconds`, `ring_seconds`, `ended_at`, `cause`, recording path.
* Bill and log the call in SmartDCM's CDR/C2C logs like any SmartDCM call, with source "ROVEXY" and the `ref`.

### 8.3 `GET /webrtc/calls/{ref}`

```json
{"ref": "6f1c…", "state": "completed", "talk_seconds": 95, "ring_seconds": 7,
 "started_at": "2026-11-23T11:40:20+05:30", "ended_at": "2026-11-23T11:42:02+05:30", "cause": "NORMAL_CLEARING",
 "recording_url": "https://abc.smartdcm.app/api/rovexy/v1/webrtc/calls/6f1c…/recording"}
```

`state`: `created`, `ringing`, `answered`, `completed`, `no_answer`, `busy`, `failed`, `cancelled`. `recording_url` only when the file is ready; it must be on the **same host** as the API and served with the **same `X-Api-Key` check** (ROVEXY refuses recording links on any other host).

### 8.4 `GET /webrtc/calls/{ref}/recording` and `POST /webrtc/calls/{ref}/hangup`

* `recording`: streams the audio (mp3/wav) with `Content-Type`, after the API-key check and tenant check.
* `hangup` (`{"cause": "NORMAL_CLEARING"}`): best effort — kill the channel if still up; answer 200 even if already ended.

---

## 9. Database (new tables only — per tenant DB or with `crm_id`, following SmartDCM's tenancy)

```sql
CREATE TABLE rovexy_api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crm_id VARCHAR(20) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,          -- sha256(key)
  key_hint VARCHAR(8) NOT NULL,               -- last 4 characters, for the list
  created_by BIGINT UNSIGNED NULL,
  last_used_at TIMESTAMP NULL, revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (crm_id)
);

CREATE TABLE rovexy_settings (
  crm_id VARCHAR(20) PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  webhook_url VARCHAR(255) NULL, recording_url VARCHAR(255) NULL,
  rovexy_secret TEXT NULL,                    -- encrypt()
  share_passwords TINYINT(1) NOT NULL DEFAULT 0,
  accept_allocations TINYINT(1) NOT NULL DEFAULT 1,
  accept_status TINYINT(1) NOT NULL DEFAULT 1,
  code_map JSON NULL,                         -- {"PTP": "<SmartDCM disposition id>", ...}
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE rovexy_inbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crm_id VARCHAR(20) NOT NULL,
  kind VARCHAR(20) NOT NULL,                  -- disposition | visit | event
  external_id VARCHAR(100) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'applied',   -- applied | rejected
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY (crm_id, external_id)
);

CREATE TABLE rovexy_webrtc_calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crm_id VARCHAR(20) NOT NULL,
  ref CHAR(36) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL, case_id BIGINT UNSIGNED NULL,
  customer_number TEXT NOT NULL,              -- encrypt()
  caller_id VARCHAR(20) NULL,
  dial_code VARCHAR(10) NOT NULL, code_expires_at TIMESTAMP NOT NULL, code_used_at TIMESTAMP NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'created',
  answered_at TIMESTAMP NULL, ended_at TIMESTAMP NULL,
  talk_seconds INT UNSIGNED NULL, ring_seconds INT UNSIGNED NULL, cause VARCHAR(48) NULL,
  recording_path VARCHAR(255) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (dial_code, code_expires_at)
);

CREATE TABLE rovexy_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crm_id VARCHAR(20) NOT NULL, direction VARCHAR(3) NOT NULL,   -- in | out
  endpoint VARCHAR(120) NOT NULL, http_status SMALLINT NULL, items INT NULL, error VARCHAR(255) NULL,
  created_at TIMESTAMP NULL, INDEX (crm_id, created_at)
);
```

Plus, if missing: index `(updated_at, id)` on the cases, users and portfolios tables; `updated_at` touched on allocation/withdrawal/deactivation.

---

## 10. Suggested code layout (Laravel 6.5)

```
app/Http/Middleware/RovexyApiKey.php
app/Http/Controllers/Rovexy/ApiController.php        (ping, teams, users, portfolios, cases, trail, telephony)
app/Http/Controllers/Rovexy/InboxController.php      (dispositions, visits, case-events)
app/Http/Controllers/Rovexy/WebrtcController.php     (create, show, recording, hangup)
app/Http/Controllers/Rovexy/SettingsController.php   (admin screen, keys, test)
app/Services/Rovexy/CursorPager.php                  (the §5 paging, shared by the three lists)
app/Services/Rovexy/ResultApplier.php                (one method per kind; uses the same code as SmartDCM's own feedback)
app/Jobs/Rovexy/SendWebhook.php                      (§7, queued, retries)
app/Jobs/Rovexy/FetchRecording.php                   (§6.1 recording download)
app/Observers/... or event listeners                 (fire SendWebhook on case/user/portfolio/telephony changes)
routes/api.php:  Route::prefix('rovexy/v1')->middleware(['rovexy.key', 'throttle:120,1'])->group(...)
database/migrations/2026_11_23_xxxxxx_create_rovexy_tables.php
resources/views/settings/rovexy.blade.php            (or the existing admin UI framework)
```

---

## 11. Test before going live (with one test CRM ID)

1. Admin creates a key, enters ROVEXY's webhook URL + secret, presses **Test ROVEXY** → 202.
2. In ROVEXY → Administration → SmartDCM: paste the SmartDCM address and key → **Save & connect** → "Connected to <company>" and a first sync: teams, users, portfolios, cases, CRM ID, numbers appear.
3. Curl checks (replace values):
   ```bash
   curl -s https://abc.smartdcm.app/api/rovexy/v1/ping -H "X-Api-Key: KEY"
   curl -s "https://abc.smartdcm.app/api/rovexy/v1/cases?limit=2" -H "X-Api-Key: KEY"
   curl -s "https://abc.smartdcm.app/api/rovexy/v1/cases?limit=2" -H "X-Api-Key: KEY-OF-ANOTHER-CRM"   # must NOT show the first CRM's cases
   ```
4. Allocate 5 cases to an FOS in SmartDCM → within seconds (webhook) they appear on the FOS's ROVEXY phone.
5. FOS records a PTP and a payment in ROVEXY → within a minute both appear on the SmartDCM case (PTP row, payment *pending verification*), with source ROVEXY.
6. Telecaller makes a Click2Call from ROVEXY → call shows in SmartDCM's C2C log under the CRM ID; the outcome arrives as a disposition; the recording is downloaded into SmartDCM.
7. Telecaller presses **In browser** in ROVEXY → hears ringing, talks, hangs up → SmartDCM CDR has the call with the `ref`; ROVEXY shows talk time and plays the recording.
8. Same disposition posted twice (same `external_id`) → only one row in SmartDCM.
9. Deactivate a user in SmartDCM → next sync suspends them in ROVEXY.
10. Turn the integration off → API answers 403, no webhooks are sent, SmartDCM works as before.

## 12. Acceptance criteria

* No existing SmartDCM screen, API, dialer or report changes for tenants without ROVEXY.
* A key reads/writes only its own CRM ID's data (tested with two tenants).
* Lists page correctly with no row missed or repeated when many rows share the same `updated_at`.
* Every posted item is idempotent by `external_id`.
* Webhooks are signed, queued, debounced and never slow down SmartDCM.
* Secrets are hashed/encrypted, never logged; `APP_DEBUG=false`.
