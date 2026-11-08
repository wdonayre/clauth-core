@extends('layouts.auth')
@section('title', 'Terms')
@section('card')
  <h1>Terms</h1>
  <p class="sub" style="text-align:left">Your Clauth account is for you only: don’t share your password or codes. Your admin can change or remove your access to apps at any time. Use the apps only for your work with the business that invited you. We may lock an account to protect it after suspicious activity.</p>
  <a class="btn-auth sec" href="{{ route('login') }}">Back</a>
@endsection
