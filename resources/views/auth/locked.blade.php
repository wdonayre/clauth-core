@extends('layouts.auth')
@section('title', __('Account locked'))
@section('no-brand', true)
@section('card-class', 'center')
@section('card')
  <span class="sico round warn" aria-hidden="true">@include('partials.icon', ['i' => 'lock', 'iconSize' => 24])</span>
  <div class="ttl">
    <h1>{{ __('Too many tries') }}</h1>
    <p class="lede">{{ __('To keep your account safe, sign-in is paused for a little while.') }}</p>
  </div>
  @if($seconds)
    <div>
      <span class="sr-only" id="timer-l">{{ __('Time left') }}</span>
      <div class="timer" role="timer" aria-labelledby="timer-l" aria-live="off" data-countdown-seconds="{{ $seconds }}" data-done="#timer-done">{{ intdiv($seconds, 60) }}:{{ str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) }}</div>
    </div>
    <p class="muted small" id="timer-wait">{{ __('You can try again when the timer ends. If you forgot your password, reset it now.') }}</p>
    <p class="small" id="timer-done" role="status" hidden>{{ __('You can try again now.') }} <a href="{{ route('login') }}">{{ __('Back to sign in') }}</a></p>
  @else
    <p class="muted small">{{ __('Try again in a few minutes. If you forgot your password, reset it now.') }}</p>
  @endif
  <a class="btn-auth pri" href="{{ route('password.request') }}">{{ __('Reset password') }}</a>
  <div class="links center"><a href="{{ route('login') }}">{{ __('Back to sign in') }}</a></div>
  <p class="muted small">{{ __('Wasn’t you?') }} <a href="{{ route('help') }}">{{ __('Tell your admin') }}</a></p>
@endsection
