@extends('layouts.auth')
@section('title', 'Continue to '.$app->name)
@section('card')
  <h1>Continue to {{ $app->name }}?</h1>
  <p class="sub">
    @include('partials.publisher', ['app' => $app])
  </p>
  <p style="margin:16px 0 8px"><strong>{{ $app->name }}</strong> wants:</p>
  <ul style="margin:0 0 16px;padding-left:20px;line-height:1.7">
    @foreach($scopes as $s)
      @continue($s === 'openid')
      <li>{{ $s === 'clauth' && $orgNames->isNotEmpty() ? 'Your roles in this app at '.$orgNames->join(', ', ' and ') : ($labels[$s] ?? $s) }}</li>
    @endforeach
    <li>To know it's you ({{ $user->email }})</li>
  </ul>
  <p class="small muted" style="margin:0 0 16px">Only what's listed here is shared. You can remove {{ $app->name }}'s access any time in My account.
    @if($app->privacy_url || $app->terms_url)
      See its
      @if($app->privacy_url)<a href="{{ $app->privacy_url }}" target="_blank" rel="noopener noreferrer">privacy policy</a>@endif
      @if($app->privacy_url && $app->terms_url) and @endif
      @if($app->terms_url)<a href="{{ $app->terms_url }}" target="_blank" rel="noopener noreferrer">terms</a>@endif.
    @endif
  </p>
  <form method="post" action="{{ url('/oauth/consent') }}">
    @csrf
    <button class="btn-auth pri" type="submit" name="decision" value="allow">Continue</button>
    <button class="btn-auth sec" type="submit" name="decision" value="deny" style="margin-top:10px">Cancel</button>
  </form>
  <form method="post" action="{{ url('/logout') }}" class="small muted" style="margin:16px 0 0">@csrf Signed in as {{ $user->email }}. Not you? <button type="submit" style="background:none;border:0;padding:0;color:var(--link);cursor:pointer;text-decoration:underline;font:inherit">Sign out</button></form>
@endsection
