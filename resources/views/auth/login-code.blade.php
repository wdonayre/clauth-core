@extends('layouts.auth')
@section('title', __('Sign in with a code'))
@section('brand-lead', __('Continue to'))
@section('card')
  @if(! $sent)
    <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'mail', 'iconSize' => 22])</span>
    <div class="ttl">
      <h1>{{ __('Sign in with a code') }}</h1>
      <p class="lede">{{ __('We’ll send a 6-digit code instead of asking for your password.') }}</p>
    </div>
    @include('partials.errors')
    <form class="stack" method="post" action="{{ url('/login/code') }}">@csrf
      <div class="field"><label for="email">{{ __('Email') }}</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div>
      @if(count($channels) > 1)
        <fieldset class="field"><legend>{{ __('Send the code by') }}</legend>
          <div class="opts">
            <label class="opt"><input type="radio" name="via" value="email" checked> {{ __('Email') }}</label>
            <label class="opt"><input type="radio" name="via" value="sms"> {{ __('Text message (to the phone on your account)') }}</label>
          </div></fieldset>
      @else<input type="hidden" name="via" value="{{ $channels[0] }}">@endif
      <label class="check"><input type="checkbox" name="remember" value="1" checked> {{ __('Keep me signed in on this device') }}</label>
      <button class="btn-auth pri" type="submit">{{ __('Send code') }}</button>
    </form>
    <div class="links center"><a href="{{ route('login') }}">{{ __('Use my password') }}</a></div>
  @else
    <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => $sent['via'] === 'sms' ? 'phone' : 'mail', 'iconSize' => 22])</span>
    <div class="ttl">
      <h1>{{ $sent['via'] === 'sms' ? __('Check your phone') : __('Check your email') }}</h1>
      <p class="lede">{{ $sent['via'] === 'sms'
        ? __('If :email has an account that can use this, we sent a code by text to the phone on that account. It works for 5 minutes.', ['email' => $sent['email']])
        : __('If :email has an account that can use this, we sent a 6-digit code to it. It works for 5 minutes.', ['email' => $sent['email']]) }}</p>
    </div>
    @include('partials.errors')
    <form class="stack" method="post" action="{{ url('/login/code/verify') }}">@csrf
      <div class="field"><label for="code">{{ __('Code') }}</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" placeholder="123456" autocomplete="one-time-code" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Sign in') }}</button>
    </form>
    <div class="links">
      <form method="post" action="{{ url('/login/code/restart') }}">@csrf<button class="linkbtn" type="submit">{{ __('Send a new code') }}</button></form>
      <a href="{{ route('login') }}">{{ __('Use my password') }}</a>
    </div>
  @endif
@endsection
@if($sent)
@section('below'){{ __('Can’t find it? Check spam, or ask your admin.') }}@endsection
@endif
