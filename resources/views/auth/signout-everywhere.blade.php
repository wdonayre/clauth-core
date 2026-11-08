@extends('layouts.auth')
@section('title', 'Sign out everywhere')
@section('card')
  <h1>Sign out everywhere?</h1>
  @if($user)
    <p class="sub">This signs <strong>{{ $user->email }}</strong> out of Clauth and every app, on every device. Open apps close their session within 15 minutes.</p>
    <form method="post" action="{{ url('/signout-everywhere') }}">@csrf
      <button class="btn-auth pri" type="submit">Sign out everywhere</button>
    </form>
  @else
    <p class="sub">You’re not signed in to Clauth in this browser. Apps still open elsewhere close their session within 15 minutes after you sign out from one of them.</p>
    <a class="btn-auth sec" href="{{ route('login') }}">Sign in</a>
  @endif
@endsection
