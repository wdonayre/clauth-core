@extends('layouts.auth')
@section('title', 'Help')
@section('card')
  <h1>Help</h1>
  <p class="sub" style="text-align:left"><strong>Forgot your password?</strong> Use “Forgot password?” on the sign-in page.<br><br><strong>Lost your phone?</strong> Sign in with one of your recovery codes, or ask your admin to reset your 2-step verification.<br><br><strong>No access to an app?</strong> Your admin gives access per app. Contact {{ \App\Services\Settings::get('support_email') }}.</p>
  <a class="btn-auth sec" href="{{ route('login') }}">Back to sign in</a>
@endsection
