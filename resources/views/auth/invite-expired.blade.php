@extends('layouts.auth')
@section('title', __('Invite expired'))
@section('card')
  <h1>{{ __('This invite has expired') }}</h1>
  <p class="sub">Invite links work once and for 7 days. Ask {{ $inviter ?? 'your admin' }} for a new one.</p>
  <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Go to sign in') }}</a>
@endsection
