{{-- Settings left nav. Params: $active ('page' on Settings itself, 'policy' on the default sign-in rules page). --}}
@php($base = ($active ?? 'page') === 'page' ? '' : route('admin.settings'))
<nav class="subnav" aria-label="Settings sections" data-subnav>
  <a href="{{ $base }}#sec" class="{{ ($active ?? 'page') === 'page' ? 'on' : '' }}">Sign-in &amp; security</a>
  <a href="{{ $base }}#methods">Sign-in methods</a>
  <a href="{{ route('admin.settings.policy') }}" class="{{ ($active ?? '') === 'policy' ? 'on' : '' }}" @if(($active ?? '') === 'policy') aria-current="page" @endif data-nospy>Default sign-in rules</a>
  <a href="{{ $base }}#ses">Sessions</a>
  <a href="{{ $base }}#keys">Signing keys</a>
  <a href="{{ $base }}#mail">Email</a>
  <a href="{{ $base }}#legal">Outside organizations</a>
  <a href="{{ $base }}#adm">Clauth admins</a>
</nav>
