@extends('layouts.auth')
@section('title', __('Approve'))
@section('card')
  <h1>{{ __('Approve') }}</h1>
  <p class="sub"><strong>{{ $app->name }}</strong> {{ __('asks for your OK.') }}</p>
  @if($r->binding_message)<div class="notice" style="font-size:16px">“{{ $r->binding_message }}”</div>@endif
  <p class="sub" style="font-size:14px">{{ __('Only approve if you’re doing this right now and the text matches what you see there.') }}</p>
  <form method="post" action="{{ url('/approve/'.$r->id) }}">@csrf
    <button class="btn-auth pri" type="submit" name="decision" value="approve">{{ __('Approve') }}</button>
    <div style="height:8px"></div>
    <button class="btn-auth sec" type="submit" name="decision" value="deny">{{ __('Refuse') }}</button>
  </form>
@endsection
