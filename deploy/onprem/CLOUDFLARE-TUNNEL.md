# Publishing ROVEXY with Cloudflare Tunnel

With Cloudflare Tunnel, phones and browsers reach ROVEXY at **https://rovexy.yourcompany.com** (your own domain), while the
server opens **no ports** to the internet and needs **no certificate**. Cloudflare provides the https certificate. A small
Cloudflare program on the server (`cloudflared`) connects *out* to Cloudflare and carries the traffic to ROVEXY.

| What | Who owns it |
|---|---|
| The domain (e.g. yourcompany.com) and the Cloudflare account | The client |
| The tunnel and its token | The client (created in their Cloudflare account) |
| The ROVEXY server | The client's own Windows Server |

You need: the domain added to a (free) Cloudflare account, with its name servers at Cloudflare; administrator access to the
ROVEXY server.

## 1. Install ROVEXY in tunnel mode

1. Run `ROVEXY-Setup-<version>.exe` on the server.
2. On **Secure connection (https)** choose **Cloudflare Tunnel (recommended)**.
3. **Server name**: the name phones will use, e.g. `rovexy.yourcompany.com`. Leave the e-mail empty.
4. Finish the wizard. ROVEXY now answers on this computer only, at `http://127.0.0.1:8088`. The last page says
   "does not answer yet" for the https address — that is expected until step 3.

## 2. Create the tunnel in Cloudflare

1. Sign in at **one.dash.cloudflare.com** (Cloudflare Zero Trust) with the client's account.
2. **Networks → Tunnels → Create a tunnel** → type **Cloudflared** → name it `rovexy` → **Save tunnel**.
3. On the next page choose **Windows**. Cloudflare shows a command like
   `cloudflared.exe service install eyJhIjoi…` — **copy the long token** (everything after `install`). Keep it private:
   anyone with the token can run this tunnel.
4. Click **Next**. Under **Public hostnames → Add a public hostname**:
   - **Subdomain**: `rovexy` · **Domain**: `yourcompany.com`
   - **Service type**: `HTTP` · **URL**: `localhost:8088`
5. **Save hostname**. Cloudflare creates the DNS record for you.

## 3. Install cloudflared on the ROVEXY server

1. Download the Windows 64-bit installer (`cloudflared-windows-amd64.msi`) from the official Cloudflare download page
   (developers.cloudflare.com → Cloudflare Tunnel → Downloads) and install it.
2. Open **Command Prompt as Administrator** and run (paste your own token):

   ```
   cloudflared.exe service install <token>
   ```

3. Within a minute the tunnel shows **HEALTHY** in Cloudflare. Open `https://rovexy.yourcompany.com/admin` from any
   computer or phone — it should show the ROVEXY sign-in page with a valid padlock.

The `cloudflared` service starts with Windows, like the ROVEXY services. Nothing else to renew.

## 4. Recommended Cloudflare settings

- **SSL/TLS → Edge Certificates → Always Use HTTPS: On.**
- Do **not** turn on Cloudflare features that change pages (Rocket Loader, Email Obfuscation) for this hostname.
- Uploads: Cloudflare's free plan accepts uploads up to 100 MB, which matches ROVEXY's own limit.

## 5. Check it

| Test | Expected |
|---|---|
| `https://rovexy.yourcompany.com/up` in a browser | A green "Application up" page |
| Sign in to the console | Works; the address bar shows https with a padlock |
| Console → Audit log after signing in | Shows your real internet IP address, not 127.0.0.1 |
| Phone app → enter `https://rovexy.yourcompany.com` | The phone connects and can register |
| From outside, scan the server's public IP for ports 80/443 | Closed (nothing is open) |

## Problems

| Symptom | Fix |
|---|---|
| Cloudflare page "Bad gateway" (502) | The ROVEXY-Web service is not running: Windows Services → start **ROVEXY Web server**. Check that the hostname URL is `localhost:8088`. |
| Tunnel shows **DOWN / INACTIVE** | The `cloudflared` service is stopped or the token is wrong: Services → start **Cloudflared agent**, or run the install command again. |
| Error 1033 | Cloudflare can't reach the tunnel: the server has no internet, or a firewall blocks outgoing port 7844. Allow outgoing TCP/UDP 7844 (and 443). |
| Sign-in loops or "page expired" | **Always Use HTTPS** is off, or the hostname does not match the Server name entered in setup. They must be the same name. |

Changing the name later: running setup again does not change it. Ametecs changes `APP_URL` on the server and the client
changes the Cloudflare public hostname to the same name, then the ROVEXY services are restarted.
