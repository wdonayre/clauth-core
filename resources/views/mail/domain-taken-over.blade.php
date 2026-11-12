@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Hello,</p>
<p style="margin:0 0 12px"><b>{{ $host }}</b> was set up as a Clauth sign-in domain for {{ $orgName }}, but it stopped passing its checks (it was never verified, or its verification record is gone).</p>
<p style="margin:0 0 12px">Another organization, <b>{{ $newOrgName }}</b>, proved it controls the domain's DNS and is now verifying it for its own apps. Your apps sign people in at {{ \App\Services\Domains::mainHost() }} again.</p>
<p style="margin:0;font-size:14px">If you still own {{ $host }} and didn’t expect this, check your DNS and contact Clauth support.</p>
@endsection
