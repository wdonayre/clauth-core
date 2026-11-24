{{-- Profile tab: name/email/phone, each organization's fields and teams (one Save), organization memberships. --}}
<div class="split">
  <form class="pp-col" id="pf" method="post" action="{{ route('admin.users.update', $user->id) }}" data-dirty-form>@csrf @method('put')
    <section class="card" aria-labelledby="d-h">
      <div class="card-h pp-line"><h2 id="d-h">Details</h2></div>
      <div class="frow pp-pad">
        <div class="f"><label for="un">Full name</label><input class="in" id="un" name="name" value="{{ old('name', $user->name) }}" required maxlength="120" autocomplete="off">@error('name')<span class="err">{{ $message }}</span>@enderror</div>
        <div class="f"><label for="ue">Email</label><input class="in" id="ue" type="email" name="email" value="{{ old('email', $user->email) }}" @readonly(! auth()->user()->isOwner()) required autocomplete="off">
          @unless(auth()->user()->isOwner())<span class="hint">Only Clauth owners can change an email.</span>@endunless
          @error('email')<span class="err">{{ $message }}</span>@enderror</div>
        <div class="f"><label for="up">Phone <span class="hint">(optional)</span></label><input class="in" id="up" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="+63" maxlength="40" autocomplete="off">
          @if($user->phone && $user->phone_verified_at)<span class="hint">Confirmed {{ $user->phone_verified_at->diffForHumans() }}</span>@endif</div>
      </div>
    </section>

    @foreach($orgData as $d)
      @if($d['fieldDefs']->isNotEmpty())
        <section class="card" aria-labelledby="pf-{{ $d['org']->id }}">
          <div class="card-h pp-line">
            <div><h2 id="pf-{{ $d['org']->id }}">{{ $d['org']->name }} profile</h2><p>Fields this organization keeps. Apps can read the ones marked “apps”.</p></div>
            <a class="small strong" href="{{ route('admin.org-data.index', $d['org']->id) }}">Edit fields</a>
          </div>
          <div class="frow pp-pad">
            @foreach($d['fieldDefs'] as $f)
              @php($v = $d['fieldValues'][$f->key] ?? null)
              @php($fid = 'fv-'.$d['org']->id.'-'.$f->key)
              @php($nm = 'orgdata['.$d['org']->id.'][fields]['.$f->key.']')
              <div class="f"><label for="{{ $fid }}">{{ $f->label }}@if($f->required)<span class="bad-t"> *</span>@endif @if($f->in_token)<span class="badge info" title="Sent to apps in sign-in tokens">apps</span>@endif</label>
                @switch($f->type)
                  @case('bool')<select class="in" id="{{ $fid }}" name="{{ $nm }}"><option value="">—</option><option value="1" @selected($v === true)>Yes</option><option value="0" @selected($v === false)>No</option></select>@break
                  @case('enum')<select class="in" id="{{ $fid }}" name="{{ $nm }}"><option value="">—</option>@foreach($f->options ?? [] as $o)<option @selected($v === $o)>{{ $o }}</option>@endforeach</select>@break
                  @case('date')<input class="in" type="date" id="{{ $fid }}" name="{{ $nm }}" value="{{ $v }}">@break
                  @case('number')<input class="in" type="number" step="any" id="{{ $fid }}" name="{{ $nm }}" value="{{ $v }}">@break
                  @default<input class="in" id="{{ $fid }}" name="{{ $nm }}" value="{{ $v }}" maxlength="255">
                @endswitch</div>
            @endforeach
          </div>
        </section>
      @endif
    @endforeach
    @error('fields.*')<div class="flash bad">{{ $message }}</div>@enderror

    <div class="actions pp-end">
      <a class="btn" href="{{ route('admin.users.show', [$user->id, 'tab' => 'profile']) }}">Discard</a>
      <button class="btn pri" type="submit">Save changes</button>
    </div>
  </form>

  <aside class="pp-col">
    @foreach($orgData as $d)
      @if($d['teams']->isNotEmpty())
        <section class="card" aria-labelledby="tm-{{ $d['org']->id }}">
          <div class="card-h pp-line"><h2 id="tm-{{ $d['org']->id }}" class="pp-h15">Teams{{ $orgData->count() > 1 ? ' · '.$d['org']->name : '' }}</h2>
            <a class="small" href="{{ route('admin.org-data.index', $d['org']->id) }}">Manage</a></div>
          <input type="hidden" form="pf" name="orgdata[{{ $d['org']->id }}][teams_shown]" value="1">
          <div class="pills pp-pad" role="group" aria-labelledby="tm-{{ $d['org']->id }}">
            @foreach($d['teams'] as $t)<label class="pill"><input type="checkbox" form="pf" name="orgdata[{{ $d['org']->id }}][teams][]" value="{{ $t->key }}" @checked(in_array($t->key, $d['heldTeams'], true))><span>{{ $t->name }}</span></label>@endforeach
          </div>
        </section>
      @endif
    @endforeach

    <section class="card" aria-labelledby="om-h">
      <div class="card-h pp-line"><h2 id="om-h" class="pp-h15">Organizations</h2></div>
      <div class="rows">
        @forelse($orgData as $d)
          <div class="row">
            <span class="grow">{{ $d['org']->name }}</span>
            <span class="badge muted">{{ $roles[$d['org']->pivot->role ?? 'member'] ?? 'Member' }}</span>
            <form method="post" action="{{ route('admin.users.orgs.remove', $user->id) }}" data-confirm="Remove {{ $user->name }} from {{ $d['org']->name }}? Their access to its apps ends now.">@csrf @method('delete')
              <input type="hidden" name="organization_id" value="{{ $d['org']->id }}">
              <button class="btn sm ghost pp-danger" type="submit" aria-label="Remove from {{ $d['org']->name }}">Remove</button></form>
          </div>
        @empty
          <div class="row muted">Not in any organization yet.</div>
        @endforelse
        @if($allOrgs->isNotEmpty())
          <form class="row" method="post" action="{{ route('admin.users.orgs.add', $user->id) }}">@csrf
            <input type="hidden" name="tab" value="profile">
            <label class="grow"><span class="sr-only">Add to organization</span>
              <select class="in" name="organization_id">@foreach($allOrgs as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></label>
            <button class="btn sm" type="submit">Add</button>
          </form>
        @endif
      </div>
    </section>
  </aside>
</div>
