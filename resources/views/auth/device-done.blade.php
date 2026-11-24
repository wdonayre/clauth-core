@extends('layouts.auth')
@section('title', $approved ? 'Device signed in' : 'Device not signed in')
@section('card')
  <h1>{{ $approved ? 'You’re signed in' : 'Not signed in' }}</h1>
  <p class="sub">{{ $approved ? "Go back to your device — {$app->name} continues there in a few seconds." : ($message ?? 'The device was not signed in. You can close this page.') }}</p>
@endsection
