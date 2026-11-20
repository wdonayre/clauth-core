@extends('layouts.console')
@section('title', $profile->name.' · '.$org->name)
@section('content')
<header class="head"><div><h1>{{ $profile->name }}</h1><p>Access profile in {{ $org->name }} · {{ $holders->count() }} {{ Str::plural('person', $holders->count()) }}</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">All profiles</a></div></header>

<div class="grid2">
  <form class="card" method="post" action="{{ route('admin.profiles.update', [$org->id, $profile->id]) }}">@csrf @method('put')
    <div class="card-h"><h2>Role in each app</h2></div>
    <div class="list">
      <div class="row form" style="gap:12px;flex-wrap:wrap">
        <div class="f grow"><label for="pn">Name</label><input class="in" id="pn" name="name" value="{{ $profile->name }}" required maxlength="80"></div>
        <div class="f grow"><label for="pd">Description</label><input class="in" id="pd" name="description" value="{{ $profile->description }}" maxlength="190"></div>
      </div>
      @forelse($apps as $a)
        @php($has = array_key_exists($a->id, $mapping))
        @php($cur = $has ? ($mapping[$a->id] ?? 'none') : '')
        @php($curRole = is_numeric($cur) ? $a->roles->firstWhere('id', (int) $cur) : null)
        @php($locked = $curRole?->tier === 'owner' && ! $canOwner)
        <div class="row">
          <span class="ico" style="background:{{ $a->color }}">{{ $a->letter() }}</span>
          <label class="grow" for="m{{ $a->id }}"><b>{{ $a->name }}</b>
            @unless($has)<br><span class="chip warn">not set — holders get no access yet</span>@endunless</label>
          <select class="in" id="m{{ $a->id }}" name="map[{{ $a->id }}]" style="max-width:260px" @disabled($locked)>
            <option value="" @selected($cur === '')>Not set</option>
            <option value="none" @selected($cur === 'none')>No access</option>
            @foreach($a->roles->sortBy(fn ($r) => [$r->tierRank(), $r->position]) as $r)
              @if($r->assignable || $curRole?->id === $r->id)
                <option value="{{ $r->id }}" @selected($curRole?->id === $r->id) @disabled(! $r->tier || ($r->tier === 'owner' && ! $canOwner))>{{ $r->name }} · {{ $r->tier ? $tiers[$r->tier] : 'no tier yet' }}</option>
              @endif
            @endforeach
          </select>
        </div>
        @if($locked)<div class="row small muted">Only an owner of {{ $org->name }} can change an Owner-tier role.</div>@endif
      @empty<div class="empty">{{ $org->name }} has no apps installed.</div>@endforelse
      <div class="row"><button class="btn pri">Save profile</button><span class="small muted">Everyone with this profile is updated; apps pick it up within 15 minutes.</span></div>
    </div>
  </form>

  <div style="display:flex;flex-direction:column;gap:20px">
    <section class="card">
      <div class="card-h"><h2>People with this profile</h2></div>
      <div class="list">
        @forelse($holders as $u)
          <div class="row"><a class="grow" href="{{ route('admin.users.show', [$u->id, 'org' => $org->id]) }}"><b>{{ $u->name }}</b> <span class="small muted">{{ $u->email }}</span></a>
            <form method="post" action="{{ route('admin.profiles.people.remove', [$org->id, $profile->id, $u->id]) }}">@csrf @method('delete')<button class="btn sm">Remove</button></form></div>
        @empty<div class="empty">Nobody yet.</div>@endforelse
        @if($members->isNotEmpty())
          <form class="row" method="post" action="{{ route('admin.profiles.people.add', [$org->id, $profile->id]) }}">@csrf
            <label class="sr-only" for="addp">Add person</label>
            <select class="in grow" id="addp" name="user_id">@foreach($members as $m)<option value="{{ $m->id }}">{{ $m->name }} — {{ $m->email }}</option>@endforeach</select>
            <button class="btn sm">Add</button></form>
        @endif
      </div>
    </section>
    <form class="card pad" method="post" action="{{ route('admin.profiles.destroy', [$org->id, $profile->id]) }}" data-confirm="Delete the {{ $profile->name }} profile? The {{ $holders->count() }} {{ Str::plural('person', $holders->count()) }} who hold it lose the access it gave them (other profiles and exceptions stay).">@csrf @method('delete')
      <h2>Delete profile</h2><p class="small muted">People keep access only through their other profiles or exceptions.</p>
      <button class="btn danger">Delete {{ $profile->name }}</button></form>
  </div>
</div>
@endsection
