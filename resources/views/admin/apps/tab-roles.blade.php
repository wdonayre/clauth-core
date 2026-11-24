{{-- App › Roles: permission matrix, roles with tiers, new role / permission, manifest sync + import + diff. --}}
@php($tiers = \App\Models\AppRole::TIERS)
@php($active = $permissions->whereNull('deprecated_at'))
@if($app->manifest_diff)
  <div class="flash info diff" role="status">
    <b>The app’s permissions file differs from roles you edited in Clauth.</b>
    @foreach($app->manifest_diff as $role => $d)
      @isset($d['manifest'])<span><b>{{ $role }}</b> — manifest: <code>{{ implode(', ', $d['manifest']) ?: 'none' }}</code>; Clauth: <code>{{ implode(', ', $d['clauth']) ?: 'none' }}</code></span>@endisset
      @isset($d['tier'])<span><b>{{ $role }}</b> tier — manifest: <code>{{ $d['tier']['manifest'] }}</code>; Clauth: <code>{{ $d['tier']['clauth'] ?? 'none' }}</code></span>@endisset
    @endforeach
    <span class="small">Clauth keeps your version. Save the table below (actions) or the role’s tier to dismiss.</span>
  </div>
@endif

<div class="section-h">
  <div><h2>What each role can do</h2><p>{{ $app->name }} defines these actions. Tick what each role may do; people get the change within 15 minutes.</p></div>
  <div class="actions">
    <span class="small muted">{{ $app->manifest_synced_at ? 'Updated from '.$app->name.' '.$app->manifest_synced_at->diffForHumans() : ($app->manifest_url ? 'Never read from '.$app->name : 'No permissions file yet') }}</span>
    @if($app->manifest_url)<form method="post" action="{{ route('admin.apps.sync', $app->key) }}">@csrf<button class="btn">@include('partials.icon', ['i' => 'refresh', 'iconSize' => 16])Check for changes</button></form>@endif
    <button class="btn pri" type="button" data-open-dialog="#new-role">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New role</button>
  </div>
</div>

@if($app->roles->isEmpty())
  <section class="card pad">
    <h2>This app has no roles</h2>
    <p class="small muted">If the app doesn’t use roles at all, add a single <b>Access</b> role: giving someone that role lets them in, and Clauth decides who gets it. Or create roles with <b>New role</b>.</p>
    <form method="post" action="{{ route('admin.apps.roles.access', $app->key) }}">@csrf<button class="btn">Add Access role</button></form>
  </section>
@else
<form class="card" method="post" action="{{ route('admin.apps.matrix', $app->key) }}">@csrf
  <div class="scroll"><table class="t matrix">
    <thead><tr><th scope="col">Action</th>
      @foreach($app->roles as $r)<th scope="col">{{ $r->name }}
        @php($n = $roleCounts[$r->id] ?? 0)<span class="sub">{{ $n === 1 ? '1 person' : $n.' people' }}</span>
        @if($r->tier)<span class="badge muted">{{ $tiers[$r->tier] }}</span>@else<span class="badge warn">No tier</span>@endif
        @if($r->edited)<span class="sub">edited</span>@endif</th>@endforeach</tr></thead>
    <tbody>
      @forelse($permissions as $p)
        <tr @class(['deprecated' => $p->deprecated_at])>
          <td>@if($p->description)<div style="font-weight:500">{{ $p->description }}</div>@endif<div class="key">{{ $p->key }}</div>@if($p->deprecated_at) <span class="badge warn">No longer used</span>@endif</td>
          @foreach($app->roles as $r)
            <td><input type="checkbox" name="matrix[{{ $r->id }}][]" value="{{ $p->id }}" aria-label="{{ $r->name }}: {{ $p->description ?: $p->key }}" @checked($r->permissions->contains('id', $p->id)) @disabled($p->deprecated_at)></td>
          @endforeach
        </tr>
      @empty<tr><td colspan="{{ $app->roles->count() + 1 }}"><div class="empty"><b>No actions yet</b><span>Add them below, check the app’s permissions file, or paste one.</span></div></td></tr>@endforelse
    </tbody>
  </table></div>
  @if($permissions->isNotEmpty())<div class="card-f"><button class="btn pri">Save roles</button></div>@endif
</form>

<section class="card">
  <div class="card-h"><div><h2>Roles</h2><p>Every role needs a tier — Clauth’s shared scale across apps (Owner › Admin › Member › Viewer). It drives 2-step rules and job roles. Old keys keep a renamed role working: tokens send them too.</p></div></div>
  @foreach($app->roles as $r)
    <div class="rolerow">
      <div class="grow"><b>{{ $r->name }}</b> <code class="muted">{{ $r->key }}</code>
        <span class="badge muted">{{ $r->source === 'manifest' ? 'From the app' : 'Made in Clauth' }}</span>
        @unless($r->tier)<span class="badge warn">no tier</span>@endunless
        @unless($r->assignable)<span class="badge muted">not assignable</span>@endunless
        @if(in_array($r->key, $app->require_mfa_roles ?? [], true))<span class="badge info">2-step</span>@endif
        @if($r->aliases)<div class="small muted">Also sent as {{ implode(', ', $r->aliases) }}</div>@endif</div>
      <form class="actions" method="post" action="{{ route('admin.apps.roles.update', [$app->key, $r->id]) }}">@csrf @method('patch')
        <select class="in" name="tier" aria-label="Tier of {{ $r->name }}"><option value="">No tier</option>
          @foreach($tiers as $k => $l)<option value="{{ $k }}" @selected($r->tier === $k)>{{ $l }}</option>@endforeach</select>
        @if($r->source !== 'manifest')
          <input class="in mono aliases" name="aliases" value="{{ implode(', ', $r->aliases ?? []) }}" placeholder="old keys" aria-label="Old keys of {{ $r->name }}">
          <label class="cb"><input type="checkbox" name="assignable" value="1" @checked($r->assignable)> Can be assigned</label>
        @endif
        <button class="btn sm">Save</button></form>
      <form method="post" action="{{ route('admin.apps.roles.destroy', [$app->key, $r->id]) }}" data-confirm="Delete role {{ $r->name }}?">@csrf @method('delete')<button class="btn sm ghost danger" aria-label="Delete role {{ $r->name }}">@include('partials.icon', ['i' => 'trash', 'iconSize' => 16])</button></form>
    </div>
  @endforeach
</section>
@endif

<div class="grid2">
  <form class="card pad form" method="post" action="{{ route('admin.apps.permissions.store', $app->key) }}">@csrf
    <div><h2>Add an action</h2><p class="small muted" style="margin:2px 0 0">For apps without a permissions file. Usually the app lists its own.</p></div>
    <div class="frow">
      <div class="f"><label for="pk">Key</label><input class="in mono" id="pk" name="key" placeholder="expense.approve" required></div>
      <div class="f"><label for="pd">What it lets people do</label><input class="in" id="pd" name="description" placeholder="Approve expenses"></div>
    </div>
    <div class="actions"><button class="btn">Add action</button></div>
  </form>
  <form class="card pad form" method="post" action="{{ route('admin.apps.manifest', $app->key) }}">@csrf
    <div><h2>Paste a permissions file</h2><p class="small muted" style="margin:2px 0 0">The app’s <code>clauth.json</code>, when Clauth can’t read it from {{ $app->manifest_url ? 'its address' : 'the app' }}.</p></div>
    <div class="f"><label for="mj" class="sr-only">clauth.json</label><textarea class="in" id="mj" name="manifest" placeholder='{"app":"{{ $app->key }}","permissions":[...],"roles":[...]}' required></textarea>
      @error('manifest')<span class="err">{{ $message }}</span>@enderror</div>
    <div class="actions"><button class="btn">Import</button></div>
  </form>
</div>

@php($mfa = $app->roles->whereIn('key', $app->require_mfa_roles ?? [])->pluck('name'))
@php($dom = $app->signInDomain())
<div class="minicards">
  <div class="card minicard"><b>Who needs 2-step</b><span>{{ $mfa->isEmpty() ? 'No role must use a 2-step code here.' : $mfa->join(', ', ' and ').' must use a 2-step code.' }}</span><a href="{{ $tabUrl('settings') }}#security">Change</a></div>
  <div class="card minicard"><b>Sign-in page</b><span>{{ $dom?->host ?? \App\Services\Domains::mainHost() }} · {{ $app->logo_path ? $app->name.' logo' : 'no logo' }}{{ $app->accent_color ? ' and colour' : '' }}.</span><a href="{{ $tabUrl('signin') }}">Change</a></div>
</div>

{{-- New role (drawer) --}}
@php($reopenRole = old('_form') === 'role')
<dialog class="dlg drawer" id="new-role" aria-labelledby="new-role-t" @if($reopenRole && $errors->any()) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.apps.roles.store', $app->key) }}">@csrf
    <input type="hidden" name="_form" value="role">
    <div class="dlg-h"><div><h2 id="new-role-t">New role in {{ $app->name }}</h2><p>Pick what people with this role can do.</p></div>
      <button class="btn icon ghost x-btn" type="button" data-close-dialog aria-label="Close">@include('partials.icon', ['i' => 'x', 'iconSize' => 18])</button></div>
    <div class="dlg-b">
      <div class="f"><label for="rn">Name</label><input class="in" id="rn" name="name" value="{{ $reopenRole ? old('name') : '' }}" placeholder="Auditor" maxlength="60" required data-name>
        @if($reopenRole)@error('name')<span class="err">{{ $message }}</span>@enderror @endif</div>
      <div class="f"><label for="rk">Key <span class="hint">— what {{ $app->name }} sees</span></label><input class="in mono" id="rk" name="key" value="{{ $reopenRole ? old('key') : '' }}" placeholder="auditor" pattern="[a-z][a-z0-9_\-]{0,40}" required data-key data-key-sep="_">
        @if($reopenRole)@error('key')<span class="err">{{ $message }}</span>@enderror @endif</div>
      <div class="frow">
        <div class="f"><label for="rt">Tier</label><select class="in" id="rt" name="tier"><option value="">Choose later</option>
          @foreach($tiers as $k => $l)<option value="{{ $k }}" @selected($reopenRole && old('tier') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="f"><label for="rf">Start from</label><select class="in" id="rf" name="copy_from" data-copy-from><option value="">Nothing</option>
          @foreach($app->roles as $r)<option value="{{ $r->id }}" data-perms="{{ $r->permissions->pluck('id')->join(' ') }}">{{ $r->name }}</option>@endforeach</select></div>
      </div>
      <div class="f"><label for="rd">Description <span class="hint">— optional</span></label><input class="in" id="rd" name="description" maxlength="190" value="{{ $reopenRole ? old('description') : '' }}"></div>
      @if($active->isNotEmpty())
      <fieldset class="f" style="border:0;padding:0;margin:0"><legend class="lbl" style="margin-bottom:4px">Can</legend>
        @foreach($active as $p)
          <label class="act"><input type="checkbox" name="permissions[]" value="{{ $p->id }}" @checked($reopenRole && in_array($p->id, old('permissions', []))) data-perm><span><b>{{ $p->description ?: $p->key }}</b><span class="key">{{ $p->key }}</span></span></label>
        @endforeach
      </fieldset>
      @else<p class="hint" style="margin:0">{{ $app->name }} has no actions yet. You can tick them for this role later.</p>@endif
      <label class="cb"><input type="checkbox" name="mfa" value="1" @checked($reopenRole && old('mfa'))> Needs 2-step</label>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Create role</button></div>
  </form>
</dialog>
