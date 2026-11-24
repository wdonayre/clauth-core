@extends('layouts.console')
@section('title', 'API tokens')
@section('content')
<header class="head"><div><h1>API tokens</h1>
  <p>Personal tokens for scripts and command-line tools. A token acts as you on the <a href="{{ url('/api/v1/openapi.json') }}" target="_blank" rel="noopener">Clauth API</a> and can never do more than you can in the console. Losing a role takes the matching powers away from your tokens too.</p></div>
  <div class="actions"><a class="btn" href="{{ route('account') }}">My account</a></div></header>

@if($t = session('new_token'))
  <div class="flash ok"><b>Copy “{{ $t['name'] }}” now — it is shown only once.</b>
    <div class="mono small" style="margin-top:8px;word-break:break-all">{{ $t['token'] }}</div>
    <pre class="mono small" style="white-space:pre-wrap;margin:10px 0 0">curl -s {{ url('/api/v1/me') }} -H "Authorization: Bearer $CLAUTH_TOKEN"</pre></div>
@endif

<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Your tokens</h2></div>
    <div class="list">
      @forelse($tokens as $tk)
        @php($dead = $tk->revoked || \Carbon\Carbon::parse($tk->expires_at)->isPast())
        <div class="row" style="flex-wrap:wrap;gap:8px">
          <div class="grow"><b>{{ $tk->name }}</b> @if($tk->revoked)<span class="chip bad">Revoked</span>@elseif($dead)<span class="chip">Expired</span>@endif
            <br><span class="small muted">{{ implode(' · ', $tk->scopes) }}</span>
            <br><span class="small muted">Made {{ \Carbon\Carbon::parse($tk->created_at)->format('j M Y') }} · expires {{ \Carbon\Carbon::parse($tk->expires_at)->format('j M Y') }} · last used {{ $tk->updated_at !== $tk->created_at ? \Carbon\Carbon::parse($tk->updated_at)->diffForHumans() : 'never' }}</span></div>
          @unless($dead)
            <form method="post" action="{{ route('account.tokens.revoke', $tk->id) }}" onsubmit="return confirm('Revoke this token? Anything using it stops working.')">@csrf @method('delete')<button class="btn sm">Revoke</button></form>
          @endunless
        </div>
      @empty<div class="empty">No tokens yet.</div>@endforelse
    </div>
  </section>
  <form class="card pad form" method="post" action="{{ route('account.tokens.store') }}">@csrf
    <h2>New token</h2>
    <div class="f"><label for="tn">Name</label><input class="in" id="tn" name="name" placeholder="My laptop CLI" required maxlength="80" value="{{ old('name') }}"></div>
    <div class="f"><label for="td">Expires after</label><select class="in" id="td" name="days">@foreach($days as $d)<option value="{{ $d }}" @selected($d === 30)>{{ $d }} days</option>@endforeach</select></div>
    <div class="f"><label>Scopes</label>
      @foreach($scopes as $s => $label)
        <label class="small" style="display:flex;gap:8px;align-items:flex-start;margin:4px 0"><input type="checkbox" name="scopes[]" value="{{ $s }}" @checked($s === 'me:read')> <span><span class="mono">{{ $s }}</span> — {{ $label }}</span></label>
      @endforeach
      @error('scopes')<div class="small bad">{{ $message }}</div>@enderror
      @if($needsTwoStep && count($scopes) > 2)<p class="small muted">Scopes that manage people or apps need 2-step verification on your account.</p>@endif
    </div>
    <div class="actions"><button class="btn pri">Create token</button></div>
    <p class="small muted">A signing-key change (rare, announced) ends tokens older than a month; make a new one then.</p>
  </form>
</div>
@endsection
