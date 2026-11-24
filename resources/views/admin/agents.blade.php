@extends('layouts.console')
@section('title', 'Agents · '.$org->name)
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@push('end')<script src="/js/pages/org-sub.js?v={{ filemtime(public_path('js/pages/org-sub.js')) }}" defer></script>@endpush
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])

<div class="intro">
  <p>AI assistants, bots and scripts that work in {{ $org->name }}’s apps under their own name. Each has its own credentials and roles, shows up in the activity log as itself, and can be suspended any time.</p>
  <div class="actions"><button class="btn pri" type="button" data-open-dialog="#new-agent">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New agent</button></div>
</div>

@if($s = session('agent_secret'))
  <section class="card" aria-labelledby="secret-h">
    <div class="card-h"><div><h2 id="secret-h">Copy the credentials for “{{ $s['name'] }}” now</h2><p>The secret is shown only once. If you lose it, make a new secret.</p></div><span class="badge warn dot">Shown once</span></div>
    <div class="pastebox">
      <div class="line"><span>Client ID</span><span class="code" id="aid">{{ $s['client_id'] }}</span><button class="btn sm" type="button" data-copy="#aid">Copy</button></div>
      <div class="line"><span>Client secret</span><span class="code" id="asec">{{ $s['secret'] }}</span><button class="btn sm" type="button" data-copy="#asec">Copy</button></div>
      <pre class="code">curl -s {{ url('/oauth/token') }} -d grant_type=client_credentials \
  -d client_id={{ $s['client_id'] }} -d client_secret=… -d audience=&lt;app key&gt;</pre>
    </div>
  </section>
@endif

<section class="card" aria-labelledby="agents-h">
  <div class="card-h"><div><h2 id="agents-h">Agents ({{ $agents->count() }})</h2><p>People can also make their own agents (via the API) that act for them with no more access than they have.</p></div></div>
  @if($agents->isEmpty())
    <div class="empty"><b>No agents yet</b><span>Make one for each bot or script, then give it a role in each app.</span></div>
  @else
    <div class="scroll">
      <table class="t">
        <thead><tr><th scope="col">Agent</th><th scope="col">Acts as</th><th scope="col">Status</th><th scope="col">Last used</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($agents as $a)
            <tr>
              <td><span class="person"><span class="av muted" aria-hidden="true">@include('partials.icon', ['i' => 'bot', 'iconSize' => 16])</span>
                <span class="who"><b>{{ $a->name }}</b><span>{{ $a->description ?: 'No description' }}</span></span></span></td>
              <td>@if($a->owner){{ $a->owner->name }}’s agent @else<span class="muted">Itself (organization agent)</span>@endif</td>
              <td><span class="badge dot {{ $a->status === 'active' ? 'ok' : 'bad' }}">{{ $a->status === 'active' ? 'Active' : 'Suspended' }}</span></td>
              <td class="muted nowrap">{{ $a->last_used_at?->diffForHumans() ?? 'Never' }}</td>
              <td class="act"><button class="btn sm" type="button" data-open-dialog="#agent-{{ $a->id }}">Manage</button></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</section>

<dialog class="dlg" id="new-agent" aria-labelledby="new-agent-h" @if($errors->has('name')) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.agents.store', $org->id) }}">@csrf
    <div class="dlg-h"><h2 id="new-agent-h">New agent</h2><p>You get its credentials once. Then give it a role in each app.</p></div>
    <div class="dlg-b">
      <label class="f"><span>Name</span><input class="in" name="name" required maxlength="80" placeholder="Inventory assistant" value="{{ old('name') }}"></label>
      <label class="f"><span>What it does</span><input class="in" name="description" maxlength="190" placeholder="Reorders stock every night" value="{{ old('description') }}"></label>
      <p class="hint" style="margin:0">Its tokens carry <span class="mono">sub = agt_…</span> so apps can tell it isn’t a person.</p>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Create agent</button></div>
  </form>
</dialog>

@foreach($agents as $a)
  @php($acc = \App\Services\Agents::access($a))
  <dialog class="dlg" id="agent-{{ $a->id }}" aria-labelledby="agent-{{ $a->id }}-h">
    <div class="dlg-h"><h2 id="agent-{{ $a->id }}-h">{{ $a->name }}</h2>
      <p>{{ $a->description ?: 'No description' }} · <span class="badge dot {{ $a->status === 'active' ? 'ok' : 'bad' }}">{{ $a->status === 'active' ? 'Active' : 'Suspended' }}</span></p></div>
    @if(! $a->owner)
      <form method="post" action="{{ route('admin.agents.access', [$org->id, $a->id]) }}">@csrf @method('put')
        <div class="dlg-b">
          <span class="lbl">Role in each app</span>
          @forelse($apps as $app)
            <div class="map" style="padding:8px 0;border-top:0">
              <span class="app">@include('partials.ui.app-icon', ['app' => $app, 'size' => 'sm'])<label for="ag{{ $a->id }}-{{ $app->id }}">{{ $app->name }}</label></span>
              <select class="in" id="ag{{ $a->id }}-{{ $app->id }}" name="apps[{{ $app->key }}]"><option value="">No access</option>@foreach($app->roles as $r)<option value="{{ $r->key }}" @selected(($acc[$app->key] ?? null) === $r->key)>{{ $r->name }}</option>@endforeach</select>
            </div>
          @empty
            <p class="hint" style="margin:0">{{ $org->name }} has no apps yet.</p>
          @endforelse
        </div>
        <div class="dlg-f"><button class="btn pri">Save access</button></div>
      </form>
    @else
      <div class="dlg-b"><div class="callout">Same access as {{ $a->owner->name }}, in {{ $org->name }} only.</div></div>
    @endif
    <div class="dlg-f">
      <form method="post" action="{{ route('admin.agents.destroy', [$org->id, $a->id]) }}" data-confirm="Delete {{ $a->name }}? Its credentials stop working now." style="margin-right:auto">@csrf @method('delete')<button class="btn danger">Delete</button></form>
      <form method="post" action="{{ route('admin.agents.status', [$org->id, $a->id]) }}">@csrf<button class="btn">{{ $a->status === 'active' ? 'Suspend' : 'Resume' }}</button></form>
      <form method="post" action="{{ route('admin.agents.rotate', [$org->id, $a->id]) }}" data-confirm="Make new credentials? The old ones stop working.">@csrf<button class="btn">New secret</button></form>
      <button class="btn" type="button" data-close-dialog>Close</button>
    </div>
  </dialog>
@endforeach
@endsection
