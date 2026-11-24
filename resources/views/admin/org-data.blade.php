@extends('layouts.console')
@section('title', 'Teams & fields · '.$org->name)
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@push('end')<script src="/js/pages/org-sub.js?v={{ filemtime(public_path('js/pages/org-sub.js')) }}" defer></script>@endpush
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])
@php($form = old('_form'))

<div class="grid2">
  <section class="card" aria-labelledby="teams-h">
    <div class="card-h">
      <div><h2 id="teams-h">Teams</h2><p>Stores, branches or departments. Apps get the team keys in sign-in tokens, e.g. for “only my store”. Teams don't give access.</p></div>
      <button class="btn" type="button" data-open-dialog="#new-team">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New team</button>
    </div>
    @forelse($teams as $t)
      <div class="team">
        <div><b style="font-weight:500">{{ $t->name }}</b>
          <div class="sub">{{ $t->users_count }} {{ Str::plural('person', $t->users_count) }} · <span class="mono">{{ $t->key }}</span>@if($t->description) · {{ $t->description }}@endif</div></div>
        @if($t->users_count)
          <span class="stack" aria-hidden="true">@foreach($t->users as $u)<span class="av">{{ $u->initials() }}</span>@endforeach @if($t->users_count > $t->users->count())<span class="av muted">+{{ $t->users_count - $t->users->count() }}</span>@endif</span>
        @endif
        <details class="menu-d">
          <summary class="btn sm icon" aria-label="More for {{ $t->name }}">@include('partials.icon', ['i' => 'more', 'iconSize' => 16])</summary>
          <div class="menu-list">
            <form method="post" action="{{ route('admin.org-data.teams.destroy', [$org->id, $t->id]) }}" data-confirm="Delete the {{ $t->name }} team? People keep their access; apps stop seeing this team.">@csrf @method('delete')
              <button class="danger">@include('partials.icon', ['i' => 'trash', 'iconSize' => 16])Delete team</button></form>
          </div>
        </details>
      </div>
    @empty
      <div class="empty"><b>No teams yet</b><span>Add one for each store, branch or department.</span></div>
    @endforelse
    <div class="card-f" style="justify-content:flex-start"><span class="hint">Put people in teams from their page (People › person).</span></div>
  </section>

  <section class="card" aria-labelledby="fields-h">
    <div class="card-h">
      <div><h2 id="fields-h">Profile fields</h2><p>Extra details you keep for each person, like employee number or start date. Apps can read the ones you allow.</p></div>
      <button class="btn" type="button" data-open-dialog="#new-field">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add field</button>
    </div>
    @if($fields->isEmpty())
      <div class="empty"><b>No profile fields yet</b><span>Add the details you want to keep about each person.</span></div>
    @else
      <div class="scroll">
        <table class="t">
          <thead><tr><th scope="col">Field</th><th scope="col">Type</th><th scope="col">Apps can read</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            @foreach($fields as $f)
              <tr>
                <td><b style="font-weight:500">{{ $f->label }}</b>
                  <div class="small muted"><span class="mono">{{ $f->key }}</span>@if($f->required) · Required @endif @if($f->editable_by === 'self') · People can edit it @endif</div></td>
                <td>{{ $types[$f->type] }}@if($f->type === 'enum') · {{ count($f->options ?? []) }} {{ Str::plural('option', count($f->options ?? [])) }}@endif</td>
                <td>@if($f->in_token)<span class="badge ok">Yes</span>@else<span class="badge muted">No</span>@endif</td>
                <td class="act"><button class="btn sm" type="button" data-open-dialog="#field-{{ $f->id }}">Edit</button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>
</div>

<dialog class="dlg" id="new-team" aria-labelledby="new-team-h" @if($form === 'new-team' && $errors->any()) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.org-data.teams.store', $org->id) }}">@csrf <input type="hidden" name="_form" value="new-team">
    <div class="dlg-h"><h2 id="new-team-h">New team</h2><p>A store, branch or department of {{ $org->name }}.</p></div>
    <div class="dlg-b">
      <label class="f"><span>Name</span><input class="in" name="name" placeholder="Ayala store" required maxlength="80" value="{{ $form === 'new-team' ? old('name') : '' }}"></label>
      <label class="f"><span>Key</span><input class="in mono" name="key" placeholder="ayala" pattern="[a-z0-9][a-z0-9-]*" maxlength="60" value="{{ $form === 'new-team' ? old('key') : '' }}" aria-describedby="tk-hint">
        <span class="hint" id="tk-hint">Optional. Apps see this and it can't change later. Lowercase letters, numbers and dashes.</span></label>
      <label class="f"><span>Description</span><input class="in" name="description" maxlength="190" placeholder="Ground floor, Ayala Center Cebu" value="{{ $form === 'new-team' ? old('description') : '' }}"></label>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Add team</button></div>
  </form>
</dialog>

<dialog class="dlg" id="new-field" aria-labelledby="new-field-h" @if($form === 'new-field' && $errors->any()) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.org-data.fields.store', $org->id) }}">@csrf <input type="hidden" name="_form" value="new-field">
    <div class="dlg-h"><h2 id="new-field-h">Add field</h2><p>Admins fill it in on each person's page.</p></div>
    <div class="dlg-b">
      <div class="frow">
        <label class="f"><span>Label</span><input class="in" name="label" placeholder="Employee number" required maxlength="80" value="{{ $form === 'new-field' ? old('label') : '' }}"></label>
        <label class="f"><span>Key</span><input class="in mono" name="key" placeholder="employee_no" required pattern="[a-z][a-z0-9_]*" maxlength="60" value="{{ $form === 'new-field' ? old('key') : '' }}">
          <span class="hint">Apps see this; it can't change.</span></label>
      </div>
      <label class="f"><span>Type</span><select class="in" name="type" data-field-type>@foreach($types as $k => $l)<option value="{{ $k }}" @selected($form === 'new-field' && old('type') === $k)>{{ $l }}</option>@endforeach</select></label>
      <label class="f" data-enum-only><span>Choices</span><textarea class="in prose" name="options" rows="3" placeholder="One per line">{{ $form === 'new-field' && is_string(old('options')) ? old('options') : '' }}</textarea>
        <span class="hint">For the Choice type only.</span></label>
      <label class="cb"><input type="checkbox" name="required" value="1"> Required</label>
      <label class="cb"><input type="checkbox" name="in_token" value="1"> Apps can read it <span class="hint">Sent in sign-in tokens.</span></label>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Add field</button></div>
  </form>
</dialog>

@foreach($fields as $f)
  <dialog class="dlg" id="field-{{ $f->id }}" aria-labelledby="field-{{ $f->id }}-h" @if($form === 'field-'.$f->id && $errors->any()) data-open-on-load @endif>
    <form method="post" action="{{ route('admin.org-data.fields.update', [$org->id, $f->id]) }}">@csrf @method('put') <input type="hidden" name="_form" value="field-{{ $f->id }}">
      <div class="dlg-h"><h2 id="field-{{ $f->id }}-h">{{ $f->label }}</h2><p><span class="mono">{{ $f->key }}</span> · {{ $types[$f->type] }}. The key and type can't change.</p></div>
      <div class="dlg-b">
        <label class="f"><span>Label</span><input class="in" name="label" value="{{ $f->label }}" required maxlength="80"></label>
        @if($f->type === 'enum')<label class="f"><span>Choices</span><textarea class="in prose" name="options" rows="4">{{ implode("\n", $f->options ?? []) }}</textarea><span class="hint">One per line.</span></label>@endif
        <label class="f"><span>Who can change it</span><select class="in" name="editable_by"><option value="admin" @selected($f->editable_by === 'admin')>Admins</option><option value="self" @selected($f->editable_by === 'self')>Admins and the person</option></select></label>
        <label class="cb"><input type="checkbox" name="required" value="1" @checked($f->required)> Required</label>
        <label class="cb"><input type="checkbox" name="in_token" value="1" @checked($f->in_token)> Apps can read it <span class="hint">Sent in sign-in tokens.</span></label>
      </div>
      <div class="dlg-f">
        <button class="btn danger" type="submit" form="field-del-{{ $f->id }}" style="margin-right:auto">Delete field</button>
        <button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Save</button>
      </div>
    </form>
    <form id="field-del-{{ $f->id }}" method="post" action="{{ route('admin.org-data.fields.destroy', [$org->id, $f->id]) }}" hidden data-confirm="Delete {{ $f->label }} and everyone's values for it?">@csrf @method('delete')</form>
  </dialog>
@endforeach
@endsection
