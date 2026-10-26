@extends('site.layout', ['title' => 'Confirm your e-mail — ROVEXY', 'noindex' => true])
@php
  $buy = ($purpose ?? 'trial') === 'buy';
  // Show the address partly hidden: gu••••@gmail.com
  [$local, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');
  $masked = mb_substr($local, 0, 2) . str_repeat('•', max(2, min(6, mb_strlen($local) - 2))) . '@' . $domain;
  $minutes = \App\Http\Controllers\Site\SiteController::OTP_MINUTES;
  // Real time left on this code and before a resend is allowed (the page is shown again after a wrong code / resend).
  $otp = \Illuminate\Support\Facades\Cache::get("otp:{$token}") ?? [];
  $now = now()->getTimestamp();
  $secondsLeft = max(0, (int) (($otp['expires_at'] ?? $now + $minutes * 60) - $now));
  $resendIn = max(0, (int) (($otp['sent_at'] ?? $now) + \App\Http\Controllers\Site\SiteController::OTP_RESEND_SECONDS - $now));
@endphp
@section('main')
<style>
.otp-wrap{padding:56px 0 80px;background:radial-gradient(900px 420px at 50% -10%,rgba(184,137,59,.12),transparent 70%),var(--paper)}
.otp{max-width:1000px;margin:0 auto;display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);border-radius:28px;overflow:hidden;background:#fff;border:1px solid var(--line);box-shadow:0 50px 100px -50px rgba(14,26,43,.45)}
@media (max-width:860px){.otp{grid-template-columns:1fr}.otp-l{display:none}}
.otp-l{position:relative;overflow:hidden;padding:48px 44px;color:#B9C6D6;background:radial-gradient(500px 360px at 100% 100%,rgba(184,137,59,.3),transparent 65%),linear-gradient(160deg,var(--navy),var(--ink))}
.otp-l > *{position:relative}
.otp-l .rings{position:absolute;right:-160px;bottom:-160px;width:420px;aspect-ratio:1}.otp-l .rings i{position:absolute;inset:0;border-radius:50%;border:1px solid rgba(212,174,106,.2)}
.otp-l .rings i:nth-child(2){inset:15%}.otp-l .rings i:nth-child(3){inset:30%;border:0;background:radial-gradient(circle,rgba(212,174,106,.35),transparent 70%)}
.otp-l .kicker{color:var(--brass-2);font-weight:600;letter-spacing:.02em;margin:0 0 10px}
.otp-l h2{color:#fff;font-size:clamp(1.8rem,3vw,2.3rem);line-height:1.1;margin:0 0 14px;max-width:13ch}
.track{list-style:none;padding:0;margin:30px 0 0}
.track li{position:relative;padding:0 0 24px 46px}.track li:last-child{padding-bottom:0}
.track li b{display:block;color:#fff;font-weight:600}.track li span{font-size:.92rem}
.track li:before{content:"";position:absolute;left:0;top:0;width:30px;height:30px;border-radius:50%;border:1.5px solid rgba(212,174,106,.45);display:grid;place-items:center;font:700 .85rem var(--body);color:var(--brass-2)}
.track li:not(:last-child):after{content:"";position:absolute;left:15px;top:34px;bottom:4px;width:1.5px;background:rgba(212,174,106,.3)}
.track li.done:before{content:"✓";background:var(--brass);border-color:var(--brass);color:var(--ink)}
.track li.on:before{content:"2";border-color:var(--brass-2);box-shadow:0 0 0 6px rgba(212,174,106,.16)}
.track li.next:before{content:"3"}
.otp-l .safe{margin-top:34px;display:flex;gap:10px;align-items:flex-start;font-size:.86rem;padding:14px 16px;border-radius:14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08)}
.otp-r{padding:48px 48px 40px}@media (max-width:640px){.otp-r{padding:32px 22px}}
.otp-r .icon{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#FBF4E6,#F3E4C4);color:var(--brass);margin-bottom:18px}
.otp-r h1{font-size:clamp(1.7rem,3vw,2.1rem);margin:0 0 8px;color:var(--ink)}
.otp-r .lead{color:var(--mute);margin:0 0 24px;line-height:1.55}.otp-r .lead b{color:var(--ink)}
.boxes{display:flex;gap:10px;margin:6px 0 10px}
.boxes input{width:56px;height:64px;border-radius:14px;border:1.5px solid var(--line);background:var(--paper);text-align:center;font:700 1.6rem var(--display);color:var(--ink);transition:border-color .15s,box-shadow .15s,background .15s;caret-color:var(--brass)}
.boxes input:focus{outline:0;border-color:var(--brass);background:#fff;box-shadow:0 0 0 4px rgba(184,137,59,.18)}
.boxes input.filled{background:#fff;border-color:var(--navy-2)}
.boxes.bad input{border-color:#E08A6A;background:#FDF3EF}
.boxes .gap{width:10px}
@media (max-width:420px){.boxes{gap:6px}.boxes input{width:44px;height:54px;font-size:1.35rem}.boxes .gap{width:4px}}
.expiry{display:flex;align-items:center;gap:8px;font-size:.88rem;color:var(--mute);margin:0 0 22px}
.expiry .dot{width:8px;height:8px;border-radius:50%;background:var(--ok)}.expiry.low .dot{background:var(--late)}.expiry.low{color:var(--late)}
.otp-r .go{width:100%;justify-content:center;padding:1em;font-size:1rem}
.otp-r .msg{border-radius:12px;padding:12px 14px;margin:0 0 18px;font-size:.93rem}
.otp-r .msg.ok{background:#E8F6EE;color:#1D6B3F}.otp-r .msg.bad{background:#FDEDE7;color:#9A2F0A}
.devcode{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px dashed #E3C27F;background:#FFF8EA;color:#6B4A00;border-radius:12px;padding:10px 14px;margin:0 0 18px;font-size:.88rem}
.devcode b{letter-spacing:.25em;font-size:1rem}.devcode button{border:0;background:var(--brass);color:var(--ink);font:600 .82rem var(--body);border-radius:8px;padding:.45em .8em;cursor:pointer;white-space:nowrap;flex:none}
.more{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-top:22px;padding-top:20px;border-top:1px solid var(--line);font-size:.9rem;color:var(--mute)}
.more form{margin:0}.more button{border:0;background:none;color:var(--navy);font:600 .9rem var(--body);cursor:pointer;padding:0;text-decoration:underline;text-underline-offset:3px}
.more button[disabled]{color:var(--mute);text-decoration:none;cursor:default}
.more a{color:var(--navy);font-weight:600}
</style>
<section class="otp-wrap"><div class="wrap">
  <div class="otp">
    <aside class="otp-l" aria-hidden="true">
      <i class="rings"><i></i><i></i><i></i></i>
      <p class="kicker">{{ $buy ? 'Your ROVEXY order' : 'Your free trial' }}</p>
      <h2>{{ $buy ? 'One step before payment' : 'Almost there' }}</h2>
      <p>We check the e-mail first, so only you can open this {{ $buy ? 'order' : 'workspace' }}.</p>
      <ol class="track">
        <li class="done"><b>Your details</b><span>Company, contact and password saved.</span></li>
        <li class="on"><b>Confirm your e-mail</b><span>Enter the 6-digit code.</span></li>
        <li class="next"><b>{{ $buy ? 'Pay and activate' : 'Workspace ready' }}</b><span>{{ $buy ? 'Secure payment, then your console opens.' : 'Sign in and add your team.' }}</span></li>
      </ol>
      <p class="safe"><span>🔒</span><span>The code works once and expires in {{ $minutes }} minutes. Ametecs will never ask you for it on a call.</span></p>
    </aside>

    <div class="otp-r">
      <div class="icon"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/></svg></div>
      <h1>Check your e-mail</h1>
      <p class="lead">We sent a 6-digit code to <b>{{ $masked }}</b>. Enter it below to {{ $buy ? 'continue to payment' : 'create your workspace' }}.</p>

      @if(!empty($error))<div class="msg bad" role="alert">{{ $error }}</div>
      @elseif(!empty($notice) && str_contains($notice, 'new code'))<div class="msg ok" role="status">{{ $notice }}</div>@endif
      @if(!empty($dev_code))
        <div class="devcode" role="status"><span>Test server (no e-mail set up) — your code: <b>{{ $dev_code }}</b></span><button type="button" data-fill="{{ $dev_code }}">Fill in</button></div>
      @endif

      <form method="post" action="/verify-email" id="otp-form" autocomplete="off">
        @csrf<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="purpose" value="{{ $purpose ?? 'trial' }}">
        <input type="hidden" name="code" id="otp-code">
        <label class="sr" for="d0" style="position:absolute;left:-9999px">6-digit code</label>
        <div class="boxes{{ !empty($error) && str_contains($error, 'not right') ? ' bad' : '' }}" id="boxes">
          @for($i = 0; $i < 6; $i++)
            @if($i === 3)<span class="gap"></span>@endif
            <input id="d{{ $i }}" inputmode="numeric" pattern="\d" maxlength="1" aria-label="Digit {{ $i + 1 }}" {{ $i === 0 ? 'autofocus autocomplete=one-time-code' : '' }}>
          @endfor
        </div>
        <noscript><input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required style="font-size:1.4rem;letter-spacing:.3em;max-width:220px;margin:8px 0"></noscript>
        <p class="expiry" id="expiry"><span class="dot"></span><span id="expiry-t">Code valid for {{ $minutes }} minutes</span></p>
        <button class="btn gold go" type="submit" id="go" disabled>{{ $buy ? 'Confirm and continue to payment' : 'Create my workspace' }} →</button>
      </form>

      <div class="more">
        <form method="post" action="/verify-email/resend">
          @csrf<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="purpose" value="{{ $purpose ?? 'trial' }}">
          <span>Didn't get it? Check spam, or </span><button type="submit" id="resend" data-wait="{{ $resendIn }}">send a new code</button>
        </form>
        <a href="{{ $buy ? '/buy' : '/#trial' }}">Use a different e-mail</a>
      </div>
    </div>
  </div>
</div></section>
<script>
(function () {
  var boxes = [].slice.call(document.querySelectorAll('#boxes input')), code = document.getElementById('otp-code'),
      go = document.getElementById('go'), form = document.getElementById('otp-form');
  function sync() {
    var v = boxes.map(function (b) { return b.value; }).join('');
    code.value = v; go.disabled = v.length !== 6;
    boxes.forEach(function (b) { b.classList.toggle('filled', !!b.value); });
    return v;
  }
  function fill(s, from) {
    s = (s || '').replace(/\D/g, '').slice(0, 6 - (from || 0));
    for (var i = 0; i < s.length; i++) boxes[(from || 0) + i].value = s[i];
    var next = Math.min((from || 0) + s.length, 5); boxes[next].focus();
    if (sync().length === 6) form.requestSubmit ? form.requestSubmit() : form.submit();
  }
  boxes.forEach(function (b, i) {
    b.addEventListener('input', function () {
      document.getElementById('boxes').classList.remove('bad');
      if (b.value.length > 1) { var s = b.value; b.value = ''; return fill(s, i); }   // typed fast / autofill
      b.value = b.value.replace(/\D/g, '');
      if (b.value && i < 5) boxes[i + 1].focus();
      if (sync().length === 6) form.requestSubmit ? form.requestSubmit() : form.submit();
    });
    b.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !b.value && i > 0) { boxes[i - 1].value = ''; boxes[i - 1].focus(); sync(); e.preventDefault(); }
      if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
      if (e.key === 'ArrowRight' && i < 5) boxes[i + 1].focus();
    });
    b.addEventListener('paste', function (e) { e.preventDefault(); fill((e.clipboardData || window.clipboardData).getData('text'), i); });
    b.addEventListener('focus', function () { b.select(); });
  });
  form.addEventListener('submit', function () { go.disabled = true; go.textContent = 'Checking…'; });
  var dev = document.querySelector('[data-fill]'); if (dev) dev.addEventListener('click', function () { fill(dev.dataset.fill, 0); });

  // Resend: once a minute (the server checks too).
  var r = document.getElementById('resend'), w = +r.dataset.wait, label = r.textContent;
  if (w) { r.disabled = true; (function tick() { r.textContent = w > 0 ? label + ' in ' + w + 's' : label; if (w-- > 0) setTimeout(tick, 1000); else r.disabled = false; })(); }

  // Expiry countdown, from when this page was shown.
  var left = {{ $secondsLeft }}, ex = document.getElementById('expiry'), et = document.getElementById('expiry-t');
  (function count() {
    var m = Math.floor(left / 60), s = left % 60;
    et.textContent = left > 0 ? 'Code expires in ' + m + ':' + (s < 10 ? '0' : '') + s : 'Code expired — send a new code';
    ex.classList.toggle('low', left < 120);
    if (left-- > 0) setTimeout(count, 1000);
  })();
})();
</script>
@endsection
