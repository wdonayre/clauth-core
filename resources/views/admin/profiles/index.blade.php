@extends('layouts.console')
@section('title', 'Access profiles · '.$org->name)
@section('content')
<header class="head"><div><h1>Access profiles</h1>
  <p>{{ $org->name }}'s job roles. Each profile gives one role in every app {{ $org->name }} uses; give people a profile instead of setting each app by hand.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>

<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Profiles ({{ $profiles->count() }})</h2></div>
    <div class="list">
      @forelse($profiles as $p)
        <a class="row" href="{{ route('admin.profiles.show', [$org->id, $p->id]) }}">
          <div class="grow"><b>{{ $p->name }}</b>@if($p->description)<br><span class="small muted">{{ $p->description }}</span>@endif</div>
          <span class="chip">{{ $p->users_count }} {{ Str::plural('person', $p->users_count) }}</span>
          @php($n = $mapped[$p->id] ?? 0)
          <span class="chip {{ $n < $apps->count() ? 'warn' : 'ok' }}">{{ $n }}/{{ $apps->count() }} apps set</span>
        </a>
      @empty<div class="empty">No profiles yet. Create one for each job in {{ $org->name }} — for example Owner, Finance, Production.</div>@endforelse
    </div>
  </section>
  <form class="card pad form" method="post" action="{{ route('admin.profiles.store', $org->id) }}">@csrf
    <h2>New profile</h2>
    <div class="f"><label for="pn">Name</label><input class="in" id="pn" name="name" placeholder="Finance" required maxlength="80"></div>
    <div class="f"><label for="pd">Description</label><input class="in" id="pd" name="description" placeholder="Pays bills, approves claims, runs reports" maxlength="190"></div>
    <div class="actions"><button class="btn pri">Create profile</button></div>
    <p class="small muted">People can hold more than one profile. When two give different roles in the same app, the higher tier wins.</p>
  </form>
</div>
@endsection
