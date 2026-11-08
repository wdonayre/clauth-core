@extends('layouts.auth')
@section('title', 'Privacy')
@section('card')
  <h1>Privacy</h1>
  <p class="sub" style="text-align:left">Clauth signs you in to apps run by The Cake Labs and its sister businesses (Alab Apparel, CakesbyGrace, CebuCake Inc.). We store your name, email, password (hashed), 2-step verification settings, which apps and roles you have, and a log of sign-ins (time, IP address, browser) for security. Apps you sign in to receive your name, email and your roles in that app only. We don’t sell or share your data, and we don’t use tracking or ads. Ask your admin or {{ \App\Services\Settings::get('support_email') }} to see or delete your data.</p>
  <a class="btn-auth sec" href="{{ route('login') }}">Back</a>
@endsection
