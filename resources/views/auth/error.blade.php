@extends('layouts.auth')
@section('title', $title)
@section('card')
  <h1>{{ $title }}</h1>
  <p class="sub">{{ $message }}</p>
  <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Go to Clauth sign in') }}</a>
@endsection
