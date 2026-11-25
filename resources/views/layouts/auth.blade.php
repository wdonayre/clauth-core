@php
  $t = $theme ?? \App\Services\Theme::for(null);
  $tApp = $t['app'] ?? null;
  $isSplit = ($t['layout'] ?? 'center') === 'split';
  // The Clauth brand panel (redesign "Sign in" board) shows on pages that ask for it, in the default Clauth look only.
  $hasPanel = ! $isSplit && ($t['style'] ?? '') === 'classic' && $__env->hasSection('panel');
  // On a shared custom domain with no app in the flow, the page is the domain's (its brand name), not its first app's.
  $flowApp = isset($app) && $app instanceof \App\Models\Application ? $app : null;
  $brandName = (! $flowApp && ! empty($t['brand'])) ? $t['brand'] : ($tApp?->name ?? ($t['brand'] ?? null));
  $isAppBrand = $tApp && $brandName === $tApp->name;
  $lightIco = fn (?string $hex) => $hex && \App\Services\Theme::luminance($hex) > 0.45;
  // "Match device": light by default, dark variables + dark logos when the visitor's device is dark.
  $isAuto = ! empty($t['auto']);
  $darkCss = \App\Services\Theme::darkCss($t);
  // Branded accounts (SPEC-branding): an organization's name/logo instead of Clauth's; white-label hides Clauth entirely.
  $brand ??= \App\Services\Brand::current($flowApp);
  $branded = $brand['level'] !== 'clauth';
  $white = $brand['level'] === 'whitelabel';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title', __('Sign in')) · {{ $tApp?->name ?? $brand['account'] }}</title>
@if($white && $brand['logo'])
<link rel="icon" href="{{ $brand['logo'] }}">
<link rel="apple-touch-icon" href="{{ $brand['logo'] }}">
@else
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif
@if($isAuto)
<meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $t['vars']['bg'] ?? '#F7F8FA' }}">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $t['dark_vars']['bg'] ?? '#0F0F12' }}">
@else
<meta name="theme-color" content="{{ $hasPanel ? '#0B0D12' : ($t['vars']['bg'] ?? '#F7F8FA') }}">
@endif
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/clauth.css?v={{ filemtime(public_path('css/clauth.css')) }}">
@if($darkCss)<style>@media (prefers-color-scheme: dark){ .auth.auto{ {{ $darkCss }} } body.auth-body{ --page-bg:{{ $t['dark_vars']['bg'] ?? '#0F0F12' }} !important } }</style>@endif
@if($fu = \App\Services\Theme::fontsUrl($t))<link href="{{ $fu }}" rel="stylesheet">@endif
<script src="/js/clauth.js?v={{ filemtime(public_path('js/clauth.js')) }}" defer></script>
<script src="/js/pages/auth.js?v={{ filemtime(public_path('js/pages/auth.js')) }}" defer></script>
@stack('head')
</head>
<body class="auth-body" style="--page-bg:{{ $t['vars']['bg'] ?? '#F7F8FA' }}">
<div class="auth {{ $t['dark'] ? 'dark' : 'light' }}{{ $isAuto ? ' auto' : '' }}{{ $isSplit ? ' split' : '' }}{{ $hasPanel ? ' panel' : '' }}" style="{{ \App\Services\Theme::css($t) }}">
  @if($isSplit)
    <aside class="split-side" aria-hidden="true"><div><b>{{ $tApp?->name ?? ($t['brand'] ?? __('Sign in')) }}</b>@if(! empty($t['tagline']))<p>{{ $t['tagline'] }}</p>@endif</div></aside>
  @elseif($hasPanel)
    <aside class="clauth-panel" aria-label="{{ $brand['name'] }}">
      @if($branded)
        <div class="mark">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="" width="30" height="30" style="border-radius:8px;background:#fff">@else<span class="app-ico" style="background:{{ $brand['color'] ?: '#22D3EE' }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>@endif<b>{{ $brand['account'] }}</b></div>
        <div>
          <h2>{{ __('One account for all your :org apps.', ['org' => $brand['name']]) }}</h2>
          <p>{{ __('Sign in once to open your apps — your admin decides what you can use.') }}</p>
        </div>
        <small>@if($brand['secured_by']){{ __('Secured by Clauth') }} · @endif<a href="{{ $brand['privacy_url'] ?: route('privacy') }}">{{ __('Privacy') }}</a> · <a href="{{ $white && $brand['support_email'] ? 'mailto:'.$brand['support_email'] : route('help') }}">{{ __('Help') }}</a></small>
      @else
      <div class="mark"><img src="/img/brand/mark-on-dark.svg" alt="" width="30" height="30"><b>Clauth</b></div>
      <div>
        <h2>{{ __('One account for all your work apps.') }}</h2>
        <p>{{ __('Sign in once to open your apps — your admin decides what you can use.') }}</p>
      </div>
      <small>{{ __('Secured by Clauth') }} · <a href="{{ route('privacy') }}">{{ __('Privacy') }}</a> · <a href="{{ route('help') }}">{{ __('Help') }}</a></small>
      @endif
    </aside>
  @endif
  <main class="auth-main @hasSection('wide') wide @endif" id="main">
    <div class="card @yield('card-class'){{ $hasPanel ? ' flat' : '' }}">
      @unless($__env->hasSection('no-brand'))
        @if($isAppBrand && $t['logo'])
          <div class="brand-row">
            @if($isAuto && ! empty($t['logo_dark']))<picture><source media="(prefers-color-scheme: dark)" srcset="{{ $t['logo_dark'] }}"><img class="app-logo" src="{{ $t['logo'] }}" alt="{{ $tApp->name }}"></picture>
            @else<img class="app-logo" src="{{ $t['logo'] }}" alt="{{ $tApp->name }}">@endif
            @hasSection('brand-lead')<div><div class="lead">@yield('brand-lead')</div><b>{{ $tApp->name }}</b></div>@endif
          </div>
        @elseif($brandName && $branded && $brand['logo'] && ! $isAppBrand)
          <div class="brand-row"><img class="app-logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
            <div>@hasSection('brand-lead')<div class="lead">@yield('brand-lead')</div>@endif<b>{{ $brandName }}</b></div></div>
        @elseif($brandName)
          @php($color = ($isAppBrand ? $tApp->color : null) ?: ($t['vars']['pri'] ?? '#0B0D12'))
          <div class="brand-row">
            <span class="app-ico {{ $lightIco($color) ? 'light' : '' }}" style="background:{{ $color }}" aria-hidden="true">{{ $isAppBrand ? $tApp->letter() : mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>
            <div>@hasSection('brand-lead')<div class="lead">@yield('brand-lead')</div>@endif<b>{{ $brandName }}</b></div>
          </div>
        @elseif(! $hasPanel && $branded)
          <div class="brand-row">
            @if($brand['logo'])<img class="app-logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">@else<span class="app-ico {{ $lightIco($brand['color']) ? 'light' : '' }}" style="background:{{ $brand['color'] ?: ($t['vars']['pri'] ?? '#0B0D12') }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>@endif
            <div>@hasSection('brand-lead')<div class="lead">@yield('brand-lead')</div>@endif<b>{{ $brand['account'] }}</b></div>
          </div>
        @elseif(! $hasPanel)
          <div class="brand-row">@if($isAuto)<picture><source media="(prefers-color-scheme: dark)" srcset="/img/brand/logo-on-dark.svg">@endif<img class="clauth-logo" src="/img/brand/logo-on-{{ $t['dark'] ? 'dark' : 'light' }}.svg" alt="Clauth" width="84" height="28">@if($isAuto)</picture>@endif</div>
        @endif
      @endunless
      @yield('card')
    </div>
    @hasSection('below')<div class="below">@yield('below')</div>@endif
  </main>
  <footer>
    @if($white)
    @elseif($branded)
    <span class="powered">{{ __('Secured by Clauth') }}</span>
    @else
    <span class="powered">{{ __('Powered by') }} @if($isAuto)<picture><source media="(prefers-color-scheme: dark)" srcset="/img/brand/logo-on-dark.svg">@endif<img src="/img/brand/logo-on-{{ $t['dark'] ? 'dark' : 'light' }}.svg" alt="Clauth" width="48" height="16">@if($isAuto)</picture>@endif</span>
    @endif
    <nav aria-label="{{ __('Legal') }}"><a href="{{ $brand['privacy_url'] ?: route('privacy') }}">{{ __('Privacy') }}</a><a href="{{ $brand['terms_url'] ?: route('terms') }}">{{ __('Terms') }}</a><a href="{{ $white && $brand['support_email'] ? 'mailto:'.$brand['support_email'] : route('help') }}">{{ __('Help') }}</a><a href="{{ route('report') }}">{{ __('Report this page') }}</a></nav>
    <nav aria-label="{{ __('Language') }}" class="langs">@foreach(config('clauth.locales') as $code => $label)@if($code !== app()->getLocale())<a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" lang="{{ $code }}">{{ $label }}</a>@endif @endforeach</nav>
  </footer>
</div>
</body>
</html>
