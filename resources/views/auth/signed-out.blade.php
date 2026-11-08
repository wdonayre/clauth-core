@extends('layouts.auth')
@section('title', 'Signed out')
@section('card')
  <h1>You’re signed out</h1>
  <p class="sub">You can close this window.</p>
  <a class="btn-auth sec" href="{{ route('login') }}">Sign in again</a>
@endsection
