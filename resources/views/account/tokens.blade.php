@extends('layouts.console')
@section('title', 'API tokens')
@push('head')<link rel="stylesheet" href="/css/pages/settings.css?v={{ filemtime(public_path('css/pages/settings.css')) }}">@endpush
@push('end')<script src="/js/pages/settings.js?v={{ filemtime(public_path('js/pages/settings.js')) }}" defer></script>@endpush
@section('content')
@php($nice = fn ($s) => trim(preg_replace('/\s*\((personal API tokens|API|API, platform clients only)\)$/', '', (string) (config("clauth.scopes.$s") ?? $s))))
<div class="acct" style="gap:24px">
<nav class="crumbs" aria-label="Breadcrumb"><a href="{{ route('account') }}">My account</a><span aria-hidden="true">/</span><span>API tokens</span></nav>
<header class="head"><div><h1>Personal API tokens</h1>
  <p>For scripts and tools that act as you on the <a href="{{ url('/api/v1/openapi.json') }}" target="_blank" rel="noopener">Clauth API</a>. A token can only do what you can do — lose a role and your tokens lose it too.</p></div></header>

@if($t = session('new_token'))
  <section class="card pad stack" aria-labelledby="new-tk-h" style="gap:12px">
    <div><h2 id="new-tk-h">Copy “{{ $t['name'] }}” now</h2><p class="muted small" style="margin:2px 0 0">It is shown only once. If you lose it, revoke it and make a new one.</p></div>
    <div class="secretbox"><code id="new-token">{{ $t['token'] }}</code><button class="btn sm" type="button" data-copy="#new-token">@include('partials.icon', ['i' => 'copy', 'iconSize' => 14])Copy</button></div>
    <div><span class="lbl">Try it</span><pre class="code" style="margin-top:6px;white-space:pre-wrap">curl -s {{ url('/api/v1/me') }} -H "Authorization: Bearer $CLAUTH_TOKEN"</pre></div>
  </section>
@endif

<form class="card pad stack" method="post" action="{{ route('account.tokens.store') }}" aria-labelledby="mk-h" style="gap:16px">@csrf
  <h2 id="mk-h">New token</h2>
  <div class="frow">
    <div class="f"><label for="tn">Name</label><input class="in" id="tn" name="name" placeholder="e.g. Weekly report script" required maxlength="80" value="{{ old('name') }}">@error('name')<span class="err">{{ $message }}</span>@enderror</div>
    <div class="f"><label for="tdays">Expires</label><select class="in" id="tdays" name="days">@foreach($days as $d)<option value="{{ $d }}" @selected((int) old('days', 30) === $d)>In {{ $d }} days</option>@endforeach</select></div>
  </div>
  <fieldset class="scopes"><legend>It can</legend>
    @foreach($scopes as $s => $label)
      <label class="cb"><input type="checkbox" name="scopes[]" value="{{ $s }}" @checked(in_array($s, old('scopes', ['me:read']), true))><span>{{ $nice($s) }} <span class="mono">{{ $s }}</span></span></label>
    @endforeach
    @error('scopes')<span class="err small bad-t">{{ $message }}</span>@enderror
    @if($needsTwoStep && count($scopes) > 2)<p class="hint" style="margin:6px 0 0">Scopes that manage people or apps need <a href="{{ route('account') }}">2-step verification</a> on your account.</p>@endif
  </fieldset>
  <div class="actions"><button class="btn pri">Create token</button></div>
  <p class="hint" style="margin:0">A signing-key change (rare, announced) ends tokens older than a month; make a new one then.</p>
</form>

<section class="card" aria-labelledby="list-h">
  <h2 id="list-h" class="ch">Your tokens</h2>
  @forelse($tokens as $tk)
    @php($expired = \Carbon\Carbon::parse($tk->expires_at)->isPast())
    @php($dead = $tk->revoked || $expired)
    <div class="set"><div><b>{{ $tk->name }}</b>
        @if($tk->revoked)<span class="badge bad">Revoked</span>@elseif($expired)<span class="badge muted">Expired</span>@endif
        <p>{{ collect($tk->scopes)->map($nice)->implode(', ') }}
          · {{ $tk->updated_at !== $tk->created_at ? 'last used '.\Carbon\Carbon::parse($tk->updated_at)->diffForHumans() : 'never used' }}
          · {{ $expired ? 'expired' : 'expires' }} {{ \Carbon\Carbon::parse($tk->expires_at)->format('M j, Y') }}</p>
        <p class="mono" style="font-size:12px">{{ implode(' · ', $tk->scopes) }} · made {{ \Carbon\Carbon::parse($tk->created_at)->format('M j, Y') }}</p></div>
      @unless($dead)
        <form method="post" action="{{ route('account.tokens.revoke', $tk->id) }}" data-confirm="Revoke “{{ $tk->name }}”? Anything using it stops working.">@csrf @method('delete')<button class="btn sm danger">Revoke</button></form>
      @endunless
    </div>
  @empty
    <div class="empty"><b>No tokens yet</b><span>Make one above for a script or the command line.</span></div>
  @endforelse
</section>
</div>
@endsection
