@extends('layouts.auth')
@section('title', __('Sign in with a code'))
@section('card')
  <h1>{{ __('Sign in with a code') }}</h1>
  @include('partials.errors')
  @if(! $sent)
    <p class="sub">{!! $app ? 'Continue to <strong>'.e($app->name).'</strong>. ' : '' !!}We'll send a 6-digit code instead of asking for your password.</p>
    <form method="post" action="{{ url('/login/code') }}">@csrf
      <div class="field"><label for="email">{{ __('Email') }}</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div>
      @if(count($channels) > 1)
        <fieldset class="field" style="border:0;padding:0;margin:0 0 12px"><legend style="margin-bottom:6px">{{ __('Send the code by') }}</legend>
          <label class="check"><input type="radio" name="via" value="email" checked> {{ __('Email') }}</label>
          <label class="check"><input type="radio" name="via" value="sms"> {{ __('Text message (to the phone on your account)') }}</label></fieldset>
      @else<input type="hidden" name="via" value="{{ $channels[0] }}">@endif
      <label class="check"><input type="checkbox" name="remember" value="1" checked> {{ __('Keep me signed in on this device') }}</label>
      <div style="height:12px"></div>
      <button class="btn-auth pri" type="submit">{{ __('Send code') }}</button>
    </form>
  @else
    <p class="sub">If {{ $sent['email'] }} has an account that can use this, we sent a code {{ $sent['via'] === 'sms' ? 'by text to the phone on that account' : 'to '.$sent['email'] }}. It works for 5 minutes.</p>
    <form method="post" action="{{ url('/login/code/verify') }}">@csrf
      <div class="field"><label for="code">{{ __('6-digit code') }}</label>
        <input class="input code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus></div>
      <button class="btn-auth pri" type="submit">{{ __('Sign in') }}</button>
    </form>
    <form method="post" action="{{ url('/login/code/restart') }}" style="text-align:center;margin-top:12px">@csrf<button class="link" style="background:none;border:0;cursor:pointer">{{ __('Use a different email or send a new code') }}</button></form>
  @endif
  <p style="text-align:center;margin:16px 0 0"><a class="link" href="{{ route('login') }}">{{ __('Sign in with a password') }}</a></p>
@endsection
