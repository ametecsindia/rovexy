# API reference (v1)

All endpoints are HTTPS JSON. Times are UTC ISO-8601; company time zones are used only for display and for calling hours. Errors look like `{"error": "<code>", "message": "<text>"}`:

| HTTP status | When |
|---|---|
| 401 | Unauthenticated, or a bad or stale signature |
| 403 | `device_not_approved`, `device_revoked`, `not_assigned`, `forbidden`, `device_not_compliant`, `mfa_required` |
| 404 | `company_unresolved` / `company_conflict` |
| 422 | Validation or domain rule |

**Company resolution happens on every request.** It uses, in order:

1. Fixed-company mode
2. A verified custom domain
3. A workspace slug (`X-Workspace` header or `{slug}.<base domain>`)

A conflict or an unresolved company returns 404. There is never a fallback.

## Authentication layers (mobile)

| Layer | How | Used for |
|---|---|---|
| Public | Company resolution only | `bootstrap`, `branding/logo`, `devices/register` (rate-limited) |
| Device signature | Headers `X-Installation-Id`, `X-Timestamp`, `X-Nonce` (16–64 `[A-Za-z0-9_-]`) and `X-Signature` = base64 DER ECDSA-P256-SHA256 over `METHOD\nPATH?QUERY\nTIMESTAMP\nNONCE\nhex(sha256(body))`. Accepted within ±300 s; each nonce is accepted only once. | `device/status`, `device/session`, `auth/refresh` |
| Device session | `Authorization: Bearer dst_…` (15 min) | `auth/login`, `policy`, `policy/ack`, `device/push-token` |
| Work session | `Authorization: Bearer wst_…` (15 min access, 12 h refresh via signed `auth/refresh`). Every request re-checks company, device approval, credential epoch, employee status, device↔employee assignment and permission. Some routes also require compliance. | Everything else |

Responses to work-session requests carry `X-Offline-Lease-Expires`. Tokens are never put in URLs.

## Mobile — `/api/mobile/v1`

| Method & path | Auth | Purpose |
|---|---|---|
| GET `bootstrap` | public | API version, product name, company slug/name, public branding (logo URL + SHA-256 or null) |
| GET `branding/logo` | public | Approved logo bytes |
| POST `devices/register` | public | `{installation_id (uuid v4), public_key (b64 SPKI P-256), manufacturer, model, os_version, sdk_int, app_version, amapi_device_name?}` → `{state, approval_code, poll_after_seconds}`. Idempotent per installation and key. A different key returns `installation_conflict`. |
| GET `device/status` | signed (the latest key, even if revoked) | `{state: pending\|approved\|rejected\|revoked, approval_code?, poll_after_seconds?}` |
| POST `device/session` | signed | `{device_token, expires_in}`. Approved devices only. |
| POST `auth/login` | device | `{employee_code, password}` → `{access_token, refresh_token, expires_in, offline_lease_expires_at, employee}` |
| POST `auth/refresh` | signed | `{refresh_token}` → rotated tokens |
| POST `auth/logout` | work | Ends the work session only |
| GET `me` | work | Employee, device compliance, lease, `realtime {ws_url, channel}`, monitoring disclosure |
| GET `policy` | device | `{fingerprint, values, controls{key:{scope, mandatory, status, reason}}, management_mode}` |
| POST `policy/ack` | device | `{fingerprint, results{key:{status, verified, detail}}}` → `{state, compliant}` |
| PUT `device/push-token` | device | `{token, provider:"fcm"}` |
| GET `customers?since=` | work + compliant | `{server_time, visible_ids[], items[]}`. Delete any cached customer that is not in `visible_ids`. |
| GET `dispositions` | work + compliant | Active disposition codes |
| POST `calls` | work + compliant + `calls.make` | `{customer_id, which: primary\|alternate, number?}` → `{call_id, state, …}` |
| GET `calls/{uuid}` · POST `calls/{uuid}/hangup` · POST `calls/{uuid}/disposition` | work + compliant | Own calls only |
| POST `sync` | work | `{events:[{id (uuid), type: disposition\|note\|follow_up\|activity\|location\|chat_read, occurred_at, payload}]}` → per-event `accepted`/`duplicate`/`rejected` (with reason, e.g. `event_after_lease_expiry`) |
| GET `conversations` · GET/POST `conversations/{id}/messages` · GET `…/messages/{mid}/attachment` | work + `chat.use` | Server timestamps; delivery/read receipts; offline resend by `client_message_id` |
| GET `media/pending` · POST `media/{uuid}/accept\|decline\|start\|end\|signal` · GET `media/{uuid}/ice` | work | Employee side of screen/camera sessions |

## Admin — `/api/admin` (cookie session + CSRF, MFA required, permission-gated)

Before signing in, the console fetches `GET /sanctum/csrf-cookie` and then sends `X-XSRF-TOKEN` on every mutation. Authentication:

| Endpoint | Purpose |
|---|---|
| `POST auth/login` | Email and password |
| `POST auth/mfa/setup` | Starts TOTP enrolment |
| `POST auth/mfa/verify` | Verifies the TOTP code |
| `GET auth/me` | Current user |
| `POST auth/logout` | Ends the session |

| Group | Endpoints (permission) |
|---|---|
| Overview | GET `overview` |
| People | GET/POST `employees` (employees.view/manage), PATCH `employees/{id}`, GET/POST `teams`, PUT `teams/{id}/members`, GET `roles` |
| Devices | GET `devices`, GET `devices/{id}` (devices.view), POST `devices/{id}/approve\|reject\|reassign` (devices.approve), POST `devices/{id}/revoke` (devices.revoke), POST `devices/{id}/wipe` (devices.wipe), POST `enrolment-tokens` |
| Policies | GET `policy/catalog`, GET/POST `policies`, POST `policies/{id}/versions`, POST `policies/{id}/versions/{v}/publish` `{accept_warnings}`, POST `policies/{id}/rollback`, PUT `policy-assignments`, GET `devices/{id}/effective-policy` |
| Customers | GET `imports/template`, GET/POST `imports`, POST `imports/{id}/preview\|validate\|commit`, GET `imports/{id}`, GET `imports/{id}/errors.csv`, GET `customers`, POST `customers/assign\|withdraw` |
| Calls | GET `calls`, GET `calls/{id}`, POST `calls/{id}/recording/playback` (recordings.play), POST `…/download` (recordings.download), POST `…/retry`, GET `follow-ups`, GET/PUT `disposition-codes` |
| Supervision | GET `presence`, GET `locations` (location.view, audited), GET/POST `media-sessions`, POST `media-sessions/{uuid}/end\|signal\|screenshots`, GET `…/ice`, GET/POST `conversations`, GET/POST `conversations/{id}/messages` |
| Settings | GET/POST `branding`, GET `storage/estimate`, GET/POST `retention-jobs`, POST `retention-jobs/{id}/approve` (a different admin must approve), GET `audit`, GET `health`, GET `reports/calls.csv` |

Supervisors see only their teams' employees, devices, customers, calls and sessions. Company-wide roles hold `scope.all_teams`.

## Other

- `GET /api/admin/recordings/{id}/stream?expires&signature`: short-lived signed stream (local storage). S3 uses provider pre-signed URLs.
- `POST /api/broadcasting/auth`: WebSocket channel authorisation. Accepts an admin session or a mobile work-session bearer.
- `POST /api/telephony/v1/events`: HMAC webhook (see TELEPHONY.md).
- `POST /api/amapi/v1/notifications`: Pub/Sub push (OIDC-verified).
- `GET /up`: health check.
