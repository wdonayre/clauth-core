@extends('layouts.auth')
@section('title', 'Choose a new password')
@section('card')
  <h1>Choose a new password</h1>
  <p class="sub">At least {{ \App\Services\Settings::get('password_min_length') }} characters.</p>
  @include('partials.errors')
  <form method="post" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="field"><label for="email">Email</label>
      <input class="input" id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required></div>
    <div class="field"><label for="password">New password</label>
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" data-meter="#meter" required>
      <div class="meter"><span id="meter"></span></div></div>
    <div class="field"><label for="password_confirmation">Confirm password</label>
      <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
    <button class="btn-auth pri" type="submit">Save password</button>
  </form>
@endsection
