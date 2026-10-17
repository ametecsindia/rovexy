# ROVEXY — going live from GitHub (Ubuntu server)

Target: one Ubuntu 22.04/24.04 server, domain **rovexy.app** on Cloudflare. Website + Super Admin at `https://rovexy.app`,
each customer workspace at `https://<workspace>.rovexy.app/admin`.

## 1. Server (once)
```bash
sudo apt update && sudo apt install -y nginx mysql-server redis-server git unzip ffmpeg \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash - && sudo apt install -y nodejs
sudo mysql -e "CREATE DATABASE rovexy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'rovexy'@'localhost' IDENTIFIED BY 'CHANGE-ME-strong-password';
  GRANT ALL ON rovexy.* TO 'rovexy'@'localhost';"
```
(If PHP 8.3 is missing on Ubuntu 22.04: `sudo add-apt-repository ppa:ondrej/php` first.)

## 2. Code from GitHub (once)
```bash
sudo mkdir -p /var/www/rovexy && sudo chown www-data:www-data /var/www/rovexy
sudo -u www-data git clone git@github.com:<your-account>/rovexy.git /var/www/rovexy    # deploy key or HTTPS token
cd /var/www/rovexy/backend
sudo -u www-data cp .env.example .env       # then edit .env — see section 4
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan rovexy:licence-keys --force  # NEW signing keys — the dev keys from the PC must not be used live
sudo -u www-data php artisan ammp:super-admin you@ametecsindia.com --name="Ejaz Hussain"   # first Super Admin login
cd ../admin && sudo -u www-data npm ci && sudo -u www-data npm run build
```
Back up `backend/storage/app/keys/licence-private.pem` somewhere safe (never in GitHub). Losing it = no new licences; replacing it breaks every licence already issued.
Then put the new **public** key into GitHub, or the next deploy puts the dev key back: copy the contents of `backend/resources/licence/public.pem`
from the server into the same file on your PC, commit and push. On-prem installs use this file to trust licences and updates.

## 3. Web server, TLS, background jobs (once)
- Cloudflare DNS: `A rovexy.app → <server IP>` and `A *.rovexy.app → <server IP>` (proxied). SSL/TLS mode **Full (strict)**.
- Cloudflare → SSL/TLS → Origin Server → **Create certificate** for `rovexy.app, *.rovexy.app` → save as `/etc/ssl/rovexy/origin.pem` and `origin.key`.
- `sudo cp deploy/nginx-rovexy.conf /etc/nginx/sites-available/rovexy && sudo ln -s ../sites-available/rovexy /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx`
- `sudo cp deploy/rovexy-*.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now rovexy-worker rovexy-reverb`
- Scheduler (payments check, reminders, licence checks): `sudo crontab -u www-data -e` →
  `* * * * * cd /var/www/rovexy/backend && php artisan schedule:run >> /dev/null 2>&1`
- Let `www-data` reload PHP and Reverb without a password (for deploy.sh): `sudo visudo -f /etc/sudoers.d/rovexy` →
  `www-data ALL=NOPASSWD: /bin/systemctl reload php8.3-fpm, /bin/systemctl restart rovexy-reverb`

## 4. `.env` on the server (must-haves)
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://rovexy.app
TENANCY_MODE=shared
TENANCY_BASE_DOMAIN=rovexy.app
SANCTUM_STATEFUL_DOMAINS=rovexy.app,*.rovexy.app
SESSION_SECURE_COOKIE=true
DB_CONNECTION=mysql / DB_DATABASE=rovexy / DB_USERNAME=rovexy / DB_PASSWORD=…
CACHE_STORE=redis / QUEUE_CONNECTION=redis / SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb / REVERB_HOST=rovexy.app / REVERB_PORT=443 / REVERB_SCHEME=https (+ app id/key/secret)
PUSH_DRIVER=fcm / FCM_PROJECT_ID=… / FCM_SERVICE_ACCOUNT_JSON_PATH=/etc/rovexy/fcm-sa.json
TELEPHONY_DRIVER=freeswitch   (never "simulator" — production refuses to start with it)
CALLING_ROUTE=sim
LICENCE_MODE=central
```
After any `.env` change: `php artisan config:cache`.

## 5. Every new release
On your PC: commit → `git push`. On the server:
```bash
sudo -u www-data bash /var/www/rovexy/deploy/deploy.sh
```
It pulls, installs packages, builds the console, migrates, caches, health-checks and restarts the workers, with a maintenance page while it runs.

## 6. After going live
- Super Admin → Settings: seller/GST details, SMTP (send a test), Razorpay **live** keys + webhook secret.
- Razorpay dashboard → Webhooks → `https://rovexy.app/api/webhooks/razorpay`: payment.captured, payment.authorized, payment.failed, order.paid.
- Super Admin → Plans & pricing: real prices.
- Phones: server URL `https://rovexy.app` (rebuild the app with it).
- On-prem customers: their `.env` gets `LICENCE_SERVER_URL=https://rovexy.app` (they get the right public key with the code).

## 7. On-premises client installer (one setup.exe for every client)
On your PC, in the project folder: double-click **`build-client.bat`** (needs PHP 8.3, Composer, npm and Inno Setup 6 —
`winget install -e --id JRSoftware.InnoSetup`). It builds the console, copies the backend to `build\client-stage`, installs
production PHP packages there, packs **`dist\ROVEXY-OnPrem-<version>.zip`** (+ `.sha256`) and test-installs it in a temp folder,
downloads the Windows runtime once into `build\cache` (PHP 8.3 NTS, Caddy, WinSW, VC++ runtime, CA list — pinned SHA-256 /
Microsoft signature; versions at the top of the .bat), and compiles **`dist\ROVEXY-Setup-<version>.exe`** (+ `.sha256`) from
`deploy\windows\rovexy-setup.iss`. Your working copy is not changed (apart from the console build in `backend\public\admin`).
- Windows clients: the setup.exe (wizard → `deploy\windows\configure.php` → `rovexy:setup`, services, firewall, health check;
  details in INSTALL.md). Linux clients / manual installs: the ZIP, as below.
- Not signed: Windows SmartScreen shows "unknown publisher" until the setup.exe is code-signed (add `SignTool=` to the .iss).
- The setup.exe and the ZIP have no client name, licence, `.env`, app key, password or private key: the same file for every client.
- Before building for real clients, `backend/resources/licence/public.pem` must be the **live** server's public key (section 2).
  The build warns when it is this PC's test key.
- Client: unzip → `install.bat` (Windows, as Administrator) or `install.sh` (Linux) → sends you the **Server ID** it shows.
- You: Super Admin → Licences → the client's on-prem licence → **Licence file…** with that Server ID → send the `.lic`.
- Client: drag the `.lic` onto `activate-licence.bat` (or `./activate-licence.sh file.lic`, or Console → Plan & licence → Import).
- Later versions: publish the update ZIP (`rovexy:build-update`) — installs update themselves; no new client package needed.
Commands inside an install: `php artisan rovexy:setup`, `rovexy:licence id|import <file>|key <key>|status`.
