@extends('layouts.auth')
@section('title', __('No access to :app yet', ['app' => $app->name]))
@section('no-brand', true)
@php($color = $app->color ?: '#0B0D12')
@php($logo = $theme['logo'] ?? null)
@php($org = $app->publisher->name)
@section('card')
  <span class="app-ico {{ \App\Services\Theme::luminance($color) > 0.45 ? 'light' : '' }}" style="background:{{ $logo ? 'transparent' : $color }}" aria-hidden="true">@if($logo)<img src="{{ $logo }}" alt="">@else{{ $app->letter() }}@endif</span>
  <div class="ttl">
    <h1>{{ __('Your account is ready') }}</h1>
    <p class="lede">{{ __(':app is invite-only, so you can’t open it yet.', ['app' => $app->name]) }}</p>
  </div>
  @include('partials.errors')
  <div class="choices">
    @if($asked)
      <div class="choice">
        <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'check', 'iconSize' => 20])</span>
        <span class="txt"><b>{{ __('Request sent to :org', ['org' => $org]) }}</b><span>{{ __('We’ll email you when they decide. You can close this page.') }}</span></span>
      </div>
    @else
      <form class="choice" method="post" action="{{ route('oauth.access-request') }}">@csrf
        <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'users', 'iconSize' => 20])</span>
        <span class="txt"><b>{{ __('Ask :org for access', ['org' => $org]) }}</b><span>{{ __('If you work with :org. We send your name and email to its admins; they choose your role.', ['org' => $org]) }}</span>
          <label class="sr-only" for="ar-note">{{ __('Note to :org (optional)', ['org' => $org]) }}</label>
          <span class="inline-f"><input class="input" id="ar-note" name="note" maxlength="500" placeholder="{{ __('Optional note, e.g. your team') }}"><button class="btn-auth pri" type="submit">{{ __('Ask') }}</button></span></span>
      </form>
    @endif
    @if($openRequest)
      <a class="choice" href="{{ route('account') }}">
        <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'org', 'iconSize' => 20])</span>
        <span class="txt"><b>{{ __(':org is waiting for review', ['org' => $openRequest->org_name]) }}</b><span>{{ __('See where it is in My account.') }}</span></span>
      </a>
    @elseif($orgsOpen)
      <a class="choice" href="{{ route('org-request', ['from' => $app->key]) }}">
        <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'org', 'iconSize' => 20])</span>
        <span class="txt"><b>{{ __('Set up your own organization') }} <span class="tag">{{ __('Needs approval') }}</span></b><span>{{ __('For your own business. The request notes you came from :app.', ['app' => $app->name]) }}</span></span>
      </a>
    @endif
  </div>
  <form method="post" action="{{ route('oauth.join') }}" class="links center">@csrf
    <button class="linkbtn" type="submit" name="decision" value="cancel">{{ __('Back to :app', ['app' => $app->name]) }}</button>
  </form>
@endsection
@section('below')
  <form method="post" action="{{ url('/logout') }}">@csrf {{ __('Signed in as :email.', ['email' => $user->email]) }} <button type="submit" class="linkbtn small">{{ __('Not you? Sign out') }}</button></form>
@endsection
