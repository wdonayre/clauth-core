{{-- Organization header + tabs, shared by every organization page. Params: $org (Organization). --}}
@php($t = fn ($r) => request()->routeIs($r) ? 'on' : '')
@php($tab = function ($name, $label, $route, $also = []) use ($org) { if (! Route::has($route)) return ''; $on = request()->routeIs($route, ...$also); return '<a class="'.($on ? 'on' : '').'" href="'.e(route($route, $org->id)).'"'.($on ? ' aria-current="page"' : '').'>'.e($label).'</a>'; })
<div class="crumbs"><a href="{{ route('admin.orgs.index') }}">Organizations</a><span aria-hidden="true">/</span><span>{{ $org->name }}</span></div>
<div class="head">
  <div class="id">
    <span class="av muted lg" style="border-radius:12px;width:48px;height:48px;font-size:16px;overflow:hidden" aria-hidden="true">@if($orgLogo = \App\Http\Controllers\Admin\OrgController::logoUrl($org))<img src="{{ $orgLogo }}" alt="" style="width:100%;height:100%;object-fit:contain">@else{{ mb_strtoupper(mb_substr($org->name, 0, 2)) }}@endif</span>
    <div><h1>{{ $org->name }}</h1><p>{{ $org->slug }}@if($org->isSuspended()) · <span class="badge bad">Suspended</span>@endif</p></div>
  </div>
  <div class="actions">@yield('org-actions')</div>
</div>
<nav class="tabs" aria-label="Organization">
  {!! $tab('people', 'People', 'admin.orgs.show') !!}
  {!! $tab('apps', 'Apps', 'admin.orgs.apps.index') !!}
  {!! $tab('profiles', 'Job roles', 'admin.profiles.index', ['admin.profiles.show', 'admin.profiles.app', 'admin.profiles.suggest', 'admin.profiles.alignment']) !!}
  {!! $tab('teams', 'Teams & fields', 'admin.org-data.index') !!}
  {!! $tab('domain', 'Sign-in domain', 'admin.orgs.domain.show') !!}
  {!! $tab('api', 'API & sync', 'admin.api-clients.index', ['admin.agents.*']) !!}
  {!! $tab('settings', 'Settings', 'admin.orgs.settings', ['admin.orgs.policy', 'admin.themes.*', 'admin.connections.*', 'admin.messages.*']) !!}
</nav>
