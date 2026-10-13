# Ametecs Managed Mobile — Architecture

"Ametecs Managed Mobile" is a configurable working name (`APP_PRODUCT_NAME`). No final product name or logo is assumed. The UI shows a neutral placeholder until an official logo is uploaded through Branding.

## 1. Starting point

- **Repository:** the session workspace was empty. No existing Ametecs repository, Capacitor shell or telephony code was provided, so this is a new project using the stack from the brief.
- **Build environment blocker (2026-10-01):** the cloud build environment's egress policy blocks Packagist, GitHub, Google Maven, Maven Central and Gradle. npm, PyPI and Ubuntu apt work. Consequences:
  - The Laravel app cannot run `composer install` here. Its **framework-independent domain core** (`backend/src/Domain`) is unit-tested here with PHPUnit 9.6 from apt. The Laravel layer is syntax-checked (`php -l`) only. Its feature tests are written but **not executed**.
  - The Android app cannot be compiled (no Android Gradle Plugin or SDK). Its pure-Kotlin logic in `android/core` is compiled and tested here with the apt Kotlin 1.3 compiler and JUnit 4. The Android/Compose layer is **not compiled**.
  - The Electron binary cannot download, so the shell's TypeScript is compiled and its security configuration is tested, but no Windows installer was built.
  - The React admin builds and its tests run here.
- See `docs/STATUS.md` for the exact verification state of each part.

## 2. Components

```
                ┌───────────────────────── Company server (cloud or on-prem) ──────────────────────────┐
 Windows admin  │  Laravel 13 (PHP 8.3+)                                                               │
 Electron shell ─┼─► /admin  (React SPA, cookie session + CSRF + TOTP MFA)                              │
 (loads server   │   /api/admin/*   (Sanctum stateful, permission-gated, company-scoped)               │
  origin only)   │   /api/mobile/*  (device-signed bootstrap + bearer work sessions)                   │
                │   /api/telephony/* (HMAC-signed webhooks)    /api/amapi/pubsub (OIDC-verified)       │
 Android app ───┼─► Reverb WebSockets (private company/device channels)                               │
 (Kotlin/Compose)│   Queue workers (Redis): imports, recording fetch/transcode, retention, push, AMAPI  │
                │   MySQL 8 · Redis · private object storage (local disk or S3-compatible)              │
                └──────┬───────────────────────┬─────────────────────────┬─────────────────────────────┘
                       │ ESL (TCP, ACL'd)       │ HTTPS                   │ HTTPS
                 FreeSWITCH / Ametecs     Android Management API     FCM HTTP v1
                 telephony (existing)     (Google, commercial EMM)   (notify only)
                       │
                 coturn (TURN, REST-API ephemeral credentials) for WebRTC screen/camera sessions
```

The server works without any desktop app open. The desktop app is a thin shell around the server's `/admin` origin.

## 3. Key decisions

| Area | Decision | Why |
|---|---|---|
| Backend | Laravel 13, PHP 8.3–8.5 (code avoids 13-only APIs, so it also runs on 12) | Laravel 13 released 17 Mar 2026. Laravel 12 bug-fix support ended 13 Aug 2026. Matches the Ametecs Laravel skill set. |
| Domain core | Plain PHP in `src/Domain`, no framework imports | Security-critical rules can be tested without the framework. This is also what allowed testing here. |
| Live events | Laravel Reverb (Pusher protocol) | First-party, self-hostable, so it works on-prem. |
| Admin auth | Sanctum SPA cookie session + `X-XSRF-TOKEN`, TOTP MFA (RFC 6238), 12 h absolute / 30 min idle expiry | No tokens in browser storage. CSRF is protected by Laravel. |
| Desktop | Electron loads the configured server origin only. `sandbox`, `contextIsolation`, no `nodeIntegration`, navigation locked, permission requests denied, downloads restricted to a chosen export folder | One UI build, always matching the server version. |
| Device management | **Android Management API (AMAPI)** as the production adapter, fully managed company-owned devices enrolled with a **factory reset** using an AMAPI enrolment-token QR code (zero-touch optional). Also an `app_only` adapter that reports every device-level control as *Requires Additional Setup*. | Custom DPCs can no longer register new Play EMM integrations, and since 2025 Play Protect's DPC allowlist can block unapproved DPCs during provisioning. AMAPI is only for **commercial EMM offerings**, not internal-only tools: Ametecs qualifies only if this is sold to customer companies. The first 500 devices need a business justification; more needs Google approval. **Verify eligibility before production.** |
| Employee app | One Kotlin/Compose app for all companies, installed by AMAPI policy as a force-installed private app. It receives `server_url`, `company_slug` and an optional `enrolment_ref` through managed configuration. | The management layer (Android Device Policy) and the employee app are separate packages: AMAPI enforces device controls and the app enforces work-session controls. |
| Device identity | App generates an EC P-256 key pair in Android Keystore (non-exportable, StrongBox when available) and a random 128-bit installation ID. The server stores the public key. Every bootstrap/refresh request is ECDSA-signed over `METHOD\nPATH\nTS\nNONCE\nSHA256(body)` (±300 s skew, nonce replay cache). | Knowing a device ID gives nothing: there is no shared secret to leak or return. |
| Sessions | Device session token (15 min, opaque, SHA-256-hashed at rest) obtained by signature. Employee work session token (15 min access + 12 h refresh, refresh requires a device signature) bound to device + credential epoch + user. | Every request checks the DB state, so revocation is immediate for HTTP. |
| Calling | Server-originated calls through a `TelephonyAdapter`. FreeSWITCH ESL adapter with agent-leg modes `pstn_callback` (server rings the employee's company SIM, then bridges) or `sip_endpoint` (in-app VoIP; needs a media-library spike). A dev-only `simulator` adapter refuses to boot unless `APP_ENV=local`. | The destination is chosen by the server from an assigned customer record, so tampering with the client cannot dial other numbers. No telephony credentials reach the app in `pstn_callback` mode. |
| Recording | FreeSWITCH `record_session` on answer → the `FetchRecording` job copies it to private storage, computes SHA-256, then transcodes (ffmpeg, optional) → *Available*. A reconciliation job flags *Failed* recordings. | Call success never implies recording success. |
| Media sessions | WebRTC, signalling over REST + Reverb, TURN via coturn `use-auth-secret` ephemeral credentials. Android uses MediaProjection with per-session OS consent (Android 14+ requires consent every session) and a foreground service notification. | No hidden capture. |
| Push | FCM HTTP v1 data-only "sync" pings with no customer data. The app always pulls truth over HTTPS. | Push failure never blocks policy retrieval. |

## 4. Company isolation

- `companies` is the tenant root. Every tenant table has `company_id NOT NULL` + FK, and composite uniques include `company_id`.
- **Company resolution** (`Domain\Tenancy\CompanyResolver`), in order: fixed-company mode (`TENANCY_MODE=fixed`, on-prem) → verified custom domain → workspace slug (`X-Workspace` header or `{slug}.base-domain`). If the domain and slug point to different companies, or nothing resolves, the request is **rejected (404)**. There is never a fallback.
- The resolved company is bound to the request. Eloquent models use `BelongsToCompany`, a global scope that **throws** if a query runs without a bound company (outside explicitly-marked platform-admin code), so a missed scope fails closed.
- Storage keys are prefixed `companies/{company_id}/…`, and signed playback URLs are minted only after a scoped DB lookup.
- WebSocket channels: `private-company.{cid}.device.{did}`, `private-company.{cid}.admin`, `private-company.{cid}.conversation.{id}`. The channel authoriser compares `cid` with the authenticated principal.
- Jobs carry `company_id` and re-bind tenancy in `handle()`.
- Exports are generated per company, stored under that company's prefix, and run through formula-injection sanitising.

## 5. Device lifecycle

```
 (factory reset) → AMAPI QR enrolment → app force-installed with managed config
   → app: register (installation_id, public key, device info)  ──► managed_devices row: approval=Pending, code=ABCD-1234
   → admin: match code, assign employee/team/policy, Approve      ──► credential epoch++, device_approval_events
   → app: signed POST /device/session → device token
   → employee: User ID + password                                  ──► work session (device+user+epoch)
   → policy sync → apply → acknowledge (per-control results)      ──► compliant / non-compliant
   → dashboard (assigned customers)
 Revoke → epoch++, all tokens/sessions deleted, WS disconnect event, push ping, active call per policy (hangup|allow_finish)
 Re-approve after revoke → requires a NEW registration (new key pair). The old key stays revoked.
 Reinstall → new installation ID → appears as a new Pending request linked to the same asset ID (if the AMAPI device name matches) → explicit approval/reassignment.
 Employee logout → ends the work session only. Device policy, AMAPI restrictions and device approval are untouched.
```

Revocation propagation bounds (asserted in tests where executable):

- HTTP: the next request (0 s). Token lookups are not cached.
- WebSocket: an immediate server-pushed `session.revoked` + channel re-authorisation every 60 s → **≤ 60 s**.
- Media sessions: the server ends the session record and pushes `media.end` immediately. The TURN credential TTL is 10 min, but the peer is torn down by the app on the event or on the next 30 s heartbeat failure → **≤ 30 s** while online.
- Offline device: protected functions lock at the **offline lease** expiry (policy `offline_access_minutes`, default 240). Device restrictions persist because AMAPI keeps enforcing them.
- Active call on revocation: policy `on_revoke_active_call` = `terminate` (default; the server sends `uuid_kill`) or `allow_finish`.

## 6. Policy engine

Effective policy = merge(company default ← team override ← device override), key-by-key. A key set at a narrower scope wins. Each output key records its source scope (shown in the admin "Effective policy" view). Before publishing, `PolicyConflictDetector` blocks or warns about: camera blocked + camera sessions enabled; microphone blocked + calling enabled; screen capture disabled + live view enabled; kiosk without the employee app in the allowlist; recording mandatory + recording disabled; calling hours empty; and so on. Every control declares the scope it applies at (`device` via AMAPI, or `app` in the employee app). Capability is reported per device as Supported / Unsupported / RequiresSetup. Application states: Pending → Applying → Applied | PartiallyApplied | Failed. A device is **compliant only if every mandatory control is Applied and verified**. The last-known-good version is stored, and rollback creates a new version with that content (audited).

## 7. Out of scope / not claimed

- No root, accessibility-service abuse, hidden camera/microphone, or auto-accepting OS consent prompts.
- Direct SIM calling mode is not implemented: it is optional, and two-sided recording is not promised.
- No legal/regulatory compliance claims. Recording notices are configurable text and audio prompts only.
