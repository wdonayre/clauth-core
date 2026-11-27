@extends('layouts.auth')
@section('title', __('Welcome'))
@section('no-brand', true)
@php($first = \Illuminate\Support\Str::before(trim($user->name ?: ''), ' ') ?: null)
@section('card')
  <div class="ttl">
    <h1>{{ $first ? __('Welcome, :name', ['name' => $first]) : __('Welcome') }}</h1>
    <p class="lede">{{ __('Your account is ready. What would you like to do?') }}</p>
  </div>
  @include('partials.errors')
  <div class="choices">
    @if($openRequest)
      <a class="choice" href="{{ route('account') }}">
        <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'org', 'iconSize' => 20])</span>
        <span class="txt"><b>{{ __(':org is waiting for review', ['org' => $openRequest->org_name]) }}</b><span>{{ __('See where it is in My account.') }}</span></span>
      </a>
    @elseif($orgsOpen)
      <a class="choice" href="{{ route('org-request') }}">
        <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'org', 'iconSize' => 20])</span>
        <span class="txt"><b>{{ __('Set up an organization') }} <span class="tag">{{ __('Needs approval') }}</span></b><span>{{ __('For a business or team that wants its people to sign in to its apps with Clauth. We review new organizations, usually within one working day.') }}</span></span>
      </a>
    @endif
    <div class="choice">
      <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'mail', 'iconSize' => 20])</span>
      <span class="txt"><b>{{ __('I was invited') }}</b><span>{{ __('Open the invite link in your email, or paste it here.') }}</span>
        <form class="inline-f" method="post" action="{{ route('welcome.invite') }}">@csrf
          <label class="sr-only" for="inv">{{ __('Invite link') }}</label>
          <input class="input" id="inv" name="link" placeholder="https://…/invite/…" required>
          <button class="btn-auth sec" type="submit">{{ __('Open') }}</button>
        </form></span>
    </div>
    <a class="choice" href="{{ route('account') }}">
      <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'user', 'iconSize' => 20])</span>
      <span class="txt"><b>{{ __('Just my account for now') }}</b><span>{{ $orgsOpen ? __('Go to My account. You can set up an organization any time.') : __('Go to My account.') }}</span></span>
    </a>
  </div>
@endsection
