@extends('layouts.auth')
@section('title', __('Recovery codes'))
@section('card')
  <div class="ttl">
    <span class="eyebrow">{{ $invite ? __('Step 3 of 3') : __('Step 2 of 2') }}</span>
    <h1>{{ __('Save your recovery codes') }}</h1>
    <p class="lede">{{ __('If you lose your phone, each code gets you in once. Keep them somewhere safe, like your password manager.') }}</p>
  </div>
  @include('partials.errors')
  <ul class="codes" id="codes" aria-label="{{ __('Recovery codes') }}">@foreach($codes as $c)<li>{{ $c }}</li>@endforeach</ul>
  <div class="btn-row noprint">
    <button class="btn-auth sec sm" type="button" data-copy="#codes">{{ __('Copy') }}</button>
    <a class="btn-auth sec sm" download="clauth-recovery-codes.txt" href="data:text/plain;charset=utf-8,{{ rawurlencode("Clauth recovery codes\n\n".implode("\n", $codes)."\n") }}">{{ __('Download') }}</a>
    <button class="btn-auth sec sm" type="button" data-print>{{ __('Print') }}</button>
  </div>
  <form class="stack noprint" method="post" action="{{ url('/login/2fa/codes') }}">
    @csrf
    <label class="check"><input type="checkbox" name="saved" value="1" required> {{ __('I’ve saved these codes') }}</label>
    <button class="btn-auth pri" type="submit">{{ ($theme['app'] ?? null) ? __('Continue to :app', ['app' => $theme['app']->name]) : __('Continue') }}</button>
  </form>
@endsection
