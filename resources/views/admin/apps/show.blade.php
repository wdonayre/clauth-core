@extends('layouts.console')
@section('title', $app->name)
@section('content')
<header class="head">
  <div style="display:flex;gap:16px;align-items:center"><span class="ico lg" style="background:{{ $app->color }}">{{ $app->letter() }}</span>
    <div><h1>{{ $app->name }}</h1><p class="mono">aud: {{ $app->key }} · {{ $app->organizations->pluck('name')->implode(', ') ?: 'no organizations yet' }}</p></div></div>
  <span class="chip {{ ['live' => 'ok', 'paused' => 'warn'][$app->status] ?? '' }}">{{ ['live' => 'Live', 'paused' => 'Paused', 'not_connected' => 'Not connected'][$app->status] }}</span>
</header>

@if($created)
  <div class="flash info"><b>{{ $created['name'] }}</b> — client ID <code>{{ $created['id'] }}</code>.
    @if($created['secret'])<br>Client secret (shown <b>once</b>; store it in the app’s .env):
      <div class="secretbox" style="margin-top:8px"><code id="sec">{{ $created['secret'] }}</code><button class="btn sm" type="button" data-copy="#sec">Copy</button></div>
    @else This is a public client: no secret, PKCE only.@endif</div>
@endif
@if($app->manifest_diff)
  <div class="flash info">The manifest wants different permissions for role(s) you edited in Clauth:
    @foreach($app->manifest_diff as $role => $d)<br><b>{{ $role }}</b> — manifest: <code>{{ implode(', ', $d['manifest']) ?: 'none' }}</code>; Clauth: <code>{{ implode(', ', $d['clauth']) ?: 'none' }}</code>@endforeach
    <br><span class="small">Clauth keeps your version. Save the matrix to dismiss.</span></div>
@endif

<nav class="tabs" aria-label="App sections">
  @foreach(['roles' => 'Permissions & roles', 'brand' => 'Sign-in page', 'settings' => 'Settings', 'users' => 'Users', 'integration' => 'Integration'] as $k => $l)
    <a class="{{ $tab === $k ? 'on' : '' }}" href="{{ route('admin.apps.show', [$app->key, 'tab' => $k]) }}">{{ $l }}</a>
  @endforeach
</nav>

@if($tab === 'roles')
  <section class="card">
    <div class="card-h"><div><h2>Roles × permissions</h2><p class="small muted" style="margin:4px 0 0">{{ $app->name }} defines permissions; Clauth decides which role gets which. Changes apply to the next token.</p></div>
      <div class="actions">
        @if($app->manifest_url)<form method="post" action="{{ route('admin.apps.sync', $app->key) }}">@csrf<button class="btn sm">Sync now</button></form>@endif
        <span class="small muted">{{ $app->manifest_synced_at ? 'Synced '.$app->manifest_synced_at->diffForHumans() : 'Never synced' }}</span>
      </div></div>
    <form method="post" action="{{ route('admin.apps.matrix', $app->key) }}">@csrf
      <div class="scroll"><table class="t matrix">
        <thead><tr><th scope="col">Permission</th>@foreach($app->roles as $r)<th scope="col">{{ $r->name }}@if($r->edited)<br><span class="small muted" style="text-transform:none;letter-spacing:0">edited</span>@endif</th>@endforeach</tr></thead>
        <tbody>
          @forelse($permissions as $p)
            <tr @if($p->deprecated_at) style="opacity:.5" @endif>
              <td><code>{{ $p->key }}</code>@if($p->deprecated_at) <span class="chip warn">deprecated</span>@endif<br><span class="small muted">{{ $p->description }}</span></td>
              @foreach($app->roles as $r)
                <td><input type="checkbox" name="matrix[{{ $r->id }}][]" value="{{ $p->id }}" aria-label="{{ $r->name }}: {{ $p->key }}" @checked($r->permissions->contains('id', $p->id)) @disabled($p->deprecated_at)></td>
              @endforeach
            </tr>
          @empty<tr><td colspan="{{ $app->roles->count() + 1 }}" class="empty">No permissions yet. Add them below, sync a manifest, or paste one.</td></tr>@endforelse
        </tbody>
      </table></div>
      @if($permissions->isNotEmpty())<div class="card-h"><button class="btn pri">Save roles</button></div>@endif
    </form>
  </section>
  <div class="grid3">
    <form class="card pad form" method="post" action="{{ route('admin.apps.roles.store', $app->key) }}">@csrf
      <h2>New role</h2>
      <div class="f"><label for="rn">Name</label><input class="in" id="rn" name="name" placeholder="Finance" required></div>
      <div class="f"><label for="rk">Key</label><input class="in mono" id="rk" name="key" placeholder="finance" required></div>
      <div class="actions"><button class="btn">Add role</button></div>
    </form>
    <form class="card pad form" method="post" action="{{ route('admin.apps.permissions.store', $app->key) }}">@csrf
      <h2>New permission</h2>
      <div class="f"><label for="pk">Key</label><input class="in mono" id="pk" name="key" placeholder="expense.approve" required></div>
      <div class="f"><label for="pd">Description</label><input class="in" id="pd" name="description" placeholder="Approve or reject claims"></div>
      <div class="actions"><button class="btn">Add permission</button></div>
    </form>
    <form class="card pad form" method="post" action="{{ route('admin.apps.manifest', $app->key) }}">@csrf
      <h2>Paste a manifest</h2>
      <div class="f"><label for="mj">clauth.json</label><textarea class="in" id="mj" name="manifest" placeholder='{"app":"{{ $app->key }}","permissions":[...],"roles":[...]}' required></textarea></div>
      <div class="actions"><button class="btn">Import</button></div>
    </form>
  </div>
  @if($app->roles->isNotEmpty())
  <section class="card"><div class="card-h"><h2>Roles</h2></div><div class="list">
    @foreach($app->roles as $r)
      <div class="row"><div class="grow"><b>{{ $r->name }}</b> <code class="muted">{{ $r->key }}</code> <span class="chip">{{ $r->source }}</span></div>
        <form method="post" action="{{ route('admin.apps.roles.destroy', [$app->key, $r->id]) }}" data-confirm="Delete role {{ $r->name }}?">@csrf @method('delete')<button class="btn sm">Delete</button></form></div>
    @endforeach
  </div></section>
  @endif
@endif

@if($tab === 'brand')
  <div class="grid2">
    <form class="card pad form" method="post" action="{{ route('admin.apps.branding', $app->key) }}" enctype="multipart/form-data" data-brand-form>@csrf
      <h2>Sign-in page</h2>
      <p class="small muted" style="margin:0">Only the sign-in and account-setup pages for {{ $app->name }} use this. “Powered by CLAUTH” always shows at the bottom.</p>
      <fieldset class="f" style="border:0;padding:0;margin:0"><legend class="lbl" style="margin-bottom:8px">Style</legend>
        <div class="pills">@foreach($themes as $k => $l)<label class="pill"><input type="radio" name="login_theme" value="{{ $k }}" @checked($app->login_theme === $k)><span>{{ $l }}</span></label>@endforeach</div>
        <span class="small muted">Clauth Classic always uses Clauth cyan. Daylight and Midnight use the accent below.</span></fieldset>
      <div class="f"><label for="acc">Accent color</label>
        <div class="actions"><input class="in mono" id="acc" name="accent_color" value="{{ old('accent_color', $app->accent_color) }}" placeholder="#FBBF24" pattern="#[0-9A-Fa-f]{6}" style="max-width:140px">
          <div class="swatches">@foreach(['#FBBF24', '#22C55E', '#6366F1', '#E1251B', '#0EA5E9'] as $c)<button type="button" class="btn sm" data-swatch="{{ $c }}" style="background:{{ $c }};border-color:{{ $c }};width:36px;padding:0" aria-label="Use {{ $c }}"></button>@endforeach</div></div>
        <span class="small muted">Button text switches to black or white automatically for contrast.</span></div>
      <div class="f"><label for="lg">Logo (PNG or SVG, transparent, ≤ 512 KB)</label><input class="in" id="lg" type="file" name="logo" accept=".png,.svg,image/png,image/svg+xml">
        @if($app->logo_path)<span class="small muted">Current: <img src="{{ $app->logoUrl() }}" alt="" style="height:24px;vertical-align:middle;background:#fff;border-radius:4px;padding:2px"></span>@else<span class="small muted">No logo: the Clauth logo shows on top.</span>@endif</div>
      <div class="f"><label for="lgd">Logo for dark styles (optional)</label><input class="in" id="lgd" type="file" name="logo_dark" accept=".png,.svg,image/png,image/svg+xml"></div>
      @if($app->logo_path)<label class="cb"><input type="checkbox" name="remove_logo" value="1"> Remove logos</label>@endif
      <div class="actions"><button class="btn pri">Save sign-in page</button></div>
    </form>
    <section class="card pad" aria-labelledby="pv-h">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px"><h2 id="pv-h">Preview</h2>
        <div class="pills"><a class="chip info" href="#" data-preview-mode="signin">Sign in</a><a class="chip" href="#" data-preview-mode="register">Account setup</a></div></div>
      <div class="preview-frame"><iframe title="Sign-in page preview" data-preview src="{{ route('admin.apps.preview', [$app->key, 'mode' => 'signin']) }}"></iframe></div>
      <p class="small muted" style="margin:8px 0 0">The preview follows the style and color you pick; save to apply logo changes.</p>
    </section>
  </div>
@endif

@if($tab === 'settings')
  <div class="grid2">
    <form class="card pad form" method="post" action="{{ route('admin.apps.update', $app->key) }}">@csrf @method('put')
      <h2>Settings</h2>
      <div class="f"><label for="sn">Name</label><input class="in" id="sn" name="name" value="{{ $app->name }}" required></div>
      <div class="f"><label for="sc">Icon color</label><input class="in mono" id="sc" name="color" value="{{ $app->color }}" pattern="#[0-9A-Fa-f]{6}" required></div>
      <div class="f"><label for="sh">Home URL</label><input class="in mono" id="sh" name="home_url" value="{{ $app->home_url }}"></div>
      <div class="f"><label for="sm">Manifest URL</label><input class="in mono" id="sm" name="manifest_url" value="{{ $app->manifest_url }}" placeholder="https://…/.well-known/clauth.json"></div>
      <div class="f"><label for="ss">Status</label><select class="in" id="ss" name="status">
        @foreach(['live' => 'Live', 'not_connected' => 'Not connected yet', 'paused' => 'Paused (nobody can sign in)'] as $k => $l)<option value="{{ $k }}" @selected($app->status === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="f"><span>Organizations that can use it</span>
        @foreach($orgs as $o)<label class="cb"><input type="checkbox" name="orgs[]" value="{{ $o->id }}" @checked($app->organizations->contains('id', $o->id))> {{ $o->name }}</label>@endforeach</div>
      <div class="f"><span>Roles that must use 2-step verification</span>
        @foreach($app->roles as $r)<label class="cb"><input type="checkbox" name="require_mfa_roles[]" value="{{ $r->key }}" @checked(in_array($r->key, $app->require_mfa_roles ?? []))> {{ $r->name }}</label>@endforeach</div>
      <div class="actions"><button class="btn pri">Save settings</button></div>
    </form>
    <div style="display:flex;flex-direction:column;gap:20px">
      @foreach($app->clients as $c)
        <section class="card pad form">
          <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><h2>{{ $c->name }}</h2>
            <span class="chip {{ $c->revoked ? 'bad' : '' }}">{{ $c->revoked ? 'Turned off' : ['web' => 'Web', 'public' => 'Public (PKCE)', 'm2m' => 'Server-to-server'][$c->type] }}</span></div>
          <div class="f"><span>Client ID</span><div class="secretbox"><code id="cid{{ $loop->index }}">{{ $c->id }}</code><button class="btn sm" type="button" data-copy="#cid{{ $loop->index }}">Copy</button></div></div>
          @if($c->type !== 'public')<p class="small muted" style="margin:0">Secret: stored hashed. {{ $c->secret_expires_at ? 'Expires '.$c->secret_expires_at->format('j M Y') : '' }}</p>@endif
          @if($c->type !== 'm2m')
          <form class="form" method="post" action="{{ route('admin.apps.clients.update', [$app->key, $c->id]) }}">@csrf @method('put')
            <div class="f"><label for="ru{{ $loop->index }}">Redirect URLs (one per line, exact match)</label><textarea class="in" id="ru{{ $loop->index }}" name="redirect_uris">{{ implode("\n", $c->redirect_uris) }}</textarea></div>
            <div class="f"><label for="pl{{ $loop->index }}">After sign-out URLs</label><textarea class="in" id="pl{{ $loop->index }}" name="post_logout_redirect_uris">{{ implode("\n", $c->post_logout_redirect_uris ?? []) }}</textarea></div>
            <div class="actions"><button class="btn">Save URLs</button></div>
          </form>
          @endif
          @unless($c->revoked)
          <div class="actions">
            @if($c->type !== 'public')<form method="post" action="{{ route('admin.apps.clients.rotate', [$app->key, $c->id]) }}" data-confirm="Make a new secret? The old one stops working right away.">@csrf<button class="btn sm">Rotate secret</button></form>@endif
            <form method="post" action="{{ route('admin.apps.clients.revoke', [$app->key, $c->id]) }}" data-confirm="Turn off this client? Sign-ins through it stop.">@csrf<button class="btn sm danger">Turn off client</button></form>
          </div>
          @endunless
        </section>
      @endforeach
      <form class="card pad form" method="post" action="{{ route('admin.apps.clients.store', $app->key) }}">@csrf
        <h2>Add a client</h2>
        <p class="small muted" style="margin:0">E.g. a mobile app next to the web app.</p>
        <div class="f"><label for="cn">Name</label><input class="in" id="cn" name="name" placeholder="{{ $app->name }} mobile" required></div>
        <div class="f"><label for="ct">Type</label><select class="in" id="ct" name="type"><option value="public">Mobile app or SPA</option><option value="web">Web app</option><option value="m2m">Server-to-server</option></select></div>
        <div class="f"><label for="cr">Redirect URL</label><input class="in mono" id="cr" name="redirect_uri" placeholder="{{ $app->key }}://auth/callback"></div>
        <div class="actions"><button class="btn">Add client</button></div>
      </form>
      <form class="card pad form" method="post" action="{{ route('admin.apps.destroy', $app->key) }}">@csrf @method('delete')
        <h2>Delete app</h2>
        <p class="small muted" style="margin:0">Removes all access to {{ $app->name }} and turns off its clients.</p>
        <div class="f"><label for="dc">Type <code>{{ $app->key }}</code> to confirm</label><input class="in mono" id="dc" name="confirm" autocomplete="off"></div>
        <div class="actions"><button class="btn danger">Delete {{ $app->name }}</button></div>
      </form>
    </div>
  </div>
@endif

@if($tab === 'users')
  <section class="card"><div class="scroll"><table class="t">
    <thead><tr><th>Person</th><th>Organization</th><th>Role</th><th>Since</th></tr></thead>
    <tbody>
    @forelse($grants as $g)
      <tr><td><a href="{{ route('admin.users.show', [$g->user_id, 'org' => $g->organization_id]) }}">{{ $g->user?->name }}</a><br><span class="small muted">{{ $g->user?->email }}</span></td>
        <td>{{ $g->organization?->name }}</td><td><span class="chip">{{ $g->role?->name }}</span></td><td class="small muted">{{ $g->granted_at?->format('j M Y') }}</td></tr>
    @empty<tr><td colspan="4" class="empty">Nobody has access yet.</td></tr>@endforelse
    </tbody></table></div></section>
@endif

@if($tab === 'integration')
  @php($iss = config('clauth.issuer'))
  <div class="grid2">
    <section class="card pad">
      <h2 style="margin-bottom:12px">Endpoints</h2>
      <table class="t"><tbody>
        @foreach(['Issuer' => $iss, 'Discovery' => "$iss/.well-known/openid-configuration", 'Authorize' => "$iss/oauth/authorize", 'Token' => "$iss/oauth/token", 'JWKS' => "$iss/.well-known/jwks.json", 'User info' => "$iss/userinfo", 'Sign out' => "$iss/oauth/logout", 'Revoke' => "$iss/oauth/revoke"] as $k => $v)
          <tr><td class="muted">{{ $k }}</td><td><code>{{ $v }}</code></td></tr>
        @endforeach
      </tbody></table>
    </section>
    <section class="card pad">
      <h2 style="margin-bottom:12px">.env for {{ $app->name }}</h2>
<pre class="json" id="envsnip">CLAUTH_ISSUER={{ $iss }}
CLAUTH_CLIENT_ID={{ $app->clients->firstWhere('type', 'web')?->id ?? $app->clients->first()?->id ?? '(add a client)' }}
CLAUTH_CLIENT_SECRET=(shown once when the client was created)
CLAUTH_REDIRECT_URI={{ $app->clients->first()?->redirect_uris[0] ?? '' }}
CLAUTH_AUDIENCE={{ $app->key }}
CLAUTH_SCOPES="openid profile email clauth offline_access"</pre>
      <button class="btn sm" type="button" data-copy="#envsnip" style="margin-top:8px">Copy</button>
      <h2 style="margin:20px 0 8px">Rules for the app</h2>
      <ul class="small" style="margin:0;padding-left:18px;color:#C4CDD8;line-height:1.7">
        <li>Use Authorization Code + PKCE (S256) — required for every client.</li>
        <li>Verify access tokens offline with JWKS: <code>iss</code>, <code>aud={{ $app->key }}</code>, <code>exp</code>; refetch JWKS on an unknown <code>kid</code>.</li>
        <li>Read roles per organization from the <code>orgs</code> claim; keep data rules (teams, limits) in the app.</li>
        <li>Publish permissions at <code>/.well-known/clauth.json</code> and keep it in code.</li>
        <li>Link local users by <code>sub</code>, never by email after the first link.</li>
      </ul>
    </section>
  </div>
@endif
@endsection
