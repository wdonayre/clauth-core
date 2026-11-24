{{-- App › Developer: clients (IDs, secrets, redirect addresses), permissions file, endpoints + discovery, registration tokens, .env. --}}
@php($iss = config('clauth.issuer'))
@php($typeLabel = ['web' => 'Website', 'public' => 'Phone, desktop or SPA (PKCE)', 'm2m' => 'Server to server', 'device' => 'TV, POS or CLI (device code)'])
@if($created)
  <div class="flash info" role="status" style="flex-direction:column;align-items:stretch">
    <span><b>{{ $created['name'] }}</b> — client ID <code>{{ $created['id'] }}</code>.@unless($created['secret']) This is a public client: no secret, PKCE only.@endunless</span>
    @if($created['secret'])<span>Client secret (shown <b>once</b>; store it in the app’s .env):</span>
      <div class="secretbox"><code id="sec">{{ $created['secret'] }}</code><button class="btn sm" type="button" data-copy="#sec">Copy</button></div>@endif
  </div>
@endif

<div class="dev-wrap">
  <div class="stack">
    <section class="card" aria-labelledby="d-app">
      <div class="ch"><h2 id="d-app">App key</h2></div>
      <div class="set" style="border-top:0"><div style="min-width:0"><span class="codev" id="appkey">{{ $app->key }}</span><p>Sent as the token audience (<code>aud</code>).</p></div>
        <span class="actions"><span class="small muted">Can’t change</span><button class="btn sm" type="button" data-copy="#appkey">Copy</button></span></div>
    </section>

    <div class="section-h" id="clients"><div><h2>Clients</h2><p>Each place {{ $app->name }} signs people in from: website, phone app, server.</p></div>
      <button class="btn" type="button" data-open-dialog="#add-client">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add a client</button></div>

    @forelse($app->clients as $c)
      <section class="card" aria-labelledby="cl-{{ $loop->index }}">
        <div class="card-h"><div><h2 id="cl-{{ $loop->index }}">{{ $c->name }}</h2><p>{{ $typeLabel[$c->type] ?? $c->type }}@if($c->registration_token_hash) · registered through the API @endif @if($c->last_used_at) · last used {{ $c->last_used_at->diffForHumans() }}@endif</p></div>
          @if($c->revoked)<span class="badge bad">Turned off</span>@else<span class="badge ok dot">On</span>@endif</div>
        <div class="set"><div style="min-width:0"><b>Client ID</b><div style="margin-top:6px"><span class="codev" id="cid{{ $loop->index }}">{{ $c->id }}</span></div></div>
          <button class="btn sm" type="button" data-copy="#cid{{ $loop->index }}">Copy</button></div>
        @if(! in_array($c->type, ['public', 'device'], true))
          <div class="set"><div><b>Client secret</b><p>Stored hashed, shown only once{{ $c->created_at ? ' · created '.$c->created_at->format('M j, Y') : '' }}{{ $c->secret_expires_at ? ' · expires '.$c->secret_expires_at->format('M j, Y') : '' }}</p></div>
            @unless($c->revoked)<form method="post" action="{{ route('admin.apps.clients.rotate', [$app->key, $c->id]) }}" data-confirm="Make a new secret? The old one stops working right away.">@csrf<button class="btn sm">Make a new secret</button></form>@endunless</div>
        @endif
        @if(! in_array($c->type, ['m2m', 'device'], true))
          <form class="set" method="post" action="{{ route('admin.apps.clients.update', [$app->key, $c->id]) }}" style="align-items:flex-end">@csrf @method('put')
            <div class="frow" style="flex:1 1 100%">
              <div class="f"><label for="ru{{ $loop->index }}">Redirect addresses <span class="hint">— one per line, exact match</span></label><textarea class="in" id="ru{{ $loop->index }}" name="redirect_uris" rows="3">{{ implode("\n", $c->redirect_uris) }}</textarea></div>
              <div class="f"><label for="pl{{ $loop->index }}">After sign-out addresses</label><textarea class="in" id="pl{{ $loop->index }}" name="post_logout_redirect_uris" rows="3">{{ implode("\n", $c->post_logout_redirect_uris ?? []) }}</textarea></div>
            </div>
            <span class="hint" style="flex:1">Where Clauth may send people back after they sign in or out.</span>
            <button class="btn sm">Save addresses</button>
          </form>
        @endif
        @unless($c->revoked)
          <div class="card-f"><form method="post" action="{{ route('admin.apps.clients.revoke', [$app->key, $c->id]) }}" data-confirm="Turn off this client? Sign-ins through it stop.">@csrf<button class="btn sm danger">Turn off client</button></form></div>
        @endunless
      </section>
    @empty
      <div class="card empty"><b>No clients yet</b><span>Add one so {{ $app->name }} can sign people in.</span></div>
    @endforelse

    <section class="card" aria-labelledby="d-man">
      <div class="ch"><h2 id="d-man">Permissions file</h2></div>
      <div class="set" style="border-top:0">
        <div style="min-width:0">@if($app->manifest_url)<span class="codev">{{ $app->manifest_url }}</span>@else<p style="margin:0">No address set. Add it under Settings, or paste the file on the Roles tab.</p>@endif
          <p style="margin-top:6px">{{ $app->manifest_synced_at ? 'Last read '.$app->manifest_synced_at->diffForHumans() : 'Never read' }} · {{ $permissions->whereNull('deprecated_at')->count() }} actions, {{ $app->roles->count() }} roles</p></div>
        @if($app->manifest_url)<form method="post" action="{{ route('admin.apps.sync', $app->key) }}">@csrf<button class="btn sm">Check now</button></form>@endif
      </div>
    </section>

    <section class="card endpoints" aria-labelledby="d-end">
      <div class="ch"><h2 id="d-end">Endpoints</h2><p>Any OpenID Connect library can read these from the discovery address.</p></div>
      <div class="rows" style="margin-top:8px">
        @foreach(['Issuer' => $iss, 'Discovery' => "$iss/.well-known/openid-configuration", 'OAuth metadata' => "$iss/.well-known/oauth-authorization-server", 'Authorize' => "$iss/oauth/authorize", 'Token' => "$iss/oauth/token", 'JWKS' => "$iss/.well-known/jwks.json", 'User info' => "$iss/userinfo", 'Sign out' => "$iss/oauth/logout", 'Revoke' => "$iss/oauth/revoke", 'Introspect' => "$iss/oauth/introspect", 'Register' => "$iss/oauth/register"] as $k => $v)
          <div class="row"><span class="lbl">{{ $k }}</span><code id="ep{{ $loop->index }}">{{ $v }}</code><button class="btn sm ghost" type="button" data-copy="#ep{{ $loop->index }}" aria-label="Copy {{ $k }} address">Copy</button></div>
        @endforeach
      </div>
    </section>

    <section class="card" aria-labelledby="d-reg">
      <div class="ch"><h2 id="d-reg">Registration tokens</h2><p>Let a tool register its own client into {{ $app->name }} (dynamic client registration). Make them with the API: <code>POST /api/v1/apps/{{ $app->key }}/registration-tokens</code>.</p></div>
      @if($regTokens->isEmpty())
        <div class="set"><p style="margin:0">None yet.</p></div>
      @else
        <div class="scroll" style="margin-top:12px"><table class="t">
          <thead><tr><th scope="col">Name</th><th scope="col">Uses left</th><th scope="col">Expires</th><th scope="col">Made</th></tr></thead>
          <tbody>@foreach($regTokens as $rt)
            @php($exp = $rt->expires_at ? \Illuminate\Support\Carbon::parse($rt->expires_at) : null)
            <tr><td>{{ $rt->name }}</td><td>{{ $rt->uses_left ?? 'Unlimited' }}</td>
              <td>@if($exp)<span class="{{ $exp->isPast() ? 'bad-t' : '' }}">{{ $exp->isPast() ? 'Expired' : $exp->format('M j, Y') }}</span>@else Never @endif</td>
              <td class="muted">{{ $rt->created_at ? \Illuminate\Support\Carbon::parse($rt->created_at)->format('M j, Y') : '—' }}</td></tr>
          @endforeach</tbody></table></div>
      @endif
    </section>
  </div>

  <aside aria-label="Connect">
    <b>Connect with the Laravel kit</b>
<pre class="env" id="envsnip">CLAUTH_ENABLED=true
CLAUTH_ISSUER={{ $iss }}
CLAUTH_AUDIENCE={{ $app->key }}
CLAUTH_CLIENT_ID={{ $app->clients->where('revoked', false)->firstWhere('type', 'web')?->id ?? $app->clients->where('revoked', false)->first()?->id ?? '(add a client)' }}
CLAUTH_CLIENT_SECRET=(shown once when the client was made)
CLAUTH_REDIRECT_URI={{ $app->clients->where('revoked', false)->first()?->redirect_uris[0] ?? '' }}
CLAUTH_SCOPES="openid profile email clauth offline_access"</pre>
    <button class="btn sm" type="button" data-copy="#envsnip" style="align-self:flex-start">@include('partials.icon', ['i' => 'copy', 'iconSize' => 14])Copy</button>
    <p class="small muted" style="margin:0">Any OpenID Connect library works too. <a href="https://helloclauth.com/docs/" target="_blank" rel="noopener">Read the guide</a></p>
    <div class="card pad" style="display:flex;flex-direction:column;gap:8px">
      <b>Clauth bar (required)</b>
      <span class="badge {{ $app->bar_seen_at && $app->bar_seen_at->gt(now()->subDays(2)) ? 'ok' : 'warn' }}" style="align-self:flex-start">{{ $app->bar_seen_at ? 'Last seen '.$app->bar_seen_at->diffForHumans() : 'Not seen yet' }}</span>
      <p class="small muted" style="margin:0">Laravel apps: <code>@clauthBar</code> in the staff layout. Others: <code>&lt;script src="{{ $iss }}/bar/v1" defer&gt;&lt;/script&gt;</code> + <code>&lt;clauth-bar src="/your/bar/endpoint"&gt;&lt;/clauth-bar&gt;</code>.</p>
    </div>
    <div class="card pad" style="display:flex;flex-direction:column;gap:8px">
      <b>Rules for the app</b>
      <ul class="small" style="margin:0;padding-left:18px;color:var(--text-2);line-height:1.7">
        <li>Use Authorization Code + PKCE (S256) — required for every client.</li>
        <li>Verify access tokens offline with JWKS: <code>iss</code>, <code>aud={{ $app->key }}</code>, <code>exp</code>; refetch JWKS on an unknown <code>kid</code>.</li>
        <li>Read roles per organization from the <code>orgs</code> claim; keep data rules (teams, limits) in the app.</li>
        <li>Publish permissions at <code>/.well-known/clauth.json</code> and keep it in code.</li>
        <li>Link local users by <code>sub</code>, never by email after the first link.</li>
      </ul>
    </div>
  </aside>
</div>

<dialog class="dlg" id="add-client" aria-labelledby="add-client-t" @if(old('_form') === 'client' && $errors->any()) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.apps.clients.store', $app->key) }}">@csrf
    <input type="hidden" name="_form" value="client">
    <div class="dlg-h"><h2 id="add-client-t">Add a client</h2><p>E.g. a phone app next to the website.</p></div>
    <div class="dlg-b">
      <div class="f"><label for="cn">Name</label><input class="in" id="cn" name="name" value="{{ old('_form') === 'client' ? old('name') : '' }}" placeholder="{{ $app->name }} mobile" maxlength="80" required></div>
      <div class="f"><label for="ct">Type</label><select class="in" id="ct" name="type">
        @foreach(['public' => 'Phone app or SPA', 'web' => 'Website', 'm2m' => 'Server to server', 'device' => 'TV, POS terminal or CLI (device code)'] as $k => $l)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="f"><label for="cr">Redirect address</label><input class="in mono" id="cr" name="redirect_uri" value="{{ old('_form') === 'client' ? old('redirect_uri') : '' }}" placeholder="{{ $app->key }}://auth/callback">
        <span class="hint">Needed for websites and phone apps.</span>@error('redirect_uri')<span class="err">{{ $message }}</span>@enderror</div>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Add client</button></div>
  </form>
</dialog>
