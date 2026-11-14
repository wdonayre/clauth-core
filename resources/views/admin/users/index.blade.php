@extends('layouts.console')
@section('title', 'Users')
@section('content')
<header class="head">
  <div><h1>Users</h1><p>People who can sign in, and which apps they use.</p></div>
  <details class="modal" @if(request('invite') || $errors->hasAny(['name','email','organization_id'])) open @endif>
    <summary class="btn pri">@include('partials.icon', ['i' => 'plus'])Invite user</summary>
    <div class="sheet"><div class="card">
      <div class="row-between" style="display:flex;justify-content:space-between;align-items:center"><h2>Invite user</h2><a href="{{ route('admin.users.index') }}" class="btn sm">Close</a></div>
      <form class="form" method="post" action="{{ route('admin.users.invite') }}" style="margin-top:16px">@csrf
        <div class="f"><label for="iname">Full name</label><input class="in" id="iname" name="name" value="{{ old('name') }}" required></div>
        <div class="f"><label for="iemail">Email</label><input class="in" id="iemail" type="email" name="email" value="{{ old('email') }}" required></div>
        <div class="f"><label for="iorg">Organization</label>
          <select class="in" id="iorg" name="organization_id" required data-org-filter>
            @foreach($orgs as $o)<option value="{{ $o->id }}" @selected(old('organization_id') === $o->id)>{{ $o->name }}</option>@endforeach
          </select></div>
        <div class="f"><span>Apps and role</span>
          @forelse($apps as $a)
            <div class="card" style="padding:12px;background:#0D1014" data-app-orgs="{{ $a->organizations->pluck('id')->implode(' ') }}">
              <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px"><span class="ico" style="background:{{ $a->color }};width:28px;height:28px;font-size:12px">{{ $a->letter() }}</span><b>{{ $a->name }}</b></div>
              <div class="pills">
                <label class="pill"><input type="radio" name="roles[{{ $a->id }}]" value="" checked><span>No access</span></label>
                @foreach($a->roles as $r)<label class="pill"><input type="radio" name="roles[{{ $a->id }}]" value="{{ $r->id }}"><span>{{ $r->name }}</span></label>@endforeach
              </div>
            </div>
          @empty<span class="small muted">No apps registered yet.</span>@endforelse
          <span class="small muted">Only apps enabled for the chosen organization can be granted.</span>
        </div>
        <label class="cb"><input type="checkbox" name="require_mfa" value="1"> Require 2-step verification (otherwise they can skip it when setting up)</label>
        @if(auth()->user()->isOwner() || auth()->user()->ownedOrgIds() !== [])<label class="cb"><input type="checkbox" name="is_org_admin" value="1"> Make them an admin of this organization</label>@endif
        <label class="cb"><input type="checkbox" name="send" value="1" checked> Email the invite now</label>
        <div class="actions"><button class="btn pri">Send invite</button></div>
      </form>
    </div></div>
  </details>
</header>

@if($invited)
  <div class="flash info">Invite for <b>{{ $invited['email'] }}</b> {{ $invited['sent'] ? 'was emailed' : 'was created (email not sent)' }}. One-time link, valid 7 days:
    <div class="secretbox" style="margin-top:8px"><code id="invlink">{{ $invited['link'] }}</code><button class="btn sm" type="button" data-copy="#invlink">Copy invite link</button></div></div>
@endif

<nav class="tabs" aria-label="Status">
  @foreach(['all' => 'All', 'active' => 'Active', 'invited' => 'Invited', 'locked' => 'Locked', 'disabled' => 'Disabled'] as $k => $l)
    <a class="{{ $status === $k ? 'on' : '' }}" href="{{ route('admin.users.index', array_merge(request()->except('page', 'status'), ['status' => $k])) }}">{{ $l }} <span class="n">{{ $counts[$k] }}</span></a>
  @endforeach
</nav>
<form class="actions" method="get">
  <input type="hidden" name="status" value="{{ $status }}">
  <label class="sr-only" for="q">Search</label><input class="in" id="q" name="q" value="{{ request('q') }}" placeholder="Search name or email" style="max-width:280px">
  <label class="sr-only" for="fapp">App</label><select class="in" id="fapp" name="app" style="max-width:180px"><option value="">All apps</option>@foreach($apps as $a)<option value="{{ $a->key }}" @selected(request('app') === $a->key)>{{ $a->name }}</option>@endforeach</select>
  <label class="sr-only" for="forg">Organization</label><select class="in" id="forg" name="org" style="max-width:200px"><option value="">All organizations</option>@foreach($orgs as $o)<option value="{{ $o->slug }}" @selected(request('org') === $o->slug)>{{ $o->name }}</option>@endforeach</select>
  <button class="btn">Filter</button>
</form>
<section class="card">
  <div class="list">
    @forelse($users as $u)
      <a class="row" href="{{ route('admin.users.show', $u->id) }}" style="color:#E8EDF2">
        <span class="avatar">{{ $u->initials() }}</span>
        <span class="grow"><b>{{ $u->name }}</b><br><span class="small muted">{{ $u->email }} · {{ $u->organizations->pluck('name')->implode(', ') ?: 'No organization' }}</span></span>
        <span class="pills">@foreach($u->grants->unique('app_id')->take(4) as $g)<span class="chip">{{ $g->app?->name }} · {{ $g->role?->name }}</span>@endforeach</span>
        @if($u->isLocked())<span class="chip bad">Locked</span>
        @else<span class="chip {{ ['active' => 'ok', 'invited' => 'info', 'disabled' => 'bad'][$u->status] ?? '' }}">{{ ucfirst($u->status) }}</span>@endif
        @if($u->hasTwoFactor())<span class="chip" title="2-step verification on">2FA</span>@endif
      </a>
    @empty<div class="empty">No one here yet.</div>@endforelse
  </div>
  @if($users->hasPages())<div class="pager">{!! $users->links('pagination::simple-default') !!}</div>@endif
</section>
@endsection
