@extends('mail.layout')
@section('body')
@php($btn = 'display:inline-block;background:#0B0D12;color:#fff;padding:10px 16px;border-radius:12px;text-decoration:none;font-weight:600')
@php($app = $ar->app->name)
@if($kind === 'new')
  <p style="margin:0 0 12px"><b>{{ $ar->user->name }}</b> ({{ $ar->user->email }}) asked for access to <b>{{ $app }}</b> in {{ $ar->organization->name }}.</p>
  @if($ar->note)<p style="margin:0 0 12px;padding:12px 14px;background:#F7F8FA;border-radius:10px">{{ $ar->note }}</p>@endif
  <p style="margin:0 0 16px"><a href="{{ $link }}" style="{{ $btn }}">Review in Clauth</a></p>
  <p style="margin:0;font-size:14px">Only approve people you know. You choose their role when you approve.</p>
@elseif($kind === 'approved')
  <p style="margin:0 0 12px">Hi {{ $ar->user->name }},</p>
  <p style="margin:0 0 16px">{{ $ar->organization->name }} gave you access to <b>{{ $app }}</b>.</p>
  @if($link)<p style="margin:0 0 16px"><a href="{{ $link }}" style="{{ $btn }}">Open {{ $app }}</a></p>@endif
@else
  <p style="margin:0 0 12px">Hi {{ $ar->user->name }},</p>
  <p style="margin:0">{{ $ar->organization->name }} didn’t give you access to {{ $app }}. If you think that’s a mistake, ask someone there to invite you.</p>
@endif
@endsection
