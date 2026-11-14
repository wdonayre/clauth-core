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
  <details style="margin-top:14px;font-size:13px;color:var(--muted)">
    <summary style="cursor:pointer;text-align:center">Lost your phone?</summary>
    <ol style="margin:10px 0 0;padding-left:20px;line-height:1.5">
      <li>Use one of the recovery codes you saved when you set up 2-step (choose <b>Use a recovery code</b> above). Then link your new phone in <b>My account</b>.</li>
      <li>No codes? Ask your organization’s admin to <b>reset your 2-step verification</b>. They’ll confirm it’s really you — by a call, video or in person — before they do. Next time you sign in you scan a new QR code.</li>
    </ol>
  </details>
@endsection
