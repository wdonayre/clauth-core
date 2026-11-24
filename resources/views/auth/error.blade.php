@extends('layouts.auth')
@section('title', $title)
@section('no-brand', true)
@php($eApp = $theme['app'] ?? null)
@section('card')
  @if($eApp)
    @php($c = $eApp->color ?: '#0B0D12')
    <span class="app-ico {{ \App\Services\Theme::luminance($c) > 0.45 ? 'light' : '' }}" style="background:{{ $c }}" aria-hidden="true">{{ $eApp->letter() }}</span>
  @else
    <span class="sico warn" aria-hidden="true">@include('partials.icon', ['i' => 'info', 'iconSize' => 22])</span>
  @endif
  <div class="ttl">
    <h1>{{ $title }}</h1>
    <p class="lede">{{ $message }}</p>
  </div>
  @auth
    <div class="notice">{{ __('You’re signed in as :email.', ['email' => auth()->user()->email]) }}</div>
    <div class="btn-row wrap">
      <a class="btn-auth pri" href="{{ route('account') }}">{{ __('Go to my apps') }}</a>
      <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Use another account') }}</a>
    </div>
  @else
    <a class="btn-auth pri" href="{{ route('login') }}">{{ __('Go to Clauth sign in') }}</a>
  @endauth
@endsection
