{{-- App › Settings: about, who can use it (installations), security, danger zone (pause, suspend, delete). One form for admin.apps.update. --}}
@php($me = auth()->user())
@php($runs = $orgs->pluck('id')->all())
@php($others = $app->installations->reject(fn ($o) => in_array($o->id, $runs, true)))
@php($seeCount = fn ($id) => $me->isOwner() || in_array($id, $me->devOrgIds(), true))
<form id="app-settings" class="stack narrow" method="post" action="{{ route('admin.apps.update', $app->key) }}">@csrf @method('put')
  <section class="card" aria-labelledby="s-about">
    <div class="ch"><h2 id="s-about">About this app</h2></div>
    <div class="fields">
      <div class="f"><label for="sn">Name</label><input class="in" id="sn" name="name" value="{{ old('name', $app->name) }}" maxlength="80" required>@error('name')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="sd">Short description</label><input class="in" id="sd" name="description" value="{{ old('description', $app->description) }}" maxlength="120" placeholder="Expense tracking"></div>
      <div class="f"><label for="sh">Home page</label><input class="in mono" id="sh" name="home_url" value="{{ old('home_url', $app->home_url) }}" placeholder="https://app.example.com">@error('home_url')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="psup">Support email</label><input class="in" id="psup" name="support_email" type="email" value="{{ old('support_email', $app->support_email) }}">@error('support_email')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="ppriv">Privacy policy</label><input class="in mono" id="ppriv" name="privacy_url" value="{{ old('privacy_url', $app->privacy_url) }}" placeholder="https://…/privacy">@error('privacy_url')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="pterm">Terms</label><input class="in mono" id="pterm" name="terms_url" value="{{ old('terms_url', $app->terms_url) }}" placeholder="https://…/terms">@error('terms_url')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="sc">Icon color</label>
        <div class="colorin"><input type="color" value="{{ $app->color }}" aria-label="Pick icon color" data-color-for="#sc"><input class="in mono" id="sc" name="color" value="{{ old('color', $app->color) }}" pattern="#[0-9A-Fa-f]{6}" required></div>@error('color')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="sm">Permissions file</label><input class="in mono" id="sm" name="manifest_url" value="{{ old('manifest_url', $app->manifest_url) }}" placeholder="https://…/.well-known/clauth.json">@error('manifest_url')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="ss">Status</label><select class="in" id="ss" name="status">
        @foreach(['live' => 'Live', 'not_connected' => 'Not connected yet', 'paused' => 'Paused (nobody can sign in)'] as $k => $l)<option value="{{ $k }}" @selected($app->status === $k)>{{ $l }}</option>@endforeach</select></div>
      @if($me->isOwner())
        <div class="f"><label for="spub">Made by</label><select class="in" id="spub" name="publisher">
          @foreach(\App\Models\Organization::orderBy('name')->get() as $o)<option value="{{ $o->id }}" @selected($app->owner_organization_id === $o->id)>{{ $o->name }}</option>@endforeach</select>
          <span class="hint">Its owners, admins and developers manage this app. Sign-in domains must belong to it.</span></div>
      @endif
    </div>
  </section>

  <section class="card" aria-labelledby="s-who">
    <div class="ch"><h2 id="s-who">Who can use it</h2><p>Organizations you run: tick to turn {{ $app->name }} on or off for them.</p></div>
    @forelse($orgs as $o)
      @php($inst = $app->installations->firstWhere('id', $o->id))
      <div class="set"><div><b>{{ $o->name }}</b>
        <p>@if($inst){{ $seeCount($o->id) ? ($orgCounts[$o->id] ?? 0).' '.(($orgCounts[$o->id] ?? 0) === 1 ? 'person' : 'people').' · ' : '' }}{{ $inst->pivot->status === 'active' ? 'added '.$inst->pivot->created_at?->format('M j') : 'waiting for approval' }}@else Not using it @endif{{ $o->id === $app->owner_organization_id ? ' · makes this app' : '' }}</p></div>
        <label class="sw"><input type="checkbox" name="orgs[]" value="{{ $o->id }}" @checked($app->organizations->contains('id', $o->id)) @disabled($o->id === $app->owner_organization_id) aria-label="{{ $o->name }} can use {{ $app->name }}"><span></span></label>
        @if($o->id === $app->owner_organization_id)<input type="hidden" name="orgs[]" value="{{ $o->id }}">@endif
      </div>
    @empty<div class="set"><p style="margin:0">You don’t run any organization that could use it.</p></div>@endforelse
    @foreach($others as $o)
      <div class="set"><div><b>{{ $o->name }}</b> <span class="badge {{ $o->pivot->status === 'active' ? 'ok' : 'warn' }}">{{ $o->pivot->status === 'active' ? 'Using it' : 'Waiting for their admin' }}</span>
        <p>{{ $seeCount($o->id) ? ($orgCounts[$o->id] ?? 0).' people · ' : '' }}{{ $o->pivot->status === 'active' ? 'added '.$o->pivot->created_at?->format('M j') : 'offered '.$o->pivot->created_at?->format('M j') }}</p></div>
        <button class="btn" form="rm-inst-{{ $loop->index }}">{{ $o->pivot->status === 'active' ? 'Remove' : 'Withdraw' }}</button>
      </div>
    @endforeach
    <div class="set"><div><b>Offer to other organizations</b><p>Other organizations can be offered it; their admin approves and sees the consent screen first.</p></div>
      <label><span class="sr-only">Other organizations</span><select class="in" name="visibility" style="width:auto">
        <option value="private" @selected($app->visibility === 'private')>Only organizations I run</option>
        <option value="installable" @selected($app->visibility === 'installable')>Other organizations can be offered it</option></select></label></div>
    @if($app->visibility === 'installable')
      <div class="set" style="justify-content:flex-start">
        <label class="sr-only" for="islug">Organization slug</label><input class="in mono" id="islug" name="slug" form="offer-inst" placeholder="organization-slug" style="max-width:240px" required>
        <button class="btn" form="offer-inst">Add organization</button>
        <p class="hint" style="flex:1 1 100%;margin:0">Their admin sees the request on their Organizations page. Nothing about their people is shared until they give someone access.</p>
      </div>
    @endif
  </section>

  <section class="card" aria-labelledby="s-sec" id="security">
    <div class="ch"><h2 id="s-sec">Security</h2></div>
    <div class="set"><div><b>Roles that need 2-step</b><p>People with these roles enter a code when they sign in.</p></div>
      <div class="pills">
        @forelse($app->roles as $r)<label class="pill"><input type="checkbox" name="require_mfa_roles[]" value="{{ $r->key }}" @checked(in_array($r->key, $app->require_mfa_roles ?? []))><span>{{ $r->name }}</span></label>
        @empty<span class="small muted">No roles yet.</span>@endforelse
      </div></div>
    <div class="set"><div><b>After sign-out, send people to</b><p>{{ $app->clients->where('revoked', false)->flatMap(fn ($c) => $c->post_logout_redirect_uris ?? [])->unique()->join(', ') ?: 'Not set' }}</p></div>
      <a class="btn" href="{{ $tabUrl('developer') }}#clients">Edit</a></div>
    <div class="set"><div><b>Sign-in rules</b><p>Which ways to sign in, 2-step and sign-up apply to {{ $app->name }}.</p></div>
      <a class="btn" href="{{ route('admin.apps.policy', $app->key) }}">Open</a></div>
  </section>

  <div class="actions" style="justify-content:flex-end"><button class="btn pri">Save settings</button></div>

  <section class="card dangerz" aria-labelledby="s-danger">
    <div class="ch"><h2 id="s-danger">Danger zone</h2></div>
    @if($app->status === 'paused')
      <div class="set"><div><b>Resume sign-in</b><p>{{ $app->name }} is paused: nobody can sign in until you resume.</p></div><button class="btn" name="resume" value="1">Resume</button></div>
    @else
      <div class="set"><div><b>Pause sign-in</b><p>Nobody can sign in to {{ $app->name }} until you resume. People already in stay in until their next refresh.</p></div><button class="btn danger" name="pause" value="1">Pause</button></div>
    @endif
    @if($me->isOwner())
      <div class="set"><div><b>{{ $app->suspended_at ? 'Suspended' : 'Suspend app' }}</b>
        <p>@if($app->suspended_at)Suspended {{ $app->suspended_at->diffForHumans() }}: {{ $app->suspend_reason }}@else Clauth owners only. Stops every sign-in and token for {{ $app->name }} right away (use for abuse). Its organization sees the reason.@endif</p></div>
        @if($app->suspended_at)<button class="btn" form="restore-app">Restore app</button>@else<button class="btn danger" type="button" data-open-dialog="#suspend-app">Suspend</button>@endif</div>
    @endif
    @if($canDelete)
      <div class="set"><div><b>Delete app</b><p>Removes its roles and everyone’s access. Can’t be undone.</p></div><button class="btn danger" type="button" data-open-dialog="#del-app">Delete</button></div>
    @endif
  </section>
</form>

{{-- Forms the settings form's buttons point at (forms can't nest). --}}
@foreach($others as $o)
  <form id="rm-inst-{{ $loop->index }}" method="post" action="{{ route('admin.apps.installations.destroy', [$app->key, $o->id]) }}" data-confirm="Remove {{ $app->name }} from {{ $o->name }}? Their people lose access to it." hidden>@csrf @method('delete')</form>
@endforeach
@if($app->visibility === 'installable')<form id="offer-inst" method="post" action="{{ route('admin.apps.installations.store', $app->key) }}" hidden>@csrf</form>@endif
@if($me->isOwner())
  @if($app->suspended_at)<form id="restore-app" method="post" action="{{ route('admin.apps.restore', $app->key) }}" hidden>@csrf @method('delete')</form>@endif
  <dialog class="dlg" id="suspend-app" aria-labelledby="suspend-t">
    <form method="post" action="{{ route('admin.apps.suspend', $app->key) }}">@csrf
      <div class="dlg-h"><h2 id="suspend-t">Suspend {{ $app->name }}?</h2><p>Everyone is signed out of it and no new tokens are issued. Its organization sees the reason.</p></div>
      <div class="dlg-b"><div class="f"><label for="asr">Reason</label><input class="in" id="asr" name="reason" maxlength="500" required placeholder="Fake login page"></div></div>
      <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn danger solid">Suspend app</button></div>
    </form>
  </dialog>
@endif
