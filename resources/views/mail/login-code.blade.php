@extends('mail.layout')
@section('body')
<p style="margin:0 0 12px">Your one-time code:</p>
<p style="margin:0 0 16px;font:600 28px/1.2 'JetBrains Mono',ui-monospace,monospace;letter-spacing:.18em">{{ $code }}</p>
<p style="margin:0 0 12px">It works for 5 minutes, once. Never share it — Clauth staff will never ask for it.</p>
<p style="margin:0;font-size:14px">If you didn’t try to sign in, you can ignore this email.</p>
@endsection
