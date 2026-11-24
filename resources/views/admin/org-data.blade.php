@extends('layouts.console')
@section('title', 'Teams & fields · '.$org->name)
@section('content')
<header class="head"><div><h1>Teams &amp; fields</h1>
  <p><b>Teams</b> are the stores, branches or departments of {{ $org->name }}. <b>Profile fields</b> are extra details about each person, such as employee number or start date. Apps receive team keys, and the fields you mark “send to apps”, in the person's sign-in token. Apps use them for data rules like “only my store”.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">Access profiles</a><a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>

<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Teams ({{ $teams->count() }})</h2></div>
    <div class="list">
      @forelse($teams as $t)
        <div class="row"><div class="grow"><b>{{ $t->name }}</b> <span class="small muted mono">{{ $t->key }}</span>@if($t->description)<br><span class="small muted">{{ $t->description }}</span>@endif</div>
          <span class="chip">{{ $t->users_count }} {{ Str::plural('person', $t->users_count) }}</span>
          <form method="post" action="{{ route('admin.org-data.teams.destroy', [$org->id, $t->id]) }}" data-confirm="Delete the {{ $t->name }} team? People keep their access; apps stop seeing this team.">@csrf @method('delete')<button class="btn sm">Delete</button></form></div>
      @empty<div class="empty">No teams yet.</div>@endforelse
    </div>
    <form class="pad form" method="post" action="{{ route('admin.org-data.teams.store', $org->id) }}" style="border-top:1px solid #1E252E">@csrf
      <div class="f"><label for="tn">New team</label><input class="in" id="tn" name="name" placeholder="Ayala store" required maxlength="80"></div>
      <div class="f"><label for="tk">Key <span class="muted small">(optional; apps see this, it can't change)</span></label><input class="in mono" id="tk" name="key" placeholder="ayala" pattern="[a-z0-9][a-z0-9-]*" maxlength="60"></div>
      <div class="actions"><button class="btn pri">Add team</button></div>
    </form>
    <p class="pad small muted" style="margin:0">Put people in teams from their page (Users › person).</p>
  </section>

  <section class="card">
    <div class="card-h"><h2>Profile fields ({{ $fields->count() }})</h2></div>
    <div class="list">
      @forelse($fields as $f)
        <details class="row" style="display:block">
          <summary style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;cursor:pointer"><b>{{ $f->label }}</b> <span class="small muted mono">{{ $f->key }}</span>
            <span class="chip">{{ $types[$f->type] }}</span>@if($f->required)<span class="chip warn">required</span>@endif
            @if($f->in_token)<span class="chip info">sent to apps</span>@endif @if($f->editable_by === 'self')<span class="chip">people edit it</span>@endif</summary>
          <form class="form" method="post" action="{{ route('admin.org-data.fields.update', [$org->id, $f->id]) }}" style="margin-top:10px">@csrf @method('put')
            <div class="f"><label>Label</label><input class="in" name="label" value="{{ $f->label }}" required maxlength="80"></div>
            @if($f->type === 'enum')<div class="f"><label>Choices (one per line)</label><textarea class="in" name="options" rows="3">{{ implode("\n", $f->options ?? []) }}</textarea></div>@endif
            <label class="cb"><input type="checkbox" name="required" value="1" @checked($f->required)> Required</label>
            <label class="cb"><input type="checkbox" name="in_token" value="1" @checked($f->in_token)> Send to apps in sign-in tokens</label>
            <div class="f"><label>Who can change it</label><select class="in" name="editable_by"><option value="admin" @selected($f->editable_by === 'admin')>Admins</option><option value="self" @selected($f->editable_by === 'self')>Admins and the person</option></select></div>
            <div class="actions"><button class="btn sm pri">Save</button></div>
          </form>
          <form method="post" action="{{ route('admin.org-data.fields.destroy', [$org->id, $f->id]) }}" data-confirm="Delete {{ $f->label }} and everyone's values for it?">@csrf @method('delete')<button class="btn sm" style="margin-top:6px">Delete field</button></form>
        </details>
      @empty<div class="empty">No profile fields yet.</div>@endforelse
    </div>
    <form class="pad form" method="post" action="{{ route('admin.org-data.fields.store', $org->id) }}" style="border-top:1px solid #1E252E">@csrf
      <div class="f"><label for="fl">New field</label><input class="in" id="fl" name="label" placeholder="Employee number" required maxlength="80" value="{{ old('label') }}"></div>
      <div class="f"><label for="fk">Key <span class="muted small">(apps see this; can't change)</span></label><input class="in mono" id="fk" name="key" placeholder="employee_no" required pattern="[a-z][a-z0-9_]*" maxlength="60" value="{{ old('key') }}"></div>
      <div class="f"><label for="ft">Type</label><select class="in" id="ft" name="type">@foreach($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
      <div class="f"><label for="fo">Choices <span class="muted small">(Choice type only; one per line)</span></label><textarea class="in" id="fo" name="options" rows="2"></textarea></div>
      <label class="cb"><input type="checkbox" name="required" value="1"> Required</label>
      <label class="cb"><input type="checkbox" name="in_token" value="1"> Send to apps in sign-in tokens</label>
      <div class="actions"><button class="btn pri">Add field</button></div>
      @if($errors->any())<div class="small bad">{{ $errors->first() }}</div>@endif
    </form>
  </section>
</div>
@endsection
