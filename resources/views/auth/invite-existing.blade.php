@extends('layouts.auth')
@section('title', __('Accept invite'))
@section('card')
  <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'mail', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ __('Accept your invite') }}</h1>
    <p class="lede">{{ __('You already have a Clauth account for :email. Sign in to accept.', ['email' => $invite->email]) }}</p>
  </div>
  <div class="notice">
    <strong>{{ $invite->inviter?->name ?? __('Your admin') }}</strong> {{ __('invited you') }}@if($app) {{ __('to') }} <strong>{{ $app->name }}</strong>@endif
    @if($invite->organization) {{ __('at') }} <strong>{{ $invite->organization->name }}</strong>@endif
    @if($role) {{ __('as') }} <strong>{{ $role->name }}</strong>@endif.
  </div>
  <a class="btn-auth pri" href="{{ route('login') }}">{{ __('Sign in to accept') }}</a>
@endsection
