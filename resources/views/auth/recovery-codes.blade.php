@extends('layouts.auth')
@section('title', 'Recovery codes')
@section('card')
  @if($invite)
    <p class="stepnote">Step 3 of 3</p>
    <ul class="steps" aria-hidden="true"><li class="on"></li><li class="on"></li><li class="on"></li></ul>
  @endif
  <h1>Save your recovery codes</h1>
  <p class="sub">If you lose your phone, each code gets you in once. Keep them somewhere safe, like your password manager.</p>
  @include('partials.errors')
  <ul class="codes" id="codes">@foreach($codes as $c)<li>{{ $c }}</li>@endforeach</ul>
  <div style="display:flex;gap:10px;margin-bottom:12px">
    <button class="btn-auth sec" type="button" data-copy="#codes">Copy</button>
    <a class="btn-auth sec" download="clauth-recovery-codes.txt" href="data:text/plain;charset=utf-8,{{ rawurlencode("Clauth recovery codes\n\n".implode("\n", $codes)."\n") }}">Download</a>
  </div>
  <form method="post" action="{{ url('/login/2fa/codes') }}">
    @csrf
    <label class="check"><input type="checkbox" name="saved" value="1" required> I’ve saved these codes</label>
    <div style="height:12px"></div>
    <button class="btn-auth pri" type="submit">Continue</button>
  </form>
@endsection
