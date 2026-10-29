<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install the ROVEXY app</title>
<meta name="robots" content="noindex">
<style>
  :root { --navy:#072654; --gold:#C89B2D; --ink:#1b2430; --muted:#5b6675; --bg:#f4f6fa; }
  * { box-sizing:border-box } body { margin:0; font-family:Montserrat,Arial,sans-serif; background:var(--bg); color:var(--ink); }
  header { background:var(--navy); color:#fff; padding:22px 20px; } header b { color:var(--gold); }
  main { max-width:560px; margin:0 auto; padding:20px 16px 40px; }
  .card { background:#fff; border-radius:16px; padding:20px; box-shadow:0 2px 10px rgba(7,38,84,.08); margin-bottom:16px; }
  .btn { display:block; text-align:center; background:var(--gold); color:var(--navy); font-weight:700; font-size:18px; padding:16px; border-radius:12px; text-decoration:none; }
  ol { padding-left:20px; line-height:1.6 } .muted { color:var(--muted); font-size:14px } code { background:#eef2f7; padding:2px 6px; border-radius:6px; }
</style>
</head>
<body>
<header><div style="font-size:22px;font-weight:800">ROVE<b>X</b>Y</div><div style="opacity:.85">Field app for {{ $company }}</div></header>
<main>
@if($apk)
  <div class="card">
    <a class="btn" href="{{ url('/get-app/download') }}">Download the ROVEXY app</a>
    <p class="muted" style="text-align:center;margin:10px 0 0">Version {{ $version }} · {{ $size }} MB · for Android 9 and newer</p>
  </div>
  <div class="card">
    <b>Install it</b>
    <ol>
      <li>If the ROVEXY app from the Play Store is on this phone, <b>uninstall it first</b> (the two can't be installed together).</li>
      <li>Tap <b>Download</b> above, then open the downloaded file.</li>
      <li>Android asks to allow installing from this browser: tap <b>Settings → Allow from this source</b>, go back, tap <b>Install</b>.</li>
      <li>Open ROVEXY and enter this server's address: <code>{{ $server }}</code></li>
      <li>Allow location <b>"All the time"</b> when asked, and turn off battery saving for ROVEXY (Settings → Apps → ROVEXY → Battery → Unrestricted). This keeps your location shared during working hours, also when the screen is off.</li>
    </ol>
  </div>
  <p class="muted">This version shares location all day while you are signed in to your work session, as your company's policy describes. The Play Store version only does this while the app is open on screen.</p>
@else
  <div class="card"><b>The app file is not on this server yet.</b><p class="muted">Install the ROVEXY app from the Google Play Store, or ask your administrator. (Administrator: the file comes with the ROVEXY server package from Ametecs.)</p></div>
@endif
</main>
</body>
</html>
