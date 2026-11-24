@extends('layouts.console')
@section('title', $user->status === 'invited' ? $user->email : $user->name)
@push('head')<link rel="stylesheet" href="/css/pages/people.css?v={{ filemtime(public_path('css/pages/people.css')) }}">@endpush
@section('content')
@php($status = $user->status === 'disabled' ? ['Turned off', 'bad'] : ($user->status === 'invited' ? ['Invited', 'warn'] : ['Active', 'ok']))
@php($mfaBadge = $user->hasTwoFactor() ? ['2-step on', 'ok'] : ($user->hasSmsTwoStep() ? ['2-step by text', 'ok'] : ($user->mfa_exempt ? ['2-step skipped', 'muted'] : ($mfaRequired ? ['2-step off — required', 'warn'] : ['2-step off', 'muted']))))
<nav class="crumbs" aria-label="Breadcrumb"><a href="{{ route('admin.users.index') }}">People</a><span aria-hidden="true">/</span><span aria-current="page">{{ $user->name }}</span></nav>

<header class="pp-head">
  <span class="av lg {{ $user->status === 'disabled' ? 'muted' : ($user->status === 'invited' ? 'pending' : '') }}" aria-hidden="true">{{ $user->status === 'invited' ? '?' : $user->initials() }}</span>
  <div class="grow">
    <h1>{{ $user->name }}</h1>
    <div class="pp-meta">
      <span>{{ $user->email }}</span>
      <span class="badge {{ $status[1] }}">{{ $status[0] }}</span>
      @if($user->isLocked())<span class="badge bad">Locked until {{ $user->locked_until->format('H:i') }}</span>@endif
      @if($user->status !== 'invited')<span class="badge {{ $mfaBadge[1] }}">{{ $mfaBadge[0] }}</span>@endif
      @if($user->isOwner())<span class="badge info">Clauth owner</span>@endif
      <span>· {{ $user->last_login_at ? 'Last signed in '.$user->last_login_at->diffForHumans() : 'Never signed in' }}</span>
    </div>
  </div>
  <div class="actions">
    @if($user->status === 'invited')<form method="post" action="{{ route('admin.users.action', [$user->id, 'reinvite']) }}">@csrf<button class="btn" type="submit">Resend invite</button></form>@endif
    @if($user->isLocked() && $canTouch)<form method="post" action="{{ route('admin.users.action', [$user->id, 'unlock']) }}">@csrf<button class="btn" type="submit">Unlock</button></form>@endif
    @if($user->status === 'active')<form method="post" action="{{ route('admin.users.action', [$user->id, 'reset-password']) }}">@csrf<button class="btn" type="submit">Send password reset</button></form>@endif
    @if($canTouch)
    <details class="menu-d pp-right">
      <summary class="btn icon" aria-label="More actions">@include('partials.icon', ['i' => 'more', 'iconSize' => 18])</summary>
      <div class="pp-menu" role="menu">
        <form method="post" action="{{ route('admin.users.action', [$user->id, 'signout']) }}">@csrf<button role="menuitem" type="submit">Sign out everywhere</button></form>
        @if($user->hasSecondFactor() && ! $isSelf)
          <form method="post" action="{{ route('admin.users.action', [$user->id, 'reset-2fa']) }}" data-confirm="Reset 2-step verification for {{ $user->name }}? They set it up again at next sign-in.">@csrf<button role="menuitem" type="submit">Reset 2-step</button></form>
        @endif
        @if(! $isSelf && ! $user->isAdmin())
          @if($user->mfa_exempt)
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'mfa-on']) }}">@csrf<button role="menuitem" type="submit">Require 2-step again</button></form>
          @else
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'mfa-off']) }}" data-confirm="Turn off 2-step verification for {{ $user->name }}? Their current 2-step setup is removed, they are signed out everywhere, and from now on they sign in with just a password — even where an app or invite requires 2-step.">@csrf<button role="menuitem" type="submit">Turn off 2-step</button></form>
          @endif
        @endif
        @if(! $isSelf)
          @if($user->status === 'disabled')
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'enable']) }}">@csrf<button role="menuitem" type="submit">Turn back on</button></form>
          @else
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'disable']) }}" data-confirm="Turn off {{ $user->name }}? They are signed out of every app.">@csrf<button role="menuitem" type="submit" class="pp-danger">Turn off account</button></form>
          @endif
        @endif
      </div>
    </details>
    @endif
  </div>
</header>

@if(session('invite_link'))
  <div class="flash info" role="status"><div class="grow">New invite link (works once, for 7 days){{ session('invite_link')['sent'] ? ', also emailed' : '' }}:
    <div class="secretbox" style="margin-top:8px"><code id="invlink">{{ session('invite_link')['link'] }}</code><button class="btn sm" type="button" data-copy="#invlink">Copy</button></div></div></div>
@endif
@foreach($invites as $inv)
  @if($user->status === 'invited')
    <div class="callout">Invite to <b>{{ $inv->organization?->name ?? 'Clauth' }}</b> sent {{ $inv->created_at->diffForHumans() }} —
      {{ $inv->expires_at->isPast() ? 'it expired '.$inv->expires_at->diffForHumans().'. Resend it to get a new link.' : 'waiting to be accepted (expires '.$inv->expires_at->diffForHumans().').' }}</div>
  @endif
@endforeach

<nav class="tabs" aria-label="Person">
  @foreach($tabs as $k => $label)
    <a class="{{ $tab === $k ? 'on' : '' }}" href="{{ route('admin.users.show', $k === 'access' ? [$user->id] : [$user->id, 'tab' => $k]) }}" @if($tab === $k) aria-current="page" @endif>{{ $label }}</a>
  @endforeach
</nav>

@include('admin.users.tabs.'.$tab)
@endsection
@push('end')<script src="/js/pages/people.js?v={{ filemtime(public_path('js/pages/people.js')) }}" defer></script>@endpush
