@extends('layouts.auth')
@section('title', __('Create your account'))
@section('brand-lead', __('Create an account for'))
@php($min = (int) \App\Services\Settings::get('password_min_length'))
@section('card')
  @if(! $pending)
    <div class="ttl">
      <h1>{{ __('Create your account') }}</h1>
      <p class="lede">{{ __('You’ll use it to sign in to :app.', ['app' => $app->name]) }}</p>
    </div>
    @include('partials.errors')
    <form class="stack" method="post" action="{{ url('/signup') }}">@csrf
      <div class="field"><label for="name">{{ __('Your name') }}</label><input class="input" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus></div>
      <div class="field"><label for="email">{{ __('Email') }}</label><input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        @if($domains)<span class="hint">{{ __('Open to addresses at :domains.', ['domains' => implode(', ', $domains)]) }}</span>@endif</div>
      <div class="field"><label for="password">{{ __('Choose a password') }}</label>
        <input class="input" id="password" name="password" type="password" autocomplete="new-password" data-meter="#meter" data-min="{{ $min }}" data-min-rule="#rule-min" required>
        <div class="meter" aria-hidden="true"><span id="meter"></span></div>
        <ul class="rules"><li class="rule" id="rule-min">{{ __('At least :n characters', ['n' => $min]) }}</li></ul></div>
      <div class="field"><label for="password_confirmation">{{ __('Type it again') }}</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
      <label class="check top"><input type="checkbox" name="terms" value="1" required> <span>{{ __('I agree to the') }} <a href="{{ route('terms') }}" target="_blank">{{ __('Terms') }}</a> {{ __('and') }} <a href="{{ route('privacy') }}" target="_blank">{{ __('Privacy policy') }}</a></span></label>
      <button class="btn-auth pri" type="submit">{{ __('Continue') }}</button>
    </form>
  @else
    <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'mail', 'iconSize' => 22])</span>
    <div class="ttl">
      <h1>{{ __('Check your email') }}</h1>
      <p class="lede">{{ __('We emailed a 6-digit code to :email. If you already have an account, sign in instead.', ['email' => $pending['email']]) }}</p>
    </div>
    @include('partials.errors')
    <form class="stack" method="post" action="{{ url('/signup/verify') }}">@csrf
      <div class="field"><label for="code">{{ __('Code') }}</label><input class="input code" id="code" name="code" inputmode="numeric" maxlength="7" placeholder="123456" autocomplete="one-time-code" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Create account') }}</button>
    </form>
  @endif
  <div class="links center"><a href="{{ route('login') }}">{{ __('I already have an account') }}</a></div>
@endsection
