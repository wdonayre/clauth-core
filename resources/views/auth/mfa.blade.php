@extends('layouts.auth')
@section('title', '2-step verification')
@section('card')
  <h1>Enter your code</h1>
  <p class="sub">{{ $recovery ? 'Type one of your saved recovery codes.' : 'Open your authenticator app and enter the 6-digit code for Clauth.' }}</p>
  @include('partials.errors')
  <form method="post" action="{{ url('/login/2fa') }}">
    @csrf
    @if($recovery)
      <div class="field"><label for="recovery_code">Recovery code</label>
        <input class="input" id="recovery_code" name="recovery_code" autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus></div>
    @else
      <div class="field"><label for="code">6-digit code</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus></div>
      @if($canTrust)
        <label class="check"><input type="checkbox" name="trust" value="1"> Trust this device for {{ \App\Services\Settings::get('trusted_device_days') }} days</label>
      @endif
    @endif
    <div style="height:12px"></div>
    <button class="btn-auth pri" type="submit">Verify</button>
  </form>
  <div class="divider">or</div>
  <p style="text-align:center;margin:0">
    @if($recovery)<a class="link" href="{{ route('login.mfa') }}">Use my authenticator app</a>
    @else<a class="link" href="{{ route('login.mfa', ['recovery' => 1]) }}">Use a recovery code</a>@endif
  </p>
@endsection
