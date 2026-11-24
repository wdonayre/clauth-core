@extends('layouts.console')
@section('title', 'API & directory sync · '.($org?->name ?? 'Platform'))
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@push('end')<script src="/js/pages/org-sub.js?v={{ filemtime(public_path('js/pages/org-sub.js')) }}" defer></script>@endpush
@php
  // Short, plain names for scopes ("users:read" → "Read people").
  $nouns = ['users' => 'people', 'orgs' => 'organizations', 'apps' => 'apps', 'access' => 'access', 'audit' => 'activity', 'connections' => 'connections',
      'agents' => 'agents', 'config' => 'setup', 'settings' => 'platform settings'];
  $can = fn ($s) => match (true) {
      $s === 'authzen' => 'Check access', $s === 'scim' => 'Directory sync',
      str_contains($s, ':') => (str_ends_with($s, ':read') ? 'Read ' : 'Manage ').($nouns[strtok($s, ':')] ?? strtok($s, ':')),
      default => $s,
  };
  $created = session('created_client');
@endphp
@section('content')
@if($org)
  @include('partials.ui.org-tabs', ['org' => $org])
@else
  <div class="head"><div><h1>Platform API clients</h1><p>Platform clients can reach <b>every</b> organization and the platform settings. Give them only the scopes they need.</p></div>
    <div class="actions"><a class="btn" href="{{ url('/api/v1/openapi.json') }}" target="_blank" rel="noopener">API reference</a>
      <button class="btn pri" type="button" data-open-dialog="#new-client">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New API client</button></div></div>
@endif

@if($created)
  <section class="card" aria-labelledby="secret-h">
    <div class="card-h"><div><h2 id="secret-h">Copy the secret for “{{ $created['name'] }}” now</h2><p>It is shown only once. If you lose it, make a new secret.</p></div><span class="badge warn dot">Shown once</span></div>
    <div class="pastebox">
      <div class="line"><span>Client ID</span><span class="code" id="cid">{{ $created['id'] }}</span><button class="btn sm" type="button" data-copy="#cid">Copy</button></div>
      <div class="line"><span>Client secret</span><span class="code" id="csec">{{ $created['secret'] }}</span><button class="btn sm" type="button" data-copy="#csec">Copy</button></div>
      <pre class="code">curl -s {{ url('/oauth/token') }} -d grant_type=client_credentials \
  -d client_id={{ $created['id'] }} -d client_secret=… -d "scope={{ $created['scopes'] }}"
curl -s {{ url('/api/v1/capabilities') }} -H "Authorization: Bearer $TOKEN"</pre>
    </div>
  </section>
@endif

@if($org)
  @php($scimNew = session('scim_token'))
  <section class="card" aria-labelledby="sync-h">
    <div class="card-h">
      <div><h2 id="sync-h">Directory sync</h2><p>Add, update and remove people automatically from your company directory (SCIM 2.0). Groups become job roles.</p></div>
      @if($scim)<span class="badge ok dot">Set up · last used {{ $scim->last_used_at?->diffForHumans() ?? 'never' }}</span>@else<span class="badge muted">Not set up</span>@endif
    </div>
    <div class="provs">
      @foreach([
        'google' => ['Google Workspace', 'Sync users and groups from your Google admin console.'],
        'entra' => ['Microsoft Entra ID', 'Provision from Entra (Azure AD) enterprise apps.'],
        'okta' => ['Okta', 'Use Okta’s SCIM provisioning.'],
        'other' => ['Other SCIM', 'Any directory or HR system that speaks SCIM 2.0.'],
      ] as $k => [$name, $text])
        <div class="prov"><b style="font-weight:600">{{ $name }}</b><span>{{ $text }}</span>
          <button class="btn sm" type="button" data-open-dialog="#scim-{{ $k }}">{{ $scim ? 'New token' : 'Set up' }}</button></div>
      @endforeach
    </div>
    <div class="pastebox">
      <span class="small muted">{{ $scimNew ? 'Paste these into your directory now. The token works for a year and is shown only once:' : 'After setup, paste these into your directory:' }}</span>
      <div class="line"><span>SCIM address</span><span class="code" id="scim-url">{{ $scimNew['url'] ?? $scimUrl }}</span><button class="btn sm" type="button" data-copy="#scim-url">Copy</button></div>
      <div class="line"><span>Token</span>
        @if($scimNew)<span class="code" id="scim-token">{{ $scimNew['token'] }}</span><button class="btn sm" type="button" data-copy="#scim-token">Copy</button>
        @else<span class="code">{{ $scim ? 'Set up '.$scim->created_at?->format('M j, Y').' · expires '.$scim->secret_expires_at?->format('M j, Y') : 'Shown once when you set up' }}</span>@endif
      </div>
      @if($scim)
        <form method="post" action="{{ route('admin.api-clients.revoke', [$orgKey, $scim->id]) }}" data-confirm="Turn directory sync off? The SCIM token stops working now.">@csrf
          <button class="btn sm danger">Turn off directory sync</button></form>
      @endif
    </div>
  </section>
@endif

@if($org && Route::has('admin.agents.index'))
<section class="card" aria-labelledby="agents-link-h">
  <div class="set" style="border-top:0"><div><b id="agents-link-h">Agents</b><p>AI agents and automations that act for {{ $org->name }} with their own credentials and limited access.</p></div><a class="btn" href="{{ route('admin.agents.index', $org->id) }}">Open agents</a></div>
</section>
@endif

<section class="card" aria-labelledby="api-h">
  <div class="card-h">
    <div><h2 id="api-h">API clients ({{ $clients->count() }})</h2>
      <p>@if($org)Let your own systems manage people, invites and access in {{ $org->name }}.@else Scripts and servers that manage Clauth itself.@endif A client acts as the person who made it, and stops working if that person no longer runs {{ $org?->name ?? 'Clauth' }}.</p></div>
    @if($org)
      <div class="actions"><a class="btn" href="{{ url('/api/v1/openapi.json') }}" target="_blank" rel="noopener">API reference</a>
        <button class="btn pri" type="button" data-open-dialog="#new-client">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New API client</button></div>
    @endif
  </div>
  @if($clients->isEmpty())
    <div class="empty"><b>No API clients yet</b><span>Make one for each script or server that talks to Clauth.</span></div>
  @else
    <div class="scroll">
      <table class="t">
        <thead><tr><th scope="col">Name</th><th scope="col">Can</th><th scope="col">Last used</th><th scope="col">Created</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($clients as $cl)
            <tr>
              <td><b style="font-weight:500">{{ $cl->name }}</b> @if($cl->revoked)<span class="badge bad">Off</span>@endif
                <div><span class="code sm">{{ Str::limit($cl->id, 12, '…') }}</span></div></td>
              <td><div class="pills">@foreach(collect($cl->scopes ?? [])->take(3) as $s)<span class="badge info" title="{{ $s }}">{{ $can($s) }}</span>@endforeach
                @if(count($cl->scopes ?? []) > 3)<span class="badge muted">+{{ count($cl->scopes) - 3 }}</span>@endif</div></td>
              <td class="muted nowrap">{{ $cl->last_used_at?->diffForHumans() ?? 'Never' }}</td>
              <td class="muted nowrap">{{ $cl->created_at?->format('M j') }} · by {{ $cl->created_by === auth()->id() ? 'you' : ($cl->creator?->name ?? '—') }}</td>
              <td class="act"><button class="btn sm" type="button" data-open-dialog="#client-{{ $loop->index }}">Manage</button></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</section>

<dialog class="dlg wide" id="new-client" aria-labelledby="new-client-h" @if($errors->has('name') || $errors->has('scopes') || $errors->has('scopes.*')) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.api-clients.store', $orgKey) }}">@csrf
    <div class="dlg-h"><h2 id="new-client-h">New API client</h2><p>The secret lasts a year. Tokens last 15 minutes; ask for a new one with client credentials.</p></div>
    <div class="dlg-b">
      <label class="f"><span>Name</span><input class="in" name="name" placeholder="HR system sync" required maxlength="80" value="{{ old('name') }}"></label>
      <fieldset class="f" style="border:0;padding:0;margin:0"><legend class="lbl" style="margin-bottom:6px">What it can do</legend>
        <div class="scopes">
          @foreach($scopes as $s => $label)
            <label class="cb"><input type="checkbox" name="scopes[]" value="{{ $s }}" @checked(old('scopes') ? in_array($s, (array) old('scopes'), true) : str_ends_with($s, ':read'))>
              <span><b style="font-weight:500">{{ $can($s) }}</b> <code class="muted">{{ $s }}</code><span class="hint">{{ $label }}</span></span></label>
          @endforeach
        </div>
        @error('scopes')<span class="err">{{ $message }}</span>@enderror
      </fieldset>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Create client</button></div>
  </form>
</dialog>

@foreach($clients as $cl)
  <dialog class="dlg" id="client-{{ $loop->index }}" aria-labelledby="client-{{ $loop->index }}-h">
    <div class="dlg-h"><h2 id="client-{{ $loop->index }}-h">{{ $cl->name }}</h2><p>@if($cl->revoked)Turned off. Its tokens no longer work.@else Acts as {{ $cl->creator?->name ?? 'the person who made it' }}.@endif</p></div>
    <div class="dlg-b">
      <dl class="kv">
        <dt>Client ID</dt><dd><span class="code" id="cl-id-{{ $loop->index }}">{{ $cl->id }}</span> <button class="btn sm" type="button" data-copy="#cl-id-{{ $loop->index }}">Copy</button></dd>
        <dt>Scopes</dt><dd class="mono">{{ implode(' ', $cl->scopes ?? []) }}</dd>
        <dt>Made by</dt><dd>{{ $cl->creator?->name ?? '—' }} · {{ $cl->created_at?->format('j M Y') }}</dd>
        <dt>Last used</dt><dd>{{ $cl->last_used_at?->diffForHumans() ?? 'Never' }}</dd>
        <dt>Secret expires</dt><dd>{{ $cl->secret_expires_at?->format('j M Y') ?? '—' }}</dd>
      </dl>
    </div>
    <div class="dlg-f">
      @unless($cl->revoked)
        <form method="post" action="{{ route('admin.api-clients.revoke', [$orgKey, $cl->id]) }}" data-confirm="Turn “{{ $cl->name }}” off? Its tokens stop working now." style="margin-right:auto">@csrf<button class="btn danger">Turn off</button></form>
        <form method="post" action="{{ route('admin.api-clients.rotate', [$orgKey, $cl->id]) }}" data-confirm="Make a new secret? The old one and its tokens stop working now.">@csrf<button class="btn">New secret</button></form>
      @endunless
      <button class="btn" type="button" data-close-dialog>Close</button>
    </div>
  </dialog>
@endforeach

@if($org)
  @foreach([
    'google' => ['Google Workspace', ['In the Google Admin console, open Apps › Web and mobile apps and add a custom SAML/SCIM app (or use auto-provisioning).', 'Paste the SCIM address and the token below.', 'Pick the groups to sync. Each group becomes a job role in Clauth.']],
    'entra' => ['Microsoft Entra ID', ['In Entra, open Enterprise applications › New application › Create your own (non-gallery).', 'Under Provisioning, choose Automatic and paste the SCIM address as Tenant URL and the token as Secret Token.', 'Assign the users and groups to sync, then start provisioning.']],
    'okta' => ['Okta', ['In Okta, add a SCIM 2.0 app integration (Header Auth).', 'Paste the SCIM address as the base URL and the token as the API token.', 'Turn on Create, Update and Deactivate users, and push the groups you want as job roles.']],
    'other' => ['Other SCIM', ['Point your directory or HR system at the SCIM address.', 'Send the token as a Bearer token.', 'Users and Groups endpoints follow SCIM 2.0; groups map to job roles.']],
  ] as $k => [$name, $steps])
    <dialog class="dlg" id="scim-{{ $k }}" aria-labelledby="scim-{{ $k }}-h">
      <form method="post" action="{{ route('admin.api-clients.scim', $orgKey) }}" @if($scim) data-confirm="Make a new SCIM token? The current token for {{ $org->name }} stops working." @endif>@csrf
        <div class="dlg-h"><h2 id="scim-{{ $k }}-h">Set up {{ $name }}</h2><p>New people get an invite email to set a password. People switched off in the directory lose access.</p></div>
        <div class="dlg-b">
          <ol class="steps">@foreach($steps as $st)<li>{{ $st }}</li>@endforeach</ol>
          <div class="callout">Clauth makes a token that works for a year and shows it once. @if($scim)<b>Your current token stops working.</b>@endif</div>
        </div>
        <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Make SCIM token</button></div>
      </form>
    </dialog>
  @endforeach
@endif
@endsection
