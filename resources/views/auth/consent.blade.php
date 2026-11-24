@extends('layouts.auth')
@section('title', __('Continue to :app', ['app' => $app->name]))
@section('no-brand', true)
@php
  $color = $app->color ?: '#0B0D12';
  $logo = $theme['logo'] ?? null;
  $me = collect(preg_split('/\s+/', trim($user->name ?: $user->email)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
@section('card')
  <div class="pair">
    <span class="app-ico {{ \App\Services\Theme::luminance($color) > 0.45 ? 'light' : '' }}" style="background:{{ $logo ? 'transparent' : $color }}" aria-hidden="true">@if($logo)<img src="{{ $logo }}" alt="">@else{{ $app->letter() }}@endif</span>
    <span class="swap" aria-hidden="true">⇄</span>
    <span class="app-ico muted" aria-hidden="true">{{ $me }}</span>
  </div>
  <div class="ttl">
    <h1>{{ __(':app wants to know who you are', ['app' => $app->name]) }}</h1>
    @if(! $app->isFirstParty())<p class="lede">@include('partials.publisher', ['app' => $app])</p>
    @else<p class="lede">{{ __('Continue to :app?', ['app' => $app->name]) }}</p>@endif
  </div>
  <div class="sees">
    <span class="lbl">{{ __('It will see') }}</span>
    <div class="li"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 13])</span><span>{{ __('That it’s you (:email)', ['email' => $user->email]) }}</span></div>
    @foreach($scopes as $s)
      @continue($s === 'openid')
      <div class="li"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 13])</span><span>{{ $s === 'clauth' && $orgNames->isNotEmpty() ? __('Your roles in this app at :orgs', ['orgs' => $orgNames->join(', ', ' '.__('and').' ')]) : ($labels[$s] ?? $s) }}</span></div>
    @endforeach
    <div class="li no"><span class="ck" aria-hidden="true">@include('partials.icon', ['i' => 'x', 'iconSize' => 13])</span><span>{{ __('Never your password, other apps or other organizations') }}</span></div>
  </div>
  <form method="post" action="{{ url('/oauth/consent') }}" class="btn-row">
    @csrf
    <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('Cancel') }}</button>
    <button class="btn-auth pri" type="submit" name="decision" value="allow">{{ __('Allow') }}</button>
  </form>
  <p class="fine">{{ __('Only what’s listed here is shared. You can remove its access any time in My account.') }}
    @if($app->privacy_url)<a href="{{ $app->privacy_url }}" target="_blank" rel="noopener noreferrer">{{ __('Privacy policy') }}</a> ·@endif
    @if($app->terms_url)<a href="{{ $app->terms_url }}" target="_blank" rel="noopener noreferrer">{{ __('Terms') }}</a> ·@endif
    <a href="{{ route('report') }}">{{ __('Report this app') }}</a></p>
@endsection
@section('below')
  <form method="post" action="{{ url('/logout') }}">@csrf {{ __('Signed in as :email.', ['email' => $user->email]) }} <button type="submit" class="linkbtn small">{{ __('Not you? Sign out') }}</button></form>
@endsection
