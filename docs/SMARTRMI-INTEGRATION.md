# ROVEXY ⇄ SmartRMI engine — integration contract (27 Nov 2026)

ROVEXY has a **SmartRMI** section: *Overview*, *Analysed calls*, *Angry-call alerts* and *Settings*. The screens, data and
alerts are built; the **SmartRMI engine** (the same engine that sends `rmi_status` / `rmi_results` and the angry-call alert
to SmartDCM) plugs in through the four addresses below.

---

## 1. SmartRMI engine ⇄ ROVEXY

Each ROVEXY workspace gets its own address and key: **SmartRMI → Settings → Connect the engine**.
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

ROVEXY keeps the alert (SmartRMI → Angry-call alerts, where a supervisor acknowledges it) and e-mails
**"RED ALERT: Angry call detected"** with the recording attached (up to 10 MB) to the agent's team supervisors
(SmartDCM: the agent's `reporting_to` managers), plus any extra addresses set in SmartRMI → Settings.
Only one alert per call and kind. Answer: `201 {"status":"alert_queued","alert_id":…,"emailed_to":2}`.

SIM and Click2Call calls are both analysed after the call, from the recording.
