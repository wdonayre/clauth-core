@extends('layouts.auth')
@section('title', __('Approve this request'))
@section('card')
  <div class="ttl">
    <h1>{{ __('Approve this request') }}</h1>
    <p class="lede"><strong>{{ $app->name }}</strong> {{ __('asks you to approve:') }}</p>
  </div>
  @foreach($items as $it)
    <div class="req"><strong>{{ $it['title'] }}</strong>
      <dl class="kv">
        @foreach($it['lines'] as [$k, $v])<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endforeach
      </dl></div>
  @endforeach
  <p class="muted small">{{ __('Only approve if this is what you want to do right now.') }}</p>
  <form method="post" action="{{ url('/oauth/details') }}" class="btn-row">@csrf
    <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('Refuse') }}</button>
    <button class="btn-auth pri" type="submit" name="decision" value="approve">{{ __('Approve') }}</button>
  </form>
@endsection
@section('below'){{ $user->email }}@endsection
