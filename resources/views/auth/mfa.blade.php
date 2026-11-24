@extends('layouts.auth')
@section('title', __('2-step verification'))
@section('card')
  <h1>{{ __('Enter your code') }}</h1>
  <p class="sub">{{ $recovery ? 'Type one of your saved recovery codes.' : ($smsSent ? 'Enter the 6-digit code we texted to '.$phone.'.' : ($totp ? 'Open your authenticator app and enter the 6-digit code for Clauth.' : 'We’ll text a 6-digit code to '.$phone.'.')) }}</p>
  @include('partials.errors')
  @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
  @if($sms && ! $recovery && ! $totp && ! $smsSent)
    <form method="post" action="{{ url('/login/2fa/sms') }}">@csrf<button class="btn-auth pri" type="submit">{{ __('Text me a code') }}</button></form>
  @else
  <form method="post" action="{{ url('/login/2fa') }}">
    @csrf
    @if($recovery)
      <div class="field"><label for="recovery_code">{{ __('Recovery code') }}</label>
        <input class="input" id="recovery_code" name="recovery_code" autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus></div>
    @else
      <div class="field"><label for="code">{{ __('6-digit code') }}</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus></div>
      @if($canTrust)
        <label class="check"><input type="checkbox" name="trust" value="1"> Trust this device for {{ \App\Services\Settings::get('trusted_device_days') }} days</label>
      @endif
    @endif
    <div style="height:12px"></div>
    <button class="btn-auth pri" type="submit">{{ __('Verify') }}</button>
  </form>
  @endif
  @if($sms && $totp && ! $smsSent && ! $recovery)
    <form method="post" action="{{ url('/login/2fa/sms') }}" style="text-align:center;margin-top:12px">@csrf<button class="link" style="background:none;border:0;cursor:pointer">Text me a code to {{ $phone }} instead</button></form>
  @endif
  @if($smsSent)
    <form method="post" action="{{ url('/login/2fa/sms') }}" style="text-align:center;margin-top:12px">@csrf<button class="link" style="background:none;border:0;cursor:pointer">{{ __('Send a new code') }}</button></form>
  @endif
  @if($totp)<div class="divider">{{ __('or') }}</div>
  <p style="text-align:center;margin:0">
    @if($recovery)<a class="link" href="{{ route('login.mfa') }}">{{ __('Use my authenticator app') }}</a>
    @else<a class="link" href="{{ route('login.mfa', ['recovery' => 1]) }}">{{ __('Use a recovery code') }}</a>@endif
  </p>@endif
  <details style="margin-top:14px;font-size:13px;color:var(--muted)">
    <summary style="cursor:pointer;text-align:center">{{ __('Lost your phone?') }}</summary>
    <ol style="margin:10px 0 0;padding-left:20px;line-height:1.5">
      <li>{{ __('Use one of the recovery codes you saved when you set up 2-step (choose') }} <b>{{ __('Use a recovery code') }}</b> {{ __('above). Then link your new phone in') }} <b>{{ __('My account') }}</b>.</li>
      <li>{{ __('No codes? Ask your organization’s admin to') }} <b>{{ __('reset your 2-step verification') }}</b>{{ __('. They’ll confirm it’s really you — by a call, video or in person — before they do. Next time you sign in you scan a new QR code.') }}</li>
    </ol>
  </details>
@endsection
