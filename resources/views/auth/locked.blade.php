@extends('layouts.auth')
@section('title', 'Account locked')
@section('card')
  <h1>Account locked</h1>
  <p class="sub">Too many wrong passwords. For your safety, sign-in is paused for {{ $minutes }} minutes. We’ve emailed you about it.</p>
  <a class="btn-auth pri" href="{{ route('password.request') }}">Reset my password</a>
  <p style="text-align:center;margin:20px 0 0"><a class="link" href="{{ route('login') }}">Back to sign in</a></p>
@endsection
