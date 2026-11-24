@extends('layouts.auth')
@section('title', __('Sign in'))
@section('panel', true)
@section('brand-lead', __('Continue to'))
@section('card')
  <div class="ttl">
    <h1>{{ __('Sign in') }}</h1>
    @if(! $app && empty($theme['brand']))<p class="lede">{{ __('to your Clauth account') }}</p>@endif
    @if($app && ! $app->isFirstParty())<p class="lede small">@include('partials.publisher', ['app' => $app])</p>@endif
  </div>
  @include('partials.errors')
  @if(! empty($socialError))<div class="notice bad" role="alert">{{ $socialError }}</div>@endif
  @if(! empty($policyNotice))<div class="notice info" role="status">{{ $policyNotice }}</div>@endif
  @include('auth._providers', ['query' => ''])
  @if((! empty($google) || count($connections ?? [])) && ($passwordForm ?? true))
    <div class="or" role="separator">{{ __('or with email') }}</div>
  @endif
  @if($passwordForm ?? true)
  <form class="stack" method="post" action="{{ $preview ?? false ? '#' : url('/login') }}" novalidate>
    @csrf
    <div class="field">
      <label for="email">{{ __('Email') }}</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
    </div>
    <div class="field">
      <div class="lblrow"><label for="password">{{ __('Password') }}</label><a href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a></div>
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1" @checked(session()->hasOldInput() ? old('remember') : true)> {{ __('Keep me signed in on this device') }}</label>
    <button class="btn-auth pri" type="submit">{{ __('Sign in') }}</button>
  </form>
  @endif
  @if(! empty($codeSignIn))<div class="links center"><a href="{{ ($preview ?? false) ? '#' : url('/login/code') }}">{{ __('Sign in with a one-time code instead') }}</a></div>@endif
  @if(! empty($signup))
    <p class="muted small" style="text-align:center">{{ __('New here?') }} <a href="{{ ($preview ?? false) ? '#' : url('/signup') }}">{{ __('Create an account') }}</a></p>
  @else
    <p class="muted small" style="text-align:center">{{ __('New here? Your admin sends you an invite.') }}</p>
  @endif
@endsection
