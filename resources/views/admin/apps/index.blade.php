@extends('layouts.console')
@section('title', 'Apps')
@section('content')
<header class="head">
  <div><h1>Apps</h1><p>Everything that signs people in through Clauth.</p></div>
  <details class="modal" @if(request('new') || $errors->any()) open @endif>
    <summary class="btn pri">@include('partials.icon', ['i' => 'plus'])Register app</summary>
    <div class="sheet"><div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center"><h2>Register app</h2><a class="btn sm" href="{{ route('admin.apps.index') }}">Close</a></div>
      <form class="form" method="post" action="{{ route('admin.apps.store') }}" style="margin-top:16px">@csrf
        <div class="f"><label for="an">Name</label><input class="in" id="an" name="name" value="{{ old('name') }}" placeholder="Xpenses" required></div>
        <div class="f"><label for="ak">Key</label><input class="in mono" id="ak" name="key" value="{{ old('key') }}" placeholder="xpenses" pattern="[a-z][a-z0-9-]{1,40}" required>
          <span class="small muted">Used as the token audience (<code>aud</code>). Can’t be changed later.</span></div>
        <div class="f"><label for="at">Type</label>
          <select class="in" id="at" name="type">
            <option value="web" @selected(old('type') === 'web')>Web app (server-side, has a secret)</option>
            <option value="public" @selected(old('type') === 'public')>Mobile app or SPA (no secret, PKCE)</option>
            <option value="m2m" @selected(old('type') === 'm2m')>Server-to-server (client credentials)</option>
          </select></div>
        <div class="f"><label for="ar">Redirect URL</label><input class="in mono" id="ar" name="redirect_uri" value="{{ old('redirect_uri') }}" placeholder="https://expenses.thecakelabs.com/auth/callback">
          <span class="small muted">Exact match only. Not needed for server-to-server.</span></div>
        <div class="f"><label for="ah">Home URL</label><input class="in mono" id="ah" name="home_url" value="{{ old('home_url') }}" placeholder="https://expenses.thecakelabs.com"></div>
        <div class="f"><span>Organizations that can use it</span>
          @foreach($orgs as $o)<label class="cb"><input type="checkbox" name="orgs[]" value="{{ $o->id }}" @checked(in_array($o->id, old('orgs', [])))> {{ $o->name }}</label>@endforeach</div>
        <div class="actions"><button class="btn pri">Register</button></div>
      </form>
    </div></div>
  </details>
</header>
<section class="grid3">
  @forelse($apps as $a)
    <a class="card pad" href="{{ route('admin.apps.show', $a->key) }}" style="display:flex;flex-direction:column;gap:12px;color:#E8EDF2">
      <span style="display:flex;gap:12px;align-items:center"><span class="ico lg" style="background:{{ $a->color }}">{{ $a->letter() }}</span>
        <span class="grow"><b style="font-size:16px">{{ $a->name }}</b><br><span class="small muted mono">{{ $a->key }}</span></span>
        <span class="chip {{ ['live' => 'ok', 'paused' => 'warn'][$a->status] ?? '' }}">{{ ['live' => 'Live', 'paused' => 'Paused', 'not_connected' => 'Not connected'][$a->status] }}</span></span>
      <span class="small muted">{{ $a->grants_count }} people · {{ $a->clients->count() }} client(s) · {{ $a->organizations->pluck('name')->implode(', ') ?: 'no organizations' }}</span>
    </a>
  @empty<div class="card empty">No apps yet. Register the first one.</div>@endforelse
</section>
@endsection
