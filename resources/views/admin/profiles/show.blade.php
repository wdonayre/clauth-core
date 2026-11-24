{{-- The open job role (right side of the Job roles page). Included by index.blade.php. --}}
<section class="card jr-main" aria-labelledby="jr-name">
  <form method="post" action="{{ route('admin.profiles.update', [$org->id, $profile->id]) }}" id="jr-form">@csrf @method('put')
    <div class="jr-top">
      <div class="titlebar">
        <h2 id="jr-name">{{ $profile->name }}</h2>
        <details class="menu-d">
          <summary class="btn icon" aria-label="More for {{ $profile->name }}">@include('partials.icon', ['i' => 'more', 'iconSize' => 18])</summary>
          <div class="menu-list">
            <a href="{{ route('admin.profiles.alignment', [$org->id, 'q' => $profile->name]) }}">@include('partials.icon', ['i' => 'list', 'iconSize' => 16])See who has what</a>
            <button type="button" data-open-dialog="#holders">@include('partials.icon', ['i' => 'users', 'iconSize' => 16])Give to people</button>
            <button type="submit" form="jr-delete" class="danger">@include('partials.icon', ['i' => 'trash', 'iconSize' => 16])Delete job role</button>
          </div>
        </details>
      </div>
      <div class="frow">
        <label class="f"><span>Name</span><input class="in" name="name" value="{{ $profile->name }}" required maxlength="80"></label>
        <label class="f"><span>Description</span><input class="in" name="description" value="{{ $profile->description }}" maxlength="190" placeholder="What this job does"></label>
      </div>
    </div>
    <div class="map h"><span>App</span><span>Role it gives</span></div>
    @forelse($apps as $a)
      @php($has = array_key_exists($a->id, $mapping))
      @php($cur = $has ? ($mapping[$a->id] ?? 'none') : '')
      @php($curRole = is_numeric($cur) ? $a->roles->firstWhere('id', (int) $cur) : null)
      @php($locked = $curRole?->tier === 'owner' && ! $canOwner)
      <div class="map">
        <span class="app">@include('partials.ui.app-icon', ['app' => $a])
          <span><label for="m{{ $a->id }}">{{ $a->name }}</label>
            @unless($has)<span class="badge warn">not set — holders get no access yet</span>@endunless</span></span>
        <select class="in" id="m{{ $a->id }}" name="map[{{ $a->id }}]" @disabled($locked)>
          <option value="" @selected($cur === '')>Not set</option>
          <option value="none" @selected($cur === 'none')>No access</option>
          @foreach($a->roles->sortBy(fn ($r) => [$r->tierRank(), $r->position]) as $r)
            @if($r->assignable || $curRole?->id === $r->id)
              <option value="{{ $r->id }}" @selected($curRole?->id === $r->id) @disabled(! $r->tier || ($r->tier === 'owner' && ! $canOwner))>{{ $r->name }} · {{ $r->tier ? $tiers[$r->tier] : 'no tier yet' }}</option>
            @endif
          @endforeach
        </select>
        @if($locked)<span class="note">Only an owner of {{ $org->name }} can change an Owner-tier role.</span>@endif
      </div>
    @empty
      <div class="empty"><b>No apps yet</b><span>{{ $org->name }} has no apps installed. Add one on the Apps tab.</span></div>
    @endforelse
    <div class="holders">
      <span>
        @if($holders->isNotEmpty())
          <span class="stack" aria-hidden="true">@foreach($holders->take(4) as $h)<span class="av">{{ $h->initials() }}</span>@endforeach @if($holders->count() > 4)<span class="av muted">+{{ $holders->count() - 4 }}</span>@endif</span>
          @if($holders->count() === 1){{ $holders->first()->name }} has this job role
          @else{{ $holders->first()->name }} and {{ $holders->count() - 1 }} {{ Str::plural('other', $holders->count() - 1) }} have this job role @endif
        @else
          Nobody has this job role yet.
        @endif
      </span>
      <button class="btn" type="button" data-open-dialog="#holders">Give to people</button>
    </div>
    <div class="card-f"><span class="hint grow" style="align-self:center">Everyone with this job role is updated; apps pick it up within 15 minutes.</span>
      <button class="btn" type="reset">Discard</button><button class="btn pri">Save</button></div>
  </form>
</section>

<form id="jr-delete" method="post" action="{{ route('admin.profiles.destroy', [$org->id, $profile->id]) }}" hidden
  data-confirm="Delete the {{ $profile->name }} job role? The {{ $holders->count() }} {{ Str::plural('person', $holders->count()) }} who hold it lose the access it gave them (other job roles and exceptions stay).">@csrf @method('delete')</form>

<dialog class="dlg" id="holders" aria-labelledby="holders-h">
  <div class="dlg-h"><h2 id="holders-h">People with {{ $profile->name }}</h2><p>They get the roles above. People keep access from their other job roles.</p></div>
  <div class="dlg-b">
    @if($members->isNotEmpty())
      <form method="post" action="{{ route('admin.profiles.people.add', [$org->id, $profile->id]) }}" class="toolbar">@csrf
        <label class="f grow"><span>Add a person</span>
          <select class="in" name="user_id">@foreach($members as $m)<option value="{{ $m->id }}">{{ $m->name }} — {{ $m->email }}</option>@endforeach</select></label>
        <button class="btn pri" style="align-self:flex-end">Add</button>
      </form>
    @else
      <p class="hint" style="margin:0">Everyone in {{ $org->name }} already has this job role.</p>
    @endif
    <div class="rows card">
      @forelse($holders as $u)
        <div class="row">
          <div class="grow">@include('partials.ui.person', ['u' => $u, 'href' => route('admin.users.show', [$u->id, 'org' => $org->id])])</div>
          <form method="post" action="{{ route('admin.profiles.people.remove', [$org->id, $profile->id, $u->id]) }}">@csrf @method('delete')<button class="btn sm">Remove</button></form>
        </div>
      @empty
        <div class="empty"><span>Nobody yet.</span></div>
      @endforelse
    </div>
  </div>
  <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Done</button></div>
</dialog>
