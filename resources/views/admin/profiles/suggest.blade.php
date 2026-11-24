@extends('layouts.console')
@section('title', 'Suggest job roles · '.$org->name)
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])

<div class="intro">
  <p>People in {{ $org->name }} without a job role, grouped by the access they have today. Accepting a suggestion creates the job role, gives it to them and removes the exceptions it now covers — <b>nobody's access changes</b>.</p>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">@include('partials.icon', ['i' => 'left', 'iconSize' => 16])All job roles</a></div>
</div>

@forelse($suggestions as $s)
  <form class="card" method="post" action="{{ route('admin.profiles.suggest.accept', $org->id) }}">@csrf
    <input type="hidden" name="key" value="{{ $s['key'] }}">
    <div class="card-h">
      <div><span class="badge warn">Suggested</span>
        <p>{{ $s['people']->count() }} {{ Str::plural('person', $s['people']->count()) }} share these roles: {{ $s['people']->pluck('name')->implode(', ') }}</p></div>
    </div>
    <div class="set">
      <div class="pills">
        @foreach($s['roles'] as $r)<span class="badge info">{{ $r->app->name }}: {{ $r->name }}@if($r->tier) · {{ $tiers[$r->tier] }}@endif</span>@endforeach
        @if(in_array(null, $s['map'], true))<span class="badge muted">No access to the other apps</span>@endif
      </div>
    </div>
    <div class="set">
      <label class="f" style="flex:1 1 260px"><span>Job role name</span><input class="in" name="name" value="{{ $s['name'] }}" required maxlength="80"></label>
      <button class="btn pri" style="align-self:flex-end" @disabled($s['blocked'])>Create and give to {{ $s['people']->count() }} {{ Str::plural('person', $s['people']->count()) }}</button>
    </div>
    @if($s['blocked'])<div class="set"><div class="flash warn grow">{{ $s['blocked'] }}</div></div>@endif
  </form>
@empty
  <div class="card"><div class="empty"><b>Nothing to suggest</b><span>Everyone in {{ $org->name }} with access already has a job role.</span></div></div>
@endforelse
@endsection
