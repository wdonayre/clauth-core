@extends('layouts.auth')
@section('title', 'Reset password')
@section('card')
  <h1>Reset your password</h1>
  <p class="sub">Enter your email and we’ll send you a link.</p>
  @include('partials.errors')
  <form method="post" action="{{ route('password.email') }}">
    @csrf
    <div class="field"><label for="email">Email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus></div>
    <button class="btn-auth pri" type="submit">Send reset link</button>
  </form>
  <p style="text-align:center;margin:20px 0 0"><a class="link" href="{{ route('login') }}">Back to sign in</a></p>
@endsection
