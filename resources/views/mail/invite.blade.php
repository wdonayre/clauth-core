@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Hi {{ $invite->name }},</p>
<p style="margin:0 0 20px"><strong>{{ $inviter }}</strong> invited you{{ $app ? ' to '.$app->name : ' to '.$brand['name'] }}{{ $org ? ' at '.$org : '' }}{{ $role ? ' as '.$role->name : '' }}. Create your account to get started.</p>
@if(filled($invite->note ?? null))
<div style="margin:0 0 20px;padding:12px 16px;border-left:3px solid #CBD5E1;background:#F8FAFC;border-radius:8px">
<p style="margin:0 0 4px;font-size:12px;color:#475569">A note from {{ $inviter }}</p>
<p style="margin:0;white-space:pre-line">{{ $invite->note }}</p>
</div>
@endif
<p style="margin:0 0 20px"><a href="{{ $link }}" style="display:inline-block;background:#0B0D12;color:#fff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:12px">Create my account</a></p>
<p style="margin:0;font-size:13px;color:#475569">This link works once and expires in 7 days. If you weren’t expecting it, ignore this email.</p>
@endsection
