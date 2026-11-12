@extends('layouts.auth')
@section('title', 'Sign in')
@section('card')
  <h1>Sign in</h1>
  <p class="sub">{!! $app ? 'Continue to <strong>'.e($app->name).'</strong>' : (! empty($theme['brand']) ? 'Continue to <strong>'.e($theme['brand']).'</strong>' : 'to your Clauth account') !!}</p>
  @include('partials.errors')
  <form method="post" action="{{ $preview ?? false ? '#' : url('/login') }}" novalidate>
    @csrf
    <div class="field">
      <label for="email">Email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
    </div>
    <div class="field">
      <div class="row-between"><label for="password">Password</label><a class="link" href="{{ route('password.request') }}">Forgot password?</a></div>
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Keep me signed in on this device</label>
    <div style="height:12px"></div>
    <button class="btn-auth pri" type="submit">Sign in</button>
  </form>
  <p class="sub" style="margin:20px 0 0;font-size:14px">New here? Ask your admin for an invite.</p>
@endsection
