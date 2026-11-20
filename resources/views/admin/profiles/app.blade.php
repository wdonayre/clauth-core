@extends('layouts.console')
@section('title', $app->name.' · Access profiles')
@section('content')
<header class="head"><div><h1>{{ $app->name }} in every profile</h1>
  <p>Pick the {{ $app->name }} role for each of {{ $org->name }}'s profiles. Profiles that haven't set it yet are pre-filled with a suggestion — check them before saving.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">All profiles</a></div></header>
<form class="card" method="post" action="{{ route('admin.profiles.app.save', [$org->id, $app->key]) }}">@csrf @method('put')
  <div class="list">
    @forelse($rows as $r)
      @php($curRole = is_numeric($r['value']) ? $app->roles->firstWhere('id', (int) $r['value']) : null)
      @php($locked = $r['set'] && $curRole?->tier === 'owner' && ! $canOwner)
      <div class="row">
        <label class="grow" for="a{{ $r['profile']->id }}"><b>{{ $r['profile']->name }}</b>
          @if(! $r['set'])<span class="chip warn">{{ $r['value'] !== '' ? 'suggested' : 'not set' }}</span>@endif</label>
        <select class="in" id="a{{ $r['profile']->id }}" name="map[{{ $r['profile']->id }}]" style="max-width:280px" @disabled($locked)>
          <option value="" @selected($r['value'] === '')>Not set</option>
          <option value="none" @selected($r['value'] === 'none')>No access</option>
          @foreach($app->roles->sortBy(fn ($x) => [$x->tierRank(), $x->position]) as $x)
            @if($x->assignable || $curRole?->id === $x->id)
              <option value="{{ $x->id }}" @selected($curRole?->id === $x->id) @disabled(! $x->tier || ($x->tier === 'owner' && ! $canOwner))>{{ $x->name }} · {{ $x->tier ? $tiers[$x->tier] : 'no tier yet' }}</option>
            @endif
          @endforeach
        </select>
      </div>
    @empty<div class="empty">{{ $org->name }} has no profiles yet.</div>@endforelse
    <div class="row"><button class="btn pri">Save</button><span class="small muted">Everyone holding these profiles is updated; apps pick it up within 15 minutes.</span></div>
  </div>
</form>
@endsection
