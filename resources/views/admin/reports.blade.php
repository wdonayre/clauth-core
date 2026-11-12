@extends('layouts.console')
@section('title', 'Reports')
@section('content')
<header class="head">
  <div><h1>Reports</h1><p>Sign-in pages people flagged, and what outside organizations changed on theirs lately. Suspend an app from its Settings tab.</p></div>
</header>
<section class="card" aria-labelledby="ro-h">
  <div class="card-h"><h2 id="ro-h">Open reports</h2></div>
  <div class="list">
    @forelse($open as $r)
      <div class="row"><span class="grow"><b>{{ \App\Models\Report::REASONS[$r->reason] ?? $r->reason }}</b> · <span class="mono">{{ $r->host }}</span>
        @if($r->app) · <a href="{{ route('admin.apps.show', [$r->app->key, 'tab' => 'settings']) }}">{{ $r->app->name }}</a>@if($r->app->publisher) <span class="small muted">by {{ $r->app->publisher->name }}</span>@endif @endif
        @if($r->details)<br><span class="small">{{ $r->details }}</span>@endif
        @if($r->url)<br><span class="small muted mono">{{ \Illuminate\Support\Str::limit($r->url, 120) }}</span>@endif</span>
        <span class="small muted">{{ $r->created_at->diffForHumans() }}</span>
        <form method="post" action="{{ route('admin.reports.close', $r->id) }}">@csrf<button class="btn sm">Close</button></form></div>
    @empty<div class="empty">No open reports.</div>@endforelse
  </div>
</section>
<section class="card" aria-labelledby="rf-h" style="margin-top:20px">
  <div class="card-h"><h2 id="rf-h">Outside apps: recent changes (30 days)</h2></div>
  <div class="list">
    @forelse($feed as $e)@include('admin.partials.event', ['e' => $e])@empty<div class="empty">No new apps or branding changes from outside organizations.</div>@endforelse
  </div>
</section>
@if($closed->isNotEmpty())
<section class="card" style="margin-top:20px"><div class="card-h"><h2>Recently closed</h2></div><div class="list">
  @foreach($closed as $r)<div class="row small"><span class="grow">{{ \App\Models\Report::REASONS[$r->reason] ?? $r->reason }} · <span class="mono">{{ $r->host }}</span>@if($r->app) · {{ $r->app->name }}@endif</span><span class="muted">closed {{ $r->closed_at?->diffForHumans() }}</span></div>@endforeach
</div></section>
@endif
@endsection
