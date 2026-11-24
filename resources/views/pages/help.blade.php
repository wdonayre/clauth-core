@extends('layouts.auth')
@section('title', __('Help'))
@section('card')
  <div class="ttl">
    <h1>{{ __('Help') }}</h1>
    <p class="lede">{{ __('Quick answers for signing in.') }}</p>
  </div>
  <div class="qa">
    <div><b>{{ __('Forgot your password?') }}</b><p>{{ __('Use “Forgot password?” on the sign-in page.') }}</p></div>
    <div><b>{{ __('Lost your phone?') }}</b><p>{{ __('Sign in with one of your recovery codes, or ask your admin to reset your 2-step verification.') }}</p></div>
    <div><b>{{ __('No access to an app?') }}</b><p>{{ __('Your admin gives access per app. Contact :email.', ['email' => \App\Services\Settings::get('support_email')]) }}</p></div>
    <div><b>{{ __('Something looks wrong?') }}</b><p><a href="{{ route('report') }}">{{ __('Report this page') }}</a></p></div>
  </div>
  <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Back to sign in') }}</a>
@endsection
