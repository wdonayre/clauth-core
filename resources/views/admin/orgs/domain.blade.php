@extends('layouts.console')
@section('title', $org->name.' · Sign-in domain')
@include('admin.orgs._assets')
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])
@include('admin.orgs._meta')
@php($main = \App\Services\Domains::mainHost())

@forelse($domains as $d)
  @php($appNames = $d->apps->pluck('name'))
  @php($dnsOk = (bool) $d->verified_at)
  @php($pointed = in_array($d->dns_target, ['cname', 'a', 'proxied'], true) || $d->reachable_at)
  @php($certOk = $d->status === 'active' || $d->cert_issued_at)
  @php($manageApp = $d->apps->first(fn ($a) => auth()->user()->canManageApp($a)))
  <section class="card" aria-labelledby="dm-{{ $d->id }}" style="max-width:1000px">
    <div class="card-h">
      <div><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><h2 id="dm-{{ $d->id }}" class="mono" style="font-size:18px">{{ $d->host }}</h2>
        <span class="badge {{ $d->status === 'active' ? 'ok' : ($d->status === 'problem' ? 'bad' : 'warn') }}">{{ $d->label() }}</span></div>
        <p>@if($appNames->isEmpty())Not used by any app yet @else Used by {{ $appNames->count() > 1 ? $appNames->slice(0, -1)->implode(', ').' and '.$appNames->last() : $appNames->first() }}@endif</p></div>
      <div class="actions">
        @if($manageApp)<a class="btn" href="{{ route('admin.apps.show', $manageApp->key) }}">Manage</a>@endif
        @if($d->isServed())<a class="btn" href="https://{{ $d->host }}" target="_blank" rel="noopener noreferrer">Open @include('partials.icon', ['i' => 'external', 'iconSize' => 14])</a>@endif
      </div>
    </div>
    <div class="steps">
      <span class="step done"><span class="n" aria-hidden="true">✓</span>Address added</span>
      <span class="step {{ $dnsOk ? 'done' : 'now' }}"><span class="n" aria-hidden="true">{{ $dnsOk ? '✓' : '2' }}</span>DNS records found<span class="sr-only">{{ $dnsOk ? ' (done)' : ' (waiting)' }}</span></span>
      <span class="step {{ $certOk ? 'done' : ($dnsOk ? 'now' : '') }}"><span class="n" aria-hidden="true">{{ $certOk ? '✓' : '3' }}</span>Certificate issued<span class="sr-only">{{ $certOk ? ' (done)' : ' (waiting)' }}</span></span>
    </div>
    @if($d->status === 'problem' && $d->last_error)<div class="flash bad" style="margin:0 22px 16px">{{ $d->last_error }}</div>@endif
    <div class="scroll dtable">
      <table class="t">
        <thead><tr><th scope="col">Type</th><th scope="col">Name</th><th scope="col">Value</th><th scope="col">Status</th></tr></thead>
        <tbody>
          <tr><td>TXT</td><td><code>{{ $d->txtName() }}</code></td>
            <td><code id="txt-{{ $d->id }}">{{ $d->txtValue() }}</code> <button class="btn sm" type="button" data-copy="#txt-{{ $d->id }}" aria-label="Copy TXT value">Copy</button></td>
            <td><span class="badge {{ $dnsOk ? 'ok' : 'warn' }}">{{ $dnsOk ? 'Found' : 'Waiting' }}</span></td></tr>
          <tr><td>CNAME</td><td><code>{{ $d->host }}</code></td>
            <td><code id="cn-{{ $d->id }}">{{ $main }}</code> <button class="btn sm" type="button" data-copy="#cn-{{ $d->id }}" aria-label="Copy CNAME value">Copy</button></td>
            <td><span class="badge {{ $pointed ? 'ok' : 'warn' }}">{{ $pointed ? 'Found' : 'Waiting' }}</span></td></tr>
        </tbody>
      </table>
    </div>
    <div class="set"><div><b>Trusted — sign people in without asking</b><p>People already signed in to Clauth go straight in.{{ $org->is_platform ? ' Always on for your own organizations.' : ' Only for your own organizations; Clauth turns it on after review.' }}</p></div>
      <span class="badge {{ $d->isTrusted() ? 'ok' : 'muted' }}">{{ $d->isTrusted() ? 'On' : 'Off' }}</span></div>
    <div class="set"><div><b>Certificate</b><p>@if($d->cert_issued_at)Issued {{ $d->cert_issued_at->format('M j, Y') }} · renews automatically @elseif($d->status === 'active')Active · renews automatically @else Requested once the DNS records are found. Clauth checks every 5 minutes.@endif</p></div></div>
  </section>
@empty
  <section class="card" style="max-width:1000px"><div class="empty"><b>No sign-in address yet</b><span>People sign in on {{ $main }}. You can give an app its own address, like auth.yourbusiness.com, on the app’s page.</span></div></section>
@endforelse

@if($manageable->count() === 1)
  <a class="btn" href="{{ route('admin.apps.show', $manageable->first()->key) }}" style="align-self:flex-start">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16]){{ $domains->isEmpty() ? 'Add an address' : 'Add another address' }}</a>
@elseif($manageable->count() > 1)
  <details class="menu-d" style="align-self:flex-start"><summary class="btn">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16]){{ $domains->isEmpty() ? 'Add an address' : 'Add another address' }}</summary>
    <div class="pop" style="left:0;right:auto"><span class="small muted" style="padding:6px 10px">Set it up on the app’s page:</span>
      @foreach($manageable as $a)<a href="{{ route('admin.apps.show', $a->key) }}">@include('partials.ui.app-icon', ['app' => $a, 'size' => 'sm']){{ $a->name }}</a>@endforeach</div></details>
@endif

@unless($org->is_platform)
  <section class="card" aria-labelledby="own-h" style="max-width:1000px">
    <div class="card-h"><div><h2 id="own-h">Your domain</h2><p>Proving the domain your organization uses is the first step to the Verified badge and higher limits.</p></div>
      @if($org->domain_verified_at)<span class="badge ok">Proven</span>@elseif($org->domain)<span class="badge warn">Waiting for DNS</span>@endif</div>
    <div class="card-body" style="padding-top:0;display:flex;flex-direction:column;gap:12px">
      @if($org->domain && $org->domain_verified_at)
        <p class="small" style="margin:0"><b class="mono">{{ $org->domain }}</b> verified {{ $org->domain_verified_at->diffForHumans() }}.@unless($org->verified_at) Clauth will review {{ $org->name }} for the Verified badge.@endunless</p>
      @elseif($org->domain)
        <p class="small" style="margin:0">Add this TXT record to <b class="mono">{{ $org->domain }}</b>’s DNS, then check:</p>
        <div class="scroll"><table class="t"><thead><tr><th scope="col">Type</th><th scope="col">Name</th><th scope="col">Value</th></tr></thead>
          <tbody><tr><td>TXT</td><td><code>{{ \App\Services\OrgLifecycle::txtName($org) }}</code></td>
            <td><code id="org-txt">{{ \App\Services\OrgLifecycle::txtValue($org) }}</code> <button class="btn sm" type="button" data-copy="#org-txt" aria-label="Copy TXT value">Copy</button></td></tr></tbody></table></div>
        <form method="post" action="{{ route('admin.orgs.domain.check', $org->id) }}">@csrf<button class="btn">@include('partials.icon', ['i' => 'refresh', 'iconSize' => 16])Check now</button></form>
      @endif
      @unless($org->domain_verified_at)
        <form class="toolbar" method="post" action="{{ route('admin.orgs.domain', $org->id) }}">@csrf
          <label class="f" style="flex:1 1 240px;max-width:320px"><span>{{ $org->domain ? 'Use a different domain' : 'Domain' }}</span><input class="in mono" name="domain" placeholder="example.com" value="{{ old('domain', $org->domain) }}" required>
            @error('domain')<span class="err">{{ $message }}</span>@enderror</label>
          <button class="btn" style="align-self:flex-end">{{ $org->domain ? 'Change' : 'Prove domain' }}</button>
        </form>
      @endunless
    </div>
  </section>
@endunless
@endsection
