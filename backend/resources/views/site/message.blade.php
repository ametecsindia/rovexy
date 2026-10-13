@extends('site.layout', ['title' => $title . ' — ROVEXY'])
@section('main')
<section><div class="wrap" style="max-width:720px">
  <h1 style="font-size:2.4rem">{{ $title }}</h1>
  @foreach($lines as $l)<p>{{ $l }}</p>@endforeach
  @if(!empty($action))<p><a class="btn" href="{{ $action[1] }}">{{ $action[0] }}</a></p>@endif
</div></section>
@endsection
