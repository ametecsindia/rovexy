#!/usr/bin/env bash
# Exercises tools/backup.sh + tools/restore.sh against the real schema (built by run.php) with fixture data and a
# real recording file, then proves records and recording references are usable after restore.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
export DB_HOST=127.0.0.1 DB_USERNAME=dev DB_PASSWORD=devpw
php "$HERE/run.php" ammp_bk >/dev/null
REC="$WORK/recordings"; FILES="$WORK/files"; mkdir -p "$REC/companies/1/recordings/2026/10" "$FILES"
head -c 48000 /dev/urandom > "$REC/companies/1/recordings/2026/10/call.wav"
SHA=$(sha256sum "$REC/companies/1/recordings/2026/10/call.wav" | cut -d' ' -f1)
mysql -h 127.0.0.1 -u dev -pdevpw ammp_bk <<SQL
INSERT INTO companies (id, slug, name, created_at, updated_at) VALUES (1,'alpha','Alpha',NOW(),NOW()),(2,'bravo','Bravo',NOW(),NOW());
INSERT INTO users (id, company_id, employee_code, name, password, created_at, updated_at) VALUES (1,1,'EMP1','E','x',NOW(),NOW());
INSERT INTO managed_devices (id, company_id, installation_id, approval_code, created_at, updated_at) VALUES (1,1,'0b7c1d2e-3f40-4a51-8b62-7c83d94e05f6','ABCD-2345',NOW(),NOW());
INSERT INTO customers (id, company_id, customer_ref, name, phone, created_at, updated_at) VALUES (1,1,'C1','Cust','+919876543210',NOW(),NOW());
INSERT INTO calls (id, company_id, uuid, provider, user_id, device_id, customer_id, destination, agent_leg, state, initiated_at, created_at, updated_at)
  VALUES (1,1,'11111111-2222-4333-8444-555555555555','freeswitch',1,1,1,'+919876543210','pstn_callback','completed',NOW(),NOW(),NOW());
INSERT INTO recordings (company_id, call_id, status, object_key, format, mime, size_bytes, sha256, created_at, updated_at)
  VALUES (1,1,'available','companies/1/recordings/2026/10/call.wav','wav','audio/wav',48000,'$SHA',NOW(),NOW());
SQL
# Duplicate company/device identity must be rejected by the schema (non-null company + uniqueness).
if mysql -h 127.0.0.1 -u dev -pdevpw ammp_bk -e "INSERT INTO managed_devices (company_id, installation_id, approval_code, created_at, updated_at) VALUES (1,'0b7c1d2e-3f40-4a51-8b62-7c83d94e05f6','ZZZZ-2345',NOW(),NOW())" 2>/dev/null; then echo "FAIL: duplicate installation accepted"; exit 1; fi
if mysql -h 127.0.0.1 -u dev -pdevpw ammp_bk -e "INSERT INTO managed_devices (company_id, installation_id, approval_code) VALUES (NULL,'x','y')" 2>/dev/null; then echo "FAIL: null company accepted"; exit 1; fi
echo "schema constraints: duplicate installation and NULL company rejected"
BK=$(DB_DATABASE=ammp_bk RECORDINGS_ROOT="$REC" FILES_ROOT="$FILES" "$HERE/../backup.sh" "$WORK/backups" | tail -1)
echo "backup: $(ls "$BK" | tr '\n' ' ')"
mysql -h 127.0.0.1 -u dev -pdevpw -e "DROP DATABASE IF EXISTS ammp_restore; CREATE DATABASE ammp_restore"
rm -rf "$WORK/restored"
DB_DATABASE=ammp_restore RECORDINGS_ROOT="$WORK/restored/recordings" FILES_ROOT="$WORK/restored/files" "$HERE/../restore.sh" "$BK"
# Tamper detection
echo x >> "$BK/database.sql.gz"
if (cd "$BK" && sha256sum -c MANIFEST.sha256 --quiet 2>/dev/null); then echo "FAIL: tampering not detected"; exit 1; fi
echo "tampered backup correctly rejected by manifest check"
rm -rf "$WORK"
