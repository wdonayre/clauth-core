@extends('layouts.auth')
@section('title', 'Continue to '.$domain->brandName())
@section('card')
  <h1>Continue as {{ $user->name ?: $user->email }}?</h1>
  <p class="sub">You're signed in to Clauth. <strong>{{ $domain->brandName() }}</strong> signs people in at <strong>{{ $domain->host }}</strong>@if($domain->organization), run by {{ $domain->organization->name }}@endif. Continue there as <strong>{{ $user->email }}</strong>?</p>
  <form method="post" action="{{ route('sso.confirm') }}">
    @csrf
    <input type="hidden" name="host" value="{{ $domain->host }}">
    <input type="hidden" name="state" value="{{ $state }}">
    <button class="btn-auth pri" type="submit">Continue</button>
  </form>
  <a class="btn-auth sec" href="{{ $back }}&none=1" style="margin-top:10px">Use a different account</a>
@endsection
