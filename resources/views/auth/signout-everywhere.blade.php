@extends('layouts.auth')
@section('title', __('Sign out everywhere'))
@section('card')
  <span class="sico warn" aria-hidden="true">@include('partials.icon', ['i' => 'out', 'iconSize' => 22])</span>
  @if($user)
    <div class="ttl">
      <h1>{{ __('Sign out everywhere?') }}</h1>
      <p class="lede">{{ __('This signs :email out of :brand and every app, on every device. Open apps close their session within 15 minutes.', ['brand' => $brand['name'], 'email' => $user->email]) }}</p>
    </div>
    <form method="post" action="{{ url('/signout-everywhere') }}">@csrf
      <button class="btn-auth pri" type="submit">{{ __('Sign out everywhere') }}</button>
    </form>
  @else
    <div class="ttl">
      <h1>{{ __('Sign out everywhere?') }}</h1>
      <p class="lede">{{ __('You’re not signed in to :brand in this browser. Apps still open elsewhere close their session within 15 minutes after you sign out from one of them.', ['brand' => $brand['name']]) }}</p>
    </div>
    <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Sign in') }}</a>
  @endif
@endsection
