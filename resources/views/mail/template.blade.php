@extends('mail.layout')
@section('body')
{!! $html !!}
@if($link)<p style="margin:16px 0 0"><a href="{{ $link }}" style="display:inline-block;background:#0B0D12;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600">{{ __('Open') }}</a></p>@endif
@endsection
