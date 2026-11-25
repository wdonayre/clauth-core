@extends('layouts.auth')
@section('title', __('Continue to :app', ['app' => $domain->brandName()]))
@section('card')
  <div class="ttl">
    <h1>{{ __('Continue as :name?', ['name' => $user->name ?: $user->email]) }}</h1>
    <p class="lede">{{ __('You\'re signed in to your :account.', ['account' => $brand['account']]) }} <strong>{{ $domain->brandName() }}</strong> {{ __('signs people in at') }} <strong>{{ $domain->host }}</strong>@if($domain->organization), {{ __('run by :org', ['org' => $domain->organization->name]) }}@endif.</p>
  </div>
  <div class="notice">{{ __('Continue there as :email?', ['email' => $user->email]) }}</div>
  <form method="post" action="{{ route('sso.confirm') }}" class="btn-row wrap">
    @csrf
    <input type="hidden" name="host" value="{{ $domain->host }}">
    <input type="hidden" name="state" value="{{ $state }}">
    <a class="btn-auth sec" href="{{ $back }}&none=1">{{ __('Use a different account') }}</a>
    <button class="btn-auth pri" type="submit">{{ __('Continue') }}</button>
  </form>
@endsection
