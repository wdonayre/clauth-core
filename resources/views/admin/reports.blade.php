@extends('layouts.console')
@section('title', 'Reports')
@push('head')<link rel="stylesheet" href="/css/pages/overview.css?v={{ filemtime(public_path('css/pages/overview.css')) }}">@endpush
@section('content')
@php($reasonBadge = ['phishing' => ['Possible phishing', 'bad'], 'impersonation' => ['Impersonation', 'bad'], 'spam' => ['Spam', 'warn'], 'other' => ['Other', 'muted']])
@php($change = ['app.registered' => ['New app', 'warn', 'registered'], 'app.branding_changed' => ['Sign-in page changed', 'muted', 'changed the sign-in page of'],
    'app.updated' => ['App changed', 'muted', 'changed the name or settings of'], 'app.domain_added' => ['New domain', 'warn', 'added a sign-in domain to']])
<header class="head">
  <div><h1>Reports</h1><p>Sign-in pages people flagged as suspicious, and changes outside organizations made to their apps.</p></div>
</header>

<nav class="seg" aria-label="Show">
  <a class="{{ $show === 'open' ? 'on' : '' }}" @if($show === 'open') aria-current="page" @endif href="{{ route('admin.reports') }}">Open · {{ $open->count() }}</a>
  <a class="{{ $show === 'closed' ? 'on' : '' }}" @if($show === 'closed') aria-current="page" @endif href="{{ route('admin.reports', ['show' => 'closed']) }}">Resolved</a>
  <a class="{{ $show === 'changes' ? 'on' : '' }}" @if($show === 'changes') aria-current="page" @endif href="{{ route('admin.reports', ['show' => 'changes']) }}">Outside app changes · {{ $feed->count() }}</a>
</nav>

@if($show === 'open')
<section class="card" aria-label="Open reports">
  @forelse($open as $r)
    @php([$bl, $bt] = $reasonBadge[$r->reason] ?? [\Illuminate\Support\Str::headline($r->reason), 'muted'])
    @php($by = $r->reporter_user_id ? $people[$r->reporter_user_id] ?? null : null)
    <div class="rep">
      <span class="badge {{ $bt }}">{{ $bl }}</span>
      <div class="body">
        <b class="strong">{{ $r->host }}: {{ \Illuminate\Support\Str::lcfirst(\App\Models\Report::REASONS[$r->reason] ?? $r->reason) }}</b>
        <div class="sub small muted">Reported by {{ $by?->email ?? 'someone not signed in' }} · from the “Report this page” link · {{ $r->created_at->diffForHumans() }}
          @if($r->app) · <a href="{{ route('admin.apps.show', [$r->app->key, 'tab' => 'settings']) }}">{{ $r->app->name }}</a>@if($r->app->publisher) by {{ $r->app->publisher->name }}@endif @endif</div>
        @if($r->details)<p class="quote">“{{ $r->details }}”</p>@endif
        @if($r->url)<div class="small muted mono url">{{ \Illuminate\Support\Str::limit($r->url, 160) }}</div>@endif
      </div>
      <div class="actions">
        <form method="post" action="{{ route('admin.reports.close', $r->id) }}">@csrf<button class="btn">Mark resolved</button></form>
        @if($r->app && ! $r->app->isSuspended())
          <button class="btn danger" type="button" data-open-dialog="#suspend-{{ $r->id }}">Suspend the app</button>
          <dialog class="dlg" id="suspend-{{ $r->id }}" aria-labelledby="suspend-{{ $r->id }}-h">
            <form method="post" action="{{ route('admin.apps.suspend', $r->app->key) }}">@csrf
              <div class="dlg-h"><h2 id="suspend-{{ $r->id }}-h">Suspend {{ $r->app->name }}?</h2><p>Nobody can sign in to it until you restore it. Its organization sees the reason.</p></div>
              <div class="dlg-b"><label class="f"><span>Reason</span><textarea class="in prose" name="reason" required maxlength="500">Reported as a suspicious sign-in page ({{ $r->host }}).</textarea></label></div>
              <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn danger solid">Suspend</button></div>
            </form>
          </dialog>
        @elseif($r->app)
          <a class="btn" href="{{ route('admin.apps.show', [$r->app->key, 'tab' => 'settings']) }}">App suspended</a>
        @endif
      </div>
    </div>
  @empty
    <div class="empty"><b>No open reports</b><span>When someone uses “Report this page” on a sign-in page, it shows up here.</span></div>
  @endforelse
</section>
@elseif($show === 'closed')
<section class="card" aria-label="Resolved reports">
  @forelse($closed as $r)
    @php([$bl, $bt] = $reasonBadge[$r->reason] ?? [\Illuminate\Support\Str::headline($r->reason), 'muted'])
    <div class="rep">
      <span class="badge muted">{{ $bl }}</span>
      <div class="body"><b class="strong">{{ $r->host }}</b>@if($r->app) <span class="muted">· {{ $r->app->name }}</span>@endif
        <div class="sub small muted">Resolved {{ $r->closed_at?->diffForHumans() }}@if($r->closed_by && isset($people[$r->closed_by])) by {{ $people[$r->closed_by]->name }}@endif · reported {{ $r->created_at->diffForHumans() }}</div>
        @if($r->details)<p class="quote">“{{ $r->details }}”</p>@endif
      </div>
    </div>
  @empty
    <div class="empty"><b>Nothing resolved yet</b><span>Reports you mark resolved stay here for reference.</span></div>
  @endforelse
</section>
@else
<section class="card" aria-labelledby="ch-h">
  <div class="card-h"><div><h2 id="ch-h">Recent changes by outside organizations</h2><p>New apps, names and logos from organizations that aren’t yours, last 30 days.</p></div></div>
  @forelse($feed as $e)
    @php([$cl, $ct, $verb] = $change[$e->event])
    <div class="rep">
      <span class="badge {{ $ct }}">{{ $cl }}</span>
      <div class="body"><b class="strong">{{ $e->app?->publisher?->name ?? 'An organization' }} {{ $verb }} “{{ $e->app?->name }}”</b>
        <div class="sub small muted">{{ $e->at->diffForHumans() }}@if($e->actor) · by {{ $e->actor->email }}@endif</div></div>
      @if($e->app && ! $e->app->trashed())<a class="btn" href="{{ route('admin.apps.show', [$e->app->key, 'tab' => 'settings']) }}">Review</a>@endif
    </div>
  @empty
    <div class="empty"><b>No outside changes</b><span>No new apps or branding changes from outside organizations.</span></div>
  @endforelse
</section>
@endif
@endsection
