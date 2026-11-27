# ROVEXY ⇄ SmartRMI engine, and Telozar "listen in" — integration contract (27 Nov 2026)

ROVEXY now has a **Call monitoring** section: *Live calls (listen in)*, *SmartRMI overview*, *Analysed calls*,
*Angry-call alerts* and *Monitoring settings*. The screens, data and alerts are built. Two outside pieces plug in:

1. **SmartRMI engine** (the same engine that sends `rmi_status` / `rmi_results` and the angry-call alert to SmartDCM).
2. **Telozar conference API** (adds a supervisor's phone to a live Click2Call call — SmartDCM did this with Vi CPaaS
   `clicktocall/callConference`).

---

## 1. SmartRMI engine ⇄ ROVEXY

Each ROVEXY workspace gets its own address and key: **Call monitoring → Monitoring settings → Connect the engine**.
The page shows four addresses like `https://rovexy.app/api/integrations/smartrmi/{uuid}/…` and a key (shown once).

Every request sends the key in the header **`X-Api-Key`**. Wrong key → 401. Unknown address → 404. Workspace without
SmartRMI in its licence → 403.

### 1.1 Calls to analyse — `GET {base}/calls?after_id=0&limit=100`
Recorded calls (SIM and Click2Call), oldest first. Keep `next_after_id` and ask again with it.

```json
{
  "data": [{
    "id": 26899, "call_uuid": "9b0c…", "provider": "sim", "started_at": "2026-11-27T05:13:26Z", "talk_seconds": 9,
    "agent": {"id": 291, "name": "akshitha", "employee_code": "TC0004"},
    "customer_ref": "RAR31879821", "b_party_masked": "+91••••••6637", "outcome": "CONNECTED",
    "recording_url": "https://rovexy.app/api/integrations/smartrmi/{uuid}/recordings/9b0c…",
    "recording_format": "mp3", "analysed": false
  }],
  "next_after_id": 26899
}
```

### 1.2 Recording — `GET {base}/recordings/{call_uuid}`
The audio file (same `X-Api-Key`). 404 = not ready yet, 410 = failed.

### 1.3 Results — `POST {base}/results`
Identify the call with **`call_uuid`** (from 1.1) **or** the Click2Call **`ref_id`** the gateway already knows
(`{call_uuid}_d_{crm_id}_{user_id}_1`). Results may be flat or nested under `rmi`, exactly as SmartDCM's `s.py` accepts:

```json
{ "call_uuid": "9b0c…", "rmi_status": "completed", "rmi_results": { … } }
{ "ref_id": "9b0c…_d_1133_291_1", "rmi": { "status": "completed", "results": { … } } }
```

ROVEXY stores `rmi_results` exactly as sent and reads these fields from it (top level or one level down; first name found wins):

| ROVEXY field | Names it looks for in `rmi_results` |
|---|---|
| Score (0–100; 0–1 is scaled ×100) | `score`, `overall_score`, `total_score`, `quality_score`, `call_score`, `rmi_score` |
| Agent tone | `tone`, `agent_tone`, `call_tone` |
| Customer sentiment | `sentiment`, `customer_sentiment`, `overall_sentiment` |
| Aggression | `aggression`, `aggression_level`, `aggressiveness` |
| Compliance risk | `compliance_risk`, `compliance`, `risk`, `risk_level`, `compliance_level` |
| Angry call (true/false) | `angry`, `is_angry`, `angry_call`, `red_alert` — if missing: aggression = high/severe |
| Summary | `summary`, `call_summary`, `overview` |
| Transcript | `transcript`, `transcription`, `conversation`, `segments` (text, or a list of `{speaker, text}`) |
| Coaching points | `coaching`, `coaching_points`, `coaching_insights`, `suggestions`, `recommendations`, `improvements` |

Answer: `{"status":"stored","call_uuid":"…","score":62,"angry":true}`. Sending again for the same call replaces it.
**A result marked angry raises an angry-call alert by itself** (once per call).

### 1.4 Angry-call alert — `POST {base}/alerts`
Same body as SmartDCM's `/api/angry-call-alert`, plus the call (`call_uuid` or `ref_id`):

```json
{ "call_uuid": "9b0c…", "kind": "angry_call", "severity": "high", "message": "Agent raised voice and threatened legal action",
  "a_start_time": "2026-11-27 10:41:00", "b_party_no": "9876543210", "duration": 42 }
```

ROVEXY keeps the alert (Call monitoring → Angry-call alerts, where a supervisor acknowledges it) and e-mails
**"RED ALERT: Angry call detected"** with the recording attached (up to 10 MB) to the agent's team supervisors
(SmartDCM: the agent's `reporting_to` managers), plus any extra addresses set in Monitoring settings.
Only one alert per call and kind. Answer: `201 {"status":"alert_queued","alert_id":…,"emailed_to":2}`.

---

## 2. Telozar — listen in to a live Click2Call call

**What ROVEXY sends** (Super Admin → Settings → Click2Call → *Conference API URL*), `POST`, JSON, header `Secret-Key`
= the OBD gateway Secret-Key already used for Click2Call:

```json
{ "cli": "918040001111", "did": "918040001111", "call_id": "<gateway call id or our ref>", "ref_id": "9b0c…_d_1133_291_1",
  "cparty_number": "9800000011", "listener_number": "9800000011", "mode": "listen" }
```

- `cli` / `did` = the company number the call went out on; `call_id` = what Telozar's `c2c_call` answered as the call id
  (if it answers none, ROVEXY's `ref_id`); `cparty_number` = the supervisor's phone (10 digits) to ring and add **muted**.
- Success = HTTP 2xx without `"status":"error"` / `"error"`. Anything else is shown to the supervisor as the gateway's message.

**Questions for the Telozar team** (Praveen's replacement):
1. The URL of the conference / barge / listen API, and its exact fields (the above matches Vi's `callConference`; rename as needed).
2. Which call id it needs — and **please return it in the `c2c_call` answer** (`call_id`), so ROVEXY can store it.
3. Can the listener join **muted** (listen only), and optionally **whisper** (agent hears) or **barge** (both hear)?
4. Auth: the same `Secret-Key` as the OBD gateway, or another key?

Until the URL is saved, *Live calls* lists the calls but the **Listen in** button stays off.

**SIM calls cannot be joined**: Android gives no app the audio of a phone call in progress. Only Click2Call
calls (placed by the gateway) can be monitored live. SIM calls still get SmartRMI analysis after the call, from the recording.
