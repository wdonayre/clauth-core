@extends('layouts.console')
@section('title', 'Updates')
@section('content')
@php($n = $edit)
<header class="head">
  <div><h1>Updates</h1><p>Release notes for each app. They show on the bell in the Clauth bar until each person reads them.</p></div>
</header>
<div class="grid2">
  <form class="card pad form" method="post" action="{{ $n ? route('admin.updates.update', $n) : route('admin.updates.store') }}">@csrf
    @if($n) @method('put') @endif
    <h2>{{ $n ? 'Edit update' : 'Post an update' }}</h2>
    <div class="f"><label for="ua">App</label>
      <select class="in" id="ua" name="app_id">
        <option value="">Every app</option>
        @foreach($apps as $a)<option value="{{ $a->id }}" @selected(old('app_id', $n?->app_id) === $a->id)>{{ $a->name }}</option>@endforeach
      </select></div>
    <div class="f"><label for="uv">Version (optional)</label><input class="in mono" id="uv" name="version" value="{{ old('version', $n?->version) }}" placeholder="v0.17.0"></div>
    <div class="f"><label for="ut">Title</label><input class="in" id="ut" name="title" value="{{ old('title', $n?->title) }}" placeholder="Receipts upload faster on mobile" required maxlength="160"></div>
    <div class="f"><label for="ub">What changed (optional)</label><textarea class="in" id="ub" name="body" style="font-family:var(--cl-font-body);font-size:14px" placeholder="One change per line. Keep it short: people read this in a small panel.">{{ old('body', $n?->body) }}</textarea></div>
    <div class="f"><label for="ul">Link (optional)</label><input class="in mono" id="ul" name="link" value="{{ old('link', $n?->link) }}" placeholder="https://…/changelog"></div>
    <div class="f"><label for="up">Publish at (optional — blank = now)</label><input class="in" id="up" type="datetime-local" name="published_at" value="{{ old('published_at', $n?->published_at?->format('Y-m-d\TH:i')) }}"></div>
    <div class="actions"><button class="btn pri">{{ $n ? 'Save' : 'Post update' }}</button>@if($n)<a class="btn" href="{{ route('admin.updates') }}">Cancel</a>@endif</div>
    <p class="small muted" style="margin:0">Releases can post these automatically: a server-to-server client of the app with scope <code>updates:write</code> calls <code>POST {{ config('clauth.issuer') }}/api/v1/release-notes</code> with <code>title</code>, <code>version</code>, <code>body</code>, <code>link</code>.</p>
  </form>

  <section class="card" aria-labelledby="ul-h">
    <div class="card-h"><h2 id="ul-h">Posted</h2></div>
    <div class="list">
      @forelse($notes as $note)
        <div class="row" style="align-items:flex-start">
          <span class="ico" style="background:{{ $note->app?->color ?? '#22D3EE' }}">{{ $note->app?->letter() ?? '★' }}</span>
          <div class="grow">
            <b>{{ $note->title }}</b> @if($note->version)<span class="chip mono">{{ $note->version }}</span>@endif
            @if($note->published_at->isFuture())<span class="chip warn">Scheduled</span>@endif
            <br><span class="small muted">{{ $note->app?->name ?? 'Every app' }} · {{ $note->published_at->format('j M Y, H:i') }} · read by {{ $reads[$note->id] ?? 0 }} · {{ $note->source === 'api' ? 'posted by the app' : 'posted here' }}</span>
            @if($note->body)<p class="small" style="margin:6px 0 0;color:#C4CDD8;white-space:pre-line">{{ \Illuminate\Support\Str::limit($note->body, 240) }}</p>@endif
          </div>
          <a class="btn sm" href="{{ route('admin.updates', ['edit' => $note->id]) }}">Edit</a>
          <form method="post" action="{{ route('admin.updates.destroy', $note) }}" data-confirm="Remove this update from every bar?">@csrf @method('delete')<button class="btn sm danger">Remove</button></form>
        </div>
      @empty<div class="empty">Nothing posted yet.</div>@endforelse
    </div>
    @if($notes->hasPages())<div class="pager">{!! $notes->links('pagination::simple-default') !!}</div>@endif
  </section>
</div>
@endsection
