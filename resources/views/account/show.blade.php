@extends('layouts.console')
@section('title', 'My account')
@section('content')
<header class="head">
  <div style="display:flex;gap:16px;align-items:center">
    <span class="avatar lg">{{ $user->initials() }}</span>
    <div><h1>{{ $user->name }}</h1><p>{{ $user->email }}</p></div>
  </div>
</header>

<section aria-labelledby="apps-h">
  <h2 id="apps-h" class="lbl" style="margin-bottom:12px">Your apps</h2>
  @if($apps->isEmpty())
    <div class="card empty">You don’t have access to any apps yet. Your admin can add you.</div>
  @else
  <div class="grid3">
    @foreach($apps as $a)
      <a class="card pad" href="{{ $a['app']->home_url ?: '#' }}" style="display:flex;flex-direction:column;gap:12px;color:var(--text)">
        <span style="display:flex;gap:12px;align-items:center">
          <span class="ico lg" style="background:{{ $a['app']->color }}">{{ $a['app']->letter() }}</span>
          <span><b style="font-size:16px">{{ $a['app']->name }}</b><br><span class="small muted">{{ $a['last'] ? 'Last used '.\Carbon\Carbon::parse($a['last'])->diffForHumans() : 'Not used yet' }}</span></span>
        </span>
        <span class="pills">@foreach($a['roles'] as $r)<span class="chip">{{ $r }}</span>@endforeach</span>
      </a>
    @endforeach
  </div>
  @endif
  @if($consents->isNotEmpty())
    <h2 class="lbl" style="margin:24px 0 12px">Apps you've allowed</h2>
    <div class="card"><div class="list">
      @foreach($consents as $c)
        <div class="row"><span class="grow"><b>{{ $c->app->name }}</b> <span class="small muted">by {{ $c->app->publisher?->name ?? '—' }} · allowed {{ $c->granted_at->diffForHumans() }}</span><br>
          <span class="small muted">Gets {{ collect($c->scopes)->reject(fn ($s) => $s === 'openid')->map(fn ($s) => ['profile' => 'your name', 'email' => 'your email', 'clauth' => 'your roles in it'][$s] ?? $s)->join(', ', ' and ') ?: 'your sign-in' }}</span></span>
          <form method="post" action="{{ route('account.consents.revoke', $c->app->key) }}" data-confirm="Remove {{ $c->app->name }}'s access? You'll be signed out of it and asked again next time.">@csrf @method('delete')<button class="btn sm danger">Remove access</button></form></div>
      @endforeach
    </div></div>
  @endif
</section>

<div class="grid2">
  <section class="card pad" aria-labelledby="sec-h">
    <h2 id="sec-h" style="margin-bottom:16px">Security</h2>
    <div class="list" style="margin:0 -20px">
      <div class="row">
        <div class="grow"><b>2-step verification</b><br><span class="small muted">
          @if($user->hasTwoFactor()) On · {{ $codesLeft }} recovery code(s) left @else Off @endif
          @if($mfaRequired) · required for your account @endif</span></div>
        @if($user->hasTwoFactor())
          <form method="post" action="{{ route('account.2fa.codes') }}">@csrf<button class="btn sm">New recovery codes</button></form>
          @unless($mfaRequired)
          <details class="modal"><summary class="btn sm danger">Turn off</summary>
            <div class="sheet"><div class="card"><h2>Turn off 2-step verification?</h2>
              <form class="form" method="post" action="{{ route('account.2fa.disable') }}" style="margin-top:16px">@csrf
                <div class="f"><label for="cp2">Your password</label><input class="in" id="cp2" type="password" name="current_password" autocomplete="current-password" required></div>
                <div class="actions"><button class="btn danger">Turn off</button></div></form></div></div></details>
          @endunless
        @else
          <form method="post" action="{{ route('account.2fa') }}">@csrf<button class="btn sm pri">Turn on</button></form>
        @endif
      </div>
      @if($google)
      <div class="row">
        <div class="grow"><b>Google</b><br><span class="small muted">
          @if($google['identity']) Linked{{ $google['identity']->email ? ' · '.$google['identity']->email : '' }} — use “Continue with Google” to sign in
          @else Not linked — link it to sign in with “Continue with Google” @endif</span></div>
        @if($google['identity'])
          <form method="post" action="{{ route('account.social.unlink', 'google') }}" data-confirm="Unlink Google? You'll sign in with your password instead.">@csrf @method('delete')<button class="btn sm danger">Unlink</button></form>
        @else
          <a class="btn sm" href="{{ \App\Services\Domains::mainUrl('account/link/google') }}">Link Google</a>
        @endif
      </div>
      @endif
      @if($newCodes)
        <div class="row"><div class="grow"><b>Your new recovery codes</b> <span class="small muted">— shown once. The old ones no longer work.</span>
          <pre class="json" id="newcodes" style="margin-top:8px">{{ implode("\n", $newCodes) }}</pre>
          <button class="btn sm" type="button" data-copy="#newcodes" style="margin-top:8px">Copy</button></div></div>
      @endif
    </div>

    <details style="margin-top:12px"><summary class="btn sm">Change password</summary>
      <form class="form" method="post" action="{{ route('account.password') }}" style="margin-top:16px">@csrf
        <div class="f"><label for="cp">Current password</label><input class="in" id="cp" type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="f"><label for="np">New password</label><input class="in" id="np" type="password" name="password" autocomplete="new-password" required>
          <span class="small muted">At least {{ \App\Services\Settings::get('password_min_length') }} characters.</span></div>
        <div class="f"><label for="np2">Confirm new password</label><input class="in" id="np2" type="password" name="password_confirmation" autocomplete="new-password" required></div>
        <div class="actions"><button class="btn pri">Save password</button></div>
      </form>
    </details>

    <form class="form" method="post" action="{{ route('account.profile') }}" style="margin-top:20px">@csrf
      <div class="f"><label for="nm">Name</label><input class="in" id="nm" name="name" value="{{ $user->name }}" autocomplete="name" required></div>
      <div class="actions"><button class="btn">Save name</button></div>
    </form>
  </section>

  <section class="card" aria-labelledby="ses-h">
    <div class="card-h"><h2 id="ses-h">Where you’re signed in</h2>
      <form method="post" action="{{ route('account.sessions.all') }}">@csrf<button class="btn sm danger">Sign out everywhere else</button></form></div>
    <div class="list">
      @foreach($web as $s)
        <div class="row"><div class="grow"><b>Clauth · {{ \Illuminate\Support\Str::limit(\App\Support\Agent::short($s->user_agent), 40) }}</b>
          <br><span class="small muted">{{ $s->ip_address }} · {{ \Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</span></div>
          @if($s->id === $currentSession)<span class="chip ok">This device</span>
          @else<form method="post" action="{{ route('account.sessions.web', $s->id) }}">@csrf @method('delete')<button class="btn sm">Sign out</button></form>@endif
        </div>
      @endforeach
      @foreach($appSessions as $s)
        <div class="row"><span class="ico" style="background:{{ $s->app?->color ?? '#334155' }}">{{ $s->app?->letter() ?? '?' }}</span>
          <div class="grow"><b>{{ $s->app?->name ?? 'App' }}</b><br><span class="small muted">{{ \App\Support\Agent::short($s->user_agent) }} · {{ $s->ip }} · {{ $s->last_used_at?->diffForHumans() }}</span></div>
          <form method="post" action="{{ route('account.sessions.app', $s->id) }}">@csrf @method('delete')<button class="btn sm">Sign out</button></form>
        </div>
      @endforeach
    </div>
  </section>
</div>
@endsection
