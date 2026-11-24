@extends('layouts.console')
@section('title', 'Sign-in rules · '.($app?->name ?? $org?->name ?? 'Platform'))
@section('content')
@php($src = ['app' => 'this app’s own rules', 'org' => ($app?->publisher?->name ?? 'the organization').'’s default rules', 'platform' => 'the platform’s rules', 'default' => 'Clauth’s built-in defaults'])
<header class="head"><div><h1>Sign-in rules</h1>
  <p>{{ $app ? "How people sign in to {$app->name}." : ($org ? "Default for every app {$org->name} publishes, unless an app has its own." : 'Default for every app without its own or its organization’s rules.') }}
  Settings still switch features on for everyone; rules here can only narrow them.
  @if($app)<br><b>Now using:</b> {{ $src[$source] }}.@endif</p></div>
  <div class="actions">
    @if($app)<a class="btn" href="{{ route('admin.apps.show', $app->key) }}">Back to {{ $app->name }}</a>@elseif($org)<a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a>@else<a class="btn" href="{{ route('admin.settings') }}">Settings</a>@endif
    @if($own)<form method="post" action="{{ url()->current() }}/reset" data-confirm="Remove these rules so the {{ $app ? 'organization’s or platform’s' : 'platform’s' }} apply?">@csrf<button class="btn">Use the default instead</button></form>@endif
  </div></header>

<div class="grid2">
  <form class="card pad form" method="post" action="{{ url()->current() }}">@csrf @method('put')
    <h2>Ways to sign in</h2>
    @foreach(\App\Services\SigninPolicies::METHODS as $k => $label)
      <label class="cb"><input type="checkbox" name="{{ $k }}" value="1" @checked($rules[$k])> {{ $label }}</label>
    @endforeach
    @if($connections->isNotEmpty())
      <div class="f"><label>Sign-in providers</label>
        <label class="cb"><input type="radio" name="connections_mode" value="all" @checked($rules['connections'] === 'all')> All that are set up</label>
        <label class="cb"><input type="radio" name="connections_mode" value="some" @checked($rules['connections'] !== 'all')> Only these:</label>
        <div class="pills" style="margin-left:24px">@foreach($connections as $c)<label class="pill"><input type="checkbox" name="connections[]" value="{{ $c->key }}" @checked(is_array($rules['connections']) && in_array($c->key, $rules['connections'], true))><span>{{ $c->name }}</span></label>@endforeach</div></div>
    @else<input type="hidden" name="connections_mode" value="all">@endif

    <h2 style="margin-top:20px">2-step verification</h2>
    <div class="f"><select class="in" name="two_step"><option value="default" @selected($rules['two_step'] === 'default')>Follow Settings and the app’s 2-step roles</option><option value="always" @selected($rules['two_step'] === 'always')>Always ask for 2-step</option></select></div>
    <label class="cb"><input type="checkbox" name="sms_second_step" value="1" @checked($rules['sms_second_step'])> Text-message codes can be the second step</label>

    @if($app)
      <h2 style="margin-top:20px">New people</h2>
      <div class="f"><select class="in" name="registration"><option value="invite" @selected($rules['registration'] === 'invite')>Invite only</option><option value="open" @selected($rules['registration'] === 'open')>Anyone can create an account (joins {{ $app->publisher?->name ?? 'the organization' }})</option></select></div>
      <div class="f"><label>Only these email domains (one per line; empty = any)</label><textarea class="in mono" name="open_domains" rows="2">{{ implode("\n", $rules['open_domains']) }}</textarea></div>
      <div class="f"><label>Access profile new people get</label><select class="in" name="open_profile"><option value="">None (an admin gives access)</option>@foreach($profiles as $p)<option value="{{ $p->key }}" @selected($rules['open_profile'] === $p->key)>{{ $p->name }}</option>@endforeach</select></div>
    @else<input type="hidden" name="registration" value="invite">@endif

    <h2 style="margin-top:20px">Ask your own system</h2>
    @if($checks->isNotEmpty())
      <div class="f"><select class="in" name="check"><option value="">Don’t ask</option>@foreach($checks as $c)<option value="{{ $c->key }}" @selected($rules['check'] === $c->key)>{{ $c->name }}</option>@endforeach</select>
        <span class="small muted">Before each sign-in Clauth posts who is signing in to that URL; it answers <code>{"allow": true}</code> or refuses.</span></div>
      <label class="cb"><input type="checkbox" name="check_fail_open" value="1" @checked($rules['check_fail_open'])> If it can’t be reached, let people in anyway</label>
    @else<p class="small muted">Add a “Your own system” connection under Sign-in &amp; senders to use this.</p>@endif

    @if($errors->any())<div class="small bad">{{ $errors->first() }}</div>@endif
    <div class="actions" style="margin-top:16px"><button class="btn pri">Save rules</button></div>
  </form>

  <section class="card">
    <div class="card-h"><h2>History</h2></div>
    <div class="list">
      @forelse($versions as $v)
        <div class="row"><div class="grow"><b>Version {{ $v->version }}</b>{{ $own && $v->version === $own->version ? ' · current' : '' }}<br>
          <span class="small muted">{{ $v->created_at?->format('j M Y H:i') }} · {{ $v->changer?->name ?? 'API / system' }}{{ $v->note ? ' · '.$v->note : '' }}</span></div>
          @if($own && $v->version !== $own->version)
            <form method="post" action="{{ url()->current() }}/restore" data-confirm="Restore version {{ $v->version }}?">@csrf<input type="hidden" name="version" value="{{ $v->version }}"><button class="btn sm">Restore</button></form>
          @endif</div>
      @empty<div class="empty">No saved versions yet — {{ $src[$source] }} apply.</div>@endforelse
    </div>
  </section>
</div>
@endsection
