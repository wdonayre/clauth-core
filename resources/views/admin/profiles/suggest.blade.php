@extends('layouts.console')
@section('title', 'Suggest profiles · '.$org->name)
@section('content')
<header class="head"><div><h1>Suggest profiles</h1>
  <p>People in {{ $org->name }} without a profile, grouped by the access they have today. Accepting a suggestion creates the profile, gives it to them and removes the exceptions it now covers — <b>nobody's access changes</b>.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">All profiles</a></div></header>
@forelse($suggestions as $s)
  <form class="card pad form" method="post" action="{{ route('admin.profiles.suggest.accept', $org->id) }}" style="margin-bottom:16px">@csrf
    <input type="hidden" name="key" value="{{ $s['key'] }}">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="f grow" style="min-width:260px;flex:1"><label for="n{{ $s['key'] }}">Profile name</label><input class="in" id="n{{ $s['key'] }}" name="name" value="{{ $s['name'] }}" required maxlength="80"></div>
      <button class="btn pri" @disabled($s['blocked'])>Create and give to {{ $s['people']->count() }} {{ Str::plural('person', $s['people']->count()) }}</button>
    </div>
    @if($s['blocked'])<p class="small" style="color:var(--cl-warning)">{{ $s['blocked'] }}</p>@endif
    <div class="pills" style="margin-top:4px">
      @foreach($s['roles'] as $r)<span class="chip">{{ $r->app->name }}: {{ $r->name }}@if($r->tier) · {{ $tiers[$r->tier] }}@endif</span>@endforeach
      @if(in_array(null, $s['map'], true))<span class="chip">No access to the other apps</span>@endif
    </div>
    <p class="small muted" style="margin:4px 0 0">{{ $s['people']->pluck('name')->implode(', ') }}</p>
  </form>
@empty
  <div class="card pad"><p class="muted">Nothing to suggest: everyone in {{ $org->name }} with access already has a profile.</p></div>
@endforelse
@endsection
