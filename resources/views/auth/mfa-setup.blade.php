@extends('layouts.auth')
@section('title', 'Set up 2-step verification')
@section('card')
  @if($invite)
    <p class="stepnote">Step 2 of 3</p>
    <ul class="steps" aria-hidden="true"><li class="on"></li><li class="on"></li><li></li></ul>
  @endif
  <h1>Protect your account</h1>
  <p class="sub">Scan this with an authenticator app (Google Authenticator, 1Password, Authy), then enter the 6-digit code it shows.</p>
  @include('partials.errors')
  <div class="qr" role="img" aria-label="QR code for your authenticator app">{!! $qr !!}</div>
  <p class="hint" style="text-align:center;margin:0 0 6px;font-size:13px;color:var(--muted)">Can’t scan? Enter this key:</p>
  <div class="secret">{{ trim(chunk_split($secret, 4, ' ')) }}</div>
  <form method="post" action="{{ url('/login/2fa/setup') }}">
    @csrf
    <div class="field"><label for="code">6-digit code</label>
      <input class="input code" id="code" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required></div>
    <button class="btn-auth pri" type="submit">Turn on 2-step verification</button>
  </form>
@endsection
