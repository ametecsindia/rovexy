#!/usr/bin/env bash
# Restores a backup produced by backup.sh into an EMPTY database and storage roots, then verifies recordings.
# Usage: DB_… RECORDINGS_ROOT=… FILES_ROOT=… ./restore.sh /backups/ammp-YYYYmmddTHHMMSSZ
set -euo pipefail
SRC="${1:?backup directory required}"
(cd "$SRC" && sha256sum -c MANIFEST.sha256 --quiet) || { echo "Backup integrity check FAILED"; exit 1; }
export MYSQL_PWD="${DB_PASSWORD:-}"
gunzip -c "$SRC/database.sql.gz" | mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:?}" "${DB_DATABASE:?}"
if [ -f "$SRC/recordings.tar.gz" ]; then mkdir -p "$RECORDINGS_ROOT"; tar -C "$RECORDINGS_ROOT" -xzf "$SRC/recordings.tar.gz"; fi
if [ -f "$SRC/company-files.tar.gz" ]; then mkdir -p "$FILES_ROOT"; tar -C "$FILES_ROOT" -xzf "$SRC/company-files.tar.gz"; fi
php "$(dirname "$0")/verify-recordings.php"
