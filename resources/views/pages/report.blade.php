@extends('layouts.auth')
@section('title', 'Report this page')
@section('card')
  <h1>Report this page</h1>
  <p class="sub">Tell Clauth if a sign-in page looks wrong{{ $app ? ' — this one is for '.$app->name.($app->publisher && ! $app->isFirstParty() ? ' by '.$app->publisher->name : '') : '' }}.</p>
  @include('partials.errors')
  <form method="post" action="{{ url('/report') }}" novalidate>
    @csrf
    <input type="hidden" name="url" value="{{ $from }}">
    <fieldset class="field" style="border:0;padding:0;margin:0 0 12px"><legend style="margin-bottom:6px">What’s wrong?</legend>
      @foreach($reasons as $k => $label)<label style="display:flex;gap:8px;align-items:center;margin:4px 0"><input type="radio" name="reason" value="{{ $k }}" @checked(old('reason') === $k) required> {{ $label }}</label>@endforeach
    </fieldset>
    <div class="field"><label for="rd">Anything else (optional)</label><textarea class="input" id="rd" name="details" rows="3" maxlength="2000">{{ old('details') }}</textarea></div>
    <button class="btn-auth pri" type="submit">Send report</button>
  </form>
@endsection
