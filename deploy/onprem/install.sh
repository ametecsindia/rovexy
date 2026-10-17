#!/usr/bin/env bash
# ROVEXY on-premises install (Linux). Run from the unpacked package folder as the web server user, e.g.
#   sudo -u www-data ./install.sh                       set up; activate the licence later with ./activate-licence.sh
#   sudo -u www-data ./install.sh /path/RX-....lic      set up and activate with the licence file from Ametecs
# Safe to run again: finished steps are skipped.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$HERE/rovexy"
PHP="${ROVEXY_PHP:-php}"
fail() { echo; echo "[ERROR] $*"; exit 1; }
[ -f "$APP/artisan" ] || fail "The rovexy folder was not found next to install.sh. Unpack the whole package and run it from there."
cd "$APP"
echo; echo " ROVEXY on-premises install"; echo " =========================="; head -1 "$HERE/VERSION.txt" 2>/dev/null || true; echo

echo "[1/5] Checking PHP"
command -v "$PHP" >/dev/null || fail "PHP was not found. Install PHP 8.3 (php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip), or set ROVEXY_PHP."
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", "<") ? 1 : 0);' || fail "PHP 8.3 or newer is needed (found $("$PHP" -r 'echo PHP_VERSION;'))."
missing=""
for e in openssl pdo mbstring curl fileinfo zip dom xml; do "$PHP" -r "exit(extension_loaded('$e') ? 0 : 1);" || missing="$missing $e"; done
[ -z "$missing" ] || fail "These PHP extensions are missing:$missing"
PHPEXE="$("$PHP" -r 'echo PHP_BINARY;')"
echo "      $PHPEXE"

echo "[2/5] Settings"
if [ ! -f .env ]; then
  cp "$HERE/env.onprem" .env
  echo "      Created rovexy/.env from env.onprem."
  echo "      Edit it now (APP_URL and the DB_ lines), then run ./install.sh again:  nano $APP/.env"
  exit 2
fi
echo "      rovexy/.env exists"
"$PHPEXE" artisan --version >/dev/null 2>&1 || { "$PHPEXE" artisan --version || true; fail "ROVEXY cannot start with this PHP and rovexy/.env (see above)."; }

echo "[3/5] Setting up ROVEXY"
args=()
if [ $# -ge 1 ]; then [ -f "$1" ] || fail "Licence file not found: $1"; args+=("--licence=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"); fi
"$PHPEXE" artisan rovexy:setup "${args[@]}" || fail "Setup did not finish. Fix what the message above says, then run ./install.sh again."

echo "[4/5] Background tasks (cron, every minute)"
cron1="* * * * * cd $APP && $PHPEXE artisan schedule:run >> /dev/null 2>&1 # ROVEXY"
cron2="* * * * * cd $APP && $PHPEXE artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1 # ROVEXY"
if command -v crontab >/dev/null && { (crontab -l 2>/dev/null | grep -v '# ROVEXY$'; echo "$cron1"; echo "$cron2") | crontab -; }; then
  echo "      added to the crontab of $(id -un)"
else
  echo "[WARN] Could not change the crontab. Add these two lines for the web server user:"; echo "$cron1"; echo "$cron2"
fi

echo "[5/5] Web server"
echo "      Document root (https): $APP/public   — nginx example in INSTALL.md"
echo "      The web server user must be able to write $APP/storage and $APP/bootstrap/cache."
echo
echo " Server ID (send it to Ametecs for the licence file):"
"$PHPEXE" artisan rovexy:licence id
echo
echo " Done. When the .lic file arrives:  ./activate-licence.sh /path/RX-....lic"
