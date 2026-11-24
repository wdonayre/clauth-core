@extends('layouts.auth')
@section('title', __('Approve this request'))
@section('card')
  <h1>{{ __('Approve this request') }}</h1>
  <p class="sub"><strong>{{ $app->name }}</strong> {{ __('asks you to approve:') }}</p>
  @foreach($items as $it)
    <div class="notice" style="margin-bottom:10px"><strong>{{ $it['title'] }}</strong>
      <dl style="margin:8px 0 0;display:grid;grid-template-columns:max-content 1fr;gap:4px 12px;font-size:14px">
        @foreach($it['lines'] as [$k, $v])<dt style="color:var(--muted)">{{ $k }}</dt><dd style="margin:0;word-break:break-word">{{ $v }}</dd>@endforeach
      </dl></div>
  @endforeach
  <p class="sub" style="font-size:14px">{{ __('Only approve if this is what you want to do right now.') }}</p>
  <form method="post" action="{{ url('/oauth/details') }}">@csrf
    <button class="btn-auth pri" type="submit" name="decision" value="approve">{{ __('Approve') }}</button>
    <div style="height:8px"></div>
    <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('Refuse') }}</button>
  </form>
  <p class="small muted" style="margin:14px 0 0">{{ $user->email }}</p>
@endsection
