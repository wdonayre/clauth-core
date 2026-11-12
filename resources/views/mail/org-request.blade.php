@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Hi {{ $req->contact_name }},</p>
@if($kind === 'verify')
  <p style="margin:0 0 12px">Thanks for asking to use Clauth for <b>{{ $req->org_name }}</b>. Please confirm this is your email address:</p>
  <p style="margin:0 0 16px"><a href="{{ $link }}" style="display:inline-block;background:#22D3EE;color:#111;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600">Confirm my email</a></p>
  <p style="margin:0;font-size:14px">We review every request by hand and reply by email. If you didn’t ask for this, ignore this email.</p>
@else
  <p style="margin:0 0 12px">Thanks for your interest in Clauth for <b>{{ $req->org_name }}</b>. We can’t open an account for it right now.</p>
  @if($req->reject_reason)<p style="margin:0 0 12px">{{ $req->reject_reason }}</p>@endif
  <p style="margin:0;font-size:14px">Questions? Reply to {{ \App\Services\Settings::get('support_email') }}.</p>
@endif
@endsection
