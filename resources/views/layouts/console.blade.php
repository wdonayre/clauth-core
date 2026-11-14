@php($me = auth()->user())
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') · Clauth</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#0B0D12">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&family=Space+Grotesk:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/clauth.css?v={{ filemtime(public_path('css/clauth.css')) }}">
<script src="/js/clauth.js?v={{ filemtime(public_path('js/clauth.js')) }}" defer></script>
</head>
<body>
<div class="console">
  <nav class="side" aria-label="Main">
    <a class="logo" href="{{ $me->isAdmin() ? route('admin.dashboard') : route('account') }}"><img src="/img/brand/logo-on-dark.svg" alt="Clauth" width="96" height="32"></a>
    @php($nav = fn ($r) => request()->routeIs($r) ? 'nav on' : 'nav')
    @if($me->isAdmin())
      @php($people = $me->isOwner() || $me->adminOrgIds() !== [])
      <a class="{{ $nav('admin.dashboard') }}" href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>@include('partials.icon', ['i' => 'grid'])Overview</a>
      @if($people)<a class="{{ $nav('admin.users.*') }}" href="{{ route('admin.users.index') }}">@include('partials.icon', ['i' => 'users'])Users</a>@endif
      <a class="{{ $nav('admin.apps.*') }}" href="{{ route('admin.apps.index') }}">@include('partials.icon', ['i' => 'box'])Apps</a>
      @if($people)<a class="{{ $nav('admin.orgs.*') }}" href="{{ route('admin.orgs.index') }}">@include('partials.icon', ['i' => 'org'])Organizations</a>
      <a class="{{ $nav('admin.audit*') }}" href="{{ route('admin.audit') }}">@include('partials.icon', ['i' => 'list'])Audit log</a>@endif
      @if($me->isOwner())<a class="{{ $nav('admin.reports*') }}" href="{{ route('admin.reports') }}">@include('partials.icon', ['i' => 'list'])Reports</a>@endif
      @if($me->isOwner())<a class="{{ $nav('admin.updates*') }}" href="{{ route('admin.updates') }}">@include('partials.icon', ['i' => 'bell'])Updates</a>@endif
      @if($me->isOwner())<a class="{{ $nav('admin.settings*') }}" href="{{ route('admin.settings') }}">@include('partials.icon', ['i' => 'gear'])Settings</a>@endif
    @endif
    <a class="{{ $nav('account') }}" href="{{ route('account') }}">@include('partials.icon', ['i' => 'user'])My account</a>
    <a class="me" href="{{ route('account') }}">
      <span class="avatar">{{ $me->initials() }}</span>
      <span style="display:flex;flex-direction:column;min-width:0"><span style="font-size:13px;font-weight:600">{{ $me->name }}</span>
      <span class="small muted">{{ $me->isOwner() ? 'Owner' : ($me->adminOrgIds() !== [] ? 'Org admin' : ($me->isAdmin() ? 'Developer' : $me->email)) }}</span></span>
    </a>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="nav" style="width:100%;background:none;border:0;cursor:pointer" type="submit">@include('partials.icon', ['i' => 'out'])Sign out</button></form>
  </nav>
  <main class="content" id="main">
    @php($susp = $me->isOwner() ? collect() : \App\Models\Organization::whereIn('id', $me->devOrgIds())->whereNotNull('suspended_at')->get())
    @foreach($susp as $so)<div class="flash bad" role="alert">{{ $so->name }} is suspended by Clauth: {{ $so->suspend_reason }}. Its apps can’t sign anyone in and this console is read-only. Contact Clauth support.</div>@endforeach
    @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash bad" role="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
  </main>
</div>
</body>
</html>
