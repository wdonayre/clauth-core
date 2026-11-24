@extends('layouts.auth')
@section('title', $approved ? __('Device signed in') : __('Device not signed in'))
@section('card-class', 'center')
@section('card')
  <span class="sico round {{ $approved ? 'ok' : 'warn' }}" aria-hidden="true">@include('partials.icon', ['i' => $approved ? 'check' : 'x', 'iconSize' => 24])</span>
  <div class="ttl">
    <h1>{{ $approved ? __('You’re signed in') : __('Not signed in') }}</h1>
    <p class="lede">{{ $approved ? __('Go back to your device — :app continues there in a few seconds.', ['app' => $app->name]) : ($message ?? __('The device was not signed in. You can close this page.')) }}</p>
  </div>
@endsection
