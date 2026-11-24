{{-- Security & sessions: how they sign in, 2-step, linked sign-ins, sessions/devices, recent sign-ins, turn off. --}}
<div class="pp-grid">
  <section class="card" aria-labelledby="hs-h">
    <div class="card-h"><h2 id="hs-h">How they sign in</h2></div>
    <div class="set">
      <div><b>Password</b><p>{{ $user->password ? ($passwordChanged ? 'Last changed '.\Carbon\Carbon::parse($passwordChanged)->diffForHumans() : 'Set') : 'Not set yet' }}</p></div>
      @if($user->status === 'active' && $canTouch)<form method="post" action="{{ route('admin.users.action', [$user->id, 'reset-password']) }}">@csrf<button class="btn" type="submit">Send reset link</button></form>@endif
    </div>
    <div class="set">
      <div><b>2-step verification
        @if($user->hasSecondFactor())<span class="badge ok">On</span>@elseif($mfaRequired)<span class="badge warn">Off — required</span>@else<span class="badge muted">Off</span>@endif</b>
        <p>@if($user->hasTwoFactor())Authenticator app{{ $recoveryLeft !== null ? ' · '.$recoveryLeft.' recovery '.\Illuminate\Support\Str::plural('code', $recoveryLeft).' left' : '' }}@if($user->hasSmsTwoStep()) · text messages too @endif
          @elseif($user->hasSmsTwoStep())Text message to {{ $user->phone }}
          @elseif($mfaRequired)They set it up at their next sign-in.
          @else Not set up.@endif</p></div>
      @if($user->hasSecondFactor() && ! $isSelf && $canTouch)
        <form method="post" action="{{ route('admin.users.action', [$user->id, 'reset-2fa']) }}" data-confirm="Reset 2-step verification for {{ $user->name }}? They set it up again at next sign-in.">@csrf<button class="btn" type="submit">Reset 2-step</button></form>
      @endif
    </div>
    @foreach($identities as $idn)
      @php($idName = \Illuminate\Support\Str::headline($idn->provider))
      @php($onlyWay = ! $user->password && $identities->count() === 1)
      <div class="set">
        <div><b>{{ $idName }}</b><p>{{ $idn->email ?? 'Linked account' }} · {{ $idn->last_used_at ? 'last used '.$idn->last_used_at->diffForHumans() : 'linked '.$idn->created_at?->diffForHumans() }}@if($onlyWay)<br>It’s the only way they sign in, so it can’t be unlinked until they have a password.@endif</p></div>
        @if($canTouch && ! $onlyWay)
          <form method="post" action="{{ route('admin.users.links.destroy', [$user->id, $idn->provider]) }}" data-confirm="Unlink {{ $idName }} from {{ $user->name }}? They can’t sign in with it until they link it again.">@csrf @method('DELETE')
            <button class="btn" type="submit" aria-label="Unlink {{ $idName }}">Unlink</button></form>
        @else
          <span class="badge muted">Linked</span>
        @endif
      </div>
    @endforeach
    <div class="set">
      <div><b>Skip 2-step for this person @if($user->mfa_exempt)<span class="badge warn">On</span>@endif</b><p>Overrides every rule that asks for it. Not allowed for Clauth admins.</p></div>
      @if(! $isSelf && ! $user->isAdmin() && $canTouch)
        @if($user->mfa_exempt)
          <form method="post" action="{{ route('admin.users.action', [$user->id, 'mfa-on']) }}">@csrf<button class="btn" type="submit">Require 2-step again</button></form>
        @else
          <form method="post" action="{{ route('admin.users.action', [$user->id, 'mfa-off']) }}" data-confirm="Turn off 2-step verification for {{ $user->name }}? Their current 2-step setup is removed, they are signed out everywhere, and from now on they sign in with just a password — even where an app or invite requires 2-step.">@csrf<button class="btn" type="submit">Turn off 2-step</button></form>
        @endif
      @else
        <span class="badge muted">{{ $user->isAdmin() ? 'Admins always use 2-step' : 'Not available' }}</span>
      @endif
    </div>
    @if($user->isLocked())
      <div class="set">
        <div><b>Locked <span class="badge bad">until {{ $user->locked_until->format('H:i') }}</span></b><p>Too many wrong passwords ({{ $user->failed_logins }}). It unlocks by itself, or now.</p></div>
        @if($canTouch)<form method="post" action="{{ route('admin.users.action', [$user->id, 'unlock']) }}">@csrf<button class="btn" type="submit">Unlock</button></form>@endif
      </div>
    @endif
  </section>

  <section class="card" aria-labelledby="ds-h">
    <div class="card-h"><h2 id="ds-h">Devices &amp; sessions</h2>
      @if($canTouch)<form method="post" action="{{ route('admin.users.action', [$user->id, 'signout']) }}" data-confirm="Sign {{ $user->name }} out of Clauth and every app?">@csrf<button class="btn danger" type="submit">Sign out everywhere</button></form>@endif</div>
    @php($any = $webSessions->isNotEmpty() || $appSessions->isNotEmpty() || $devices->isNotEmpty())
    @foreach($webSessions as $s)
      @php($agent = \App\Support\Agent::short($s->user_agent))
      <div class="set"><div><b>{{ $agent }}</b><p>Clauth · {{ $s->ip_address }} · {{ \Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</p></div>
        @if($isSelf && $s->id === session()->getId())<span class="badge info">This browser</span>
        @elseif($canTouch)
          <form method="post" action="{{ route('admin.users.sessions.revoke', [$user->id, 'web', \App\Http\Controllers\Admin\UserController::sessionHandle($s->id)]) }}">@csrf @method('DELETE')
            <button class="btn" type="submit" aria-label="Sign out of {{ $agent }} (Clauth)">Sign out</button></form>
        @else<span class="badge info">Clauth</span>@endif
      </div>
    @endforeach
    @foreach($appSessions as $s)
      @php($agent = \App\Support\Agent::short($s->user_agent))
      <div class="set"><div class="pp-sess">@if($s->app)@include('partials.ui.app-icon', ['app' => $s->app, 'size' => 'sm'])@endif
        <div><b>{{ $agent }}</b><p>{{ collect([$s->ip, $s->last_used_at?->diffForHumans(), $s->app?->name])->filter()->implode(' · ') }}</p></div></div>
        @if($canTouch)
          <form method="post" action="{{ route('admin.users.sessions.revoke', [$user->id, 'app', $s->id]) }}">@csrf @method('DELETE')
            <button class="btn" type="submit" aria-label="Sign out of {{ $s->app?->name ?? 'the app' }} on {{ $agent }}">Sign out</button></form>
        @endif
      </div>
    @endforeach
    @foreach($devices as $d)
      <div class="set"><div><b>{{ \App\Support\Agent::short($d->user_agent) }}</b><p>Remembered device · {{ collect([$d->ip, $d->last_seen_at ? 'seen '.$d->last_seen_at->diffForHumans() : null])->filter()->implode(' · ') }}</p></div>
        @if($d->trusted_until && $d->trusted_until->isFuture())<span class="badge muted">Trusted until {{ $d->trusted_until->format('M j') }}</span>@else<span class="badge muted">Not trusted</span>@endif</div>
    @endforeach
    @unless($any)<div class="empty"><span>Not signed in anywhere right now.</span></div>@endunless
  </section>

  <section class="card" aria-labelledby="rs-h">
    <div class="card-h"><h2 id="rs-h">Recent sign-ins</h2><a class="small" href="{{ route('admin.users.show', [$user->id, 'tab' => 'activity']) }}">All activity</a></div>
    @forelse($signins as $e)
      <div class="set">
        <div><b>{{ \App\Services\Audit::label($e->event) }}@if($e->app) · {{ $e->app->name }}@endif</b>
          <p>{{ collect([$e->user_agent ? \App\Support\Agent::short($e->user_agent) : null, $e->ip])->filter()->implode(' · ') ?: '—' }}</p></div>
        <span class="small muted" title="{{ $e->at->toIso8601String() }}">{{ $e->at->diffForHumans() }}</span>
      </div>
    @empty<div class="empty"><span>No sign-ins yet.</span></div>@endforelse
  </section>

  @if(! $isSelf && $canTouch)
    <section class="card pp-dangerzone" aria-labelledby="ac-h">
      <div class="card-h"><h2 id="ac-h" class="bad-t">Account</h2></div>
      <div class="set">
        @if($user->status === 'disabled')
          <div><b>Turn this account back on</b><p>They can sign in again with their existing access.</p></div>
          <form method="post" action="{{ route('admin.users.action', [$user->id, 'enable']) }}">@csrf<button class="btn" type="submit">Turn back on</button></form>
        @else
          <div><b>Turn off this account</b><p>They can’t sign in to any app. You can turn it back on later.</p></div>
          <form method="post" action="{{ route('admin.users.action', [$user->id, 'disable']) }}" data-confirm="Turn off {{ $user->name }}? They are signed out of every app.">@csrf<button class="btn danger" type="submit">Turn off</button></form>
        @endif
      </div>
      @if(auth()->user()->isOwner())
        @if(! $lastOwner)
          <div class="set">
            <div><b>Delete account</b><p>Removes them from every organization and ends every session. Activity records stay.</p></div>
            <button class="btn danger" type="button" data-open-dialog="#del-dlg">Delete</button>
          </div>
        @endif
      @else
        @foreach($orgs as $o)
          <div class="set">
            <div><b>Remove from {{ $o->name }}</b><p>They lose its job roles and app access. Only a Clauth owner can delete the account itself.</p></div>
            <form method="post" action="{{ route('admin.users.orgs.remove', $user->id) }}" data-confirm="Remove {{ $user->name }} from {{ $o->name }}? They lose its job roles and app access.">@csrf @method('DELETE')
              <input type="hidden" name="organization_id" value="{{ $o->id }}">
              <button class="btn danger" type="submit">Remove from organization</button></form>
          </div>
        @endforeach
      @endif
    </section>
  @endif
</div>

@if(! $isSelf && $canTouch && auth()->user()->isOwner() && ! $lastOwner)
<dialog class="dlg" id="del-dlg" aria-labelledby="del-h" @if($errors->has('confirm_email')) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.users.destroy', $user->id) }}" data-delete-form>@csrf @method('DELETE')
    <div class="dlg-h"><h2 id="del-h">Delete {{ $user->name }}’s account?</h2>
      <p>They’re signed out everywhere and removed from every organization, with all their app access. This can’t be undone.</p></div>
    <div class="dlg-b">
      <div class="f"><label for="del-confirm">Type <b>{{ $user->email }}</b> to confirm</label>
        <input class="in" id="del-confirm" name="confirm_email" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" required data-must-equal="{{ mb_strtolower($user->email) }}">
        @error('confirm_email')<span class="err">{{ $message }}</span>@enderror</div>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn danger" type="submit" data-delete-submit>Delete account</button></div>
  </form>
</dialog>
@endif
