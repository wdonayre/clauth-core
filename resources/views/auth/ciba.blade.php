@extends('layouts.auth')
@section('title', __('Approve'))
@section('card')
  <div class="ttl">
    <h1>{{ __('Approve') }}</h1>
    <p class="lede"><strong>{{ $app->name }}</strong> {{ __('asks for your OK.') }}</p>
  </div>
  @if($r->binding_message)<div class="notice big">“{{ $r->binding_message }}”</div>@endif
  <p class="muted small">{{ __('Only approve if you’re doing this right now and the text matches what you see there.') }}</p>
  <form method="post" action="{{ url('/approve/'.$r->id) }}" class="btn-row">@csrf
    <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('Refuse') }}</button>
    <button class="btn-auth pri" type="submit" name="decision" value="approve">{{ __('Approve') }}</button>
  </form>
@endsection
