@extends('layouts.console')
@section('title', 'API clients · '.($org?->name ?? 'Platform'))
@section('content')
<header class="head"><div><h1>API clients</h1>
  <p>@if($org)Scripts and servers that manage {{ $org->name }} through the Clauth API: invite people, give access profiles, read who has what.@else Platform clients can reach <b>every</b> organization and the platform settings. Give them only the scopes they need.@endif
  A client acts as the person who created it, and stops working if that person no longer runs {{ $org?->name ?? 'Clauth' }}.</p></div>
  <div class="actions"><a class="btn" href="{{ url('/api/v1/openapi.json') }}" target="_blank" rel="noopener">API reference (OpenAPI)</a>
    <a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>

@if($c = session('created_client'))
  <div class="flash ok"><b>Copy the secret for “{{ $c['name'] }}” now — it is shown only once.</b>
    <div class="mono small" style="margin-top:8px;word-break:break-all">client_id: {{ $c['id'] }}<br>client_secret: {{ $c['secret'] }}</div>
    <pre class="mono small" style="white-space:pre-wrap;margin:10px 0 0">curl -s {{ url('/oauth/token') }} -d grant_type=client_credentials \
  -d client_id={{ $c['id'] }} -d client_secret=… -d "scope={{ $c['scopes'] }}"
curl -s {{ url('/api/v1/capabilities') }} -H "Authorization: Bearer $TOKEN"</pre></div>
@endif

<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Clients ({{ $clients->count() }})</h2></div>
    <div class="list">
      @forelse($clients as $cl)
        <div class="row" style="flex-wrap:wrap;gap:8px">
          <div class="grow"><b>{{ $cl->name }}</b> @if($cl->revoked)<span class="chip bad">Off</span>@endif
            <br><span class="small muted mono">{{ $cl->id }}</span>
            <br><span class="small muted">{{ implode(' · ', $cl->scopes ?? []) }}</span>
            <br><span class="small muted">By {{ $cl->creator?->name ?? '—' }} · last used {{ $cl->last_used_at?->diffForHumans() ?? 'never' }} · secret expires {{ $cl->secret_expires_at?->format('j M Y') }}</span></div>
          @unless($cl->revoked)
            <form method="post" action="{{ route('admin.api-clients.rotate', [$orgKey, $cl->id]) }}" onsubmit="return confirm('Make a new secret? The old one and its tokens stop working now.')">@csrf<button class="btn sm">New secret</button></form>
            <form method="post" action="{{ route('admin.api-clients.revoke', [$orgKey, $cl->id]) }}" onsubmit="return confirm('Turn this client off?')">@csrf<button class="btn sm">Turn off</button></form>
          @endunless
        </div>
      @empty<div class="empty">No API clients yet.</div>@endforelse
    </div>
  </section>
  <form class="card pad form" method="post" action="{{ route('admin.api-clients.store', $orgKey) }}">@csrf
    <h2>New API client</h2>
    <div class="f"><label for="cn">Name</label><input class="in" id="cn" name="name" placeholder="HR sync script" required maxlength="80"></div>
    <div class="f"><label>Scopes</label>
      @foreach($scopes as $s => $label)
        <label class="small" style="display:flex;gap:8px;align-items:flex-start;margin:4px 0"><input type="checkbox" name="scopes[]" value="{{ $s }}" @checked(str_ends_with($s, ':read'))> <span><span class="mono">{{ $s }}</span> — {{ $label }}</span></label>
      @endforeach
      @error('scopes')<div class="small bad">{{ $message }}</div>@enderror
    </div>
    <div class="actions"><button class="btn pri">Create client</button></div>
    <p class="small muted">The secret lasts a year. Tokens last 15 minutes; ask for a new one with client credentials.</p>
  </form>
</div>
@endsection
