@extends('layouts.console')
@section('title', $app->name)
@push('head')<link rel="stylesheet" href="/css/pages/apps.css?v={{ filemtime(public_path('css/pages/apps.css')) }}">@endpush
@section('content')
@php($tabUrl = fn ($t, $extra = []) => route('admin.apps.show', [$app->key, 'tab' => $t] + $extra))
<nav class="crumbs" aria-label="Breadcrumb"><a href="{{ route('admin.apps.index') }}">Apps</a><span aria-hidden="true">/</span><span>{{ $app->name }}</span></nav>
<header class="apphead">
  @include('partials.ui.app-icon', ['app' => $app, 'size' => 'lg xl'])
  <div class="who">
    <div class="t"><h1>{{ $app->name }}</h1>@include('admin.apps.status', ['a' => $app])</div>
    <p>{{ collect([$app->description, 'Made by '.($app->publisher?->name ?? 'no organization yet'),
      $app->organizations->isNotEmpty() ? 'Used by '.$app->organizations->pluck('name')->join(', ', ' and ') : 'Not used by any organization yet'])->filter()->join(' · ') }}</p>
  </div>
  <div class="actions">
    @if($app->home_url)<a class="btn" href="{{ $app->home_url }}" target="_blank" rel="noopener">Open app @include('partials.icon', ['i' => 'external', 'iconSize' => 14])</a>@endif
    <details class="menu-d">
      <summary class="btn icon" aria-label="More actions">@include('partials.icon', ['i' => 'more', 'iconSize' => 18])</summary>
      <div class="menu" role="menu">
        <a role="menuitem" href="{{ route('admin.apps.policy', $app->key) }}">@include('partials.icon', ['i' => 'shield', 'iconSize' => 16])Sign-in rules</a>
        @if($app->manifest_url)<form method="post" action="{{ route('admin.apps.sync', $app->key) }}">@csrf<button role="menuitem">@include('partials.icon', ['i' => 'refresh', 'iconSize' => 16])Check for changes</button></form>@endif
        <a role="menuitem" href="{{ $tabUrl('developer') }}">@include('partials.icon', ['i' => 'code', 'iconSize' => 16])Client ID and secrets</a>
        @if($app->publisher)<a role="menuitem" href="{{ route('admin.themes.index', $app->owner_organization_id) }}">@include('partials.icon', ['i' => 'palette', 'iconSize' => 16]){{ $app->publisher->name }} themes</a>@endif
        @if($canDelete)<button role="menuitem" class="danger" type="button" data-open-dialog="#del-app">@include('partials.icon', ['i' => 'trash', 'iconSize' => 16])Delete app</button>@endif
      </div>
    </details>
  </div>
</header>

<nav class="tabs" aria-label="App sections">
  @foreach(['roles' => 'Roles', 'people' => 'People', 'signin' => 'Sign-in page', 'settings' => 'Settings', 'developer' => 'Developer', 'webhooks' => 'Webhooks'] as $k => $l)
    <a class="{{ $tab === $k ? 'on' : '' }}" href="{{ $tabUrl($k) }}" @if($tab === $k) aria-current="page" @endif>{{ $l }}@if($k === 'people')<span class="n">{{ $peopleCount }}</span>@endif</a>
  @endforeach
</nav>

@include('admin.apps.tab-'.$tab)

@if($canDelete)
<dialog class="dlg" id="del-app" aria-labelledby="del-app-t" @if($errors->has('confirm')) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.apps.destroy', $app->key) }}">@csrf @method('delete')
    <div class="dlg-h"><h2 id="del-app-t">Delete {{ $app->name }}?</h2><p>Removes its roles and everyone’s access, and turns off its clients. This can’t be undone.</p></div>
    <div class="dlg-b"><div class="f"><label for="dc">Type <code>{{ $app->key }}</code> to confirm</label><input class="in mono" id="dc" name="confirm" autocomplete="off" required>
      @error('confirm')<span class="err">{{ $message }}</span>@enderror</div></div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn danger solid">Delete app</button></div>
  </form>
</dialog>
@endif

@if($connected && $created)
@php($iss = config('clauth.issuer'))
<dialog class="dlg connect" id="connected" aria-labelledby="connected-t" data-open-on-load>
  <div class="dlg-h">
    <div class="t"><h2 id="connected-t">Connect an app</h2><span class="stepno">Step 3 of 3</span></div>
    <div class="steps" aria-hidden="true"><span class="on"></span><span class="on"></span><span class="on"></span></div>
  </div>
  <div class="dlg-b">
    <div class="flash" role="status">{{ $app->name }} is ready.@if($created['secret']) Copy the secret now — it won’t be shown again.@else It’s a phone or desktop app: no secret, it signs in with PKCE.@endif</div>
<pre class="env" id="connected-env">CLAUTH_ENABLED=true
CLAUTH_ISSUER={{ $iss }}
CLAUTH_AUDIENCE={{ $app->key }}
CLAUTH_CLIENT_ID={{ $created['id'] }}
@if($created['secret'])CLAUTH_CLIENT_SECRET={{ $created['secret'] }}
@endif
@if($app->clients->firstWhere('id', $created['id'])?->redirect_uris[0] ?? null)CLAUTH_REDIRECT_URI={{ $app->clients->firstWhere('id', $created['id'])->redirect_uris[0] }}
@endif</pre>
    <button class="btn" type="button" data-copy="#connected-env" style="align-self:flex-start">@include('partials.icon', ['i' => 'copy', 'iconSize' => 16])Copy all</button>
    <p class="small muted" style="margin:0">Next: add roles on the Roles tab, or serve a permissions file at <code>/.well-known/clauth.json</code>. <a href="https://helloclauth.com/docs/" target="_blank" rel="noopener">Guide</a></p>
  </div>
  <div class="dlg-f"><a class="btn" href="{{ $tabUrl('roles') }}">Add roles</a><button class="btn pri" type="button" data-close-dialog>Done</button></div>
</dialog>
@endif
@endsection
@push('end')<script src="/js/pages/apps.js?v={{ filemtime(public_path('js/pages/apps.js')) }}" defer></script>@endpush
