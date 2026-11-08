@extends('layouts.auth')
@section('title', 'Create your account')
@section('card')
  <p class="stepnote">Step 1 of 3</p>
  <ul class="steps" aria-hidden="true"><li class="on"></li><li></li><li></li></ul>
  <h1>Create your account</h1>
  <div class="notice" style="margin-top:16px">
    <strong>{{ $invite->inviter?->name ?? 'Your admin' }}</strong> invited you
    @if($app) to <strong>{{ $app->name }}</strong>@endif
    @if($invite->organization) at <strong>{{ $invite->organization->name }}</strong>@endif
    @if($role) as <strong>{{ $role->name }}</strong>@endif.
  </div>
  @include('partials.errors')
  <form method="post" action="{{ ($preview ?? false) ? '#' : route('invite.show', $token) }}">
    @csrf
    <div class="field"><label for="name">Full name</label>
      <input class="input" id="name" name="name" value="{{ old('name', $invite->name) }}" autocomplete="name" required></div>
    <div class="field"><label for="email">Email</label>
      <input class="input" id="email" type="email" value="{{ $invite->email }}" autocomplete="username" readonly>
      <span class="hint">From your invite. Ask your admin if it’s wrong.</span></div>
    <div class="field"><label for="password">Password</label>
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" data-meter="#meter" required>
      <div class="meter"><span id="meter"></span></div>
      <span class="hint">At least {{ \App\Services\Settings::get('password_min_length') }} characters. A short sentence works well.</span></div>
    <div class="field"><label for="password_confirmation">Confirm password</label>
      <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
    <label class="check"><input type="checkbox" name="terms" value="1" required> <span>I agree to the <a class="link" href="{{ route('terms') }}" target="_blank">Terms</a> and <a class="link" href="{{ route('privacy') }}" target="_blank">Privacy policy</a></span></label>
    <div style="height:12px"></div>
    <button class="btn-auth pri" type="submit">Create account</button>
  </form>
@endsection
