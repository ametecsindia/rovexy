<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $mode === 'forgot' ? 'Forgot password' : 'Set your password' }} — ROVEXY</title>
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
<style>
  @font-face { font-family: M; font-weight: 500; src: url(/brand/fonts/montserrat-latin-500-normal.woff2) format("woff2"); }
  @font-face { font-family: M; font-weight: 700; src: url(/brand/fonts/montserrat-latin-700-normal.woff2) format("woff2"); }
  @font-face { font-family: M; font-weight: 800; src: url(/brand/fonts/montserrat-latin-800-normal.woff2) format("woff2"); }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; grid-template-columns: 46% 1fr; font-family: M, "Segoe UI", system-ui, sans-serif; color: #072654; background: #fff; }
  .brand { background: #072654; color: #fff; padding: 44px 56px; display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; }
  .brand .wm { position: absolute; right: -120px; bottom: -90px; width: 520px; opacity: .07; filter: brightness(0) invert(1); }
  .brand img.lg { width: 210px; position: relative; }
  .brand h2 { font-size: 2rem; line-height: 1.15; font-weight: 800; margin: 0 0 12px; position: relative; } .brand h2 span { color: #E0B84D; }
  .brand p { color: #c9d4e3; max-width: 380px; line-height: 1.6; position: relative; margin: 0; }
  .brand small { color: #8195b2; position: relative; }
  main { display: grid; place-items: center; padding: 32px 20px; }
  .in { width: min(380px, 100%); }
  .tag { font-size: .7rem; font-weight: 700; letter-spacing: .18em; text-transform: uppercase; color: #C89B2D; margin-bottom: 10px; }
  h1 { margin: 0 0 6px; font-size: 1.5rem; font-weight: 800; }
  .sub { margin: 0 0 22px; color: #5d6b79; font-size: .9rem; line-height: 1.5; }
  label { display: block; font-size: .78rem; font-weight: 700; color: #34465a; margin: 0 0 6px; }
  input { width: 100%; height: 46px; border: 1.5px solid #d6dde5; border-radius: 10px; padding: 0 14px; font: 500 .92rem M, system-ui; color: #072654; margin-bottom: 14px; }
  input:focus { outline: none; border-color: #0A2F6B; box-shadow: 0 0 0 4px rgba(10,47,107,.1); }
  button, .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 48px; border: 0; border-radius: 10px; background: #0A2F6B; color: #fff; font: 700 .94rem M, system-ui; cursor: pointer; text-decoration: none; }
  .err { background: #fdecea; color: #b42318; border-radius: 10px; padding: 10px 12px; font-size: .86rem; margin-bottom: 14px; }
  .ok { background: #e6f4ec; color: #1e7b4f; border-radius: 10px; padding: 12px 14px; font-size: .88rem; margin-bottom: 16px; line-height: 1.5; }
  .hint { color: #7a8795; font-size: .78rem; margin: -8px 0 14px; }
  .alt { margin-top: 16px; font-size: .82rem; color: #6b7886; text-align: center; } .alt a { color: #0A2F6B; font-weight: 700; }
  .kv { background: #f6f8fb; border-radius: 10px; padding: 10px 12px; font-size: .86rem; margin-bottom: 16px; } .kv b { color: #072654; }
  .logo-sm { display: none; width: 180px; margin-bottom: 24px; }
  @media (max-width: 900px) { body { grid-template-columns: 1fr; } .brand { display: none; } .logo-sm { display: block; } main { place-items: start center; padding-top: 40px; } }
</style>
</head>
<body>
<aside class="brand">
  <img class="wm" src="/brand/rovexy-r.svg" alt="">
  <img class="lg" src="/brand/rovexy-logo-dark.svg" alt="ROVEXY — Your Workforce. In Motion.">
  <div><h2>Your workforce,<br><span>always on record.</span></h2><p>Calls, field visits and company phones for collection teams — managed from one secure console.</p></div>
  <small>© {{ date('Y') }} Ametecs India Pvt. Ltd.</small>
</aside>
<main><div class="in">
  <img class="logo-sm" src="/brand/rovexy-logo.svg" alt="ROVEXY">
@if ($mode === 'done')
  <div class="tag">All set</div>
  <h1>Password saved</h1>
  <p class="sub">You can now sign in to the {{ $company->name }} console with {{ $user->email }} and your new password.</p>
  <a class="btn" href="{{ $console }}">Open the console</a>
@elseif ($mode === 'forgot')
  <div class="tag">Company console</div>
  <h1>Forgot your password?</h1>
  @if ($sent)
    <div class="ok">If <b>{{ request('email') }}</b> has an account in the <b>{{ $workspace }}</b> workspace, we have e-mailed a link to reset the password. It works for 60 minutes. Check your spam folder too.</div>
  @else
    <p class="sub">Enter your workspace and sign-in e-mail. We'll e-mail you a link to choose a new password.</p>
  @endif
  @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
  <form method="post" action="/password-forgot">
    @csrf
    <label for="ws">Workspace</label><input id="ws" name="workspace" value="{{ old('workspace', $workspace) }}" required maxlength="40" pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" autocapitalize="none">
    <p class="hint">The short name in your console address, e.g. <b>delta-collections</b>.</p>
    <label for="em">E-mail</label><input id="em" type="email" name="email" value="{{ old('email', request('email')) }}" required autocomplete="username">
    <button type="submit">E-mail me a reset link</button>
  </form>
@elseif (!$row)
  <div class="tag">Link expired</div>
  <h1>This link no longer works</h1>
  <p class="sub">Password links work once and expire. Ask for a new one below, or ask your ROVEXY contact to send a new set-password e-mail.</p>
  <a class="btn" href="/password-forgot">Get a new link</a>
@else
  <div class="tag">{{ $company->name }}</div>
  <h1>{{ $row->purpose === 'welcome' ? 'Set your password' : 'Choose a new password' }}</h1>
  <p class="sub">{{ $row->purpose === 'welcome' ? 'Welcome to ROVEXY. Choose the password you will use to sign in.' : 'Choose a new password for your account.' }}</p>
  <div class="kv">Workspace <b>{{ $company->slug }}</b> · Sign-in e-mail <b>{{ $user->email }}</b></div>
  @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
  <form method="post" action="/password/{{ $token }}">
    @csrf
    <label for="pw">New password</label><input id="pw" type="password" name="password" required minlength="10" autocomplete="new-password" autofocus>
    <p class="hint">At least 10 characters, with letters and numbers.</p>
    <label for="pw2">New password again</label><input id="pw2" type="password" name="password_confirmation" required minlength="10" autocomplete="new-password">
    <button type="submit">Save password</button>
  </form>
@endif
  <p class="alt">Need help? <a href="/contact">Contact ROVEXY support</a></p>
</div></main>
</body>
</html>
