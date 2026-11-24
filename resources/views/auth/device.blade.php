@extends('layouts.auth')
@section('title', __('Sign in a device'))
@section('card')
  <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'monitor', 'iconSize' => 22])</span>
  @if($client && ! $bad)
    <div class="ttl">
      <h1>{{ __('Sign in a device') }}</h1>
      <p class="lede">{{ __(':app is asking to sign in as :name (:email) on a device.', ['app' => $client->app->name, 'name' => $user->name, 'email' => $user->email]) }}</p>
    </div>
    @include('partials.errors')
    <div class="notice">{{ __('Only continue if you started this on a TV, terminal or computer in front of you and it shows the code') }} <strong class="devcode">{{ substr($code, 0, 4) }}-{{ substr($code, 4) }}</strong>.</div>
    <form method="post" action="{{ url('/device') }}" class="stack">@csrf
      <input type="hidden" name="user_code" value="{{ $code }}">
      <button class="btn-auth pri" type="submit" name="decision" value="approve">{{ __('Yes, sign in') }}</button>
      <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('No, it wasn’t me') }}</button>
    </form>
  @else
    <div class="ttl">
      <h1>{{ __('Sign in a device') }}</h1>
      <p class="lede">{{ __('Enter the code shown on your device.') }}</p>
    </div>
    @include('partials.errors')
    @if($bad)<div class="notice bad" role="alert">{{ __('That code isn’t valid or has expired. Check the screen of your device.') }}</div>@endif
    <form method="get" action="{{ url('/device') }}" class="stack">
      <div class="field"><label for="user_code">{{ __('Code') }}</label>
        <input class="input code" id="user_code" name="user_code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH" maxlength="9" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Continue') }}</button>
    </form>
  @endif
@endsection
