@extends('layouts.auth')
@section('title', __('2-step by text message'))
@section('no-brand', true)
@section('card')
  <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'phone', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ __('Use text messages') }}</h1>
    <p class="lede">{{ __('We\'ll text you a 6-digit code each time you sign in on a new device. An authenticator app is safer, so switch to one later if you can.') }}</p>
  </div>
  @include('partials.errors')
  @if(! $phone)
    <form class="stack" method="post" action="{{ url('/login/2fa/sms-setup') }}">@csrf
      <div class="field"><label for="phone">{{ __('Mobile number') }}</label>
        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0917 123 4567" value="{{ old('phone') }}" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Text me a code') }}</button>
    </form>
  @else
    <form class="stack" method="post" action="{{ url('/login/2fa/sms-setup/confirm') }}">@csrf
      <div class="field"><label for="code">{{ __('Code sent to :phone', ['phone' => \App\Services\Sms::mask($phone)]) }}</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" placeholder="123456" autocomplete="one-time-code" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Verify and continue') }}</button>
    </form>
    <div class="links center"><form method="post" action="{{ url('/login/2fa/sms-setup') }}">@csrf<input type="hidden" name="phone" value="{{ $phone }}"><button class="linkbtn" type="submit">{{ __('Send a new code') }}</button></form></div>
  @endif
  <div class="links center"><a href="{{ route('login.mfa.setup') }}">{{ __('Use an authenticator app instead') }}</a></div>
@endsection
