@extends('layouts.console')
@section('title', 'Settings')
@section('content')
<header class="head"><div><h1>Settings</h1><p>Sign-in rules for everyone, token signing keys and Clauth admins.</p></div></header>
<div class="grid2">
  <form class="card pad form" method="post" action="{{ route('admin.settings.update') }}">@csrf @method('put')
    <h2>Sign-in &amp; security</h2>
    <label class="cb"><input type="checkbox" name="require_mfa_everyone" value="1" @checked($s['require_mfa_everyone'])> Require 2-step verification for everyone</label>
    <p class="small muted" style="margin:-8px 0 0">Admins always need it. Apps can also require it per role.</p>
    <div class="f"><label for="pm">Minimum password length</label><input class="in" id="pm" type="number" name="password_min_length" value="{{ $s['password_min_length'] }}" min="8" max="64"></div>
    <label class="cb"><input type="checkbox" name="breached_password_check" value="1" @checked($s['breached_password_check'])> Block passwords found in data breaches</label>
    <div class="f"><label for="la">Lock account after this many wrong passwords</label><input class="in" id="la" type="number" name="lockout_attempts" value="{{ $s['lockout_attempts'] }}" min="3" max="20"></div>
    <div class="f"><label for="lm">Lock for (minutes)</label><input class="in" id="lm" type="number" name="lockout_minutes" value="{{ $s['lockout_minutes'] }}" min="1"></div>
    <div class="f"><label for="si">Clauth session ends after (hours idle)</label><input class="in" id="si" type="number" name="session_idle_hours" value="{{ $s['session_idle_hours'] }}" min="1" max="72"></div>
    <div class="f"><label for="td">“Trust this device” lasts (days, 0 = off)</label><input class="in" id="td" type="number" name="trusted_device_days" value="{{ $s['trusted_device_days'] }}" min="0" max="90"></div>
    <label class="cb"><input type="checkbox" name="new_device_email" value="1" @checked($s['new_device_email'])> Email people when they sign in on a new device</label>
    <label class="cb"><input type="checkbox" name="legal_reviewed" value="1" @checked($s['legal_reviewed'])> A lawyer has reviewed the <a href="{{ route('terms') }}" target="_blank">Terms</a>, <a href="{{ route('privacy') }}" target="_blank">Privacy Policy</a> and <a href="{{ route('dpa') }}" target="_blank">Data Processing Agreement</a>. Outside organizations can request to join.</label>
    <div class="f"><label for="se">Support email (shown on Help)</label><input class="in" id="se" type="email" name="support_email" value="{{ $s['support_email'] }}"></div>
    <div class="actions"><button class="btn pri">Save settings</button></div>
  </form>
  <div style="display:flex;flex-direction:column;gap:20px">
    <section class="card">
      <div class="card-h"><div><h2>Signing keys</h2><p class="small muted" style="margin:4px 0 0">RS256. A new key is published first, then signs tokens after 7 days.</p></div>
        <form method="post" action="{{ route('admin.settings.keys.stage') }}">@csrf<button class="btn sm">Rotate key</button></form></div>
      <div class="list">
        @foreach($keys as $k)
          <div class="row"><div class="grow"><code>{{ $k->kid }}</code><br><span class="small muted">
            @if($k->status === 'staged') Starts signing {{ $k->activates_at?->format('j M Y') }}
            @elseif($k->status === 'retiring') Still published until {{ $k->retires_at?->format('j M Y') }}
            @elseif($k->status === 'active') Signing since {{ $k->activates_at?->format('j M Y') }}
            @else Retired {{ $k->retires_at?->format('j M Y') }} @endif</span></div>
            <span class="chip {{ ['active' => 'ok', 'staged' => 'info', 'retiring' => 'warn', 'retired' => ''][$k->status] }}">{{ ucfirst($k->status) }}</span>
            @if($k->status === 'staged')<form method="post" action="{{ route('admin.settings.keys.activate', $k->id) }}" data-confirm="Start signing with this key now? Apps that cached the old JWKS refetch it on the new kid.">@csrf<button class="btn sm">Activate now</button></form>@endif
          </div>
        @endforeach
      </div>
    </section>
    <section class="card">
      <div class="card-h"><h2>Clauth admins</h2></div>
      <div class="list">
        @foreach($admins as $a)
          <a class="row" href="{{ route('admin.users.show', $a->id) }}" style="color:var(--text)"><span class="avatar">{{ $a->initials() }}</span>
            <span class="grow"><b>{{ $a->name }}</b><br><span class="small muted">{{ $a->email }}</span></span>
            <span class="chip {{ $a->isOwner() ? 'info' : '' }}">{{ $a->isOwner() ? 'Owner' : $a->organizations->map(fn ($o) => (\App\Models\Organization::ROLES[$o->pivot->role] ?? $o->pivot->role).' · '.$o->name)->implode(', ') }}</span></a>
        @endforeach
      </div>
      <p class="small muted" style="padding:0 20px 16px;margin:0">Set org roles (owner, admin, developer) from a user’s page or Organizations. Owners are added on the server with <code>php artisan clauth:owner</code>.</p>
    </section>
    <section class="card pad"><h2>Email</h2><p class="small muted" style="margin:6px 0 0">Sending with <code>{{ $mail['mailer'] }}</code> from <code>{{ $mail['from'] }}</code>.</p></section>
  </div>
</div>
@endsection
