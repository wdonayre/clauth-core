@extends('layouts.console')
@section('title', $user->name)
@section('content')
<header class="head">
  <div style="display:flex;gap:16px;align-items:center">
    <span class="avatar lg">{{ $user->initials() }}</span>
    <div><h1>{{ $user->name }}</h1><p>{{ $user->email }} · {{ $user->sub() }}</p>
      <div class="pills" style="margin-top:8px">
        @if($user->isLocked())<span class="chip bad">Locked until {{ $user->locked_until->format('H:i') }}</span>@endif
        <span class="chip {{ ['active' => 'ok', 'invited' => 'info', 'disabled' => 'bad'][$user->status] ?? '' }}">{{ ucfirst($user->status) }}</span>
        <span class="chip">{{ $user->hasTwoFactor() ? '2-step on' : '2-step off' }}</span>
        @if($user->mfa_exempt)<span class="chip bad">2-step not required (admin override)</span>@endif
        @if($user->isOwner())<span class="chip info">Clauth owner</span>@endif
        <span class="chip">Last sign-in {{ $user->last_login_at?->diffForHumans() ?? 'never' }}</span>
      </div></div>
  </div>
  <div class="actions">
    @if($user->status === 'invited')<form method="post" action="{{ route('admin.users.action', [$user->id, 'reinvite']) }}">@csrf<button class="btn">Resend invite</button></form>@endif
    @if($user->status === 'active')<form method="post" action="{{ route('admin.users.action', [$user->id, 'reset-password']) }}">@csrf<button class="btn">Send password reset</button></form>@endif
    @if($user->isLocked())<form method="post" action="{{ route('admin.users.action', [$user->id, 'unlock']) }}">@csrf<button class="btn">Unlock</button></form>@endif
  </div>
</header>

@if(session('invite_link'))
  <div class="flash info">New invite link (one-time, 7 days){{ session('invite_link')['sent'] ? ', also emailed' : '' }}:
    <div class="secretbox" style="margin-top:8px"><code id="invlink">{{ session('invite_link')['link'] }}</code><button class="btn sm" type="button" data-copy="#invlink">Copy</button></div></div>
@endif

<div class="grid2">
  <section class="card" aria-labelledby="acc-h">
    <div class="card-h"><h2 id="acc-h">App access</h2>
      @if($orgs->count() > 1)
        <nav class="pills" aria-label="Organization">@foreach($orgs as $o)<a class="chip {{ $org && $o->id === $org->id ? 'info' : '' }}" href="{{ route('admin.users.show', [$user->id, 'org' => $o->id]) }}">{{ $o->name }}</a>@endforeach</nav>
      @endif
    </div>
    @if(! $org)
      <div class="empty">{{ $user->name }} isn’t in any organization yet.</div>
    @else
      <form method="post" action="{{ route('admin.users.grants', $user->id) }}">@csrf
        <input type="hidden" name="organization_id" value="{{ $org->id }}">
        <div class="list">
          <div class="row"><span class="lbl">{{ $org->name }}</span></div>
          @forelse($apps as $a)
            <div class="row" style="align-items:flex-start">
              <span class="ico" style="background:{{ $a->color }}">{{ $a->letter() }}</span>
              <div class="grow"><b>{{ $a->name }}</b>
                <div class="pills" style="margin-top:8px">
                  <label class="pill"><input type="radio" name="roles[{{ $a->id }}]" value="" @checked(! $grants->has($a->id))><span>No access</span></label>
                  @foreach($a->roles as $r)
                    <label class="pill"><input type="radio" name="roles[{{ $a->id }}]" value="{{ $r->id }}" @checked($grants->get($a->id)?->app_role_id == $r->id)><span>{{ $r->name }}</span></label>
                  @endforeach
                </div>
                @if($g = $grants->get($a->id))
                  <div class="pills" style="margin-top:8px">@foreach($a->roles->firstWhere('id', $g->app_role_id)?->permissionKeys() ?? [] as $p)<span class="chip mono">{{ $p }}</span>@endforeach</div>
                @endif
              </div>
            </div>
          @empty<div class="empty">No apps are enabled for {{ $org->name }}.</div>@endforelse
          @if($canSetRole)
            <div class="row"><label for="orole">Role in {{ $org->name }}</label>
              <select class="in" id="orole" name="org_role" style="max-width:200px">@foreach($roles as $k => $label)<option value="{{ $k }}" @selected($orgRole === $k)>{{ $label }}</option>@endforeach</select>
              <span class="small muted">Admins manage people and installed apps; developers manage {{ $org->name }}'s own apps.</span></div>
          @elseif($orgRole !== 'member')
            <div class="row small muted">{{ $roles[$orgRole] ?? $orgRole }} of {{ $org->name }}</div>
          @endif
          <div class="row"><button class="btn pri">Save access</button><span class="small muted">Applies at the app’s next token refresh (≤ 15 min).</span></div>
        </div>
      </form>
    @endif
    <div class="list" style="border-top:1px solid #1E252E">
      @if($allOrgs->isNotEmpty())
      <form class="row" method="post" action="{{ route('admin.users.orgs.add', $user->id) }}">@csrf
        <label class="sr-only" for="addorg">Add to organization</label>
        <select class="in grow" id="addorg" name="organization_id" style="max-width:260px">@foreach($allOrgs as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
        <button class="btn sm">Add to organization</button></form>
      @endif
      @if($org)
      <form class="row" method="post" action="{{ route('admin.users.orgs.remove', $user->id) }}" data-confirm="Remove {{ $user->name }} from {{ $org->name }}? Their access to its apps ends now.">@csrf @method('delete')
        <input type="hidden" name="organization_id" value="{{ $org->id }}"><button class="btn sm danger">Remove from {{ $org->name }}</button></form>
      @endif
    </div>
  </section>

  <div style="display:flex;flex-direction:column;gap:20px">
    <section class="card pad" aria-labelledby="tok-h">
      <h2 id="tok-h">Token preview{{ $previewApp ? ' · '.$previewApp->name : '' }}</h2>
      <p class="small muted" style="margin:6px 0 12px">What {{ $previewApp?->name ?? 'an app' }} receives in the access token right now.</p>
      @if($preview)<pre class="json">{{ json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>@else<p class="muted">No apps yet.</p>@endif
    </section>

    <section class="card pad" aria-labelledby="pro-h">
      <h2 id="pro-h" style="margin-bottom:12px">Profile</h2>
      <form class="form" method="post" action="{{ route('admin.users.update', $user->id) }}">@csrf @method('put')
        <div class="f"><label for="un">Name</label><input class="in" id="un" name="name" value="{{ $user->name }}" required></div>
        <div class="f"><label for="ue">Email</label><input class="in" id="ue" type="email" name="email" value="{{ $user->email }}" @readonly(! auth()->user()->isOwner()) required></div>
        <div class="f"><label for="up">Phone</label><input class="in" id="up" name="phone" value="{{ $user->phone }}"></div>
        <div class="actions"><button class="btn">Save</button></div>
      </form>
    </section>

    <section class="card pad" aria-labelledby="dz-h">
      <h2 id="dz-h" style="margin-bottom:12px">Security</h2>
      <div class="actions">
        <form method="post" action="{{ route('admin.users.action', [$user->id, 'signout']) }}">@csrf<button class="btn">Sign out everywhere</button></form>
        @if($user->hasTwoFactor() && $user->id !== auth()->id())<form method="post" action="{{ route('admin.users.action', [$user->id, 'reset-2fa']) }}" data-confirm="Reset 2-step verification for {{ $user->name }}? They set it up again at next sign-in.">@csrf<button class="btn">Reset 2-step</button></form>@endif
        @if($user->id !== auth()->id() && ! $user->isAdmin())
          @if($user->mfa_exempt)
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'mfa-on']) }}">@csrf<button class="btn">Require 2-step again</button></form>
          @else
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'mfa-off']) }}" data-confirm="Turn off 2-step verification for {{ $user->name }}? Their current 2-step setup is removed, they are signed out everywhere, and from now on they sign in with just a password — even where an app or invite requires 2-step.">@csrf<button class="btn">Turn off 2-step</button></form>
          @endif
        @endif
        @if($user->id !== auth()->id())
          @if($user->status === 'disabled')
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'enable']) }}">@csrf<button class="btn">Turn back on</button></form>
          @else
            <form method="post" action="{{ route('admin.users.action', [$user->id, 'disable']) }}" data-confirm="Disable {{ $user->name }}? They are signed out of every app.">@csrf<button class="btn danger">Disable user</button></form>
          @endif
        @endif
      </div>
    </section>

    <section class="card" aria-labelledby="ev-h">
      <div class="card-h"><h2 id="ev-h">Recent activity</h2></div>
      <div class="list">@forelse($events as $e)@include('admin.partials.event', ['e' => $e])@empty<div class="empty">Nothing yet.</div>@endforelse</div>
    </section>
  </div>
</div>
@endsection
