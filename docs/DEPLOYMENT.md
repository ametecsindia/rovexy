# Setup and deployment

## 1. Local development (Windows + Laragon, or Linux)

Prerequisites:

- PHP 8.3+ with `pdo_mysql`, `redis`, `openssl`, `sodium`, `intl`, `zip`, `gd`, `pcntl` (Linux)
- Composer 2
- MySQL 8 (or MariaDB 10.11+)
- Redis 7
- Node 22
- ffmpeg (optional, for MP3)

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
# .env for local: APP_ENV=local APP_DEBUG=true APP_URL=http://localhost:8000 TELEPHONY_DRIVER=simulator PUSH_DRIVER=log
#                 MANAGEMENT_DRIVER=app_only SESSION_SECURE_COOKIE=false SANCTUM_STATEFUL_DOMAINS=localhost:8000,localhost:5173
#                 BROADCAST_CONNECTION=reverb (or null)  TENANCY_MODE=shared
php artisan migrate
php artisan db:seed            # SAFE dev seed: 2 fictitious companies, refuses to run in production
composer test:domain           # framework-free domain tests
php artisan test               # full suite incl. acceptance tests
php artisan serve              # http://localhost:8000
php artisan queue:work         # imports, recordings, simulator
php artisan schedule:work      # reconciliation, media expiry, presence
php artisan reverb:start       # WebSockets (optional in dev)

cd ../admin && npm ci && npm run dev        # http://localhost:5173/admin  (VITE_WORKSPACE=demo-collections)
npm run build                               # outputs to backend/public/admin
```

Dev sign-in, from the seed:

- Console: `admin@demo-collections.example` with password `DevOnly-Passw0rd!`. MFA enrolment starts at first sign-in.
- Phone: User ID `EMP001` with the same password.

## 2. Production topology

```
            ┌─ nginx (TLS) ─┬─ php-fpm (Laravel) ── MySQL 8 (primary + backups)
Internet ───┤               ├─ /app/* → Reverb :8080 (WebSockets)
            │               └─ /admin  → static console (public/admin)
            ├─ queue workers (Supervisor): default, recordings
            ├─ scheduler (cron: * * * * * php artisan schedule:run)
            ├─ telephony:listen (Supervisor; one per FreeSWITCH node)
            ├─ Redis (cache, queues, sessions, nonce/replay store)
            ├─ Recording storage: private disk or S3-compatible bucket (versioned)
            └─ coturn (TURN over TLS 5349/TCP 443 for restrictive networks)
```

## 3. Cloud (multi-company SaaS)

- `TENANCY_MODE=shared`, `TENANCY_BASE_DOMAIN=mdm.example.com`, with a wildcard DNS record and certificate for `*.mdm.example.com`.
- Custom company domains: add them with `ammp:create-company --domain=` (they start **unverified**). Verify through a DNS TXT record before use. Add each one to `SANCTUM_STATEFUL_DOMAINS`.
- Recordings go to S3-compatible storage with:
  - a private bucket and Block Public Access
  - SSE enabled and versioning on
  - a lifecycle rule **only** for noncurrent versions

  Retention deletions happen only through approved retention jobs.
- Provision each company:

  ```bash
  php artisan ammp:create-company acme "Acme Collections" admin@acme.example --timezone=Asia/Kolkata --country=IN
  ```

## 4. On-premises (single company)

- `TENANCY_MODE=fixed`, `TENANCY_FIXED_COMPANY_ID=<id>`. A request carrying another workspace is rejected.
- TLS: use a **publicly trusted** certificate (e.g. ACME DNS-01 for an internal hostname), or build a customer-specific APK that pins the company CA (`network_security_config.xml`). The Windows console trusts certificates in the Windows store, so install the company CA there through Group Policy. No certificate-bypass settings exist anywhere.
- Outbound internet is still required for:
  - Android Management (Google APIs)
  - FCM, which is optional (the app also polls and uses WebSockets)
- Recordings: local disk (`RECORDINGS_DRIVER=local`, `RECORDINGS_LOCAL_ROOT` on a dedicated volume) or on-prem MinIO.

## 5. Processes (Supervisor examples)

```ini
[program:ammp-queue]
command=php /srv/ammp/backend/artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
numprocs=2
autorestart=true
user=www-data

[program:ammp-reverb]
command=php /srv/ammp/backend/artisan reverb:start --host=127.0.0.1 --port=8080
autorestart=true
user=www-data
```

## 6. Security settings checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false`. The app refuses to boot with simulator telephony, log-driver push, debug mode, or a non-https `APP_URL`.
- [ ] `SESSION_SECURE_COOKIE=true` and HSTS enabled on nginx.
- [ ] ESL listening on a private interface with an ACL; strong passwords in a secrets store (not in git).
- [ ] Service-account JSON files are mode 600 and owned by the PHP user.
- [ ] MySQL user has only the privileges it needs, plus TLS if the database is remote.
- [ ] Backups are encrypted at rest and off-site.
- [ ] Logs stay on the server; the redaction tap is on by default.

## 7. Backup and restore

**Backup**, run nightly from cron. It produces a single-transaction MySQL dump, tarballs of the recordings and company files, and a SHA-256 manifest:

```bash
DB_HOST=… DB_DATABASE=ammp DB_USERNAME=backup DB_PASSWORD=… RECORDINGS_ROOT=/srv/ammp/recordings \
FILES_ROOT=/srv/ammp/backend/storage/app/company tools/backup.sh /backups
```

With S3 storage, rely on bucket versioning and cross-region replication instead of the tarball, and back up the database only.

**Restore**, into an empty database and empty storage roots:

```bash
DB_DATABASE=ammp_restore … tools/restore.sh /backups/ammp-20261001T020000Z
```

The script refuses a backup whose manifest does not match. After loading, `tools/verify-recordings.php` checks every *Available* recording for:

- the company prefix
- the file's presence
- the size
- the SHA-256

It also prints record counts.

**Verified 2026-10-01** with `tools/schema-check/backup-restore-test.sh` (real schema, fixture data, real file, tamper detection).

Do a restore drill quarterly into a staging server. Then run `php artisan test` and play one recording.
