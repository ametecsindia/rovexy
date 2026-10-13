# Managed-device enrolment (company-owned Android)

## 1. Method and why

- **Mode:** fully managed (company-owned, work-only) devices, managed through the **Android Management API (AMAPI)**. On these devices, Google's *Android Device Policy* is the device-policy controller.
- **Factory reset: required.** Fully managed enrolment happens only during setup of a new or factory-reset phone, by scanning an AMAPI enrolment QR code: tap the welcome screen 6 times, or use zero-touch for bulk purchases. A phone already in use cannot be converted without a reset.
- **Why not a custom device-policy controller (DPC):**
  - Google closed new Play EMM API registrations, and turned the Play EMM API off on 30 Sep 2025.
  - Unapproved DPCs can be blocked by Play Protect's DPC allowlist during provisioning.
  - Building and certifying a DPC is a large separate product.
- **Eligibility:** AMAPI is only for commercial EMM solutions offered to customers (internal-only use is not allowed). Up to 500 devices need a documented business justification, and more need Google's approval. Confirm Ametecs' eligibility and quota before production: <https://developers.google.com/android/management/permissible-usage>
- **Without AMAPI** (`MANAGEMENT_DRIVER=app_only`), everything works except device-level controls, which are reported as *Requires additional setup*. They are never shown as applied.

## 2. One-time setup per deployment

1. Create a Google Cloud project and enable the **Android Management API**. Create a service account with the *Android Management User* role and download its JSON key to the server (e.g. `/etc/ammp/amapi-sa.json`, mode 600). Set `AMAPI_SERVICE_ACCOUNT_JSON_PATH`.
2. Create an **enterprise per company** through the AMAPI signup flow (`signupUrls.create` → admin completes the Google flow → `enterprises.create`). Store the enterprise name on the company: `companies.amapi_enterprise = enterprises/LCxxxxxx`. A single on-prem company can use `AMAPI_ENTERPRISE_NAME` instead.
3. Create a Pub/Sub topic and a **push** subscription to `https://<server>/api/amapi/v1/notifications` with OIDC authentication. Set the audience in `AMAPI_PUBSUB_AUDIENCE`. Enable `STATUS_REPORT` and `ENROLLMENT` notifications on the enterprise.
4. Publish the employee app as a **private app** for each enterprise in managed Google Play (package `in.ametecs.managedmobile`, signed release APK).
5. Set `MANAGEMENT_DRIVER=amapi`.

## 3. Enrolling a phone

1. In the admin console, open **Devices**, then request an **enrolment token** (`POST /api/admin/enrolment-tokens`). The console shows the QR code. It expires after 1 hour by default.
2. Factory-reset the phone. On the welcome screen, tap 6 times, connect to Wi-Fi and scan the QR code.
3. Android Device Policy provisions the phone and applies the enrolment policy. That policy force-installs the employee app with **managed configuration**:
   - `server_url`
   - `company_slug`
   - `lock_server=true`
4. The employee app opens with the company server pre-filled and locked. It generates its Keystore key, registers, and shows the **approval code**.
5. The administrator opens **Devices → Review** and types the code read from the phone. They choose the employee, team, asset tag and an optional device policy, then approve.
6. The phone establishes its device session. The employee signs in with User ID and password. The app downloads the effective policy and reports per-control results. The server then creates a per-device AMAPI policy (`policies/dev-{id}`) with the effective device-level controls and assigns it to the device.
7. Work opens only when every mandatory control is applied **and verified**. Verification comes from the app's own observations, or from AMAPI's compliance report for the current policy.

## 4. Identity and lifecycle rules

| Event | What happens |
|---|---|
| App reinstall / data cleared | A new installation ID and key are created, so the phone appears as a **new pending request**. The history links it to the previous record when the AMAPI device name matches. It needs explicit approval and assignment. |
| Employee logs out | Only the work session ends. Approval, AMAPI restrictions and the device policy stay. |
| Reassign to another employee | **Devices → device → Reassign**. The old employee's sessions end, and the new employee must sign in. |
| Revoke (lost phone, leaver) | All credentials die at once (the epoch is bumped). Active sessions, media and calls are ended (per policy). The phone shows *Access revoked*. **The phone is not wiped, and restrictions remain.** |
| Re-approve after revoke | Requires a **new registration** (a new key pair, created via *Register again* on the phone after the admin asks). The old key can never be reactivated. |
| Wipe | A separate `devices.wipe` permission, typed asset-tag confirmation, a reason, and an audit entry. AMAPI only. |
| Move to another company/server | Not possible from the phone: once a device session exists, the server field is locked. Procedure: revoke in company A → AMAPI `devices.delete` (wipe) in A's enterprise → enrol fresh with B's QR code. |
| Policy failure | The device stays non-compliant and work is blocked. IT recovery: check **Effective policy & capability** → fix or roll back the profile (rollback publishes the previous settings as a new version) → the phone re-syncs. |

## 5. Tracked per device

These are the `managed_devices` columns:

- company asset tag, installation ID, AMAPI device name
- manufacturer, model, Android version and SDK, app version
- approval state and code, credential epoch
- assigned employee and team, management mode and status, capability matrix
- desired / applied / last-good policy fingerprints, policy state, compliance detail
- presence, work state, battery, network, last contact
