#!/usr/bin/env bash
# ROVEXY licence activation (Linux).
#   ./activate-licence.sh                     show this server's ID (Ametecs needs it to make the licence file)
#   ./activate-licence.sh /path/RX-....lic    activate with the licence file from Ametecs
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE/rovexy" || { echo "[ERROR] The rovexy folder was not found next to this file."; exit 1; }
PHP="${ROVEXY_PHP:-php}"
if [ $# -eq 0 ]; then
  echo; echo " This server's ID (send it to Ametecs; they send back a .lic file locked to this server):"; echo
  "$PHP" artisan rovexy:licence id
  echo; "$PHP" artisan rovexy:licence status
  exit 0
fi
[ -f "$1" ] || { echo "[ERROR] Licence file not found: $1"; exit 1; }
exec "$PHP" artisan rovexy:licence import "$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
