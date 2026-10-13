# Ametecs Managed Mobile (working name)

A platform for managing company-owned Android phones and placing customer calls, built for Ametecs India Private Limited.

- **Server:** Laravel 13 + MySQL + Redis + Reverb. It runs in the cloud (multi-company) or on-premises (fixed company).
- **Admin console:** React/TypeScript, served by the server at `/admin`. It is wrapped by a hardened **Windows Electron app**.
- **Employee app:** a single Kotlin/Jetpack Compose app for all companies. Device-level controls are enforced through the **Android Management API**.
- **Calling:** server-originated VoIP through a replaceable telephony adapter (FreeSWITCH ESL), with central recording storage.

The product name is configurable (`APP_PRODUCT_NAME`). No logo is bundled: both the console and the app show a neutral placeholder until the company uploads its official logo.

## Read first

| Document | What it covers |
|---|---|
| [docs/STATUS.md](docs/STATUS.md) | **What is verified, what is not, and why.** Test results, checklist, known limitations |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Components, key decisions, isolation, device lifecycle, revocation bounds |
| [docs/CAPABILITY_MATRIX.md](docs/CAPABILITY_MATRIX.md) | Per-control support and verification; physical-device matrix to fill in |
| [docs/ENROLMENT.md](docs/ENROLMENT.md) | Android Management setup, QR enrolment (factory reset), lifecycle rules |
| [docs/TELEPHONY.md](docs/TELEPHONY.md) | FreeSWITCH integration, events, recording, simulator, validation checklist |
| [docs/API.md](docs/API.md) | API groups, authentication layers, signatures |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Local dev, cloud, on-prem, processes, backup/restore |
| [docs/BUILD.md](docs/BUILD.md) | APK and Windows installer build steps and prerequisites |
| [docs/TEST_PLAN.md](docs/TEST_PLAN.md) | Automated suites, acceptance criteria mapping, physical-device test plan |
| [docs/USER_GUIDES.md](docs/USER_GUIDES.md) | Administrator and employee guides |

## Layout

```
backend/   Laravel app. app/Domain is framework-free and unit-tested. Migrations, seeders, tests, .env.example
admin/     React + TypeScript console (builds into backend/public/admin)
desktop/   Electron shell (sandboxed, context-isolated, navigation-locked) + Windows NSIS config
android/   core/ (pure Kotlin, tested) + app/ (Compose, Keystore identity, sync, location, WebRTC media)
tools/     backup/restore, recording verification, schema DDL check, Kotlin↔PHP interop check
docs/
```

## Quick verification (no framework install needed)

```bash
cd backend && phpunit -c phpunit.domain.xml        # 40 tests
cd admin && npm ci && npm test && npm run build    # 5 tests
cd desktop && npm ci && npm test                   # 5 tests
tools/interop-check.sh                             # Kotlin-signed request verified by PHP
```
"# rovexy" 
