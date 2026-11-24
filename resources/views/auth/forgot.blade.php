@extends('layouts.auth')
@section('title', __('Reset password'))
@section('card')
  <h1>{{ __('Reset your password') }}</h1>
  <p class="sub">{{ __('Enter your email and we’ll send you a link.') }}</p>
  @include('partials.errors')
  <form method="post" action="{{ route('password.email') }}">
    @csrf
    <div class="field"><label for="email">{{ __('Email') }}</label>
      <input class="input" id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus></div>
    <button class="btn-auth pri" type="submit">{{ __('Send reset link') }}</button>
  </form>
  <p style="text-align:center;margin:20px 0 0"><a class="link" href="{{ route('login') }}">{{ __('Back to sign in') }}</a></p>
@endsection
