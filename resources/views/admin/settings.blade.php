@extends('layouts.console')
@section('title', 'Settings')
@push('head')<link rel="stylesheet" href="/css/pages/settings.css?v={{ filemtime(public_path('css/pages/settings.css')) }}">@endpush
@push('end')<script src="/js/pages/settings.js?v={{ filemtime(public_path('js/pages/settings.js')) }}" defer></script>@endpush
@section('content')
@php($F = 'settings-form')
<header class="head"><div><h1>Settings</h1><p>Rules for everyone who signs in through Clauth.</p></div></header>

{{-- One form for every setting; the fields below join it with form="settings-form" so the sections can sit in any order. --}}
<form id="{{ $F }}" method="post" action="{{ route('admin.settings.update') }}" hidden>@csrf @method('put')</form>

<div class="withnav">
  @include('admin.settings.nav', ['active' => 'page'])

  <div class="stack">
    {{-- ---------- Sign-in & security ---------- --}}
    <section id="sec" class="card" aria-labelledby="sec-h">
      <h2 id="sec-h" class="ch">Sign-in &amp; security</h2>
      <div class="set"><div><b>Require 2-step for everyone</b><p>Admins always need it. Apps can also require it for certain roles.</p></div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="require_mfa_everyone" value="1" @checked($s['require_mfa_everyone']) aria-label="Require 2-step for everyone"><span></span></label></div>
      <div class="set"><div><b>Require 2-step for app owners and admins</b>
          <p>Anyone with an Owner- or Admin-tier role in any app.
          @unless($s['require_mfa_admin_tiers'])
            @if($tierPreview->isEmpty()) Turning this on changes nothing for anyone today.
            @else Turning this on asks {{ $tierPreview->count() }} {{ Str::plural('person', $tierPreview->count()) }} to set it up at their next sign-in:@endif
          @endunless</p>
          @if(! $s['require_mfa_admin_tiers'] && $tierPreview->isNotEmpty())
            <ul class="plain">
              @foreach($tierPreview as $u)
                <li><a href="{{ route('admin.users.show', $u->id) }}">{{ $u->name }}</a> <span class="muted">— {{ $u->grants->filter(fn ($g) => in_array($g->role?->tier, \App\Models\AppRole::MFA_TIERS, true))->map(fn ($g) => $g->app?->name.' '.$g->role->name)->unique()->implode(', ') }}</span>@if($u->hasTwoFactor()) <span class="chip">already uses 2-step</span>@endif</li>
              @endforeach
            </ul>
          @endif
        </div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="require_mfa_admin_tiers" value="1" @checked($s['require_mfa_admin_tiers']) aria-label="Require 2-step for app owners and admins"><span></span></label></div>
      <div class="set"><div><b>Block passwords found in data breaches</b><p>Checked privately against known leaks when a password is set.</p></div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="breached_password_check" value="1" @checked($s['breached_password_check']) aria-label="Block breached passwords"><span></span></label></div>
      <div class="set"><div><b>Minimum password length</b></div>
        <label class="num"><input class="in" type="number" form="{{ $F }}" name="password_min_length" value="{{ old('password_min_length', $s['password_min_length']) }}" min="8" max="64" aria-label="Minimum password length"> characters</label></div>
      <div class="set"><div><b>Lock an account after wrong passwords</b><p>Stops guessing. People can try again after the lock ends.</p></div>
        <span class="num"><input class="in" type="number" form="{{ $F }}" name="lockout_attempts" value="{{ old('lockout_attempts', $s['lockout_attempts']) }}" min="3" max="20" aria-label="Wrong passwords before the lock"> tries, for <input class="in" type="number" form="{{ $F }}" name="lockout_minutes" value="{{ old('lockout_minutes', $s['lockout_minutes']) }}" min="1" max="1440" aria-label="Lock minutes"> min</span></div>
      <div class="set"><div><b>Email people about sign-ins from a new device</b></div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="new_device_email" value="1" @checked($s['new_device_email']) aria-label="Email about new devices"><span></span></label></div>
    </section>

    {{-- ---------- Sign-in methods ---------- --}}
    <section id="methods" class="card" aria-labelledby="methods-h">
      <h2 id="methods-h" class="ch">Ways people can sign in</h2>
      <p class="ch-p">Each app can turn these off for its own sign-in page with its <a href="{{ route('admin.settings.policy') }}">sign-in rules</a>. Clauth stays invite-only either way.</p>
      <div class="set"><div class="m"><span class="mi">@include('partials.icon', ['i' => 'mail', 'iconSize' => 20])</span><div><b>Email and password</b><p>Always available.</p></div></div>
        <span class="badge ok">On</span></div>
      <div class="set"><div class="m"><span class="mi"><svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg></span>
          <div><b>Google</b>
            @if($google)<p>Connected · redirect <span class="code">{{ $googleCallback }}</span></p>
            @else<p>Not set up. Add <span class="code">GOOGLE_CLIENT_ID</span> and <span class="code">GOOGLE_CLIENT_SECRET</span> to the server’s settings to show “Continue with Google”.</p>@endif</div></div>
        <span class="badge {{ $google ? 'ok' : 'muted' }}">{{ $google ? 'On' : 'Not set up' }}</span></div>
      <div class="set"><div class="m"><span class="mi">@include('partials.icon', ['i' => 'lock', 'iconSize' => 20])</span><div><b>One-time code by email</b><p>People type a 6-digit code we email them, instead of a password.</p></div></div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="email_code_signin" value="1" @checked($s['email_code_signin']) aria-label="One-time code by email"><span></span></label></div>
      <div class="set"><div class="m"><span class="mi">@include('partials.icon', ['i' => 'phone', 'iconSize' => 20])</span><div><b>One-time code by text</b>
            <p>For people with a verified phone.
              @if($smsAvailable) Sending through {{ $sms->name }}. @else Needs an SMS gateway first. @endif
              <a href="{{ route('admin.connections.index', 'platform') }}">{{ $smsAvailable ? 'Manage SMS gateway' : 'Set up an SMS gateway' }}</a></p></div></div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="sms_signin" value="1" @checked($s['sms_signin']) aria-label="One-time code by text"><span></span></label></div>
      <div class="set"><div class="m"><span class="mi" aria-hidden="true"></span><div><b>Text messages per day</b><p>For the whole platform. Stops runaway costs.</p></div></div>
        <label class="num"><input class="in" type="number" form="{{ $F }}" name="sms_daily_cap" value="{{ old('sms_daily_cap', $s['sms_daily_cap']) }}" min="0" max="100000" aria-label="Text messages per day"> a day</label></div>
      @php($hasMeta = $providers->contains('type', 'meta'))
      @foreach($providers as $c)
        <div class="set"><div class="m"><span class="mi">@if($c->type === 'meta')<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v2.9h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/></svg>@else @include('partials.icon', ['i' => 'link', 'iconSize' => 20])@endif</span>
            <div><b>{{ $c->name }}</b><p>{{ $c->type === 'meta' ? 'Facebook' : ($c->type === 'oidc' ? 'OpenID Connect' : 'OAuth 2') }} · redirect <span class="code">{{ \App\Services\Domains::mainUrl("login/c/{$c->key}/callback") }}</span></p></div></div>
          <span class="actions"><span class="badge {{ $c->isActive() ? 'ok' : 'muted' }}">{{ $c->isActive() ? 'On' : ucfirst($c->status) }}</span><a class="btn sm" href="{{ route('admin.connections.index', 'platform') }}#c-{{ $c->id }}">Manage</a></span></div>
      @endforeach
      @unless($hasMeta)
        <div class="set"><div class="m"><span class="mi"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.9 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.7 4.5-4.7 1.3 0 2.7.2 2.7.2v2.9h-1.5c-1.5 0-2 .9-2 1.9V12h3.4l-.5 3.5h-2.9v8.4A12 12 0 0 0 24 12z"/></svg></span><div><b>Facebook</b><p>Not set up</p></div></div>
          <a class="btn" href="{{ route('admin.connections.index', 'platform') }}">Set up</a></div>
      @endunless
    </section>

    <section class="card pad cta" aria-labelledby="add-h">
      <div class="grow"><h2 id="add-h">Add another provider</h2><p class="muted small">Microsoft, Okta, Auth0 or any other OpenID Connect or OAuth provider. Organizations can add their own too.</p></div>
      <a class="btn pri" href="{{ route('admin.connections.index', 'platform') }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add provider</a>
    </section>

    <section class="card pad cta" aria-labelledby="rules-h">
      <div class="grow"><h2 id="rules-h">Default sign-in rules</h2><p class="muted small">Which of these methods apps use, 2-step, and checks with your own system — for every app without its own or its organization’s rules.</p></div>
      <a class="btn" href="{{ route('admin.settings.policy') }}">Edit rules</a>
    </section>

    {{-- ---------- Sessions ---------- --}}
    <section id="ses" class="card" aria-labelledby="ses-h">
      <h2 id="ses-h" class="ch">Sessions</h2>
      <div class="set"><div><b>Sign people out after being idle</b><p>Unless they ticked “Keep me signed in”.</p></div>
        <label class="num"><input class="in" type="number" form="{{ $F }}" name="session_idle_hours" value="{{ old('session_idle_hours', $s['session_idle_hours']) }}" min="1" max="72" aria-label="Idle hours"> hours</label></div>
      <div class="set"><div><b>Trust a device after a 2-step code for</b><p>Trusted devices skip the code. Admins always enter it. 0 turns this off.</p></div>
        <label class="num"><input class="in" type="number" form="{{ $F }}" name="trusted_device_days" value="{{ old('trusted_device_days', $s['trusted_device_days']) }}" min="0" max="90" aria-label="Trusted device days"> days</label></div>
    </section>

    <div class="savebar" data-savebar>
      <span class="muted small" data-dirty-note hidden>You have unsaved changes.</span>
      <button class="btn" type="reset" form="{{ $F }}" data-discard>Discard</button>
      <button class="btn pri" type="submit" form="{{ $F }}">Save settings</button>
    </div>

    {{-- ---------- Signing keys ---------- --}}
    <section id="keys" class="card" aria-labelledby="keys-h">
      <h2 id="keys-h" class="ch">Signing keys</h2>
      <p class="ch-p">Clauth signs every token with the active key (RS256). Apps check signatures with the public keys.</p>
      @foreach($keys->where('status', 'active') as $k)
        <div class="set"><div><b>Active key</b><p><span class="code">{{ $k->kid }}</span> · since {{ $k->activates_at?->format('M j, Y') }}</p></div><span class="badge ok">Active</span></div>
      @endforeach
      <div class="set"><div><b>Rotate</b><p>Publish a new key now; it starts signing in 7 days so apps pick it up first. Nobody is signed out.</p></div>
        <form method="post" action="{{ route('admin.settings.keys.stage') }}">@csrf<button class="btn">@include('partials.icon', ['i' => 'refresh', 'iconSize' => 16])Start rotation</button></form></div>
      @foreach($keys->where('status', '!=', 'active') as $k)
        <div class="set"><div><b>{{ ['staged' => 'Next key', 'retiring' => 'Previous key', 'retired' => 'Retired key'][$k->status] ?? ucfirst($k->status) }}</b><p><span class="code">{{ $k->kid }}</span> ·
          @if($k->status === 'staged') starts signing {{ $k->activates_at?->format('M j, Y') }}
          @elseif($k->status === 'retiring') still published until {{ $k->retires_at?->format('M j, Y') }}
          @else retired {{ $k->retires_at?->format('M j, Y') }} @endif</p></div>
          <span class="actions"><span class="badge {{ ['staged' => 'info', 'retiring' => 'warn', 'retired' => 'muted'][$k->status] ?? '' }}">{{ ucfirst($k->status) }}</span>
            @if($k->status === 'staged')<form method="post" action="{{ route('admin.settings.keys.activate', $k->id) }}" data-confirm="Start signing with this key now? Apps that cached the old JWKS refetch it on the new kid.">@csrf<button class="btn sm">Activate now</button></form>@endif</span></div>
      @endforeach
    </section>

    {{-- ---------- Email ---------- --}}
    <section id="mail" class="card" aria-labelledby="mail-h">
      <h2 id="mail-h" class="ch">Email</h2>
      <div class="set"><div><b>Sending from</b><p>{{ $mail['from'] }} · through <span class="code">{{ $mail['mailer'] }}</span></p></div>
        <form method="post" action="{{ route('admin.settings.update') }}">@csrf @method('put')<input type="hidden" name="send_test" value="1"><button class="btn">@include('partials.icon', ['i' => 'mail', 'iconSize' => 16])Send a test</button></form></div>
      <div class="set"><div><label for="se"><b>Support address</b></label><p>Shown on Help and in emails.</p></div>
        <input class="in mono w-field" id="se" type="email" form="{{ $F }}" name="support_email" value="{{ old('support_email', $s['support_email']) }}" required></div>
      <div class="set"><div><b>Emails and texts</b><p>The wording of invites, codes and alerts, per language.</p></div>
        <a class="btn" href="{{ route('admin.messages.index', 'platform') }}">Edit messages</a></div>
    </section>

    {{-- ---------- Outside organizations ---------- --}}
    <section id="legal" class="card" aria-labelledby="legal-h">
      <h2 id="legal-h" class="ch">Outside organizations</h2>
      <div class="set"><div><b>Legal documents reviewed</b><p>A lawyer has reviewed the <a href="{{ route('terms') }}" target="_blank" rel="noopener">Terms</a>, <a href="{{ route('privacy') }}" target="_blank" rel="noopener">Privacy Policy</a> and <a href="{{ route('dpa') }}" target="_blank" rel="noopener">Data Processing Agreement</a>. Turning this on lets outside organizations request to join.</p></div>
        <label class="sw"><input type="checkbox" form="{{ $F }}" name="legal_reviewed" value="1" @checked($s['legal_reviewed']) aria-label="Legal documents reviewed"><span></span></label></div>
      <div class="set"><div class="muted small">Changes here are saved with <b>Save settings</b>.</div><button class="btn pri" type="submit" form="{{ $F }}">Save settings</button></div>
    </section>

    {{-- ---------- Admins ---------- --}}
    <section id="adm" class="card" aria-labelledby="adm-h">
      <h2 id="adm-h" class="ch">Clauth admins</h2>
      <p class="ch-p">Owners manage everything here; organization owners, admins and developers manage their own organizations. Admins always use 2-step.</p>
      @foreach($admins as $a)
        <div class="set">
          @include('partials.ui.person', ['u' => $a, 'href' => route('admin.users.show', $a->id), 'sub' => $a->email.($a->id === auth()->id() ? ' · you' : '')])
          <span class="actions">
            @unless($a->hasTwoFactor())<span class="badge warn">No 2-step yet</span>@endunless
            @if($a->isOwner())<span class="badge info">Owner</span>@endif
            @foreach($a->organizations as $o)<span class="badge">{{ (\App\Models\Organization::ROLES[$o->pivot->role] ?? $o->pivot->role).' · '.$o->name }}</span>@endforeach
          </span>
        </div>
      @endforeach
      <div class="set"><div><p>Give someone an organization role (owner, admin, developer) from their page in People. Clauth owners are added on the server with <code>php artisan clauth:owner</code>.</p></div>
        <a class="btn" href="{{ route('admin.users.index') }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add an admin</a></div>
    </section>
  </div>
</div>
@endsection
