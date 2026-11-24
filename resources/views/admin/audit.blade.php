@extends('layouts.console')
@section('title', 'Activity')
@push('head')<link rel="stylesheet" href="/css/pages/overview.css?v={{ filemtime(public_path('css/pages/overview.css')) }}">@endpush
@section('content')
@php($kinds = \App\Http\Controllers\Admin\AuditController::KINDS)
@php($ranges = \App\Http\Controllers\Admin\AuditController::RANGES)
<header class="head">
  <div><h1>Activity</h1><p>Every sign-in and change, kept for one year.</p></div>
  <a class="btn" href="{{ route('admin.audit.export', request()->except('page')) }}">@include('partials.icon', ['i' => 'download', 'iconSize' => 16])Download CSV</a>
</header>

<form class="filters" method="get" action="{{ route('admin.audit') }}" role="search" aria-label="Filter activity">
  <nav class="seg" aria-label="Kind">
    @foreach($kinds as $k => $l)
      <a class="{{ $cat === $k ? 'on' : '' }}" @if($cat === $k) aria-current="page" @endif href="{{ route('admin.audit', array_merge(request()->except('page', 'cat'), $k === 'all' ? [] : ['cat' => $k])) }}">{{ $l }}</a>
    @endforeach
  </nav>
  @if($cat !== 'all')<input type="hidden" name="cat" value="{{ $cat }}">@endif
  <label class="searchin"><span class="sr-only">Search activity</span>@include('partials.icon', ['i' => 'search', 'iconSize' => 16])<input class="in" type="search" name="q" value="{{ request('q') }}" placeholder="Person, app or IP address"></label>
  @if($apps->count() > 1)
    <label><span class="sr-only">App</span>
      <select class="in" name="app" data-autosubmit aria-label="App">
        <option value="">All apps</option>
        @foreach($apps as $a)<option value="{{ $a->key }}" @selected(request('app') === $a->key)>{{ $a->name }}</option>@endforeach
      </select></label>
  @endif
  <label><span class="sr-only">When</span>
    <select class="in" name="range" data-autosubmit aria-label="When">
      @foreach($ranges as $k => $l)<option value="{{ $k }}" @selected($range === $k)>{{ $l }}</option>@endforeach
    </select></label>
  @if($range === 'custom')
    <span class="dates">
      <label class="small muted" for="af">From</label><input class="in" id="af" type="date" name="from" value="{{ request('from') }}">
      <label class="small muted" for="at">To</label><input class="in" id="at" type="date" name="to" value="{{ request('to') }}">
    </span>
  @endif
  <button class="btn">Search</button>
  @if(request()->hasAny(['q', 'app', 'range', 'from', 'to', 'cat']))<a class="btn ghost" href="{{ route('admin.audit') }}">Clear</a>@endif
</form>

<section class="card" aria-label="Activity">
  @php($lastDay = null)
  @forelse($events as $e)
    @php($day = \App\Http\Controllers\Admin\AuditController::day($e->at))
    @if($day !== $lastDay)<div class="day">{{ $day }}</div>@php($lastDay = $day)@endif
    @include('admin.partials.event', ['e' => $e])
  @empty
    <div class="empty"><b>Nothing here</b><span>No activity matches these filters.</span></div>
  @endforelse
</section>
@if($events->hasPages())
  <nav class="pager" aria-label="Pages">
    <span class="small">Showing {{ $events->firstItem() }}–{{ $events->lastItem() }} of {{ number_format($events->total()) }}</span>
    <span class="actions">
      @if($events->onFirstPage())<span class="btn sm disabled" aria-disabled="true">Newer</span>@else<a class="btn sm" href="{{ $events->previousPageUrl() }}" rel="prev">Newer</a>@endif
      @if($events->hasMorePages())<a class="btn sm" href="{{ $events->nextPageUrl() }}" rel="next">Older</a>@else<span class="btn sm disabled" aria-disabled="true">Older</span>@endif
    </span>
  </nav>
@endif
@endsection
