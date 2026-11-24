@extends('layouts.auth')
@section('title', __('Create your account'))
@section('card')
  <h1>{{ __('Create your account') }}</h1>
  <p class="sub">{{ __('for') }} <strong>{{ $app->name }}</strong></p>
  @include('partials.errors')
  @if(! $pending)
    <form method="post" action="{{ url('/signup') }}">@csrf
      <div class="field"><label for="name">{{ __('Full name') }}</label><input class="input" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus></div>
      <div class="field"><label for="email">{{ __('Email') }}</label><input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        @if($domains)<span class="hint">Open to addresses at {{ implode(', ', $domains) }}.</span>@endif</div>
      <div class="field"><label for="password">{{ __('Password') }}</label>
        <input class="input" id="password" name="password" type="password" autocomplete="new-password" data-meter="#meter" required>
        <div class="meter"><span id="meter"></span></div>
        <span class="hint">At least {{ \App\Services\Settings::get('password_min_length') }} characters.</span></div>
      <div class="field"><label for="password_confirmation">{{ __('Confirm password') }}</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
      <label class="check"><input type="checkbox" name="terms" value="1" required> <span>{{ __('I agree to the') }} <a class="link" href="{{ route('terms') }}" target="_blank">{{ __('Terms') }}</a> {{ __('and') }} <a class="link" href="{{ route('privacy') }}" target="_blank">{{ __('Privacy policy') }}</a></span></label>
      <div style="height:12px"></div>
      <button class="btn-auth pri" type="submit">{{ __('Continue') }}</button>
    </form>
  @else
    <p class="sub">{{ __('We emailed a 6-digit code to') }} <strong>{{ $pending['email'] }}</strong>{{ __('. If you already have an account, sign in instead.') }}</p>
    <form method="post" action="{{ url('/signup/verify') }}">@csrf
      <div class="field"><label for="code">{{ __('Code') }}</label><input class="input code" id="code" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Create account') }}</button>
    </form>
  @endif
  <p style="text-align:center;margin:16px 0 0"><a class="link" href="{{ route('login') }}">{{ __('I already have an account') }}</a></p>
@endsection
