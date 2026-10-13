@extends('site.layout', ['title' => 'Confirm your e-mail — ROVEXY', 'noindex' => true])
@section('main')
<section><div class="wrap" style="max-width:520px">
  <h1 style="font-size:2.4rem">Check your e-mail</h1>
  <p>We sent a 6-digit code to <b>{{ $email }}</b>. Enter it to create your workspace. The code works for 15 minutes.</p>
  @if(!empty($error))<div class="err" role="alert">{{ $error }}</div>@endif
  <form method="post" action="/trial/verify">
    @csrf<input type="hidden" name="token" value="{{ $token }}">
    <label>Code<input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" autofocus style="font-size:1.6rem;letter-spacing:.3em;max-width:220px"></label>
    <button class="btn gold" type="submit">Create my workspace</button>
  </form>
  <p class="mute" style="margin-top:24px;font-size:.92rem">No e-mail? Check spam, or <a href="/#trial">fill in the form again</a>.</p>
</div></section>
@endsection
