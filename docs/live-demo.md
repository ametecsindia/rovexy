# ROVEXY live demo (website)

The ROVEXY Android app rebuilt as a web page, so prospects can try it on rovexy.app without installing anything.

- **https://rovexy.app/demo** (with demo controls and "Things to try"). The phone is always laid out at 390 × 844 and scaled as a whole to fit the window.
- Linked from the site menu, the phone menu and the footer (**Live demo**).
- Home page **How it works**: the phone is the real demo app (`/demo?tour=1`, an iframe). Each of the 9 steps is a short scene played in the real screens (finger taps, typing, the call timer, the blue dot walking to Suresh Babu until check-in appears). The step advances when its scene ends. Scenes are `SCENES` at the end of `demo.js`; visitors can't tap inside it, and "Try it yourself →" opens `/demo`.

## Files

| File | What |
|---|---|
| `backend/public/live-demo/index.html` | The page |
| `backend/public/live-demo/demo.js` | The app: every screen of `android/.../ui/*.kt` (Screens, Home, Cases, FieldMap) |
| `backend/public/live-demo/data.js` | Sample data: 16 cases in Hyderabad, outcomes and payment modes copied from `App\Support\Outcomes` |
| `backend/public/live-demo/demo.css` | Styles (app = Design.kt colours, Material 3) |
| `backend/public/live-demo/vendor/` | Preact + htm (13 KB) and Leaflet 1.9.4, served locally (no CDN) |
| `backend/routes/web.php` | `GET /demo` (central server only) |
| `backend/app/Http/Middleware/SecurityHeaders.php` | `/demo` may be framed by our own site (`SAMEORIGIN`), for How it works; everything else stays `DENY` |
| `backend/resources/views/site/home.blade.php` | How it works: live phone instead of the old drawn screens |
| `backend/resources/views/site/layout.blade.php` | "Live demo" link in the menu and footer |
| `backend/tests/Feature/LiveDemoTest.php` | Route, menu link, sitemap |

No build step: edit the files and reload. When you change `demo.js` or `demo.css`, bump `?v=1` in `index.html` so browsers fetch the new copy.

## Run and test

- Laragon: open `http://127.0.0.1:8000/demo` (or your Laragon site) after copying the files.
- Test: `cd backend && vendor/bin/phpunit tests/Feature/LiveDemoTest.php`
- Deploy: nothing extra. `deploy.sh` ships `public/` as is; nginx serves `/live-demo/*` as static files and `/demo` goes through Laravel.

## What works (same rules as the app)

- **Device setup**: Connect / Register with validation, approval code screen (auto-approved after ~9 s or from the demo controls), rejected / revoked screen with "Register again", device-policy screen, offline-lease "Locked" screen.
- **Sign in**: User ID + password (demo password `demo`), show/hide, error messages.
- **Home**: greeting, online/offline + sync pill with waiting events, portfolio progress, Today card, banners (call setup, errors, new message), Customers near me shortcut, 8 priority tiles, search (name, case ID, last 4+ digits), filter sheet with live "Show N cases", removable chips, 12 sorts, empty states, Sync, menu (Messages, What my company can see, call setup, Sign out).
- **Calling**: Call from list, profile or map; primary/alternate number choice; simulated Phone app (calling → ringing → answered with timer, mute/keypad/speaker; switched-off customers never answer); saving; call feedback with all 16 outcomes, promise (amount + date), payment (amount, 8 modes, reference, paid-on date, photo/file slip), notes, follow-up; required-field rules; duplicate-payment check; case status rules from `CallService` (follow-up → "Follow up", first call → "In progress", one open callback per case). Call permissions missing → permission prompt; recording not proven → recording gate and call settings.
- **Case profile**: header metrics, Call / Callback / Payment / Directions, Promise / Note / Status, 7 tabs (Overview with timeline, Details, Loan & dues, Payments, Calls, Follow-ups, Address & map with mini map, address history, update location), offline saved summary, offline queue for notes/status/callbacks.
- **Customers near me**: map with name-chip pins, my position, radius filters, search, nearest-customer card with distance and time, Route, Start navigation (opens Google Maps), Find nearest, Plan a route (up to 10 stops, ordered nearest-next, Navigate all), check-in within 100 m (Suresh Babu is 62 m from the starting position), pins over 300 km flagged, cases without a pin with Set pin, expandable bottom sheet.
- **Supervision**: supervisor chat with read ticks and replies, heads-up notification, screen-share and camera requests with the Android prompt, internet call, red "sharing" banner with STOP / HANG UP, disclosure dialog.

## Simulated or different from the real app

- **No ROVEXY server.** Cases, approvals, calls and saves live only in the page; Reset or reload starts again. Nothing is sent anywhere.
- **Phone app, SIM call and recording** are simulated (a stand-in Phone app screen, marked "simulated").
- **GPS** is a fixed position in Kondapur, Hyderabad; demo controls can walk the phone to a customer.
- **Maps**: OpenStreetMap tiles (Leaflet) instead of Google Maps, no clustering. Road routes come from the public OSRM server when reachable; otherwise distance and time are estimated (straight line × 1.32 at about 25 km/h) and marked "estimated". The app uses Google Routes.
- **Android prompts** (permissions, screen capture, call settings) are stand-ins, labelled "simulated".
- Date and time pickers are the browser's own pickers, not Android's.
- Demo-only items (the yellow dashed hints, the demo controls and "Things to try") are not part of the app.
