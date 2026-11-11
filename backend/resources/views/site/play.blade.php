@extends('site.layout', ['title' => 'Get the ' . $appName . ' app — ROVEXY', 'noindex' => true])
@section('main')
<style>
.xd{padding:56px 0 80px;background:radial-gradient(900px 420px at 50% -10%,rgba(184,137,59,.12),transparent 70%),var(--paper)}
.xd-card{max-width:1060px;margin:0 auto;display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);border-radius:28px;overflow:hidden;background:#fff;border:1px solid var(--line);box-shadow:0 50px 100px -50px rgba(14,26,43,.45)}
@media (max-width:860px){.xd-card{grid-template-columns:1fr}}
.xd-l{position:relative;overflow:hidden;padding:48px 44px;color:#B9C6D6;background:radial-gradient(500px 360px at 100% 100%,rgba(184,137,59,.3),transparent 65%),linear-gradient(160deg,var(--navy),var(--ink))}
.xd-l .kicker{color:var(--brass-2);font-weight:600;margin:0 0 10px}
.xd-l h1{color:#fff;font-size:clamp(1.9rem,3.2vw,2.5rem);line-height:1.08;margin:0 0 14px}
.xd-l ul{list-style:none;padding:0;margin:28px 0 0}
.xd-l li{position:relative;padding:0 0 18px 34px}
.xd-l li:before{content:"✓";position:absolute;left:0;top:1px;width:22px;height:22px;border-radius:50%;background:rgba(212,174,106,.18);color:var(--brass-2);display:grid;place-items:center;font-size:.8rem;font-weight:700}
.xd-l li b{color:#fff;display:block;font-weight:600}
.xd-r{padding:44px 44px 40px}
@media (max-width:520px){.xd-l,.xd-r{padding:30px 20px}}
.xd-r h2{margin:0 0 4px;font-size:1.5rem}.xd-r .lead{color:var(--mute);margin:0 0 22px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}@media (max-width:560px){.grid2{grid-template-columns:1fr}}
.btn.wide{width:100%;justify-content:center;margin-top:6px}
.xd-r small.hint{display:block;font-weight:400;color:var(--mute);font-size:.8rem;margin-top:4px}
.busy{display:none;color:var(--mute);font-size:.9rem;margin-top:10px}form.sent .busy{display:block}
</style>
<section class="xd"><div class="wrap">
  <div class="xd-card">
    <aside class="xd-l">
      <p class="kicker">{{ $app === 'admin' ? 'For managers and admins' : 'For your field and calling staff' }}</p>
      <h1>Get the {{ $appName }} app</h1>
      <p>{{ $app === 'admin' ? 'The full company console on your Android phone.' : 'Recorded calls, verified visits and payment slips on company phones.' }}</p>
      <ul>
        <li><b>Tell us about your company</b>Takes under a minute.</li>
        <li><b>Confirm your e-mail</b>We send a 6-digit code.</li>
        <li><b>Install from Google Play</b>The app page opens straight after.</li>
      </ul>
    </aside>
    <div class="xd-r">
      <h2>Your details</h2>
      <p class="lead">Confirm your e-mail with a 6-digit code, then Google Play opens.</p>
      <form method="post" action="/play/{{ $app }}" onsubmit="this.classList.add('sent');this.querySelector('button[type=submit]').disabled=true">
        @csrf<input class="hp" name="hp_x9" tabindex="-1" autocomplete="new-password" aria-hidden="true">
        @if($errors->any())<div class="err" role="alert">{{ $errors->first() }}</div>@endif
        <label>Company name<input name="company" value="{{ old('company') }}" required maxlength="150" autocomplete="organization"></label>
        <div class="grid2">
          <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email"></label>
          <label>How many employees?<input type="number" name="users" value="{{ old('users') }}" required min="1" max="100000" inputmode="numeric"></label>
        </div>
        <label>Purpose
          <select name="purpose" required><option value="">Choose…</option>
            @foreach($purposes as $p)<option @selected(old('purpose') === $p)>{{ $p }}</option>@endforeach
          </select></label>
        <label>Industry
          <select name="industry" required><option value="">Choose your industry…</option>
            @foreach($industries as $i)<option @selected(old('industry') === $i)>{{ $i }}</option>@endforeach
          </select></label>
        <button class="btn gold wide" type="submit">Send my code →</button>
        <p class="busy" role="status">Sending your code…</p>
      </form>
    </div>
  </div>
</div></section>
@endsection
