@extends('layouts.auth')
@section('title', __('Reset your password'))
@section('no-brand', true)
@section('card')
  <a class="back" href="{{ route('login') }}">← {{ __('Back to sign in') }}</a>
  <div class="ttl">
    <h1>{{ __('Forgot your password?') }}</h1>
    <p class="lede">{{ __('Enter your email and we’ll send you a link to choose a new one.') }}</p>
  </div>
  @include('partials.errors')
  <form class="stack" method="post" action="{{ route('password.email') }}">
    @csrf
    <div class="field"><label for="email">{{ __('Email') }}</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus></div>
    <button class="btn-auth pri" type="submit">{{ __('Send reset link') }}</button>
  </form>
@endsection
