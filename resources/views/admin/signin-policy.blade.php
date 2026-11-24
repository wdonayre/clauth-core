@extends('layouts.console')
@section('title', 'Sign-in rules · '.($app?->name ?? $org?->name ?? 'Platform'))
@push('head')<link rel="stylesheet" href="/css/pages/settings.css?v={{ filemtime(public_path('css/pages/settings.css')) }}">@endpush
@push('end')<script src="/js/pages/settings.js?v={{ filemtime(public_path('js/pages/settings.js')) }}" defer></script>@endpush
@php($src = ['app' => 'this app’s own rules', 'org' => ($app?->publisher?->name ?? 'the organization').'’s default rules', 'platform' => 'the platform’s rules', 'default' => 'Clauth’s built-in defaults'])
@php($methodHints = ['password' => 'People type their email and password.', 'google' => 'The “Continue with Google” button.', 'email_code' => 'A 6-digit code we email them, instead of a password.', 'sms_code' => 'A 6-digit code by text, for people with a verified phone.'])
@php($offWhy = ['google' => 'Not set up on this Clauth, so it isn’t shown either way.', 'email_code' => 'Off in Settings, so it isn’t shown either way.', 'sms_code' => 'Off in Settings or no SMS gateway, so it isn’t shown either way.'])
@section('org-actions')
  @if($own)<form method="post" action="{{ url()->current() }}/reset" data-confirm="Remove these rules so the platform’s apply?">@csrf<button class="btn">Use the default instead</button></form>@endif
@endsection
@section('content')

{{-- ---------- Header: org tabs, app breadcrumb, or Settings ---------- --}}
@if($org)
  @include('partials.ui.org-tabs', ['org' => $org])
@elseif($app)
  <div class="crumbs"><a href="{{ route('admin.apps.index') }}">Apps</a><span aria-hidden="true">/</span><a href="{{ route('admin.apps.show', $app->key) }}">{{ $app->name }}</a><span aria-hidden="true">/</span><span>Sign-in rules</span></div>
  <header class="head">
    <div class="id">@include('partials.ui.app-icon', ['app' => $app, 'size' => 'lg'])<div><h1>Sign-in rules</h1><p>How people sign in to {{ $app->name }}.</p></div></div>
    <div class="actions">
      <a class="btn" href="{{ route('admin.apps.show', $app->key) }}">@include('partials.icon', ['i' => 'left', 'iconSize' => 16])Back to {{ $app->name }}</a>
      @if($own)<form method="post" action="{{ url()->current() }}/reset" data-confirm="Remove these rules so the organization’s or platform’s apply?">@csrf<button class="btn">Use the default instead</button></form>@endif
    </div>
  </header>
@else
  <header class="head"><div><h1>Settings</h1><p>Rules for everyone who signs in through Clauth.</p></div>
    <div class="actions">@if($own)<form method="post" action="{{ url()->current() }}/reset" data-confirm="Remove the platform’s rules so Clauth’s built-in defaults apply?">@csrf<button class="btn">Use the default instead</button></form>@endif</div></header>
@endif

<div class="{{ ! $app && ! $org ? 'withnav' : '' }}">
  @if(! $app && ! $org)@include('admin.settings.nav', ['active' => 'policy'])@endif

  <div class="split">
    <form class="stack" method="post" action="{{ url()->current() }}">@csrf @method('put')
      <div class="callout">
        @if($app)<b>Now using:</b> {{ $src[$source] }}.
        @elseif($org) Default for every app {{ $org->name }} publishes, unless an app has its own.
        @else Default for every app without its own or its organization’s rules. @endif
        Settings still switch features on for everyone; rules here can only narrow them.
      </div>

      <section class="card" aria-labelledby="ways-h">
        <h2 id="ways-h" class="ch">Ways to sign in</h2>
        @foreach(\App\Services\SigninPolicies::METHODS as $k => $label)
          <div class="set"><div><b>{{ $label }}</b><p>{{ $methodHints[$k] ?? '' }}@if(! ($available[$k] ?? true)) <span class="warn-t">{{ $offWhy[$k] ?? '' }}</span>@endif</p></div>
            <label class="sw"><input type="checkbox" name="{{ $k }}" value="1" @checked($rules[$k]) aria-label="{{ $label }}"><span></span></label></div>
        @endforeach
        @if($connections->isNotEmpty())
          <div class="set"><div><b>Sign-in providers</b><p>Buttons for the providers set up under Sign-in &amp; senders.</p></div>
            <div class="seg" role="radiogroup" aria-label="Sign-in providers">
              <label><input type="radio" name="connections_mode" value="all" @checked($rules['connections'] === 'all')>All that are set up</label>
              <label><input type="radio" name="connections_mode" value="some" @checked($rules['connections'] !== 'all')>Only these</label>
            </div></div>
          <div class="indent" data-provider-pills>
            <div class="pills" role="group" aria-label="Providers to offer">@foreach($connections as $c)<label class="pill"><input type="checkbox" name="connections[]" value="{{ $c->key }}" @checked(is_array($rules['connections']) && in_array($c->key, $rules['connections'], true))><span>{{ $c->name }}</span></label>@endforeach</div>
          </div>
        @else<input type="hidden" name="connections_mode" value="all">@endif
      </section>

      <section class="card" aria-labelledby="two-h">
        <h2 id="two-h" class="ch">2-step verification</h2>
        <div class="set"><div><label for="two_step"><b>When to ask</b></label><p>Admins always use 2-step.</p></div>
          <select class="in sel" id="two_step" name="two_step"><option value="default" @selected($rules['two_step'] === 'default')>Follow Settings and the app’s 2-step roles</option><option value="always" @selected($rules['two_step'] === 'always')>Always ask for 2-step</option></select></div>
        <div class="set"><div><b>Text-message codes can be the second step</b><p>For people who aren’t admins and have a verified phone.</p></div>
          <label class="sw"><input type="checkbox" name="sms_second_step" value="1" @checked($rules['sms_second_step']) aria-label="Text-message codes can be the second step"><span></span></label></div>
      </section>

      @if($app)
        <section class="card" aria-labelledby="new-h">
          <h2 id="new-h" class="ch">New people</h2>
          <div class="set"><div><label for="registration"><b>Who can get an account</b></label></div>
            <select class="in sel" id="registration" name="registration"><option value="invite" @selected($rules['registration'] === 'invite')>Invite only</option><option value="open" @selected($rules['registration'] === 'open')>Anyone can create an account (joins {{ $app->publisher?->name ?? 'the organization' }})</option></select></div>
          <div class="set"><div><label for="open_domains"><b>Only these email domains</b></label><p>One per line. Empty means any.</p></div>
            <textarea class="in mono sel" id="open_domains" name="open_domains" rows="2">{{ implode("\n", $rules['open_domains']) }}</textarea></div>
          <div class="set"><div><label for="open_profile"><b>Access profile new people get</b></label></div>
            <select class="in sel" id="open_profile" name="open_profile"><option value="">None (an admin gives access)</option>@foreach($profiles as $p)<option value="{{ $p->key }}" @selected($rules['open_profile'] === $p->key)>{{ $p->name }}</option>@endforeach</select></div>
        </section>
      @else<input type="hidden" name="registration" value="invite">@endif

      <section class="card" aria-labelledby="chk-h">
        <h2 id="chk-h" class="ch">Ask your own system</h2>
        @if($checks->isNotEmpty())
          <div class="set"><div><label for="check"><b>Before each sign-in, ask</b></label><p>Clauth posts who is signing in to that URL; it answers <code>{"allow": true}</code> or refuses.</p></div>
            <select class="in sel" id="check" name="check"><option value="">Don’t ask</option>@foreach($checks as $c)<option value="{{ $c->key }}" @selected($rules['check'] === $c->key)>{{ $c->name }}</option>@endforeach</select></div>
          <div class="set"><div><b>If it can’t be reached, let people in anyway</b><p>Off means sign-in is refused until it answers.</p></div>
            <label class="sw"><input type="checkbox" name="check_fail_open" value="1" @checked($rules['check_fail_open']) aria-label="Let people in if it can’t be reached"><span></span></label></div>
        @else
          <div class="set"><div><p>Add a “Your own system” connection under Sign-in &amp; senders to use this.</p></div></div>
        @endif
      </section>

      @if($errors->any())<div class="flash bad" role="alert">{{ $errors->first() }}</div>@endif
      <div class="savebar"><button class="btn pri" type="submit">Save rules</button></div>
    </form>

    <aside class="stack">
      <section class="card" aria-labelledby="hist-h">
        <div class="card-h"><div><h2 id="hist-h">History</h2><p>Every save is a version you can go back to.</p></div></div>
        <div class="list">
          @forelse($versions as $v)
            <div class="row"><div class="grow"><b>Version {{ $v->version }}</b>@if($own && $v->version === $own->version) <span class="badge ok">Current</span>@endif<br>
              <span class="sub">{{ $v->created_at?->format('M j, Y H:i') }} · {{ $v->changer?->name ?? 'API / system' }}{{ $v->note ? ' · '.$v->note : '' }}</span></div>
              @if($own && $v->version !== $own->version)
                <form method="post" action="{{ url()->current() }}/restore" data-confirm="Restore version {{ $v->version }}?">@csrf<input type="hidden" name="version" value="{{ $v->version }}"><button class="btn sm">Restore</button></form>
              @endif</div>
          @empty<div class="empty"><b>No saved versions yet</b><span>{{ ucfirst($src[$source]) }} apply.</span></div>@endforelse
        </div>
      </section>
    </aside>
  </div>
</div>
@endsection
