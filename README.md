# ROVEXY — Your Workforce. In Motion.

Field-force and calling platform for collection agencies, by Ametecs India Private Limited. One server (cloud at
rovexy.app, or on-premises at the client), one admin console, one Android app for every company.

- **Server:** Laravel 13 (PHP 8.3) + MySQL/MariaDB. Cloud (many companies) or on-premises (one company).
- **Admin console:** React/TypeScript, served by the server at `/admin` (cloud: `/w/<workspace>/admin`). Super Admin at `/super`, Accounts at `/accounts` (cloud only).
- **Android app:** Kotlin/Compose. Two builds: **play** (Play Store; location only while the app is on screen) and **direct** (downloaded from the client's own server at `/get-app`; all-day location while signed in).
- **Calling:** the phone's SIM (recorded by the phone's dialer, uploaded by the app) or **Click2Call** (gateway rings the employee, then the customer; recording fetched by the server), Smart31 number rotation.
- **Field work:** customer pins from addresses (automatic), FOS circles, admin route plans (one two-wheeler optimisation per FOS per day), Today's route on the phone, check-in within 100 m (server-verified), fake-GPS refusal, payments with slips, live map.
- **Google Maps cost:** basic routing only on the phone, every call counted against a monthly limit per company (default = Google's free allowance).

## Guides (start here)

| Guide | For |
|---|---|
| [deploy/onprem/INSTALL.md](deploy/onprem/INSTALL.md) | Installing at a client (Windows setup.exe, or manual) |
| [deploy/onprem/CLOUDFLARE-TUNNEL.md](deploy/onprem/CLOUDFLARE-TUNNEL.md) | Publishing an on-prem server on the client's domain with no open ports |
| [docs/GUIDE-GOOGLE-SETUP.md](docs/GUIDE-GOOGLE-SETUP.md) | The client's Google Cloud account, the two keys, quotas and the usage limits |
| [docs/GUIDE-ROUTE-PLANS.md](docs/GUIDE-ROUTE-PLANS.md) | Circles, route plans, plan vs actual, Today's route on the phone |
| [docs/GUIDE-INSTALLATION.md](docs/GUIDE-INSTALLATION.md) | Ametecs checklist from payment to handover (short form of the execution playbook) |
| [deploy/README.md](deploy/README.md) | The cloud server (rovexy.app): first set-up and `deploy.sh` |
| [docs/STATUS.md](docs/STATUS.md) | What is built and verified, and what is not |

Older technical references: [ARCHITECTURE](docs/ARCHITECTURE.md), [API](docs/API.md), [TELEPHONY](docs/TELEPHONY.md), [ENROLMENT](docs/ENROLMENT.md), [BUILD](docs/BUILD.md), [TEST_PLAN](docs/TEST_PLAN.md), [USER_GUIDES](docs/USER_GUIDES.md) — written for the first version (Oct 2026, then called "Ametecs Managed Mobile"); where they disagree with the guides above, the guides win.

## Layout

```
backend/   Laravel app: app/ (Domain = framework-free), database/migrations, routes, tests (PHPUnit)
admin/     React console + Super Admin + Accounts (builds into backend/public/admin)
android/   app/ (Compose; flavours play / direct) + core/ (pure Kotlin)
desktop/   Electron shell for the console (optional)
deploy/    cloud server files (nginx, systemd, deploy.sh), onprem/ (env, guides), windows/ (setup.exe: Inno Setup + configure.php)
brand/     ROVEXY logos
tools/     backup / restore and checks
```

## Checks

```bash
cd backend && composer install && vendor/bin/phpunit          # full suite
cd admin && npm ci && npx tsc --noEmit && npm test && npm run build
```
The Android app is built in Android Studio on the developer PC (play bundle for the Play Store, direct release APK for `/get-app`).
`build-client.bat` builds the on-prem package and `ROVEXY-Setup-<version>.exe` (signed when `ROVEXY_SIGN_THUMBPRINT` is set).

## Rules the code keeps

- Nothing client-specific in `.env`: keys and settings are entered in the console (stored encrypted) or arrive with the licence.
- Every Google call is necessary and counted (clients pay Google themselves on-premises).
- Customer data never leaves the client's server; phones get only what the employee may see.
