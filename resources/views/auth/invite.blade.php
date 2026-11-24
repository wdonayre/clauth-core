@extends('layouts.auth')
@section('title', __('Create your account'))
@section('no-brand', true)
@php
  $min = (int) \App\Services\Settings::get('password_min_length');
  $org = $invite->organization;
  $who = $org?->name ?? $app?->name ?? 'Clauth';
  $initials = collect(preg_split('/\s+/', trim($who)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
  $icoColor = $app?->color ?: '#0B0D12';
@endphp
@section('card')
  <span class="eyebrow">{{ __('Step 1 of 3') }}</span>
  <div class="brand-row">
    <span class="app-ico {{ \App\Services\Theme::luminance($icoColor) > 0.45 ? 'light' : '' }}" style="background:{{ $icoColor }}" aria-hidden="true">{{ $initials }}</span>
    <div><div class="lead">{{ __(':name invited you to', ['name' => $invite->inviter?->name ?? __('Your admin')]) }}</div><b>{{ $who }}</b></div>
  </div>
  <div class="ttl">
    <h1>{{ __('Set up your account') }}</h1>
    <p class="lede">
      @if($app && $role){{ __('You’ll use it to open :app as :role, and the other apps your team uses.', ['app' => $app->name, 'role' => $role->name]) }}
      @elseif($app){{ __('You’ll use it to open :app and the other apps your team uses.', ['app' => $app->name]) }}
      @else{{ __('You’ll use it to open the apps your team uses.') }}@endif
    </p>
  </div>
  @include('partials.errors')
  @if(! empty($socialError))<div class="notice bad" role="alert">{{ $socialError }}</div>@endif
  <form class="stack" method="post" action="{{ ($preview ?? false) ? '#' : route('invite.show', $token) }}">
    @csrf
    <div class="field"><label for="name">{{ __('Your name') }}</label>
      <input class="input" id="name" name="name" value="{{ old('name', $invite->name) }}" autocomplete="name" required></div>
    <div class="field"><label for="email">{{ __('Email') }}</label>
      <input class="input" id="email" type="email" value="{{ $invite->email }}" autocomplete="username" readonly aria-describedby="email-h">
      <span class="hint" id="email-h">{{ __('From your invite. Ask your admin if it’s wrong.') }}</span></div>
    <div class="field"><label for="password">{{ __('Choose a password') }}</label>
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" data-meter="#meter" data-min="{{ $min }}" data-min-rule="#rule-min" required aria-describedby="rule-min">
      <div class="meter" aria-hidden="true"><span id="meter"></span></div>
      <ul class="rules"><li class="rule" id="rule-min">{{ __('At least :n characters. A short sentence works well.', ['n' => $min]) }}</li></ul></div>
    <div class="field"><label for="password_confirmation">{{ __('Type it again') }}</label>
      <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
    <label class="check top"><input type="checkbox" name="terms" value="1" required> <span>{{ __('I agree to the') }} <a href="{{ route('terms') }}" target="_blank">{{ __('Terms') }}</a> {{ __('and') }} <a href="{{ route('privacy') }}" target="_blank">{{ __('Privacy policy') }}</a></span></label>
    <button class="btn-auth pri" type="submit">{{ __('Create account') }}</button>
  </form>
  @if(! empty($google) || count($connections ?? []))
    <div class="or" role="separator">{{ __('or') }}</div>
    @include('auth._providers', ['query' => '?invite=1'])
    <p class="muted small">{{ __('Use the account for :email. By continuing you agree to the Terms and Privacy policy.', ['email' => $invite->email]) }}</p>
  @endif
@endsection
