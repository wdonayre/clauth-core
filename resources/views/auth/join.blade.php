@extends('layouts.auth')
@section('title', __('Join :app', ['app' => $app->name]))
@section('no-brand', true)
@php
  $color = $app->color ?: '#0B0D12';
  $logo = $theme['logo'] ?? null;
  $me = collect(preg_split('/\s+/', trim($user->name ?: $user->email)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
  $role = $role->name ?: (\App\Models\AppRole::TIERS[$role->tier] ?? $role->key);
@endphp
@section('card')
  <div class="pair">
    <span class="app-ico {{ \App\Services\Theme::luminance($color) > 0.45 ? 'light' : '' }}" style="background:{{ $logo ? 'transparent' : $color }}" aria-hidden="true">@if($logo)<img src="{{ $logo }}" alt="">@else{{ $app->letter() }}@endif</span>
    <span class="swap" aria-hidden="true">⇄</span>
    <span class="app-ico muted" aria-hidden="true">{{ $me }}</span>
  </div>
  <div class="ttl">
    <h1>{{ __('Join :app', ['app' => $app->name]) }}</h1>
    <p class="lede">{{ __('You’re signed in to Clauth, but you don’t have a :app account yet. Continue and :app makes one from your Clauth account. No new password.', ['app' => $app->name]) }}</p>
  </div>
  <div class="acct">
    <span class="app-ico muted sm" aria-hidden="true">{{ $me }}</span>
    <div><b>{{ $user->name ?: $user->email }}</b>@if($user->name)<small>{{ $user->email }}</small>@endif</div>
  </div>
  <div class="sees">
    <span class="lbl">{{ __(':app will get', ['app' => $app->name]) }}</span>
    @if(in_array('profile', $scopes, true))
      <div class="li"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 13])</span><span>{{ __('Your name and profile photo') }}</span></div>
    @endif
    <div class="li"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 13])</span><span>{{ __('Your email address') }}</span></div>
    <div class="li"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 13])</span><span>{!! __('You join as :role', ['role' => '<b>'.e($role).'</b>']) !!}@if($app->publisher) {{ __('in :org', ['org' => $app->publisher->name]) }}@endif</span></div>
    <div class="li no"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'x', 'iconSize' => 13])</span><span>{{ __('Never your password, your other apps or other organizations') }}</span></div>
  </div>
  <form method="post" action="{{ route('oauth.join') }}" class="btn-row">
    @csrf
    <button class="btn-auth sec" type="submit" name="decision" value="cancel">{{ __('Cancel') }}</button>
    <button class="btn-auth pri" type="submit" name="decision" value="join">{{ __('Continue') }}</button>
  </form>
  @if($app->terms_url || $app->privacy_url)
  <p class="fine">
      {{ __('By continuing you agree to :app’s', ['app' => $app->name]) }}
      @if($app->terms_url)<a href="{{ $app->terms_url }}" target="_blank" rel="noopener noreferrer">{{ __('Terms') }}</a>@endif
      @if($app->terms_url && $app->privacy_url) {{ __('and') }} @endif
      @if($app->privacy_url)<a href="{{ $app->privacy_url }}" target="_blank" rel="noopener noreferrer">{{ __('Privacy policy') }}</a>@endif.
  </p>
  @endif
@endsection
@section('below')
  <form method="post" action="{{ url('/logout') }}">@csrf {{ __('Signed in as :email.', ['email' => $user->email]) }} <button type="submit" class="linkbtn small">{{ __('Not you? Sign out') }}</button></form>
@endsection
