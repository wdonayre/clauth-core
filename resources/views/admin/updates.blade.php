@extends('layouts.console')
@section('title', 'Release notes')
@push('head')<link rel="stylesheet" href="/css/pages/overview.css?v={{ filemtime(public_path('css/pages/overview.css')) }}">@endpush
@section('content')
@php($n = $edit)
@php($openForm = $n || $errors->any() || request()->boolean('new'))
<header class="head">
  <div><h1>Release notes</h1><p>Short “what’s new” posts. They show on the bell in each app’s Clauth bar until people read them.</p></div>
  @if($n)
    <a class="btn pri" href="{{ route('admin.updates', ['new' => 1]) }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New release note</a>
  @else
    <button class="btn pri" type="button" data-open-dialog="#note-dlg">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New release note</button>
  @endif
</header>

<div class="relwrap">
  <section class="card" aria-label="Release notes">
    @forelse($notes as $note)
      @php($scheduled = $note->published_at->isFuture())
      @php($of = $note->app_id ? ($audience[$note->app_id] ?? 0) : $everyone)
      <div class="note">
        @if($note->app)@include('partials.ui.app-icon', ['app' => $note->app])@else<span class="ico star" aria-hidden="true">★</span>@endif
        <div class="grow">
          <div class="t"><b class="strong">{{ $note->title }}</b>
            @if($note->version)<span class="badge info mono">{{ $note->version }}</span>@endif
            @if($scheduled)<span class="badge muted">Scheduled · {{ $note->published_at->format('M j, H:i') }}</span>@else<span class="badge ok">Published</span>@endif
          </div>
          <div class="sub small muted">{{ $note->app?->name ?? 'Every app' }}
            · {{ $note->source === 'api' ? 'posted by the app automatically' : ($note->created_by === auth()->id() ? 'by you' : 'by '.($authors[$note->created_by] ?? 'an admin')) }}
            @unless($scheduled) · {{ $note->published_at->format('j M Y') }} · read by {{ $reads[$note->id] ?? 0 }} of {{ $of }}@endunless</div>
          @if($note->body)<p class="body">{{ \Illuminate\Support\Str::limit($note->body, 240) }}</p>@endif
        </div>
        <div class="acts">
          <a class="btn sm" href="{{ route('admin.updates', ['edit' => $note->id]) }}">Edit<span class="sr-only"> {{ $note->title }}</span></a>
          <form method="post" action="{{ route('admin.updates.destroy', $note) }}" data-confirm="Remove this release note from every bar?">@csrf @method('delete')<button class="btn sm ghost danger">Remove<span class="sr-only"> {{ $note->title }}</span></button></form>
        </div>
      </div>
    @empty
      <div class="empty"><b>Nothing posted yet</b><span>Post a short note when an app gets something new.</span></div>
    @endforelse
  </section>

  <aside aria-labelledby="bell-h">
    <span class="small muted strong" id="bell-h">How people see it</span>
    <div class="card bell">
      <div class="bh"><b class="strong">What’s new</b><span class="small" style="color:var(--link)">Mark all read</span></div>
      @forelse($preview as $p)
        <div class="bi"><span class="unread" aria-hidden="true"></span><div><b style="font-weight:500">{{ $p->title }}</b>
          <div class="small muted">{{ $p->app?->name ?? 'Every app' }}@if($p->version) · {{ $p->version }}@endif · {{ $p->published_at->diffForHumans() }}</div></div></div>
      @empty
        <div class="bi"><span class="small muted">Published notes show here.</span></div>
      @endforelse
    </div>
    <p class="small muted" style="margin:0">Apps can post notes for themselves through the API: a server-to-server client of the app with scope <code>updates:write</code> calls <code>POST {{ config('clauth.issuer') }}/api/v1/release-notes</code> with <code>title</code>, <code>version</code>, <code>body</code>, <code>link</code>.</p>
  </aside>
</div>
@if($notes->hasPages())
  <nav class="pager" aria-label="Pages">
    <span class="small">Page {{ $notes->currentPage() }} of {{ $notes->lastPage() }}</span>
    <span class="actions">
      @if($notes->onFirstPage())<span class="btn sm disabled" aria-disabled="true">Newer</span>@else<a class="btn sm" href="{{ $notes->previousPageUrl() }}" rel="prev">Newer</a>@endif
      @if($notes->hasMorePages())<a class="btn sm" href="{{ $notes->nextPageUrl() }}" rel="next">Older</a>@else<span class="btn sm disabled" aria-disabled="true">Older</span>@endif
    </span>
  </nav>
@endif

<dialog class="dlg" id="note-dlg" aria-labelledby="note-dlg-h" @if($openForm) data-open-on-load @endif>
  <form class="form" method="post" action="{{ $n ? route('admin.updates.update', $n) : route('admin.updates.store') }}">@csrf
    @if($n) @method('put') @endif
    <div class="dlg-h"><h2 id="note-dlg-h">{{ $n ? 'Edit release note' : 'Post an update' }}</h2><p>Keep it short: people read this in a small panel.</p></div>
    <div class="dlg-b">
      <div class="frow">
        <div class="f"><label for="ua">App</label>
          <select class="in" id="ua" name="app_id">
            <option value="">Every app</option>
            @foreach($apps as $a)<option value="{{ $a->id }}" @selected(old('app_id', $n?->app_id) === $a->id)>{{ $a->name }}</option>@endforeach
          </select></div>
        <div class="f"><label for="uv">Version (optional)</label><input class="in mono" id="uv" name="version" value="{{ old('version', $n?->version) }}" placeholder="v0.17.0" maxlength="40"></div>
      </div>
      <div class="f"><label for="ut">Title</label><input class="in" id="ut" name="title" value="{{ old('title', $n?->title) }}" placeholder="Receipts upload faster on mobile" required maxlength="160">@error('title')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="ub">What changed (optional)</label><textarea class="in prose" id="ub" name="body" maxlength="4000" placeholder="One change per line.">{{ old('body', $n?->body) }}</textarea></div>
      <div class="frow">
        <div class="f"><label for="ul">Link (optional)</label><input class="in mono" id="ul" name="link" value="{{ old('link', $n?->link) }}" placeholder="https://…/changelog">@error('link')<span class="err">{{ $message }}</span>@enderror</div>
        <div class="f"><label for="up">Publish at (optional)</label><input class="in" id="up" type="datetime-local" name="published_at" value="{{ old('published_at', $n?->published_at?->format('Y-m-d\TH:i')) }}"><span class="hint">Blank = now. A later time schedules it.</span></div>
      </div>
    </div>
    <div class="dlg-f">
      @if($n)<a class="btn" href="{{ route('admin.updates') }}">Cancel</a>@else<button class="btn" type="button" data-close-dialog>Cancel</button>@endif
      <button class="btn pri">{{ $n ? 'Save' : 'Post update' }}</button>
    </div>
  </form>
</dialog>
@endsection
