@extends('mail.layout')
@section('body')
@php($btn = 'display:inline-block;background:#0B0D12;color:#fff;padding:10px 16px;border-radius:12px;text-decoration:none;font-weight:600')
@if(in_array($kind, ['new', 'answered'], true))
  <p style="margin:0 0 12px">{{ $kind === 'new' ? 'Someone asked to set up an organization on Clauth.' : 'The person answered your question. The request is waiting for you again.' }}</p>
  <p style="margin:0 0 12px"><b>{{ $req->org_name }}</b> ({{ $req->slug }})<br>{{ $req->contact_name }} · {{ $req->contact_email }}@if($req->website)<br>{{ $req->website }}@endif</p>
  <p style="margin:0 0 16px"><a href="{{ $link }}" style="{{ $btn }}">Review the request</a></p>
@else
  <p style="margin:0 0 12px">Hi {{ $req->contact_name }},</p>
  @if($kind === 'verify')
    <p style="margin:0 0 12px">Thanks for asking to use Clauth for <b>{{ $req->org_name }}</b>. Please confirm this is your email address:</p>
    <p style="margin:0 0 16px"><a href="{{ $link }}" style="{{ $btn }}">Confirm my email</a></p>
    <p style="margin:0;font-size:14px">We review every request by hand and reply by email. If you didn’t ask for this, ignore this email.</p>
  @elseif($kind === 'info')
    <p style="margin:0 0 12px">Thanks for asking to set up <b>{{ $req->org_name }}</b> on Clauth. Before we decide, the reviewer has a question:</p>
    <p style="margin:0 0 12px;padding:12px 14px;background:#F7F8FA;border-radius:10px">{{ $req->info_message }}</p>
    <p style="margin:0 0 16px"><a href="{{ $link }}" style="{{ $btn }}">Answer in Clauth</a></p>
    <p style="margin:0;font-size:14px">The request closes by itself if there’s no answer within {{ \App\Models\OrganizationRequest::EXPIRES_DAYS }} days.</p>
  @elseif($kind === 'approved')
    <p style="margin:0 0 12px"><b>{{ $req->org_name }}</b> is set up on Clauth, and you’re its Owner. Three steps to get going:</p>
    <ol style="margin:0 0 16px;padding-left:20px"><li>Turn on 2-step sign-in (owners need it)</li><li>Add the apps your team uses</li><li>Invite your team</li></ol>
    <p style="margin:0 0 16px"><a href="{{ $link }}" style="{{ $btn }}">Open {{ $req->org_name }}</a></p>
  @elseif($kind === 'expired')
    <p style="margin:0 0 12px">Your request to set up <b>{{ $req->org_name }}</b> on Clauth closed after {{ \App\Models\OrganizationRequest::EXPIRES_DAYS }} days without an answer. Your account still works, and you can send a new request from My account.</p>
  @else
    <p style="margin:0 0 12px">Thanks for asking to set up <b>{{ $req->org_name }}</b> on Clauth. We couldn’t approve it this time.</p>
    @if($req->reject_reason)<p style="margin:0 0 12px;padding:12px 14px;background:#F7F8FA;border-radius:10px">{{ $req->reject_reason }}</p>@endif
    <p style="margin:0;font-size:14px">@if($req->user_id)Your Clauth account still works, and you can send a new request from My account. @endif Questions? Reply to {{ \App\Services\Settings::get('support_email') }}.</p>
  @endif
@endif
@endsection
