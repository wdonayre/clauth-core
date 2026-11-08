@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Hi {{ $invite->name }},</p>
<p style="margin:0 0 20px"><strong>{{ $inviter }}</strong> invited you{{ $app ? ' to '.$app->name : ' to Clauth' }}{{ $org ? ' at '.$org : '' }}{{ $role ? ' as '.$role->name : '' }}. Create your account to get started.</p>
<p style="margin:0 0 20px"><a href="{{ $link }}" style="display:inline-block;background:#0E7490;color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px">Create my account</a></p>
<p style="margin:0;font-size:13px;color:#475569">This link works once and expires in 7 days. If you weren’t expecting it, ignore this email.</p>
@endsection
