# Telephony integration (FreeSWITCH / existing Ametecs telephony)

**Rule for initial development:** do not modify production telephony. Point the platform at a **non-production FreeSWITCH** (or a dedicated profile/gateway), then validate there first.

## 1. How a call works

```
Phone app ──POST /api/mobile/v1/calls {customer_id, which}──► Server
  Server: device + employee + assignment + compliance + calling hours (company TZ) + prefix allowlist + one call at a time
  Server picks the destination FROM THE CUSTOMER RECORD and creates calls.uuid
  Server ──ESL: bgapi originate──► FreeSWITCH
     A-leg (agent): sofia/gateway/<gw>/<employee work number>     (pstn_callback)
                or user/dev_<deviceId>@<domain>                   (sip_endpoint — media client not yet built)
     on A answer → bridge B-leg: sofia/gateway/<gw>/<customer>
     B-leg execute_on_answer=record_session::ametecs/<company>/<yyyy>/<mm>/<call uuid>.wav
  FreeSWITCH ──ESL events──► `php artisan telephony:listen` ──► CallService::ingest (idempotent)
  Recording: RECORD_STOP / hangup → FetchRecording → private storage + SHA-256 → (ffmpeg MP3) → Available
```

- The client never supplies a route. A number sent by the client must exactly match the record, or the call is rejected (`destination_mismatch`). Manual dialling is off by default and is still checked against the prefix allowlist.
- No SIP or ESL credentials ever reach the phone in `pstn_callback` mode.
- All values put into ESL commands go through strict character-class checks (`OriginateCommand`). Commas, braces, spaces, `..` and newlines are refused.

## 2. FreeSWITCH requirements (read-only use of existing configuration)

| Item | Requirement |
|---|---|
| `mod_event_socket` | Listen on a private address. ACL limited to the app/worker servers. Use a strong password (`FREESWITCH_ESL_PASSWORD`). Never expose it publicly. |
| Gateway | An existing outbound gateway name (`FREESWITCH_GATEWAY`) able to reach both the employee work numbers and customer numbers. |
| Caller ID | `FREESWITCH_CALLER_ID`: a number the company is permitted to present. |
| Recordings | `record_session` with a **relative** path writes under `$${recordings_dir}`. Mount that directory read-only on the app servers (`FREESWITCH_RECORDING_SOURCE=local`, `FREESWITCH_RECORDINGS_BASE`). Or expose it through an HMAC-protected HTTP endpoint on the FreeSWITCH host (`http`: GET `<base>/<path>?exp=<unix>&sig=hex(HMAC_SHA256(secret, path|exp))`). |
| Recording notice | Configure an announcement on the B-leg if the company requires one: e.g. `execute_on_answer` → `playback` before `record_session`, or a dialplan on the gateway profile. The app shows the policy text to employees. **This is not legal advice.** |
| Version check | Confirm on your build that the A-leg `CHANNEL_HANGUP_COMPLETE` carries `variable_originate_disposition` / `variable_last_bridge_hangup_cause` for B-leg outcomes (busy, no answer, rejected). The mapping is in `EslEventMapper`. |

## 3. Run the listener

```ini
# /etc/supervisor/conf.d/ammp-telephony.conf
[program:ammp-telephony]
command=php /srv/ammp/backend/artisan telephony:listen
user=www-data
autorestart=true
stopwaitsecs=10
```

The listener reconnects with exponential backoff. Duplicate ESL events are no-ops because of the unique `(call_id, provider_event_id)` constraint.

## 4. Alternative: HTTP events from an existing Ametecs telephony system

`POST /api/telephony/v1/events` with headers:

- `X-Telephony-Timestamp`: unix seconds, ±300 s
- `X-Telephony-Signature`: hex `HMAC_SHA256(TELEPHONY_WEBHOOK_SECRET, timestamp + "." + rawBody)`

Body:

```json
{"call_id":"<uuid we sent>","company_id":4,"provider_event_id":"<unique per event>","type":"customer_answered",
 "at":"2026-10-01T06:00:10Z","cause":null,"provider_call_id":"<their id>"}
```

Types are `agent_ringing`, `agent_answered`, `customer_ringing`, `customer_answered`, `customer_hangup`, `hangup`, `failed`, `recording_started` and `recording_stopped`. Originating calls through such a system requires a new `TelephonyAdapter` implementation (one class: `originate`, `hangup`, `health`).

## 5. Outcomes and timing

| Outcome | Meaning |
|---|---|
| `agent_unanswered` | The employee's phone never answered. The customer was not dialled. |
| `completed` | Answered, normal clearing |
| `disconnected` | Answered, then dropped abnormally (e.g. `MEDIA_TIMEOUT`) |
| `busy` / `no_answer` / `rejected` | Customer-side result before answer |
| `cancelled` | Ended by the agent before the customer answered |
| `failed` | Network or number failure, or telephony unavailable (`failure_reason=telephony_unavailable`) |

Timings are measured as follows:

- `ring_seconds`: customer ringing until answer, or until the end.
- `talk_seconds`: answer until the end.
- `total_seconds`: initiation until the end.

Recording status is tracked separately: `not_required`, `pending`, `recording`, `processing`, `available`, `failed` or `deleted_by_retention`.

## 6. Mandatory recording failure

With `recording.mode=mandatory`, if `RECORD_START` has not arrived 10 s after the customer answers, the watchdog marks the recording **Failed**. It then alerts the console and the phone, and follows `recording.on_failure`:

- `continue_flagged` (default): the call continues, marked as failed.
- `terminate`: `uuid_kill`.

Missing files are retried for up to 5 attempts. Reconciliation runs every 10 min, and admins can retry manually.

## 7. Development simulator

`TELEPHONY_DRIVER=simulator` is allowed only with `APP_ENV=local` or `testing`; the app refuses to boot with it in production. It drives scripted events through the real ingestion path, and the destination's last 4 digits pick the scenario:

- `0001`: busy
- `0002`: no answer
- `0003`: employee did not answer
- anything else: answered for about 20 s

There is no audio and no recording file, so required recordings end as **Failed** by design.

## 8. Validation checklist (non-production FreeSWITCH)

- [ ] ESL health is green in **System health**.
- [ ] Answered call: timings are plausible, and the recording becomes Available with a playable two-sided MP3 and a matching SHA-256.
- [ ] Busy, no answer, rejected, invalid number and employee not answering each map correctly.
- [ ] A duplicate ESL event delivered twice (restart the listener mid-call) leaves exactly one call and correct state.
- [ ] Recording directory unavailable: the recording ends Failed after retries, and recovers after a fix through **Retry fetch**.
- [ ] Mandatory recording + `terminate`: the call is cut when recording cannot start.
- [ ] Revoking a device mid-call terminates it (or lets it finish, per policy).


## Exotel click-to-call (cloud telephony, no FreeSWITCH needed)

`CALLING_ROUTE=server`, `TELEPHONY_DRIVER=exotel`, plus `EXOTEL_SID`, `EXOTEL_API_KEY`, `EXOTEL_API_TOKEN`,
`EXOTEL_CALLER_ID` (your ExoPhone) and optionally `EXOTEL_SUBDOMAIN` (`api.in.exotel.com` Mumbai, default; `api.exotel.com` Singapore).

1. Employee taps Call → server authorises → `POST /v1/Accounts/{sid}/Calls/connect.json` with `From` = employee work number,
   `To` = customer, `CallerId` = ExoPhone, `Record=true`, `RecordingChannels=dual`, `StatusCallback` = a signed per-call URL.
2. Exotel rings the employee, then the customer. The customer sees the ExoPhone.
3. Result: read from `GET /Calls/{CallSid}.json?details=true` (`Status`, `Details.ConversationDuration`, `Leg1Status`,
   `Leg2Status`, `RecordingUrl`). The callback body is never trusted; it only triggers a read. Reads also happen when the
   phone polls the call, when an admin opens Calls, and every minute from the scheduler — so it works on a LAN PC that
   Exotel cannot call back.
4. Recording: downloaded from Exotel with the API credentials (only from Exotel hosts), SHA-256, stored privately as MP3.

Prerequisites on the Exotel side: KYC-complete account, an ExoPhone, recording enabled, and employees' work numbers in
E.164 under Employees. Each employee's number must be able to receive calls from the ExoPhone.
