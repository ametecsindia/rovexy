# From payment to handover — Ametecs checklist (on-premises)

Short form of the execution playbook (ROVEXY-Execution-Playbook.docx). Go-live on day 5, then two weeks of hypercare.

| Day | Step | Done when |
|---|---|---|
| 0 | Payment received (deposit ₹1,000 per FOS + setup ₹1,000 per FOS + GST). Super Admin → Licences → the licence → **Deposit received…** | Deposit shows on the licence |
| 0 | Issue the licence (on-prem, users = FOS + office users) | Licence key sent |
| 1 | Requirements call: server (4 cores / 8 GB / 500 GB for 50 FOS), Windows Server 2016+, MySQL, domain in the client's Cloudflare account | Server ready |
| 1 | Client creates the Google Cloud project, billing and the two keys (docs/GUIDE-GOOGLE-SETUP.md) | Keys in hand |
| 2 | Run `ROVEXY-Setup-<version>.exe` → **Cloudflare Tunnel** (deploy/onprem/INSTALL.md) | Setup finishes OK |
| 2 | Cloudflare tunnel + `cloudflared.exe service install <token>` (deploy/onprem/CLOUDFLARE-TUNNEL.md) | `https://<client domain>/admin` opens with a padlock |
| 2 | Licence activates (online check-in) — Firebase key arrives with it | Plan & licence: active · System health: push configured |
| 3 | Integrations → Google Maps: server key + display key → Save & test; check usage limits | "working" |
| 3 | Upload employees (Groups & people → Bulk upload) and the first portfolio | Map pins placed |
| 3 | Circles for each FOS (docs/GUIDE-ROUTE-PLANS.md) | Every FOS has a circle |
| 4 | FOS phones: `https://<client domain>/get-app` (QR on Devices & approvals), install, approve each phone, battery **Unrestricted**, location **All the time** | Every phone approved |
| 4 | Test call from each phone → Devices shows **Recording verified** | All phones verified |
| 4 | UAT with the client (test-case sheets) | Signed off |
| 5 | Go-live: first published route plans, live map, plan vs actual | FOS working |
| 5–19 | Hypercare: daily check of System health (nightly backup), plan vs actual alerts, Google usage | Handover note signed |

After handover: backups are copied off the server regularly (client IT), AMC from year 2 (Super Admin → licence → AMC
per year), Click2Call / Smart31 add-ons billed monthly from **Calling add-ons, deposits & AMC**.
