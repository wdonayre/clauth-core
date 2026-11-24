{{-- Access tab: per organization, job roles + a role per app (exceptions set by hand). Posts to users.grants per org. --}}
<div class="split">
  <div class="pp-col">
    @forelse($cards as $c)
      @php($org = $c['org'])
      @php($heldNames = $c['profiles']->whereIn('id', $c['held'])->pluck('name'))
      <form class="card pp-org" method="post" action="{{ route('admin.users.grants', $user->id) }}" data-dirty-form aria-labelledby="org-{{ $org->id }}">@csrf
        <input type="hidden" name="organization_id" value="{{ $org->id }}">
        <input type="hidden" name="profiles_shown" value="1">
        <div class="card-h">
          <div>
            <h2 id="org-{{ $org->id }}">{{ $org->name }}</h2>
            <p>{{ $roles[$c['orgRole']] ?? ucfirst($c['orgRole']) }} ·
              @if($heldNames->isNotEmpty())Job role <b class="pp-strong">{{ $heldNames->implode(', ') }}</b> {{ $heldNames->count() > 1 ? 'set' : 'sets' }} their app roles
              @else no job role @endif</p>
          </div>
          <div class="actions">
            @if($c['profiles']->isNotEmpty())
              <button class="btn" type="button" data-open-dialog="#jr-{{ $org->id }}">{{ $heldNames->isNotEmpty() ? 'Change job role' : 'Set job role' }}</button>
            @else
              <a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">Make a job role</a>
            @endif
          </div>
        </div>

        <dialog class="dlg" id="jr-{{ $org->id }}" aria-labelledby="jr-h-{{ $org->id }}">
          <div class="dlg-h"><h2 id="jr-h-{{ $org->id }}">Job role in {{ $org->name }}</h2><p>A job role gives the right role in every app. Pick one or more.</p></div>
          <div class="dlg-b">
            <div class="radios">
              @foreach($c['profiles'] as $p)
                @php($ownerP = $p->hasOwnerTier())
                <label class="radio-card"><input type="checkbox" name="profiles[]" value="{{ $p->id }}" @checked(in_array($p->id, $c['held'], true)) @disabled($ownerP && ! $c['canOwner'])>
                  <span><b>{{ $p->name }}</b><p>{{ $c['gives'][$p->id] ?? 'Gives no app roles yet' }}@if($ownerP && ! $c['canOwner']) · only an owner of {{ $org->name }} can change this @endif</p></span></label>
                @if($ownerP && ! $c['canOwner'] && in_array($p->id, $c['held'], true))<input type="hidden" name="profiles[]" value="{{ $p->id }}">@endif
              @endforeach
            </div>
            <a class="small" href="{{ route('admin.profiles.index', $org->id) }}">Manage job roles</a>
          </div>
          <div class="dlg-f"><button class="btn pri" type="button" data-close-dialog>Done</button></div>
        </dialog>

        @if($org->pivot->deactivated_at)
          <div class="pp-note"><span class="badge bad">Switched off by directory sync</span> {{ $org->name }}’s HR system (SCIM) turned this person off {{ \Carbon\Carbon::parse($org->pivot->deactivated_at)->diffForHumans() }}: no access to its apps until it turns them back on.</div>
        @endif

        @if($c['apps']->isNotEmpty())
          <div class="arow arow-h" aria-hidden="true"><span>App</span><span>Role</span><span>Why</span></div>
        @endif
        @forelse($c['apps'] as $a)
          @php($e = $c['access'][$a->id] ?? ['role' => null, 'override' => null, 'profileRole' => null, 'via' => [], 'unmapped' => [], 'conflict' => false, 'candidates' => collect()])
          @php($ov = $e['override'])
          @php($cur = $ov ? ($ov->app_role_id ? (string) $ov->app_role_id : 'none') : 'profile')
          @php($ownerLocked = ! $c['canOwner'] && (($e['role'] ?? null)?->tier === 'owner'))
          <div class="arow">
            <span class="pp-app-h">@include('partials.ui.app-icon', ['app' => $a])<b>{{ $a->name }}</b></span>
            <label><span class="sr-only">Role in {{ $a->name }} for {{ $org->name }}</span>
              <select class="in" name="roles[{{ $a->id }}]" @disabled($ownerLocked)>
                <option value="profile" @selected($cur === 'profile')>{{ $heldNames->isNotEmpty() ? 'From job role ('.($e['profileRole']?->name ?? 'no access').')' : 'From job role (none)' }}</option>
                @foreach($a->roles as $r)
                  @if($r->assignable || $cur === (string) $r->id)
                    <option value="{{ $r->id }}" @selected($cur === (string) $r->id) @disabled($r->tier === 'owner' && ! $c['canOwner'])>{{ $r->name }}</option>
                  @endif
                @endforeach
                <option value="none" @selected($cur === 'none')>No access</option>
              </select></label>
            <span class="pp-why">
              @if($ov)
                <span class="badge warn">Set by hand</span> {{ $ov->reason ?: 'No reason given' }}
              @elseif($e['role'])
                From the {{ implode(' & ', $e['via']) }} job role
              @elseif($e['unmapped'])
                <span class="badge warn" title="Not set for {{ $a->name }} in: {{ implode(', ', $e['unmapped']) }}">Not set</span> {{ implode(', ', $e['unmapped']) }} doesn’t say what to give in {{ $a->name }}
              @elseif($heldNames->isNotEmpty())
                {{ $heldNames->implode(', ') }} doesn’t include {{ $a->name }}
              @else
                No job role, nothing set by hand
              @endif
              @if($e['conflict'])<span class="badge warn" title="Job roles give: {{ $e['candidates']->pluck('name')->implode(', ') }}">Job roles disagree</span>@endif
              @if($ownerLocked)<span class="hint">Only an owner of {{ $org->name }} can change an Owner-tier role.</span>@endif
            </span>
          </div>
        @empty
          <div class="empty"><b>No apps in {{ $org->name }} yet</b><span>Install an app for this organization first.</span></div>
        @endforelse

        @if($c['canSetRole'])
          <div class="set">
            <div><label class="t" for="orole-{{ $org->id }}">Role in {{ $org->name }}</label><p>Admins manage people and installed apps; developers manage {{ $org->name }}’s own apps.</p></div>
            <select class="in pp-narrow" id="orole-{{ $org->id }}" name="org_role">@foreach($roles as $k => $label)<option value="{{ $k }}" @selected($c['orgRole'] === $k)>{{ $label }}</option>@endforeach</select>
          </div>
        @endif

        <div class="pp-savebar" data-savebar>
          <span class="grow pp-savemsg">You have unsaved changes. They reach the apps within 15 minutes.</span>
          <label class="pp-reason"><span class="sr-only">Reason for a role set by hand</span>
            <input class="in" name="reason" maxlength="190" placeholder="Reason for a role set by hand (optional)"></label>
          <button class="btn" type="reset">Discard</button>
          <button class="btn pri" type="submit">Save changes</button>
        </div>
      </form>
    @empty
      <div class="card"><div class="empty"><b>{{ $user->name }} isn’t in any organization yet</b><span>Add them to one to give access to its apps.</span></div></div>
    @endforelse

    @if($allOrgs->isNotEmpty())
      <button class="btn pp-self-start" type="button" data-open-dialog="#addorg-dlg">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add to another organization</button>
      <dialog class="dlg" id="addorg-dlg" aria-labelledby="addorg-h">
        <form method="post" action="{{ route('admin.users.orgs.add', $user->id) }}">@csrf
          <div class="dlg-h"><h2 id="addorg-h">Add to an organization</h2><p>They become a member. Give them a job role afterwards.</p></div>
          <div class="dlg-b"><div class="f"><label for="addorg">Organization</label>
            <select class="in" id="addorg" name="organization_id">@foreach($allOrgs as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></div></div>
          <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri" type="submit">Add</button></div>
        </form>
      </dialog>
    @endif
  </div>

  <aside class="pp-col">
    @if($previewApp)
      <section class="card pad pp-side" aria-labelledby="sees-h">
        <h2 id="sees-h">What {{ $previewApp->name }} can see</h2>
        <p class="small muted">Name, email, and their role in each organization{{ $previewRoles ? ': '.implode(', ', $previewRoles) : ' — no role yet' }}.</p>
        @if($previewPerms)
          <ul class="pp-perms">@foreach($previewPerms as $perm)<li>{{ $perm }}</li>@endforeach</ul>
        @endif
        @if($previewApps->count() > 1)
          <div class="pills" aria-label="Other apps">@foreach($previewApps as $pa)<a class="badge {{ $pa->id === $previewApp->id ? 'info' : 'muted' }}" href="{{ route('admin.users.show', [$user->id, 'preview' => $pa->id]) }}" @if($pa->id === $previewApp->id) aria-current="true" @endif>{{ $pa->name }}</a>@endforeach</div>
        @endif
        <details class="pp-token"><summary>Show the raw token</summary>
          <pre class="json" aria-label="Token preview for {{ $previewApp->name }}">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>
      </section>
    @endif
    <section class="card pad pp-side" aria-labelledby="ss-h">
      <h2 id="ss-h">Signed in on</h2>
      @forelse($appSessions as $s)
        <div class="pp-kv"><span>{{ \App\Support\Agent::short($s->user_agent) }}@if($s->app) · {{ $s->app->name }}@endif</span><span class="muted">{{ $s->last_used_at?->diffForHumans() ?? '—' }}</span></div>
      @empty<p class="small muted">Not signed in to any app right now.</p>@endforelse
      <div class="actions">
        @if($canTouch)<form method="post" action="{{ route('admin.users.action', [$user->id, 'signout']) }}">@csrf<button class="btn sm" type="submit">Sign out everywhere</button></form>@endif
        <a class="small" href="{{ route('admin.users.show', [$user->id, 'tab' => 'security']) }}">All sessions</a>
      </div>
    </section>
    <p class="small muted">User ID <span class="mono">{{ $user->sub() }}</span></p>
  </aside>
</div>
