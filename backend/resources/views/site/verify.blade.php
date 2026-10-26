@extends('site.layout', ['title' => 'Confirm your e-mail — ROVEXY', 'noindex' => true])
@php($buy = ($purpose ?? 'trial') === 'buy')
@section('main')
<section><div class="wrap" style="max-width:520px">
  <h1 style="font-size:2.4rem">Check your e-mail</h1>
  <p>Enter the 6-digit code we sent to <b>{{ $email }}</b> to {{ $buy ? 'continue to payment' : 'create your workspace' }}. The code works for {{ \App\Http\Controllers\Site\SiteController::OTP_MINUTES }} minutes.</p>
  @if(!empty($notice) && empty($error))<div class="ok" role="status" style="background:#e8f6ee;border:1px solid #9ad3b1;color:#1d6b3f;padding:10px 12px;border-radius:8px;margin:12px 0">{{ $notice }}</div>@endif
  @if(!empty($error))<div class="err" role="alert">{{ $error }}</div>@endif
  <form method="post" action="/verify-email" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Checking…'">
    @csrf<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="purpose" value="{{ $purpose ?? 'trial' }}">
    <label>Code<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" autofocus style="font-size:1.6rem;letter-spacing:.3em;max-width:220px" title="6 digits"></label>
    <button class="btn gold" type="submit">{{ $buy ? 'Confirm and go to payment' : 'Create my workspace' }}</button>
  </form>
  <form method="post" action="/verify-email/resend" style="margin-top:18px">
    @csrf<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="purpose" value="{{ $purpose ?? 'trial' }}">
    <button class="btn" type="submit" id="resend" data-wait="{{ \App\Http\Controllers\Site\SiteController::OTP_RESEND_SECONDS }}">Send a new code</button>
  </form>
  <p class="mute" style="margin-top:24px;font-size:.92rem">No e-mail? Check spam, wait a minute and send a new code, or <a href="{{ $buy ? '/buy' : '/#trial' }}">fill in the form again</a>.</p>
</div></section>
<script>
  // Resend is allowed once a minute; the server checks this too.
  (function () { var b = document.getElementById('resend'), s = +b.dataset.wait, t = b.textContent; if (!s) return; b.disabled = true;
    var i = setInterval(function () { s--; b.textContent = s > 0 ? t + ' (' + s + 's)' : t; if (s <= 0) { b.disabled = false; clearInterval(i); } }, 1000); b.textContent = t + ' (' + s + 's)'; })();
</script>
@endsection
