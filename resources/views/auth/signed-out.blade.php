@extends('layouts.auth')
@section('title', __('Signed out'))
@section('card-class', 'center')
@section('card')
  <span class="sico round ok" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 24])</span>
  <div class="ttl">
    <h1>{{ __('You’re signed out') }}</h1>
    <p class="lede">{{ ($everywhere ?? false) ? __('You’re signed out of Clauth and every app. Open apps close their session within 15 minutes.') : __('You can close this window.') }}</p>
  </div>
  <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Sign in again') }}</a>
@endsection
