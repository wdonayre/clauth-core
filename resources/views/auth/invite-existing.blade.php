@extends('layouts.auth')
@section('title', 'Accept invite')
@section('card')
  <h1>Accept your invite</h1>
  <div class="notice" style="margin-top:16px">
    <strong>{{ $invite->inviter?->name ?? 'Your admin' }}</strong> invited you
    @if($app) to <strong>{{ $app->name }}</strong>@endif
    @if($invite->organization) at <strong>{{ $invite->organization->name }}</strong>@endif
    @if($role) as <strong>{{ $role->name }}</strong>@endif.
  </div>
  <p class="sub">You already have a Clauth account for <strong>{{ $invite->email }}</strong>. Sign in to accept.</p>
  <a class="btn-auth pri" href="{{ route('login') }}">Sign in to accept</a>
@endsection
