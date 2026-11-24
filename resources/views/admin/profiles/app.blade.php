@extends('layouts.console')
@section('title', $app->name.' · Job roles · '.$org->name)
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])

<div class="intro">
  <p>Pick the {{ $app->name }} role for each job role. Job roles that haven't set it yet are pre-filled with a suggestion — check them before saving.</p>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">@include('partials.icon', ['i' => 'left', 'iconSize' => 16])All job roles</a></div>
</div>

<form class="card" method="post" action="{{ route('admin.profiles.app.save', [$org->id, $app->key]) }}">@csrf @method('put')
  <div class="card-h"><div style="display:flex;align-items:center;gap:12px">@include('partials.ui.app-icon', ['app' => $app])
    <div><h2>{{ $app->name }} in every job role</h2><p>Everyone holding these job roles is updated; apps pick it up within 15 minutes.</p></div></div></div>
  <div class="map h"><span>Job role</span><span>Role in {{ $app->name }}</span></div>
  @forelse($rows as $r)
    @php($curRole = is_numeric($r['value']) ? $app->roles->firstWhere('id', (int) $r['value']) : null)
    @php($locked = $r['set'] && $curRole?->tier === 'owner' && ! $canOwner)
    <div class="map">
      <span class="app"><span><label for="a{{ $r['profile']->id }}"><b>{{ $r['profile']->name }}</b></label>
        @if(! $r['set'])<span class="badge warn">{{ $r['value'] !== '' ? 'suggested' : 'not set' }}</span>@endif</span></span>
      <select class="in" id="a{{ $r['profile']->id }}" name="map[{{ $r['profile']->id }}]" @disabled($locked)>
        <option value="" @selected($r['value'] === '')>Not set</option>
        <option value="none" @selected($r['value'] === 'none')>No access</option>
        @foreach($app->roles->sortBy(fn ($x) => [$x->tierRank(), $x->position]) as $x)
          @if($x->assignable || $curRole?->id === $x->id)
            <option value="{{ $x->id }}" @selected($curRole?->id === $x->id) @disabled(! $x->tier || ($x->tier === 'owner' && ! $canOwner))>{{ $x->name }} · {{ $x->tier ? $tiers[$x->tier] : 'no tier yet' }}</option>
          @endif
        @endforeach
      </select>
      @if($locked)<span class="note">Only an owner of {{ $org->name }} can change an Owner-tier role.</span>@endif
    </div>
  @empty
    <div class="empty"><b>No job roles yet</b><span>{{ $org->name }} has no job roles to set.</span></div>
  @endforelse
  <div class="card-f"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">Cancel</a><button class="btn pri">Save</button></div>
</form>
@endsection
