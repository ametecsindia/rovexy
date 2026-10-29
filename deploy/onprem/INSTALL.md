# ROVEXY on-premises — installation

This package is the same for every company. Your company's licence is a separate file (`.lic`) that Ametecs makes for **your server**.

## Windows server: ROVEXY-Setup.exe (recommended)
Everything below (PHP, web server, background tasks) is done by **`ROVEXY-Setup-x.y.exe`**. Before you start you need:
- **Windows Server 2016 or newer** (or Windows 10/11), 64-bit, signed in as an administrator.
- An **internet name** for the server, e.g. `rovexy.yourcompany.com`. The ROVEXY phone app only connects over https with a
  publicly trusted certificate, so one of:
  - **Cloudflare Tunnel (recommended)**: the domain is in the company's own Cloudflare account; Cloudflare provides https,
    **no ports are opened** and no certificate is needed on the server. See **`CLOUDFLARE-TUNNEL.md`** (next to setup).
  - **Let's Encrypt** (free, automatic): the name's DNS points to the server, ports 80 and 443 free and reachable from the internet; or
  - **your own certificate** for that name: a `.pfx` (with its password) or a `.crt`/`.pem` + `.key`, from a public certificate authority.
- **MySQL 5.7+ / MariaDB 10.3+** and a user with all rights on the ROVEXY database (setup creates the database if the user may).
  For automatic backups before updates, the MySQL client tools (`mysqldump`) should be on the server.

Then: put your `.lic` file (if you have it) **next to** `ROVEXY-Setup-x.y.exe`, double-click the setup, answer the pages
(server name and certificate, database, company and administrator), click Install. Setup installs to `C:\ROVEXY`, checks
every answer before it continues, creates the database tables, the company and its administrator, activates the licence,
registers the Windows services *ROVEXY-Web, ROVEXY-PHP, ROVEXY-Scheduler, ROVEXY-Queue* (they start with Windows), opens
the firewall for 80/443 and checks that ROVEXY answers. The last page shows the result, the **Server ID** and the licence
status — with **Choose licence file…** if the licence is not active yet. Desktop / Start menu: *ROVEXY Console*.

- No licence file yet? ROVEXY runs in evaluation mode (14 days, 10 users). Send the Server ID to Ametecs, then add the file in
  the console (Plan & licence → Import & activate) or with *Choose licence file…* by running setup again.
- **Backups:** every night at 00:30 the database is backed up to `C:\ROVEXY\backups` (the newest 7 are kept). The console's
  *System health* page shows when the last backup ran. Copy that folder to another disk or computer regularly — a backup on
  the same disk does not survive the disk failing.
- **Phone app with all-day location:** field employees open **https://<your server>/get-app** on the phone (the link and a QR
  code are in the console under *Devices & approvals*). That version keeps sharing location while the employee is signed in, also
  with the screen off; the Play Store version only does it while the app is open. Uninstall the Play Store app first. The app file
  comes with the ROVEXY package and its updates.
- **Upgrade:** run the newer `ROVEXY-Setup-x.y.exe`. It backs up the database to `C:\ROVEXY\backups`, keeps all settings and
  data, and updates the program. **Uninstall** (Windows Settings → Apps) removes the program and services but keeps
  `rovexy\.env`, `rovexy\storage`, `data`, `backups`, `logs` and the database.
- Logs: `C:\ROVEXY\logs` (`install.log` has no passwords). Settings: `C:\ROVEXY\rovexy\.env` (Administrators only).

**No domain name or static IP?** Choose *No domain name - only on this computer* on the first page. Setup then skips the
server-name page and runs ROVEXY at **https://localhost:8443** on that computer only: Caddy makes its own certificate and
Windows is told to trust it, port 8443 is used (so IIS / Laragon on 80/443 do not clash), no firewall port is opened.
Database, company, administrator and licence work exactly the same (the licence file is locked to this computer's
Server ID; no internet needed to activate). The **phone app cannot connect** in this mode — it needs a public name with
a trusted certificate. Use it for a single office PC, demos and trials.

The rest of this guide is the manual install from the ZIP (Linux, or a Windows server that already has PHP and a web server).

## What you need
- A Windows or Linux server that staff and the company phones can reach over **https**.
- **PHP 8.3** or newer (command line and web) with: openssl, pdo + pdo_mysql (or pdo_sqlite), mbstring, curl, fileinfo, zip, dom/xml.
  Recommended: intl, gd, bcmath. Windows: the "Thread Safe" zip from windows.php.net, or Laragon / XAMPP.
- **MySQL 8 / MariaDB 10.6+** with an empty database and a user for it (or SQLite for a small office).
- A web server with an https certificate: Apache (Laragon, XAMPP), nginx, or IIS.

## 1. Unpack
Unpack the whole ZIP, for example to `C:\ROVEXY` (Windows) or `/opt/rovexy` (Linux). Keep the folder layout:
```
ROVEXY-OnPrem-x.y\
  install.bat  install.sh  activate-licence.bat  activate-licence.sh  env.onprem  INSTALL.md
  rovexy\        ← the application (its "public" folder is the web root)
```

## 2. Install
**Windows:** right-click `install.bat` → *Run as administrator*. **Linux:** `sudo -u www-data ./install.sh`.

The first run creates `rovexy\.env` from `env.onprem` and opens it. Set:
- `APP_URL` — the https address of this server, e.g. `https://rovexy.yourcompany.com`
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (and `DB_HOST` if the database is on another machine)

Save, then the install continues (Linux: run `./install.sh` again). It asks for your **company name**, the **administrator's
e-mail** and a **password** (12+ characters), creates the database tables, and shows this server's **Server ID**.

Running the install again is safe: finished steps are skipped.

## 3. Activate your licence
1. Send the **Server ID** (40 characters, shown by the install and by `activate-licence`) to Ametecs.
2. Ametecs sends back a licence file named like `RX-XXXX-XXXX-XXXX-XXXX.lic`. It only works on this server.
3. **Windows:** drag the `.lic` file onto `activate-licence.bat`. **Linux:** `./activate-licence.sh /path/RX-….lic`.
   (Or in the console: *Plan & licence → Offline licence file → Import & activate*.)

Server with internet? Instead of a file you can enter the licence key in the console (*Plan & licence → Licence key*).
Until a licence is activated, ROVEXY runs as a 14-day evaluation for up to 10 users.

## 4. Background tasks
ROVEXY needs two commands every minute (licence checks, reminders, recordings, imports). The install adds them for you:
- Windows: Task Scheduler entries **ROVEXY Scheduler** and **ROVEXY Queue** (run as SYSTEM).
- Linux: two lines in the web user's crontab (marked `# ROVEXY`).

If the install could not add them, add them by hand (replace the paths):
```
php C:\ROVEXY\rovexy\artisan schedule:run
php C:\ROVEXY\rovexy\artisan queue:work --stop-when-empty --max-time=55
```

## 5. Web server
Point the site's document root to `…\rovexy\public` and serve it over https.
- **Apache / Laragon / XAMPP:** nothing else — `public\.htaccess` is included (mod_rewrite on).
- **nginx:**
  ```
  server {
      listen 443 ssl http2;
      server_name rovexy.yourcompany.com;
      ssl_certificate /etc/ssl/rovexy.crt;  ssl_certificate_key /etc/ssl/rovexy.key;
      root /opt/rovexy/rovexy/public;  index index.php;  client_max_body_size 100m;
      location / { try_files $uri $uri/ /index.php?$query_string; }
      location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock;
                          fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
      location ~ /\.(?!well-known) { deny all; }
  }
  ```
- **IIS:** add the URL Rewrite module and a rule sending everything that is not a file or folder to `index.php`.

The web server user must be able to write `rovexy\storage` and `rovexy\bootstrap\cache`.

## 6. Sign in and connect the phones
Open `https://<your server>/admin` and sign in with the administrator e-mail and password from step 2.
On each phone, install the ROVEXY app and enter the same server address.

## Updates
*Plan & licence → Check for update* downloads and installs new versions (database and files are backed up first, and put back
if anything fails). Updates need an active licence; perpetual licences need an active AMC.

## Help
- `activate-licence` without a file shows the Server ID and the current licence status.
- "This licence file was made for another server": the file belongs to a different Server ID — ask Ametecs for one for this server.
- Logs: `rovexy\storage\logs\laravel.log`.
- Ametecs India · sales@ametecsindia.com
