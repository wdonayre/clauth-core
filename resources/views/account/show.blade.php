@extends('layouts.console')
@section('title', 'My account')
@push('head')<link rel="stylesheet" href="/css/pages/settings.css?v={{ filemtime(public_path('css/pages/settings.css')) }}">@endpush
@push('end')<script src="/js/pages/settings.js?v={{ filemtime(public_path('js/pages/settings.js')) }}" defer></script>@endpush
@section('content')
@php($dlg = old('_dlg'))
@php($first = \Illuminate\Support\Str::of($user->name)->trim()->explode(' ')->first() ?: $user->name)
<div class="acct">
<header class="head">
  <div><h1>Hi, {{ $first }}</h1><p>Open an app, or manage how you sign in.</p></div>
  <div class="actions"><a class="btn" href="{{ route('account.tokens') }}">@include('partials.icon', ['i' => 'key', 'iconSize' => 16])API tokens</a></div>
</header>

{{-- ---------- Apps ---------- --}}
<section aria-labelledby="apps-h">
  <h2 id="apps-h" class="sec">Your apps</h2>
  @if($apps->isEmpty())
    <div class="card empty"><b>No apps yet</b><span>You don’t have access to any apps yet. Your admin can add you.</span></div>
  @else
    <div class="tiles">
      @foreach($apps as $a)
        @php($href = $a['app']->home_url)
        <{{ $href ? 'a' : 'div' }} class="card tile {{ $href ? '' : 'off' }}" @if($href) href="{{ $href }}" @endif>
          @include('partials.ui.app-icon', ['app' => $a['app']])
          <span><b>{{ $a['app']->name }}</b>
            <span class="sub" style="display:block">{{ implode(', ', $a['roles']) }}</span>
            <span class="sub" style="display:block">{{ $a['last'] ? 'Last used '.\Carbon\Carbon::parse($a['last'])->diffForHumans() : 'Not used yet' }}</span></span>
        </{{ $href ? 'a' : 'div' }}>
      @endforeach
    </div>
  @endif
</section>

@if($consents->isNotEmpty())
<section class="card" aria-labelledby="cons-h">
  <h2 id="cons-h" class="ch">Apps you've allowed</h2>
  <p class="ch-p">Apps from outside your organizations that you let see your details.</p>
  @foreach($consents as $c)
    <div class="set"><div class="m">@include('partials.ui.app-icon', ['app' => $c->app])
        <div><b>{{ $c->app->name }}</b><p>By {{ $c->app->publisher?->name ?? '—' }} · allowed {{ $c->granted_at->diffForHumans() }} · gets {{ collect($c->scopes)->reject(fn ($s) => $s === 'openid')->map(fn ($s) => ['profile' => 'your name', 'email' => 'your email', 'clauth' => 'your roles in it'][$s] ?? $s)->join(', ', ' and ') ?: 'your sign-in' }}</p></div></div>
      <form method="post" action="{{ route('account.consents.revoke', $c->app->key) }}" data-confirm="Remove {{ $c->app->name }}'s access? You'll be signed out of it and asked again next time.">@csrf @method('delete')<button class="btn sm danger">Remove access</button></form></div>
  @endforeach
</section>
@endif

{{-- ---------- How you sign in ---------- --}}
<section class="card" aria-labelledby="sec-h">
  <h2 id="sec-h" class="ch">How you sign in</h2>

  <div class="set"><div><b>Password</b><p>At least {{ \App\Services\Settings::get('password_min_length') }} characters. Changing it signs out your other devices.</p></div>
    <button class="btn" type="button" data-open-dialog="#dlg-pw">Change</button></div>

  <div class="set"><div><b>2-step verification</b>
      @if($user->hasTwoFactor() || $user->hasSmsTwoStep())<span class="badge ok">On</span>@else<span class="badge {{ $mfaRequired ? 'warn' : 'muted' }}">Off</span>@endif
      <p>@if($user->hasTwoFactor()) Authenticator app · {{ $codesLeft }} recovery {{ \Illuminate\Support\Str::plural('code', $codesLeft) }} left
         @elseif($user->hasSmsTwoStep()) By text message to {{ \App\Services\Sms::mask($user->phone) }}
         @else A code from your phone as well as your password. @endif
         @if($mfaRequired) · required for your account @endif</p></div>
    @if($user->hasTwoFactor())
      <button class="btn" type="button" data-open-dialog="#dlg-2fa">Manage</button>
    @else
      <form method="post" action="{{ route('account.2fa') }}">@csrf<button class="btn pri">{{ $user->hasSmsTwoStep() ? 'Add an authenticator app' : 'Turn on' }}</button></form>
    @endif
  </div>

  @if($newCodes)
    <div class="set"><div style="flex-basis:100%"><b>Your new recovery codes</b><p>Shown once. Save them somewhere safe — the old ones no longer work.</p>
      <ul class="codes" id="newcodes" style="margin-top:10px">@foreach($newCodes as $code)<li>{{ $code }}</li>@endforeach</ul>
      <button class="btn sm" type="button" data-copy="#newcodes" style="margin-top:10px">@include('partials.icon', ['i' => 'copy', 'iconSize' => 14])Copy</button></div></div>
  @endif

  @if(\App\Services\Sms::available())
    <div class="set"><div><b>Text message</b>
        @if($user->phone_verified_at)<span class="badge ok">Verified</span>@endif
        <p>@if($user->phone_verified_at) {{ \App\Services\Sms::mask($user->phone) }}@if($user->hasSmsTwoStep()) · used for 2-step @endif — for sign-in and 2-step codes
           @else Add a mobile number to get sign-in and 2-step codes by text. @endif</p>
        @if(session('account.phone'))
          <form class="inline-form" method="post" action="{{ route('account.phone.verify') }}">@csrf
            <label class="sr-only" for="pcode">Code we texted you</label>
            <input class="in" id="pcode" name="code" inputmode="numeric" maxlength="7" placeholder="6-digit code" autocomplete="one-time-code" required><button class="btn pri">Verify</button></form>
          @error('phone_code')<div class="err small bad-t">{{ $message }}</div>@enderror
        @else
          <form class="inline-form" method="post" action="{{ route('account.phone') }}">@csrf
            <label class="sr-only" for="pnum">Mobile number</label>
            <input class="in" id="pnum" name="phone" type="tel" placeholder="0917 123 4567" autocomplete="tel" required><button class="btn">{{ $user->phone_verified_at ? 'Change number' : 'Send code' }}</button></form>
          @error('phone')<div class="err small bad-t">{{ $message }}</div>@enderror
        @endif
      </div>
      @if($user->phone_verified_at && ! $user->isAdmin())
        <form method="post" action="{{ route('account.sms-two-step') }}">@csrf<input type="hidden" name="on" value="{{ $user->sms_two_step ? 0 : 1 }}"><button class="btn">{{ $user->sms_two_step ? 'Stop using for 2-step' : 'Use for 2-step' }}</button></form>
      @endif
    </div>
  @endif

  @if($google)
    <div class="set"><div class="m"><span class="mi"><svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg></span>
        <div><b>Google</b><p>@if($google['identity']) Linked{{ $google['identity']->email ? ' to '.$google['identity']->email : '' }} — use “Continue with Google” to sign in @else Not linked — link it to sign in with “Continue with Google” @endif</p></div></div>
      @if($google['identity'])
        <form method="post" action="{{ route('account.social.unlink', 'google') }}" data-confirm="Unlink Google? You'll sign in with your password instead.">@csrf @method('delete')<button class="btn">Unlink</button></form>
      @else
        <a class="btn" href="{{ \App\Services\Domains::mainUrl('account/link/google') }}">Link Google</a>
      @endif
    </div>
  @endif
  @foreach($linkable as $l)
    <div class="set"><div class="m"><span class="mi">@include('partials.icon', ['i' => 'link', 'iconSize' => 20])</span>
        <div><b>{{ $l['c']->name }}</b><p>@if($l['identity']) Linked{{ $l['identity']->email ? ' to '.$l['identity']->email : '' }} — use “Continue with {{ $l['c']->name }}” to sign in @else Not linked @endif</p></div></div>
      @if($l['identity'])
        <form method="post" action="{{ route('account.connection.unlink', $l['c']->key) }}" data-confirm="Unlink {{ $l['c']->name }}?">@csrf @method('delete')<button class="btn">Unlink</button></form>
      @else
        <a class="btn" href="{{ \App\Services\Domains::mainUrl('account/link/c/'.$l['c']->key) }}">Link {{ $l['c']->name }}</a>
      @endif
    </div>
  @endforeach
</section>

{{-- ---------- Sessions ---------- --}}
<section class="card" aria-labelledby="ses-h">
  <div class="card-h"><h2 id="ses-h">Where you're signed in</h2>
    <form method="post" action="{{ route('account.sessions.all') }}" data-confirm="Sign out of every other browser and app?">@csrf<button class="btn danger">Sign out everywhere else</button></form></div>
  @foreach($web as $s)
    <div class="set"><div class="m"><span class="mi">@include('partials.icon', ['i' => 'monitor', 'iconSize' => 20])</span>
        <div><b>{{ \Illuminate\Support\Str::limit(\App\Support\Agent::short($s->user_agent), 48) }}</b> @if($s->id === $currentSession)<span class="badge muted">This device</span>@endif
          <p>Clauth · {{ $s->ip_address }} · {{ $s->id === $currentSession ? 'active now' : \Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</p></div></div>
      @unless($s->id === $currentSession)<form method="post" action="{{ route('account.sessions.web', $s->id) }}">@csrf @method('delete')<button class="btn">Sign out</button></form>@endunless
    </div>
  @endforeach
  @foreach($appSessions as $s)
    <div class="set"><div class="m">@if($s->app)@include('partials.ui.app-icon', ['app' => $s->app])@else<span class="ico" style="background:#334155">?</span>@endif
        <div><b>{{ $s->app?->name ?? 'App' }}</b><p>{{ \App\Support\Agent::short($s->user_agent) }} · {{ $s->ip }} · {{ $s->last_used_at?->diffForHumans() }}</p></div></div>
      <form method="post" action="{{ route('account.sessions.app', $s->id) }}">@csrf @method('delete')<button class="btn">Sign out</button></form>
    </div>
  @endforeach
</section>

{{-- ---------- Appearance (console.js saves the choice: same key as the sidebar switch) ---------- --}}
<section class="card pad" aria-labelledby="look-h">
  <h2 id="look-h">Appearance</h2>
  <p class="muted small" style="margin:2px 0 14px">Applies to Clauth in this browser.</p>
  <div class="themepick" role="radiogroup" aria-labelledby="look-h" data-theme-switch>
    <button type="button" role="radio" aria-checked="false" data-mode="light"><span class="pv l" aria-hidden="true"><span class="sd"></span><span class="mn"><i></i><i></i></span></span>Light</button>
    <button type="button" role="radio" aria-checked="false" data-mode="dark"><span class="pv d" aria-hidden="true"><span class="sd"></span><span class="mn"><i></i><i></i></span></span>Dark</button>
    <button type="button" role="radio" aria-checked="true" data-mode="auto"><span class="pv a" aria-hidden="true"><span class="mn"><i></i><i></i></span><span class="mn"><i></i><i></i></span></span>Match my device</button>
  </div>
</section>

{{-- ---------- Profile ---------- --}}
<section class="card" aria-labelledby="prof-h">
  <h2 id="prof-h" class="ch">Profile</h2>
  <div class="set"><div><b>Name</b><p>{{ $user->name }}</p></div><button class="btn" type="button" data-open-dialog="#dlg-name">Edit</button></div>
  <div class="set"><div><b>Email</b><p>{{ $user->email }} · ask your admin to change it</p></div></div>
</section>

<section class="card" aria-labelledby="dev-h">
  <h2 id="dev-h" class="ch">For developers</h2>
  <div class="set"><div><b>Personal API tokens</b><p>For scripts and tools that act as you. A token can only do what you can do.</p></div>
    <a class="btn" href="{{ route('account.tokens') }}">Manage tokens</a></div>
</section>
</div>

{{-- ---------- Dialogs ---------- --}}
<dialog class="dlg" id="dlg-pw" aria-labelledby="dlg-pw-h" @if($dlg === 'pw' && $errors->any()) data-open-on-load @endif>
  <form method="post" action="{{ route('account.password') }}">@csrf<input type="hidden" name="_dlg" value="pw">
    <div class="dlg-h"><h2 id="dlg-pw-h">Change password</h2><p>Your other devices will be signed out.</p></div>
    <div class="dlg-b">
      <div class="f"><label for="cp">Current password</label><input class="in" id="cp" type="password" name="current_password" autocomplete="current-password" required>@if($dlg === 'pw')@error('current_password')<span class="err">{{ $message }}</span>@enderror @endif</div>
      <div class="f"><label for="np">New password</label><input class="in" id="np" type="password" name="password" autocomplete="new-password" required data-meter="#np-meter">
        <div class="progress" aria-hidden="true"><span id="np-meter" style="width:0"></span></div>
        <span class="hint">At least {{ \App\Services\Settings::get('password_min_length') }} characters.</span>@error('password')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="np2">Confirm new password</label><input class="in" id="np2" type="password" name="password_confirmation" autocomplete="new-password" required></div>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Save password</button></div>
  </form>
</dialog>

@if($user->hasTwoFactor())
<dialog class="dlg" id="dlg-2fa" aria-labelledby="dlg-2fa-h" @if($dlg === '2fa' && $errors->any()) data-open-on-load @endif>
  <div class="dlg-h"><h2 id="dlg-2fa-h">2-step verification</h2><p>On with an authenticator app · {{ $codesLeft }} recovery {{ \Illuminate\Support\Str::plural('code', $codesLeft) }} left.</p></div>
  <div class="dlg-b">
    <div class="set" style="padding:0;border:0"><div><b>Recovery codes</b><p>Make a new set if you used or lost them. The old ones stop working.</p></div>
      <form method="post" action="{{ route('account.2fa.codes') }}">@csrf<button class="btn">New recovery codes</button></form></div>
    @unless($mfaRequired)
      <hr class="sep">
      <form class="form" method="post" action="{{ route('account.2fa.disable') }}">@csrf<input type="hidden" name="_dlg" value="2fa">
        <div><b>Turn off 2-step verification</b><p class="muted small" style="margin:2px 0 0">You’ll sign in with just your password.</p></div>
        <div class="f"><label for="cp2">Your password</label><input class="in" id="cp2" type="password" name="current_password" autocomplete="current-password" required>@if($dlg === '2fa')@error('current_password')<span class="err">{{ $message }}</span>@enderror @endif</div>
        <div class="actions"><button class="btn danger">Turn off</button></div>
      </form>
    @else
      <p class="callout">2-step verification is required for your account, so it can’t be turned off.</p>
    @endunless
  </div>
  <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Close</button></div>
</dialog>
@endif

<dialog class="dlg" id="dlg-name" aria-labelledby="dlg-name-h" @if($dlg === 'name' && $errors->any()) data-open-on-load @endif>
  <form method="post" action="{{ route('account.profile') }}">@csrf<input type="hidden" name="_dlg" value="name">
    <div class="dlg-h"><h2 id="dlg-name-h">Edit name</h2><p>Apps you use see this name.</p></div>
    <div class="dlg-b"><div class="f"><label for="nm">Name</label><input class="in" id="nm" name="name" value="{{ old('name', $user->name) }}" autocomplete="name" required maxlength="120">@error('name')<span class="err">{{ $message }}</span>@enderror</div></div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Save name</button></div>
  </form>
</dialog>
@endsection
