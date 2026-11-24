@extends('layouts.console')
@section('title', $org->name.' · Apps')
@include('admin.orgs._assets')
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])
@include('admin.orgs._meta')

@foreach($requests as $a)
  <div class="flash info" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span class="grow"><b>{{ $a->name }}</b> by {{ $a->publisher?->name ?? '—' }} wants to be available to {{ $org->name }}. It will receive the name, email and roles of the people you give access to.</span>
    <form method="post" action="{{ route('admin.orgs.requests.approve', [$org->id, $a->key]) }}">@csrf<button class="btn pri sm">Approve</button></form>
    <form method="post" action="{{ route('admin.orgs.apps.destroy', [$org->id, $a->key]) }}">@csrf @method('delete')<button class="btn sm">Decline</button></form>
  </div>
@endforeach

<section class="card" aria-labelledby="apps-h" style="max-width:980px">
  <div class="card-h">
    <div><h2 id="apps-h">Apps people here can use</h2><p>Give access to an app, then choose roles on People or Job roles.</p></div>
    @if($addable->isNotEmpty())<button class="btn pri" type="button" data-open-dialog="#add-app">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add an app</button>@endif
  </div>
  <div class="list">
    @forelse($apps as $a)
      @php($n = $people[$a->id] ?? 0)
      <div class="row" style="flex-wrap:wrap">
        @include('partials.ui.app-icon', ['app' => $a])
        <div class="grow"><b>{{ $a->name }}</b><div class="sub">Made by {{ $a->publisher?->name ?? 'Clauth' }} · {{ $n }} {{ $n === 1 ? 'person has' : 'people have' }} a role</div></div>
        @if($a->isSuspended())<span class="badge bad">Suspended</span>
        @elseif($a->status === 'live')<span class="badge ok">Live</span>
        @elseif($a->status === 'paused')<span class="badge warn">Paused</span>
        @else<span class="badge muted">Not connected</span>@endif
        @if(auth()->user()->canManageApp($a))<a class="btn" href="{{ route('admin.apps.show', $a->key) }}">Open</a>@endif
        <details class="menu-d"><summary class="btn icon" aria-label="More for {{ $a->name }}">@include('partials.icon', ['i' => 'more', 'iconSize' => 16])</summary>
          <div class="pop">
            <form method="post" action="{{ route('admin.orgs.apps.destroy', [$org->id, $a->key]) }}" data-confirm="Remove {{ $a->name }} from {{ $org->name }}? Everyone’s access to it here is removed and they’re signed out of it.">@csrf @method('delete')
              <button class="danger">@include('partials.icon', ['i' => 'trash', 'iconSize' => 16])Remove from {{ $org->name }}</button></form>
          </div></details>
      </div>
    @empty
      <div class="empty"><b>No apps yet</b><span>Add an app so people in {{ $org->name }} can sign in to it.</span></div>
    @endforelse
  </div>
</section>

@if($canBuild)
  <section class="card" style="max-width:980px;padding:18px 20px;display:flex;flex-wrap:wrap;align-items:center;gap:14px;border-style:dashed;background:transparent">
    <div class="grow" style="min-width:240px"><b>Building your own app?</b><div class="small muted">Developers in {{ $org->name }} can register it here and give it to other organizations.</div></div>
    <a class="btn" href="{{ route('admin.apps.index') }}">Connect an app</a>
  </section>
@endif

@if($addable->isNotEmpty())
  <dialog class="dlg" id="add-app" aria-labelledby="add-app-h" @error('app_id') data-open-on-load @enderror>
    <form method="post" action="{{ route('admin.orgs.apps.store', $org->id) }}">@csrf
      <div class="dlg-h"><h2 id="add-app-h">Add an app</h2><p>People in {{ $org->name }} can then be given access to it.</p></div>
      <div class="dlg-b">
        <label class="f"><span>App</span>
          <select class="in" name="app_id" required>@foreach($addable as $a)<option value="{{ $a->id }}">{{ $a->name }}{{ $a->publisher && $a->owner_organization_id !== $org->id ? ' (by '.$a->publisher->name.')' : '' }}</option>@endforeach</select>
          @error('app_id')<span class="err">{{ $message }}</span>@enderror</label>
      </div>
      <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Add app</button></div>
    </form>
  </dialog>
@endif
@endsection
