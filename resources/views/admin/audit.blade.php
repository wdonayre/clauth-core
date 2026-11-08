@extends('layouts.console')
@section('title', 'Audit log')
@section('content')
<header class="head">
  <div><h1>Audit log</h1><p>Every sign-in, security event and admin change. Kept for one year.</p></div>
  <a class="btn" href="{{ route('admin.audit.export', request()->query()) }}">Export CSV</a>
</header>
<nav class="tabs" aria-label="Category">
  @foreach(['all' => 'All', 'signin' => 'Sign-in', 'security' => 'Security', 'role' => 'Roles', 'admin' => 'Admin', 'app' => 'Apps'] as $k => $l)
    <a class="{{ $cat === $k ? 'on' : '' }}" href="{{ route('admin.audit', array_merge(request()->except('page', 'cat'), $k === 'all' ? [] : ['cat' => $k])) }}">{{ $l }}</a>
  @endforeach
</nav>
<form class="actions" method="get">
  @if(request('cat'))<input type="hidden" name="cat" value="{{ request('cat') }}">@endif
  <label class="sr-only" for="aq">Search</label><input class="in" id="aq" name="q" value="{{ request('q') }}" placeholder="Person, event or IP" style="max-width:260px">
  <label class="small muted" for="af">From</label><input class="in" id="af" type="date" name="from" value="{{ request('from') }}" style="max-width:170px">
  <label class="small muted" for="at">To</label><input class="in" id="at" type="date" name="to" value="{{ request('to') }}" style="max-width:170px">
  <button class="btn">Filter</button>
</form>
<section class="card"><div class="list">
  @forelse($events as $e)@include('admin.partials.event', ['e' => $e])@empty<div class="empty">No events match.</div>@endforelse
</div>@if($events->hasPages())<div class="pager">{!! $events->links('pagination::simple-default') !!}</div>@endif</section>
@endsection
