@extends('layouts.auth')
@section('title', __('2-step verification'))
@section('no-brand', true)
@section('card')
  <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => $recovery ? 'key' : 'phone', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ $recovery ? __('Use a recovery code') : __('Enter your code') }}</h1>
    <p class="lede">{{ $recovery ? __('Type one of your saved recovery codes.') : ($smsSent ? __('Enter the 6-digit code we texted to :phone.', ['phone' => $phone]) : ($totp ? __('Open your authenticator app and type the 6-digit code for Clauth.') : __('We’ll text a 6-digit code to :phone.', ['phone' => $phone]))) }}</p>
  </div>
  @include('partials.errors')
  @if($sms && ! $recovery && ! $totp && ! $smsSent)
    <form method="post" action="{{ url('/login/2fa/sms') }}">@csrf<button class="btn-auth pri" type="submit">{{ __('Text me a code') }}</button></form>
  @else
  <form class="stack" method="post" action="{{ url('/login/2fa') }}">
    @csrf
    @if($recovery)
      <div class="field"><label for="recovery_code">{{ __('Recovery code') }}</label>
        <input class="input" id="recovery_code" name="recovery_code" autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus></div>
    @else
      <div class="field"><label for="code" id="code-l" class="sr-only">{{ __('6-digit code') }}</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus
          data-otp="6" data-otp-for="code-l" data-otp-focus data-otp-label="{{ __('6-digit code') }}" data-otp-digit="{{ __('Digit') }}"></div>
      @if($canTrust)
        <label class="check"><input type="checkbox" name="trust" value="1"> {{ __('Trust this device for :n days', ['n' => \App\Services\Settings::get('trusted_device_days')]) }}</label>
      @endif
    @endif
    <button class="btn-auth pri" type="submit">{{ __('Verify') }}</button>
  </form>
  @endif
  <div class="links">
    @if($totp)
      @if($recovery)<a href="{{ route('login.mfa') }}">{{ __('Use my authenticator app') }}</a>
      @else<a href="{{ route('login.mfa', ['recovery' => 1]) }}">{{ __('Use a recovery code') }}</a>@endif
    @endif
    @if($sms && $totp && ! $smsSent && ! $recovery)
      <form method="post" action="{{ url('/login/2fa/sms') }}">@csrf<button class="linkbtn" type="submit">{{ __('Text me a code to :phone instead', ['phone' => $phone]) }}</button></form>
    @endif
    @if($smsSent)
      <form method="post" action="{{ url('/login/2fa/sms') }}">@csrf<button class="linkbtn" type="submit">{{ __('Send a new code') }}</button></form>
    @endif
    <details class="lost">
      <summary>{{ __('Lost your phone?') }}</summary>
      <ol>
        <li>{{ __('Use one of the recovery codes you saved when you set up 2-step (choose') }} <b>{{ __('Use a recovery code') }}</b> {{ __('above). Then link your new phone in') }} <b>{{ __('My account') }}</b>.</li>
        <li>{{ __('No codes? Ask your organization’s admin to') }} <b>{{ __('reset your 2-step verification') }}</b>{{ __('. They’ll confirm it’s really you — by a call, video or in person — before they do. Next time you sign in you scan a new QR code.') }}</li>
      </ol>
    </details>
  </div>
@endsection
@section('below')
  <form method="post" action="{{ url('/logout') }}">@csrf {{ __('Signing in as :email', ['email' => $user->email]) }} · <button class="linkbtn small" type="submit">{{ __('Not you?') }}</button></form>
@endsection
