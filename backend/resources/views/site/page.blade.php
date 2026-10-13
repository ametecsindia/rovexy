@extends('site.layout', ['title' => $title . ' — ROVEXY'])
@section('style')
.doc h2{font-size:1.35rem;margin:34px 0 8px}.doc .upd{color:var(--mute);font-size:.9rem}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:28px 0}
.cards div{background:var(--mist);border-radius:16px;padding:20px}.cards b{display:block;font-family:var(--display);margin-bottom:4px}
@endsection
@section('main')
<section><div class="wrap doc" style="max-width:780px">
  <h1 style="font-size:2.6rem">{{ $title }}</h1>
  @if(!empty($updated))<p class="upd">Last updated {{ $updated }}</p>@endif
  @if(!empty($cards))<div class="cards">@foreach($cards as [$h, $v, $href])<div><b>{{ $h }}</b>@if($href)<a href="{{ $href }}">{{ $v }}</a>@else{{ $v }}@endif</div>@endforeach</div>@endif
  @foreach($sections as [$h, $p])@if($h)<h2>{{ $h }}</h2>@endif<p>{{ $p }}</p>@endforeach
  <p style="margin-top:32px"><a class="btn" href="{{ $action[1] ?? url('/') }}">{{ $action[0] ?? 'Back to ROVEXY' }}</a></p>
</div></section>
@endsection
