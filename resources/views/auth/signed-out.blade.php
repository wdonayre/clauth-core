@extends('layouts.auth')
@section('title', __('Signed out'))
@section('card')
  <h1>{{ __('You’re signed out') }}</h1>
  <p class="sub">{{ ($everywhere ?? false) ? 'You’re signed out of Clauth and every app. Open apps close their session within 15 minutes.' : 'You can close this window.' }}</p>
  <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Sign in again') }}</a>
@endsection
