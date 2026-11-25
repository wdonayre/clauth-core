{{-- Branded account portal (SPEC-branding B2): My account on an organization's own sign-in domain, in its brand. --}}
@php($me = auth()->user())
@php($brand ??= \App\Services\Brand::current())
@php($color = $brand['color'])
@php($onColor = $color && \App\Services\Theme::luminance($color) > 0.45 ? '#0B0D12' : '#FFFFFF')
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title') · {{ $brand['account'] }}</title>
<script src="/js/theme-boot.js?v={{ filemtime(public_path('js/theme-boot.js')) }}"></script>
@if($brand['level'] === 'whitelabel' && $brand['logo'])
<link rel="icon" href="{{ $brand['logo'] }}">
@else
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
@endif
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/clauth.css?v={{ filemtime(public_path('css/clauth.css')) }}">
<link rel="stylesheet" href="/css/console.css?v={{ filemtime(public_path('css/console.css')) }}">
<link rel="stylesheet" href="/css/pages/portal.css?v={{ filemtime(public_path('css/pages/portal.css')) }}">
@stack('head')
<script src="/js/clauth.js?v={{ filemtime(public_path('js/clauth.js')) }}" defer></script>
<script src="/js/console.js?v={{ filemtime(public_path('js/console.js')) }}" defer></script>
</head>
<body>
<a class="sr-only" href="#main">Skip to content</a>
<div class="console portal" @if($color) style="--pri-bg:{{ $color }};--pri-text:{{ $onColor }};--pri-hover:{{ $color }};--accent:{{ $color }}" @endif>
  <header class="portal-top">
    <a class="portal-brand" href="{{ route('account') }}">
      @if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="" width="32" height="32">@else<span class="portal-ico" style="background:{{ $color ?: 'var(--pri-bg)' }};color:{{ $color ? $onColor : 'var(--pri-text)' }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>@endif
      <span>{{ $brand['account'] }}</span>
    </a>
    <nav class="portal-nav" aria-label="{{ __('Account') }}">
      @if($me->isAdmin())<a href="{{ \App\Services\Domains::mainUrl('admin') }}">{{ __('Admin console') }}</a>@endif
      <form method="post" action="{{ route('logout') }}">@csrf<button class="btn sm ghost" type="submit">{{ __('Sign out') }}</button></form>
    </nav>
  </header>
  <main class="content portal-main" id="main">
    @if (session('status'))<div class="flash" role="status">@include('partials.icon', ['i' => 'check', 'iconSize' => 18]){{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash bad" role="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
  </main>
  <footer class="portal-foot">
    <span>{{ $brand['account'] }}@if($brand['secured_by']) · {{ __('Secured by Clauth') }}@endif</span>
    <nav aria-label="{{ __('Legal') }}"><a href="{{ $brand['privacy_url'] ?: route('privacy') }}">{{ __('Privacy') }}</a><a href="{{ $brand['terms_url'] ?: route('terms') }}">{{ __('Terms') }}</a>@if($brand['support_email'])<a href="mailto:{{ $brand['support_email'] }}">{{ __('Help') }}</a>@else<a href="{{ route('help') }}">{{ __('Help') }}</a>@endif</nav>
  </footer>
</div>
@stack('end')
</body>
</html>
