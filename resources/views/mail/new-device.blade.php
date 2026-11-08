@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Hi {{ $user->name }},</p>
<p style="margin:0 0 12px">Your Clauth account was just used to sign in on a new device:</p>
<p style="margin:0 0 16px;padding:12px;background:#F1F5F9;border-radius:8px;font-size:14px">{{ \App\Support\Agent::short($agent) }} · {{ $ip }} · {{ now()->format('j M Y, H:i') }} UTC</p>
<p style="margin:0;font-size:14px">If this was you, nothing to do. If not, <a href="{{ route('password.request') }}">reset your password</a> now and tell your admin.</p>
@endsection
