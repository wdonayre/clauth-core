@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Hi {{ $user->name }},</p>
<p style="margin:0 0 12px">There were too many wrong passwords for your Clauth account, so sign-in is paused for {{ \App\Services\Settings::get('lockout_minutes') }} minutes.</p>
<p style="margin:0;font-size:14px">If this wasn’t you, <a href="{{ route('password.request') }}">reset your password</a> and tell your admin.</p>
@endsection
