@extends('layouts.auth')
@section('title', __('Invite expired'))
@section('card')
  <span class="sico warn" aria-hidden="true">@include('partials.icon', ['i' => 'mail', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ __('This invite has expired') }}</h1>
    <p class="lede">{{ __('Invite links work once and for 7 days. Ask :name for a new one.', ['name' => $inviter ?? __('your admin')]) }}</p>
  </div>
  <a class="btn-auth pri" href="{{ route('login') }}">{{ __('Go to sign in') }}</a>
@endsection
