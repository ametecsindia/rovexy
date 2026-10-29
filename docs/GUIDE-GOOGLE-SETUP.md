# Google Maps for a ROVEXY client — set-up guide

Each on-premises client uses **its own Google Cloud account**: the client pays Google directly, and ROVEXY keeps the bill
at Google's free monthly allowance unless the client's admin raises a limit. Cloud workspaces on rovexy.app follow the
same steps with their own Google account.

## 1. Google Cloud account (client)

1. Sign in at **console.cloud.google.com** with the client's company Google account → **Select a project → New project** (e.g. `rovexy-<client>`).
2. **Billing → Link a billing account** (card or invoice). Google needs it even for free use.
3. **APIs & Services → Library**: enable **Maps JavaScript API**, **Routes API** and **Geocoding API**. Nothing else is needed.

## 2. The two keys

ROVEXY uses two keys so that the key on phones can only show a map.

| Key | Where it goes | Restrict it to (Google Cloud → Credentials → the key) |
|---|---|---|
| **Server key** | Console → Integrations → Google Maps → *API key* | API restrictions: **Routes API, Geocoding API**. Application restriction: IP address of the server if it has a fixed public IP, else none |
| **Map display key** | Console → Integrations → Google Maps → *Map display key* | API restrictions: **Maps JavaScript API** only. Application restriction: **Websites** = `https://<the client's ROVEXY address>/*` |

Press **Save & test**. Without a display key, phones show "Map not set up" (distances and the list still work).

## 3. Daily quotas (safety net in Google)

Google Cloud → APIs & Services → each API → **Quotas**: set a per-day cap. For about 50 field users:

| API | Daily cap |
|---|---|
| Geocoding | 3,000 (20,000 on the day of the first big upload) |
| Routes – Compute Routes | 2,000 |
| Routes – Route Matrix | 2,000 elements |
| Maps JavaScript | 2,500 map loads |

Also **Billing → Budgets & alerts**: a budget of ₹1,000 a month with an e-mail alert at 50 % and 90 %.

## 4. ROVEXY's own limits (Integrations → Google Maps usage)

ROVEXY counts every request it sends, per month, before sending it. The default limits are Google's free allowance:

| Service | Used for | Default monthly limit |
|---|---|---|
| Address → pin (Geocoding) | Pins after uploads; the same address is never looked up twice | 70,000 |
| Road distances (Route Matrix) | Find nearest / Refresh on the phone (5 customers per tap) | 70,000 elements |
| Routes on the phone (Compute Routes) | Tapping a customer, multi-stop route | 70,000 |
| Day plans, two-wheeler | Optimise route on a plan (one per FOS per day) | 7,000 |

When a limit is reached ROVEXY stops asking Google: straight-line distances on the phone, new pins wait for next month
(or until the admin raises the limit), plans keep the straight-line order. Raising a limit above the free allowance means
Google bills the client's account for the extra.

Not counted by ROVEXY: map loads on the phone (Maps JavaScript; normally far below the free allowance) and anything else
using the same Google account. Google's own numbers (Billing → Reports) are the final word.

## 5. Check

- Integrations → Google Maps shows **working** after Save & test.
- Upload a small portfolio with addresses: the Portfolios page shows "Map pins: X of Y placed".
- A phone opens Field map and shows the Google map; Find nearest shows road distances.
- Next day: Integrations → Google Maps usage shows the counts; Google Cloud → Billing → Reports shows ₹0 for the month.
