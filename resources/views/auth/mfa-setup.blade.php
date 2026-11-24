@extends('layouts.auth')
@section('title', __('Set up 2-step verification'))
@section('card')
  <div class="ttl">
    <span class="eyebrow">{{ $invite ? __('Step 2 of 3') : __('Step 1 of 2') }}</span>
    <h1>{{ __('Protect your account') }}</h1>
    <p class="lede">{{ $canSkip ? __('Recommended, not required — you can skip this and turn it on later in My account.') : __('Your account needs a second step when you sign in.') }}</p>
  </div>
  @include('partials.errors')
  <div class="num-step"><span class="num" aria-hidden="true">1</span><div><b>{{ __('Scan with an authenticator app') }}</b><div class="muted small">{{ __('Google Authenticator, Microsoft Authenticator, 1Password…') }}</div></div></div>
  <div class="qr" role="img" aria-label="{{ __('QR code for your authenticator app') }}">{!! $qr !!}</div>
  <details class="key">
    <summary>{{ __('Can’t scan? Type a key instead') }}</summary>
    <div class="secret" id="secret">{{ trim(chunk_split($secret, 4, ' ')) }}</div>
  </details>
  <form class="stack" method="post" action="{{ url('/login/2fa/setup') }}">
    @csrf
    <div class="num-step"><span class="num" aria-hidden="true">2</span>
      <div class="field"><label for="code"><b>{{ __('Enter the 6-digit code it shows') }}</b></label>
        <input class="input code" id="code" name="code" inputmode="numeric" maxlength="7" placeholder="123 456" autocomplete="one-time-code" required></div></div>
    <button class="btn-auth pri" type="submit">{{ __('Turn on 2-step') }}</button>
  </form>
  @if(! empty($smsSetup) || $canSkip)
  <div class="links center" style="flex-direction:column;gap:0">
    @if(! empty($smsSetup))<a href="{{ route('login.mfa.sms-setup') }}">{{ __('No authenticator app? Use text messages') }}</a>@endif
    @if($canSkip)
      <form method="post" action="{{ route('login.mfa.skip') }}">@csrf<button class="linkbtn" type="submit">{{ __('Skip for now') }}</button></form>
    @endif
  </div>
  @endif
@endsection
