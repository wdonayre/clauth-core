@php($me = auth()->user())
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') · Clauth</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/clauth.css?v={{ filemtime(public_path('css/clauth.css')) }}">
<script src="/js/clauth.js?v={{ filemtime(public_path('js/clauth.js')) }}" defer></script>
</head>
<body>
<div class="console">
  <nav class="side" aria-label="Main">
    <a class="logo" href="{{ $me->isAdmin() ? route('admin.dashboard') : route('account') }}"><img src="/img/clauth-logo.jpg" alt="Clauth"></a>
    @php($nav = fn ($r) => request()->routeIs($r) ? 'nav on' : 'nav')
    @if($me->isAdmin())
      <a class="{{ $nav('admin.dashboard') }}" href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>@include('partials.icon', ['i' => 'grid'])Overview</a>
      <a class="{{ $nav('admin.users.*') }}" href="{{ route('admin.users.index') }}">@include('partials.icon', ['i' => 'users'])Users</a>
      @if($me->isOwner())<a class="{{ $nav('admin.apps.*') }}" href="{{ route('admin.apps.index') }}">@include('partials.icon', ['i' => 'box'])Apps</a>@endif
      <a class="{{ $nav('admin.orgs.*') }}" href="{{ route('admin.orgs.index') }}">@include('partials.icon', ['i' => 'org'])Organizations</a>
      <a class="{{ $nav('admin.audit*') }}" href="{{ route('admin.audit') }}">@include('partials.icon', ['i' => 'list'])Audit log</a>
      @if($me->isOwner())<a class="{{ $nav('admin.settings*') }}" href="{{ route('admin.settings') }}">@include('partials.icon', ['i' => 'gear'])Settings</a>@endif
    @endif
    <a class="{{ $nav('account') }}" href="{{ route('account') }}">@include('partials.icon', ['i' => 'user'])My account</a>
    <a class="me" href="{{ route('account') }}">
      <span class="avatar">{{ $me->initials() }}</span>
      <span style="display:flex;flex-direction:column;min-width:0"><span style="font-size:13px;font-weight:600">{{ $me->name }}</span>
      <span class="small muted">{{ $me->isOwner() ? 'Owner' : ($me->isAdmin() ? 'Org admin' : $me->email) }}</span></span>
    </a>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="nav" style="width:100%;background:none;border:0;cursor:pointer" type="submit">@include('partials.icon', ['i' => 'out'])Sign out</button></form>
  </nav>
  <main class="content" id="main">
    @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash bad" role="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
  </main>
</div>
</body>
</html>
