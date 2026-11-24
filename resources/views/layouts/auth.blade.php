@php($t = $theme ?? \App\Services\Theme::for(null))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title', __('Sign in')) · {{ $t['app']?->name ?? 'Clauth' }}</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="theme-color" content="#0B0D12">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&family=Space+Grotesk:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/clauth.css?v={{ filemtime(public_path('css/clauth.css')) }}">
@if($fu = \App\Services\Theme::fontsUrl($t))<link href="{{ $fu }}" rel="stylesheet">@endif
<script src="/js/clauth.js?v={{ filemtime(public_path('js/clauth.js')) }}" defer></script>
</head>
<body>
<div class="auth {{ $t['dark'] ? 'dark' : 'light' }} {{ ($t['layout'] ?? 'center') === 'split' ? 'split' : '' }}" style="{{ \App\Services\Theme::css($t) }}">
  @if(($t['layout'] ?? 'center') === 'split')
    <aside class="split-side" aria-hidden="true"><div><b>{{ $t['app']?->name ?? ($t['brand'] ?? __('Sign in')) }}</b>@if(! empty($t['tagline']))<p>{{ $t['tagline'] }}</p>@endif</div></aside>
  @endif
  <main @hasSection('wide') style="max-width:760px" @endif>
    <div class="card">
      <div class="brand">
        @if($t['app'] && $t['logo'])
          <img src="{{ $t['logo'] }}" alt="{{ $t['app']->name }}">
        @else
          <img class="clauth-logo" src="/img/brand/logo-on-{{ $t['dark'] ? 'dark' : 'light' }}.svg" alt="Clauth" width="120" height="40">
        @endif
      </div>
      @yield('card')
    </div>
  </main>
  <footer>
    <span class="powered">{{ __('Powered by') }} <img src="/img/brand/logo-on-{{ $t['dark'] ? 'dark' : 'light' }}.svg" alt="Clauth" width="54" height="18"></span>
    <nav aria-label="Legal"><a href="{{ route('privacy') }}">{{ __('Privacy') }}</a><a href="{{ route('terms') }}">{{ __('Terms') }}</a><a href="{{ route('help') }}">{{ __('Help') }}</a><a href="{{ route('report') }}">{{ __('Report this page') }}</a></nav>
    <nav aria-label="Language" class="langs">@foreach(config('clauth.locales') as $code => $label)@if($code !== app()->getLocale())<a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" lang="{{ $code }}">{{ $label }}</a>@endif @endforeach</nav>
  </footer>
</div>
</body>
</html>
