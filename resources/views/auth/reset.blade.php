@extends('layouts.auth')
@section('title', __('Choose a new password'))
@section('no-brand', true)
@php($min = (int) \App\Services\Settings::get('password_min_length'))
@php($forEmail = old('email', $email))
@section('card')
  <div class="ttl">
    <h1>{{ __('Choose a new password') }}</h1>
    @if($forEmail)<p class="lede">{{ __('For :email', ['email' => $forEmail]) }}</p>@endif
  </div>
  @include('partials.errors')
  <form class="stack" method="post" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    @if($forEmail)
      <input type="hidden" name="email" value="{{ $forEmail }}">
    @else
      <div class="field"><label for="email">{{ __('Email') }}</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" required></div>
    @endif
    <div class="field"><label for="password">{{ __('New password') }}</label>
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" data-meter="#meter" data-min="{{ $min }}" data-min-rule="#rule-min" required @if($forEmail) autofocus @endif>
      <div class="meter" aria-hidden="true"><span id="meter"></span></div></div>
    <ul class="rules">
      <li class="rule" id="rule-min">{{ __('At least :n characters', ['n' => $min]) }}</li>
      @if(\App\Services\Settings::get('breached_password_check'))<li class="rule">{{ __('Not found in known data breaches (we check when you save)') }}</li>@endif
    </ul>
    <div class="field"><label for="password_confirmation">{{ __('Type it again') }}</label>
      <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
    <button class="btn-auth pri" type="submit">{{ __('Save password') }}</button>
  </form>
  <p class="muted small">{{ __('You’ll be signed out on your other devices.') }}</p>
@endsection
