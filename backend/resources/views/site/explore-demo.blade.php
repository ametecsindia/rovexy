@extends('site.layout', ['title' => 'Explore the ROVEXY demo', 'noindex' => false])
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
      <p class="kicker">Explore Demo</p>
      <h1>See ROVEXY at work in your city</h1>
      <p>Your own console, already full: a team, customers, two months of calls and collections — set in the city you choose.</p>
      <ul>
        <li><b>10 employees and 100 customers</b>Tele-callers and field agents with their approved phones, customers with local addresses.</li>
        <li><b>Recorded calls and payment slips</b>Listen to calls, check slips, verify payments, follow up promises.</li>
        <li><b>Reports you can download</b>Dashboard, portfolio and employee reports, exports and attendance for the last two months.</li>
        <li><b>Change anything</b>Add people, customers and numbers; it is your demo.</li>
      </ul>
    </aside>
    <div class="xd-r">
      <h2>Open my demo</h2>
      <p class="lead">Confirm your e-mail with a 6-digit code, and the console opens.</p>
      <form method="post" action="/explore-demo" id="xd" onsubmit="this.classList.add('sent');this.querySelector('button[type=submit]').disabled=true">
        @csrf<input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        @if($errors->has('demo'))<div class="err" role="alert">{{ $errors->first('demo') }}</div>@elseif($errors->any())<div class="err" role="alert">{{ $errors->first() }}</div>@endif
        <div class="grid2">
          <label>Your name<input name="name" value="{{ old('name') }}" required maxlength="150" autocomplete="name"></label>
          <label>Business name<input name="business" value="{{ old('business') }}" required maxlength="150" autocomplete="organization"></label>
          <label>Mobile number<input name="mobile" value="{{ old('mobile') }}" required maxlength="20" inputmode="tel" autocomplete="tel" pattern="[+0-9 ()\-]{10,20}" title="10-digit mobile number"></label>
          <label>E-mail<input type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email"></label>
        </div>
        <label>Business address<input name="address" value="{{ old('address') }}" required maxlength="300" autocomplete="street-address"></label>
        <label>City
          <select name="city" required>
            <option value="">Choose your city…</option>
            @foreach($cities as $key => $label)<option value="{{ $key }}" @selected(old('city') === $key)>{{ $label }}</option>@endforeach
          </select>
          <small class="hint">Your demo's employees, customers and addresses are from this city. Not listed? Choose the nearest one.</small>
        </label>
        <div class="grid2">
          <label>Password<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
          <label>Confirm password<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
        </div>
        <label class="check"><input type="checkbox" name="terms" value="1" required> <span>I agree to the <a href="/terms">terms</a> and <a href="/privacy">privacy policy</a></span></label>
        <button class="btn gold wide" type="submit">Send my code →</button>
        <p class="busy" role="status">Sending your code…</p>
      </form>
    </div>
  </div>
</div></section>
@endsection
