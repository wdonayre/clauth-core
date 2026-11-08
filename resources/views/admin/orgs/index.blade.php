@extends('layouts.console')
@section('title', 'Organizations')
@section('content')
<header class="head">
  <div><h1>Organizations</h1><p>The businesses people belong to. Roles are given per organization.</p></div>
  @if($canEdit)
  <details class="modal"><summary class="btn pri">@include('partials.icon', ['i' => 'plus'])New organization</summary>
    <div class="sheet"><div class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2>New organization</h2><a class="btn sm" href="{{ route('admin.orgs.index') }}">Close</a></div>
      <form class="form" method="post" action="{{ route('admin.orgs.store') }}" style="margin-top:16px">@csrf
        <div class="f"><label for="on">Name</label><input class="in" id="on" name="name" required></div>
        <div class="f"><label for="os">Slug</label><input class="in mono" id="os" name="slug" pattern="[a-z0-9][a-z0-9-]{1,60}" required><span class="small muted">Appears in tokens as the key of the <code>orgs</code> claim. Don’t change it later.</span></div>
        <div class="actions"><button class="btn pri">Create</button></div>
      </form></div></div></details>
  @endif
</header>
<div class="grid2">
  @forelse($orgs as $o)
    <section class="card pad">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <div><h2>{{ $o->name }}</h2><p class="small muted mono" style="margin:4px 0 0">{{ $o->slug }} · {{ $o->users_count }} people</p></div>
        <span class="chip {{ $o->status === 'active' ? 'ok' : 'bad' }}">{{ ucfirst($o->status) }}</span>
      </div>
      <p class="lbl" style="margin:16px 0 6px">Org admins</p>
      <div class="pills">@forelse($o->users as $u)<a class="chip" href="{{ route('admin.users.show', [$u->id, 'org' => $o->id]) }}">{{ $u->name }}</a>@empty<span class="small muted">None</span>@endforelse</div>
      @if($canEdit)
        <form class="form" method="post" action="{{ route('admin.orgs.update', $o->id) }}" style="margin-top:16px">@csrf @method('put')
          <div class="f"><label for="n{{ $o->id }}">Name</label><input class="in" id="n{{ $o->id }}" name="name" value="{{ $o->name }}" required></div>
          <div class="f"><span>Apps enabled</span>
            @forelse($apps as $a)<label class="cb"><input type="checkbox" name="apps[]" value="{{ $a->id }}" @checked($o->apps->contains('id', $a->id))> {{ $a->name }}</label>@empty<span class="small muted">No apps yet.</span>@endforelse</div>
          <div class="f"><label for="s{{ $o->id }}">Status</label><select class="in" id="s{{ $o->id }}" name="status"><option value="active" @selected($o->status === 'active')>Active</option><option value="disabled" @selected($o->status === 'disabled')>Disabled (no app access)</option></select></div>
          <div class="actions"><button class="btn">Save</button></div>
        </form>
        <form class="actions" method="post" action="{{ route('admin.orgs.admins', $o->id) }}" style="margin-top:12px">@csrf
          <label class="sr-only" for="ae{{ $o->id }}">Member email</label><input class="in" id="ae{{ $o->id }}" type="email" name="email" placeholder="member email" style="max-width:240px" required>
          <input type="hidden" name="is_org_admin" value="1"><button class="btn sm">Make org admin</button>
        </form>
      @else
        <p class="lbl" style="margin:16px 0 6px">Apps</p>
        <div class="pills">@forelse($o->apps as $a)<span class="chip">{{ $a->name }}</span>@empty<span class="small muted">None</span>@endforelse</div>
      @endif
    </section>
  @empty<div class="card empty">No organizations yet.</div>@endforelse
</div>
@endsection
