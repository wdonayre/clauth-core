@extends('layouts.auth')
@section('title', __('Sign out everywhere'))
@section('card')
  <h1>{{ __('Sign out everywhere?') }}</h1>
  @if($user)
    <p class="sub">{{ __('This signs') }} <strong>{{ $user->email }}</strong> {{ __('out of Clauth and every app, on every device. Open apps close their session within 15 minutes.') }}</p>
    <form method="post" action="{{ url('/signout-everywhere') }}">@csrf
      <button class="btn-auth pri" type="submit">{{ __('Sign out everywhere') }}</button>
    </form>
  @else
    <p class="sub">{{ __('You’re not signed in to Clauth in this browser. Apps still open elsewhere close their session within 15 minutes after you sign out from one of them.') }}</p>
    <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Sign in') }}</a>
  @endif
@endsection
