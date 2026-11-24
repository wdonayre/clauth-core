@php($me = auth()->user())
@php($isAdmin = $me->isAdmin())
@php($people = $me->isOwner() || $me->adminOrgIds() !== [])
@php($nav = fn (...$r) => request()->routeIs(...$r) ? 'nav on' : 'nav')
@php($cur = fn (...$r) => request()->routeIs(...$r) ? 'aria-current=page' : '')
@php($switchOrgs = $people ? \App\Models\Organization::orderBy('name')->when(! $me->isOwner(), fn ($q) => $q->whereIn('id', $me->adminOrgIds()))->limit(30)->get(['id', 'name', 'slug']) : collect())
@php($curOrg = request()->route('org'))
@php($curOrg = $curOrg instanceof \App\Models\Organization ? $curOrg : ($curOrg ? $switchOrgs->firstWhere('id', $curOrg) : null))
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') · Clauth</title>
<script src="/js/theme-boot.js?v={{ filemtime(public_path('js/theme-boot.js')) }}"></script>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#F7F8FA" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0B0D12" media="(prefers-color-scheme: dark)">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/clauth.css?v={{ filemtime(public_path('css/clauth.css')) }}">
<link rel="stylesheet" href="/css/console.css?v={{ filemtime(public_path('css/console.css')) }}">
@stack('head')
<script src="/js/clauth.js?v={{ filemtime(public_path('js/clauth.js')) }}" defer></script>
<script src="/js/console.js?v={{ filemtime(public_path('js/console.js')) }}" defer></script>
</head>
<body>
<a class="sr-only" href="#main">Skip to content</a>
<div class="console">
  <aside class="side" id="side" aria-label="Clauth">
    <a class="brand" href="{{ $isAdmin ? route('admin.dashboard') : route('account') }}">
      <svg width="26" height="26" viewBox="0 0 120 120" aria-hidden="true"><path class="k-ring" d="M84.51 39.43A32 32 0 1 0 84.51 80.57" fill="none" stroke-width="14"/><circle class="k-hole" cx="60" cy="54" r="8"/><path class="k-hole" d="M55.5 57h9l2.5 16h-14z"/></svg>
      <span>Clauth</span>
    </a>
    <button class="btn icon ghost menu-toggle" type="button" aria-controls="side" aria-expanded="false" data-menu-toggle aria-label="Menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>

    @if($people && $switchOrgs->isNotEmpty())
    <details class="orgswitch">
      <summary aria-label="Organization">
        <span class="oi" style="overflow:hidden">@if($curOrg && ($curLogo = \App\Http\Controllers\Admin\OrgController::logoUrl($curOrg)))<img src="{{ $curLogo }}" alt="" style="width:100%;height:100%;object-fit:contain">@else{{ $curOrg ? mb_strtoupper(mb_substr($curOrg->name, 0, 2)) : 'All' }}@endif</span>
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $curOrg?->name ?? ($me->isOwner() ? 'All organizations' : 'My organizations') }}</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="color:var(--muted)"><path d="M7 15l5 5 5-5M7 9l5-5 5 5"/></svg>
      </summary>
      <div class="menu" role="menu">
        <a role="menuitem" class="{{ $curOrg ? '' : 'on' }}" href="{{ route('admin.orgs.index') }}"><span class="oi" style="width:22px;height:22px;font-size:10px">All</span>All organizations</a>
        @foreach($switchOrgs as $o)
          <a role="menuitem" class="{{ $curOrg?->id === $o->id ? 'on' : '' }}" href="{{ Route::has('admin.orgs.show') ? route('admin.orgs.show', $o->id) : route('admin.orgs.index').'#org-'.$o->id }}"><span class="av muted" style="width:22px;height:22px;font-size:10px;border-radius:6px;overflow:hidden">@if($oLogo = \App\Http\Controllers\Admin\OrgController::logoUrl($o))<img src="{{ $oLogo }}" alt="" style="width:100%;height:100%;object-fit:contain">@else{{ mb_strtoupper(mb_substr($o->name, 0, 2)) }}@endif</span>{{ $o->name }}</a>
        @endforeach
      </div>
    </details>
    @endif

    @if($isAdmin)
    <button class="searchbtn" type="button" data-palette-open>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <span>Search</span><span class="kbd" data-kbd>⌘K</span>
    </button>
    @endif

    <nav aria-label="Console">
      @if($isAdmin)
        <a class="{{ $nav('admin.dashboard') }}" href="{{ route('admin.dashboard') }}" {{ $cur('admin.dashboard') }}>@include('partials.icon', ['i' => 'home'])Overview</a>
        @if($people)<a class="{{ $nav('admin.users.*') }}" href="{{ route('admin.users.index') }}" {{ $cur('admin.users.*') }}>@include('partials.icon', ['i' => 'users'])People</a>@endif
        <a class="{{ $nav('admin.apps.*', 'admin.themes.*') }}" href="{{ route('admin.apps.index') }}" {{ $cur('admin.apps.*') }}>@include('partials.icon', ['i' => 'box'])Apps</a>
        @if($people)<a class="{{ $nav('admin.orgs.*', 'admin.profiles.*', 'admin.org-data.*', 'admin.api-clients.*', 'admin.agents.*', 'admin.connections.*', 'admin.messages.*') }}" href="{{ route('admin.orgs.index') }}" {{ $cur('admin.orgs.*') }}>@include('partials.icon', ['i' => 'org'])Organizations</a>@endif
        @if($people || $me->isOwner())
          <span class="nav-h">Security</span>
          @if($people)<a class="{{ $nav('admin.audit*') }}" href="{{ route('admin.audit') }}" {{ $cur('admin.audit*') }}>@include('partials.icon', ['i' => 'activity'])Activity</a>@endif
          @if($me->isOwner())@php($openReports = \App\Models\Report::where('status', 'open')->count())<a class="{{ $nav('admin.reports*') }}" href="{{ route('admin.reports') }}" {{ $cur('admin.reports*') }}>@include('partials.icon', ['i' => 'flag'])Reports @if($openReports)<span class="n">{{ $openReports }}</span>@endif</a>@endif
        @endif
        @if($me->isOwner())
          <span class="nav-h">Configure</span>
          <a class="{{ $nav('admin.updates*') }}" href="{{ route('admin.updates') }}" {{ $cur('admin.updates*') }}>@include('partials.icon', ['i' => 'bell'])Release notes</a>
          <a class="{{ $nav('admin.settings*') }}" href="{{ route('admin.settings') }}" {{ $cur('admin.settings*') }}>@include('partials.icon', ['i' => 'gear'])Settings</a>
        @endif
      @else
        <a class="{{ $nav('account') }}" href="{{ route('account') }}" {{ $cur('account') }}>@include('partials.icon', ['i' => 'user'])My account</a>
        <a class="{{ $nav('account.tokens') }}" href="{{ route('account.tokens') }}" {{ $cur('account.tokens') }}>@include('partials.icon', ['i' => 'key'])API tokens</a>
      @endif
    </nav>

    <div class="foot">
      <div class="themeseg" role="radiogroup" aria-label="Appearance" data-theme-switch>
        <button type="button" role="radio" aria-checked="false" data-mode="light" title="Light">@include('partials.icon', ['i' => 'sun', 'iconSize' => 14])Light</button>
        <button type="button" role="radio" aria-checked="false" data-mode="dark" title="Dark">@include('partials.icon', ['i' => 'moon', 'iconSize' => 14])Dark</button>
        <button type="button" role="radio" aria-checked="true" data-mode="auto" title="Match my device">@include('partials.icon', ['i' => 'monitor', 'iconSize' => 14])Auto</button>
      </div>
      <a class="me {{ request()->routeIs('account*') ? 'on' : '' }}" href="{{ route('account') }}" {{ $cur('account*') }}>
        <span class="av" aria-hidden="true">{{ $me->initials() }}</span>
        <span class="who"><b>{{ $me->name }}</b><span>{{ $me->isOwner() ? 'Owner' : ($me->adminOrgIds() !== [] ? 'Org admin' : ($isAdmin ? 'Developer' : 'Member')) }} · My account</span></span>
      </a>
      <form method="post" action="{{ route('logout') }}">@csrf<button class="signout" type="submit">@include('partials.icon', ['i' => 'out', 'iconSize' => 16])Sign out</button></form>
    </div>
  </aside>

  <main class="content" id="main">
    @php($susp = $me->isOwner() ? collect() : \App\Models\Organization::whereIn('id', $me->devOrgIds())->whereNotNull('suspended_at')->get())
    @foreach($susp as $so)<div class="flash bad" role="alert">{{ $so->name }} is suspended by Clauth: {{ $so->suspend_reason }}. Its apps can’t sign anyone in and this console is read-only. Contact Clauth support.</div>@endforeach
    @if (session('status'))<div class="flash" role="status">@include('partials.icon', ['i' => 'check', 'iconSize' => 18]){{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash bad" role="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
  </main>
</div>

@if($isAdmin)
<div class="palette" data-palette role="dialog" aria-modal="true" aria-label="Search" data-url="{{ route('admin.search') }}">
  <div class="box">
    <div class="q">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="color:var(--muted)"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <input type="search" placeholder="Search people, apps and organizations" aria-label="Search" autocomplete="off" data-palette-input>
      <span class="kbd">Esc</span>
    </div>
    <div class="res" data-palette-results role="listbox">
      <div class="grp">Go to</div>
      <a class="it" href="{{ route('admin.dashboard') }}">@include('partials.icon', ['i' => 'home'])Overview</a>
      @if($people)<a class="it" href="{{ route('admin.users.index') }}">@include('partials.icon', ['i' => 'users'])People</a>@endif
      <a class="it" href="{{ route('admin.apps.index') }}">@include('partials.icon', ['i' => 'box'])Apps</a>
      @if($people)<a class="it" href="{{ route('admin.orgs.index') }}">@include('partials.icon', ['i' => 'org'])Organizations</a>
      <a class="it" href="{{ route('admin.audit') }}">@include('partials.icon', ['i' => 'activity'])Activity</a>@endif
      @if($me->isOwner())<a class="it" href="{{ route('admin.settings') }}">@include('partials.icon', ['i' => 'gear'])Settings</a>@endif
      <a class="it" href="{{ route('account') }}">@include('partials.icon', ['i' => 'user'])My account</a>
    </div>
    <div class="ft"><span><span class="kbd">↑</span> <span class="kbd">↓</span> to move</span><span><span class="kbd">↵</span> to open</span></div>
  </div>
</div>
@endif
@stack('end')
</body>
</html>
