#!/usr/bin/env bash
# Consistent backup: MySQL dump (single transaction) + private recordings/company files + checksum manifest.
# Usage: DB_HOST=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=… RECORDINGS_ROOT=… FILES_ROOT=… ./backup.sh /backups
set -euo pipefail
DEST_ROOT="${1:?destination directory required}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="$DEST_ROOT/ammp-$STAMP"
mkdir -p "$DEST"
umask 077
export MYSQL_PWD="${DB_PASSWORD:-}"
mysqldump --single-transaction --routines --triggers --hex-blob --set-gtid-purged=OFF \
  -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:?}" "${DB_DATABASE:?}" 2>/dev/null \
  | gzip -9 > "$DEST/database.sql.gz" \
  || mysqldump --single-transaction --routines --triggers --hex-blob -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:?}" "${DB_DATABASE:?}" | gzip -9 > "$DEST/database.sql.gz"
# Recordings on local disk (for S3-compatible storage use bucket versioning/replication instead — see docs/DEPLOYMENT.md).
if [ -n "${RECORDINGS_ROOT:-}" ] && [ -d "$RECORDINGS_ROOT" ]; then tar -C "$RECORDINGS_ROOT" -czf "$DEST/recordings.tar.gz" .; fi
if [ -n "${FILES_ROOT:-}" ] && [ -d "$FILES_ROOT" ]; then tar -C "$FILES_ROOT" -czf "$DEST/company-files.tar.gz" .; fi
(cd "$DEST" && sha256sum ./* > MANIFEST.sha256)
echo "$DEST"
