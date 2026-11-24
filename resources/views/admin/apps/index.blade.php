@extends('layouts.console')
@section('title', 'Apps')
@push('head')<link rel="stylesheet" href="/css/pages/apps.css?v={{ filemtime(public_path('css/pages/apps.css')) }}">@endpush
@section('content')
<header class="head">
  <div><h1>Apps</h1><p>Everything that signs people in through Clauth.</p></div>
  <div class="actions"><button class="btn pri" type="button" data-open-dialog="#connect">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Connect an app</button></div>
</header>

@if($all->count() > 3 || $q !== '' || $status !== '')
<form class="toolbar" method="get" action="{{ route('admin.apps.index') }}" role="search" data-app-filter>
  <label class="searchin">
    <span class="sr-only">Search apps</span>
    @include('partials.icon', ['i' => 'search', 'iconSize' => 16])
    <input class="in" type="search" name="q" value="{{ $q }}" placeholder="Search apps by name or key" autocomplete="off" data-app-search>
  </label>
  @if($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
  <nav class="seg" aria-label="Filter by status">
    @foreach(['' => 'All', 'live' => 'Live', 'not_connected' => 'Not connected', 'paused' => 'Paused'] + (auth()->user()->isOwner() ? ['suspended' => 'Suspended'] : []) as $k => $l)
      <a class="{{ $status === $k ? 'on' : '' }}" href="{{ route('admin.apps.index', array_filter(['q' => $q, 'status' => $k])) }}" @if($status === $k) aria-current="page" @endif>{{ $l }}</a>
    @endforeach
  </nav>
</form>
@endif

<section class="apps-grid" aria-label="Apps">
  @foreach($apps as $a)
    @php($host = $a->domains->first()?->host ?? \App\Services\Domains::mainHost())
    <a class="card appcard" href="{{ route('admin.apps.show', $a->key) }}" data-app-card="{{ mb_strtolower($a->name.' '.$a->key.' '.$a->description) }}">
      <div class="top">
        @include('partials.ui.app-icon', ['app' => $a, 'size' => 'lg'])
        <div class="grow" style="min-width:0"><div class="nm">{{ $a->name }}</div><div class="ds">{{ $a->description ?: $a->key }}</div></div>
        @include('admin.apps.status', ['a' => $a])
      </div>
      <div class="meta">
        <div><span>People</span><div>{{ $a->grants_count }}</div></div>
        <div><span>Signed in today</span><div>{{ $today[$a->id] ?? 0 }}</div></div>
        <div><span>Organizations</span><div>{{ $a->organizations->count() }}</div></div>
        <div><span>Sign-in page</span><div title="{{ $host }}">{{ $host }}</div></div>
      </div>
      <span class="small muted">Made by {{ $a->publisher?->name ?? '—' }} · {{ $a->clients->where('revoked', false)->count() }} {{ Str::plural('client', $a->clients->where('revoked', false)->count()) }} · Clauth bar {{ $a->bar_seen_at ? 'seen '.$a->bar_seen_at->diffForHumans() : 'not installed yet' }}</span>
    </a>
  @endforeach
  @if($apps->isEmpty() && ($q !== '' || $status !== ''))
    <div class="card empty" style="grid-column:1/-1"><b>No apps match</b><span>Try another name or clear the filter.</span><a class="btn sm" href="{{ route('admin.apps.index') }}">Clear</a></div>
  @endif
  <div class="card appcard next" data-app-next>
    <div class="nm">{{ $all->isEmpty() ? 'Connect your first app' : 'Connect your next app' }}</div>
    <ol><li>Name it and add its sign-in address</li><li>Copy the secret into the app</li><li>Choose who can use it</li></ol>
    <div class="go"><button class="btn" type="button" data-open-dialog="#connect">Start</button><a href="https://helloclauth.com/docs/" target="_blank" rel="noopener">Read the guide</a></div>
  </div>
</section>
<p class="small muted" data-app-none hidden>No apps match that search.</p>

@include('admin.apps.connect')
@endsection
@push('end')<script src="/js/pages/apps.js?v={{ filemtime(public_path('js/pages/apps.js')) }}" defer></script>@endpush
