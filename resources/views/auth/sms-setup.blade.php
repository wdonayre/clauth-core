@extends('layouts.auth')
@section('title', __('2-step by text message'))
@section('card')
  <h1>{{ __('Use text messages') }}</h1>
  <p class="sub">{{ __('We\'ll text you a 6-digit code each time you sign in on a new device. An authenticator app is safer, so switch to one later if you can.') }}</p>
  @include('partials.errors')
  @if(! $phone)
    <form method="post" action="{{ url('/login/2fa/sms-setup') }}">@csrf
      <div class="field"><label for="phone">{{ __('Mobile number') }}</label>
        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0917 123 4567" value="{{ old('phone') }}" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Text me a code') }}</button>
    </form>
  @else
    <form method="post" action="{{ url('/login/2fa/sms-setup/confirm') }}">@csrf
      <div class="field"><label for="code">Code sent to {{ \App\Services\Sms::mask($phone) }}</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Verify and continue') }}</button>
    </form>
    <form method="post" action="{{ url('/login/2fa/sms-setup') }}" style="margin-top:12px;text-align:center">@csrf<input type="hidden" name="phone" value="{{ $phone }}"><button class="link" style="background:none;border:0;cursor:pointer">{{ __('Send a new code') }}</button></form>
  @endif
  <p style="text-align:center;margin:16px 0 0"><a class="link" href="{{ route('login.mfa.setup') }}">{{ __('Use an authenticator app instead') }}</a></p>
@endsection
