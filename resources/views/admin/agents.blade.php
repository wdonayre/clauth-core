@extends('layouts.console')
@section('title', 'Agents · '.$org->name)
@section('content')
<header class="head"><div><h1>Agents</h1>
  <p>AI assistants, bots and scripts that work in {{ $org->name }}’s apps under their own name. Each gets its own credentials and roles, shows up in the audit log as itself, and can be suspended any time. People can also make their own agents (via the API) that act for them with no more access than they have.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>

@if($s = session('agent_secret'))
  <div class="flash ok"><b>Copy the credentials for “{{ $s['name'] }}” now — the secret is shown only once.</b>
    <div class="mono small" style="margin-top:8px;word-break:break-all">client_id: {{ $s['client_id'] }}<br>client_secret: {{ $s['secret'] }}</div>
    <pre class="mono small" style="white-space:pre-wrap;margin:10px 0 0">curl -s {{ url('/oauth/token') }} -d grant_type=client_credentials \
  -d client_id={{ $s['client_id'] }} -d client_secret=… -d audience=&lt;app key&gt;</pre></div>
@endif

<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Agents ({{ $agents->count() }})</h2></div>
    <div class="list">
      @forelse($agents as $a)
        @php($acc = \App\Services\Agents::access($a))
        <details class="row" style="display:block">
          <summary style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;cursor:pointer"><b>{{ $a->name }}</b>
            @if($a->owner)<span class="chip">{{ $a->owner->name }}’s agent</span>@endif
            <span class="chip {{ $a->status === 'active' ? 'ok' : 'bad' }}">{{ $a->status === 'active' ? 'Active' : 'Suspended' }}</span>
            <span class="small muted">last used {{ $a->last_used_at?->diffForHumans() ?? 'never' }}</span></summary>
          @if($a->description)<p class="small muted" style="margin:8px 0 0">{{ $a->description }}</p>@endif
          @if(! $a->owner)
            <form class="form" method="post" action="{{ route('admin.agents.access', [$org->id, $a->id]) }}" style="margin-top:10px">@csrf @method('put')
              @foreach($apps as $app)
                <div class="f"><label>{{ $app->name }}</label><select class="in" name="apps[{{ $app->key }}]"><option value="">No access</option>@foreach($app->roles as $r)<option value="{{ $r->key }}" @selected(($acc[$app->key] ?? null) === $r->key)>{{ $r->name }}</option>@endforeach</select></div>
              @endforeach
              <div class="actions"><button class="btn sm pri">Save access</button></div>
            </form>
          @else<p class="small muted">Same access as {{ $a->owner->name }}, in {{ $org->name }} only.</p>@endif
          <div class="actions" style="margin-top:8px">
            <form method="post" action="{{ route('admin.agents.rotate', [$org->id, $a->id]) }}" data-confirm="Make new credentials? The old ones stop working.">@csrf<button class="btn sm">New secret</button></form>
            <form method="post" action="{{ route('admin.agents.status', [$org->id, $a->id]) }}">@csrf<button class="btn sm">{{ $a->status === 'active' ? 'Suspend' : 'Resume' }}</button></form>
            <form method="post" action="{{ route('admin.agents.destroy', [$org->id, $a->id]) }}" data-confirm="Delete {{ $a->name }}?">@csrf @method('delete')<button class="btn sm danger">Delete</button></form>
          </div>
        </details>
      @empty<div class="empty">No agents yet.</div>@endforelse
    </div>
  </section>
  <form class="card pad form" method="post" action="{{ route('admin.agents.store', $org->id) }}">@csrf
    <h2>New agent</h2>
    <div class="f"><label for="an">Name</label><input class="in" id="an" name="name" required maxlength="80" placeholder="Inventory assistant"></div>
    <div class="f"><label for="ad">What it does</label><input class="in" id="ad" name="description" maxlength="190" placeholder="Reorders stock every night"></div>
    <div class="actions"><button class="btn pri">Create agent</button></div>
    <p class="small muted">Then open it to give it a role in each app. Its tokens carry <span class="mono">sub = agt_…</span> so apps can tell it isn’t a person.</p>
  </form>
</div>
@endsection
