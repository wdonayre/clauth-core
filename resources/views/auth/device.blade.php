@extends('layouts.auth')
@section('title', __('Sign in a device'))
@section('card')
  <h1>{{ __('Sign in a device') }}</h1>
  @include('partials.errors')
  @if($client && ! $bad)
    <p class="sub"><strong>{{ $client->app->name }}</strong> {{ __('is asking to sign in as') }} <strong>{{ $user->name }}</strong> ({{ $user->email }}) on a device.</p>
    <div class="notice">{{ __('Only continue if you started this on a TV, terminal or computer in front of you and it shows the code') }} <strong class="mono">{{ substr($code, 0, 4) }}-{{ substr($code, 4) }}</strong>.</div>
    <form method="post" action="{{ url('/device') }}" style="margin-top:14px">@csrf
      <input type="hidden" name="user_code" value="{{ $code }}">
      <button class="btn-auth pri" type="submit" name="decision" value="approve">{{ __('Yes, sign in') }}</button>
      <div style="height:8px"></div>
      <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('No, it wasn’t me') }}</button>
    </form>
  @else
    <p class="sub">{{ __('Enter the code shown on your device.') }}</p>
    @if($bad)<div class="notice bad" role="alert">{{ __('That code isn’t valid or has expired. Check the screen of your device.') }}</div>@endif
    <form method="get" action="{{ url('/device') }}">
      <div class="field"><label for="user_code">{{ __('Code') }}</label>
        <input class="input code" id="user_code" name="user_code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH" maxlength="9" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Continue') }}</button>
    </form>
  @endif
@endsection
