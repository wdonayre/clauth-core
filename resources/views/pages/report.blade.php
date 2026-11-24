@extends('layouts.auth')
@section('title', __('Report this page'))
@section('no-brand', true)
@section('card')
  <span class="sico bad" aria-hidden="true">@include('partials.icon', ['i' => 'flag', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ __('Report this page') }}</h1>
    <p class="lede">@if($app){{ $app->publisher && ! $app->isFirstParty()
      ? __('Tell Clauth if a sign-in page looks wrong — this one is for :app by :org.', ['app' => $app->name, 'org' => $app->publisher->name])
      : __('Tell Clauth if a sign-in page looks wrong — this one is for :app.', ['app' => $app->name]) }}@else{{ __('Tell Clauth if a sign-in page looks wrong.') }}@endif</p>
  </div>
  @include('partials.errors')
  <form class="stack" method="post" action="{{ url('/report') }}" novalidate>
    @csrf
    <input type="hidden" name="url" value="{{ $from }}">
    <fieldset class="field"><legend>{{ __('What’s wrong?') }}</legend>
      <div class="opts">
        @foreach($reasons as $k => $label)<label class="opt"><input type="radio" name="reason" value="{{ $k }}" @checked(old('reason') === $k) required> {{ __($label) }}</label>@endforeach
      </div>
    </fieldset>
    <div class="field"><label for="rd">{{ __('Anything else (optional)') }}</label><textarea class="input" id="rd" name="details" rows="3" maxlength="2000">{{ old('details') }}</textarea></div>
    <button class="btn-auth pri" type="submit">{{ __('Send report') }}</button>
  </form>
  <p class="muted small">{{ __('Don’t enter your password on a page you don’t trust.') }}</p>
@endsection
