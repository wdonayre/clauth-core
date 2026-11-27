@extends('layouts.console')
@section('title', 'Organizations')
@include('admin.orgs._assets')
@section('content')
@php($me = auth()->user())
<header class="head">
  <div><h1>Organizations</h1><p>Each business has its own people, apps and admins.</p></div>
  <div class="actions">
    @if($canEdit)
      <details class="menu-d"><summary class="btn">Platform @include('partials.icon', ['i' => 'down', 'iconSize' => 14])</summary>
        <div class="pop">
          <a href="{{ route('admin.api-clients.index', 'platform') }}">Platform API clients</a>
          <a href="{{ route('admin.connections.index', 'platform') }}">Platform sign-in &amp; SMS</a>
          <a href="{{ route('admin.messages.index', 'platform') }}">Platform messages</a>
          <a href="{{ route('admin.themes.index', 'platform') }}">Platform themes</a>
        </div></details>
      <button class="btn pri" type="button" data-open-dialog="#new-org">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New organization</button>
    @endif
  </div>
</header>

@if($requests->isNotEmpty())
  <section class="card reqbar" aria-labelledby="req-h">
    <h2 id="req-h" class="sr-only">Requests to join</h2>
    <a class="badge warn" href="{{ route('admin.org-requests.index') }}">{{ $requests->count() }} {{ Str::plural('request', $requests->count()) }}</a>
    <div class="grow" style="display:flex;flex-direction:column;gap:6px">
      @foreach($requests as $r)
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <span class="grow"><b>{{ $r->org_name }}</b> asked to set up an organization {{ $r->created_at->diffForHumans() }}.</span>
          <a class="btn sm" href="{{ route('admin.org-requests.show', $r->id) }}">Review</a>
        </div>
      @endforeach
    </div>
  </section>
@endif

@foreach($orgs as $o)
  @foreach($o->installRequests as $a)
    <div class="flash info" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <span class="grow"><b>{{ $a->name }}</b> by {{ $a->publisher?->name ?? '—' }} wants to be available to {{ $o->name }}. Waiting for your approval.</span>
      <a class="btn sm" href="{{ route('admin.orgs.apps.index', $o->id) }}">Review</a>
    </div>
  @endforeach
@endforeach

<div class="card scroll">
  <table class="t orgs-t">
    <thead><tr><th scope="col">Organization</th><th scope="col">People</th><th scope="col">Apps</th><th scope="col">Admins</th><th scope="col">Status</th></tr></thead>
    <tbody>
      @forelse($orgs as $o)
        @php($sub = $o->is_platform ? 'Your business' : ($o->verified_at ? 'Verified' : 'Unverified'))
        @php($makes = $o->publishedApps->pluck('name')->take(2)->implode(', '))
        @php($admins = $o->users->map(fn ($u) => $u->id === $me->id ? 'you' : $u->name))
        <tr>
          <td><a class="olink" href="{{ route('admin.orgs.show', $o->id) }}">
            <span class="ologo {{ $o->is_platform ? 'own' : '' }} @if($o->logo_path) img @endif" aria-hidden="true">@if($oLogo = \App\Http\Controllers\Admin\OrgController::logoUrl($o))<img src="{{ $oLogo }}" alt="">@else{{ mb_strtoupper(mb_substr($o->name, 0, 2)) }}@endif</span>
            <span class="who"><b>{{ $o->name }}</b><span>{{ $sub }}@if($makes) · makes {{ $makes }}@endif</span></span></a></td>
          <td>{{ $o->users_count }}</td>
          <td>@if($o->apps->isEmpty())<span class="muted">—</span>@else{{ $o->apps->sortBy('name')->pluck('name')->implode(', ') }}@endif</td>
          <td>@if($admins->isEmpty())<span class="muted">—</span>@else{{ ucfirst($admins->implode(', ')) }}@endif</td>
          <td><span class="pills">
            @if($o->suspended_at)<span class="badge bad" title="{{ $o->suspend_reason }}">Suspended</span>
            @elseif($o->status !== 'active')<span class="badge bad">{{ ucfirst($o->status) }}</span>
            @elseif($o->deletion_requested_at)<span class="badge bad">Deleting {{ $o->deletion_requested_at->addDays(\App\Services\OrgLifecycle::GRACE_DAYS)->format('j M') }}</span>
            @elseif($o->apps_count === 0)<span class="badge muted">No apps yet</span>
            @else<span class="badge ok">Active</span>@endif
            @if($o->installRequests->isNotEmpty())<span class="badge info">{{ $o->installRequests->count() }} waiting</span>@endif
          </span></td>
        </tr>
      @empty
        <tr><td colspan="5"><div class="empty"><b>No organizations yet</b><span>Create one to start adding people and apps.</span></div></td></tr>
      @endforelse
    </tbody>
  </table>
</div>

@if($canEdit)
  <dialog class="dlg" id="new-org" aria-labelledby="new-org-h" @if($errors->has('name') || $errors->has('slug')) data-open-on-load @endif>
    <form method="post" action="{{ route('admin.orgs.store') }}">@csrf
      <div class="dlg-h"><h2 id="new-org-h">New organization</h2><p>A business whose people sign in with Clauth.</p></div>
      <div class="dlg-b">
        <label class="f"><span>Name</span><input class="in" name="name" value="{{ old('name') }}" required>@error('name')<span class="err">{{ $message }}</span>@enderror</label>
        <label class="f"><span>Short name</span><input class="in mono" name="slug" value="{{ old('slug') }}" pattern="[a-z0-9][a-z0-9-]{1,60}" required>
          <span class="hint">Apps see this in tokens as the key of the <code>orgs</code> claim. Don’t change it later.</span>@error('slug')<span class="err">{{ $message }}</span>@enderror</label>
        <label class="cb"><input type="checkbox" name="is_platform" value="1" @checked(old('is_platform'))><span>One of my own businesses<span class="hint">Its apps skip the consent screen and its sign-in domains are trusted.</span></span></label>
      </div>
      <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Create</button></div>
    </form>
  </dialog>
@endif
@endsection
