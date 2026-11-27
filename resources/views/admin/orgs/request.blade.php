@extends('layouts.console')
@section('title', $r->org_name.' · Request')
@include('admin.orgs._assets')
@section('content')
@php($pending = $r->status === 'pending')
@php($badge = ['pending' => ['Waiting for you', 'warn'], 'info_requested' => ['Waiting for their answer', 'muted'], 'approved' => ['Approved', 'ok'], 'rejected' => ['Declined', 'bad'], 'cancelled' => ['Cancelled by them', 'muted'], 'expired' => ['Closed: no answer', 'muted']][$r->status] ?? [$r->status, 'muted'])
<div class="crumbs"><a href="{{ route('admin.orgs.index') }}">Organizations</a><span aria-hidden="true">/</span><a href="{{ route('admin.org-requests.index') }}">Requests</a><span aria-hidden="true">/</span><span>{{ $r->org_name }}</span></div>
<header class="head">
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px"><h1>{{ $r->org_name }}</h1><span class="badge {{ $badge[1] }}">{{ $badge[0] }}</span></div>
</header>
@include('partials.errors')

<div class="org-split">
  <div class="main" style="display:flex;flex-direction:column;gap:16px">
    <section class="card" aria-labelledby="dt-h">
      <div class="card-h"><h2 id="dt-h">Their request</h2></div>
      <dl class="kv req-kv">
        <dt>Short name</dt><dd class="mono">{{ $r->slug }}</dd>
        <dt>Website</dt><dd>@if($r->website)<a href="{{ $r->website }}" target="_blank" rel="noopener noreferrer">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($r->website, '/')) }}</a>@else<span class="muted">—</span>@endif</dd>
        @if($r->country)<dt>Country</dt><dd>{{ $r->country }}</dd>@endif
        @if($r->team_size)<dt>People</dt><dd>{{ $r->sizeLabel() }}</dd>@endif
        <dt>Came from</dt><dd>{{ $r->sourceApp?->name ?? ($r->user_id ? 'Clauth sign-up' : 'Public form') }}</dd>
        <dt>Wants to use it for</dt><dd>{{ $r->purpose }}</dd>
        <dt>Agreed to</dt><dd>Terms and Data Processing Agreement, version {{ config('clauth.terms_version') }}</dd>
        <dt>Sent</dt><dd>{{ $r->created_at->diffForHumans() }}@if($r->updated_at->gt($r->created_at->addMinute())) · last changed {{ $r->updated_at->diffForHumans() }}@endif</dd>
        @if($r->info_message)<dt>You asked</dt><dd>{{ $r->info_message }}@if($r->info_requested_at) <span class="muted small">({{ $r->info_requested_at->diffForHumans() }})</span>@endif</dd>@endif
        @if($r->decided_at)<dt>Closed</dt><dd>{{ $r->decided_at->diffForHumans() }}</dd>@endif
        @if($r->reject_reason)<dt>Note sent</dt><dd>{{ $r->reject_reason }}</dd>@endif
      </dl>
    </section>
    <section class="card" aria-labelledby="by-h">
      <div class="card-h"><h2 id="by-h">Asked by</h2></div>
      <dl class="kv req-kv">
        <dt>Name</dt><dd>{{ $r->contact_name }}</dd>
        <dt>Email</dt><dd>{{ $r->contact_email }}</dd>
        @if($r->user)
          <dt>Account</dt><dd><a href="{{ route('admin.users.show', $r->user->id) }}">Made {{ $r->user->created_at->diffForHumans() }}</a></dd>
          <dt>2-step</dt><dd>{{ $r->user->hasSecondFactor() ? 'On' : 'Not yet (owners must turn it on)' }}</dd>
        @endif
      </dl>
    </section>
  </div>

  <aside>
    <section class="card pad" aria-labelledby="ck-h">
      <h2 id="ck-h" style="font-size:15px;margin-bottom:6px">Checks</h2>
      <div class="chk">@if($checks['email'])<span class="y" aria-hidden="true">✓</span>Email address confirmed @else<span class="x" aria-hidden="true">✕</span>Email address not confirmed @endif</div>
      <div class="chk">@if($checks['free'])<span class="q" aria-hidden="true">?</span>Personal email address: nothing ties it to the organization @elseif($checks['match'] === true)<span class="y" aria-hidden="true">✓</span>Email matches the website’s domain @elseif($checks['match'] === false)<span class="q" aria-hidden="true">?</span>Email is on a different domain from the website @else<span class="q" aria-hidden="true">?</span>No website given @endif</div>
      @if($r->isOpen())
        <div class="chk">@if($checks['slug'] && ! $checks['reserved'])<span class="y" aria-hidden="true">✓</span>Short name is free @elseif($checks['reserved'])<span class="x" aria-hidden="true">✕</span>Short name is reserved @else<span class="x" aria-hidden="true">✕</span>Short name is taken now @endif</div>
        @if($checks['first'] !== null)<div class="chk">@if($checks['first'])<span class="y" aria-hidden="true">✓</span>First request from this account @else<span class="q" aria-hidden="true">?</span>This account has asked before @endif</div>@endif
        @if($checks['owns'])<div class="chk"><span class="q" aria-hidden="true">?</span>Already owns {{ $checks['owns'] }} {{ \Illuminate\Support\Str::plural('organization', $checks['owns']) }}</div>@endif
        <div class="chk"><span class="q" aria-hidden="true">?</span>Domain not proven yet — they can do this after approval</div>
      @endif
    </section>

    @if($pending)
      <section class="card pad" aria-labelledby="dec-h" style="display:flex;flex-direction:column;gap:12px">
        <h2 id="dec-h" style="font-size:15px">Decide</h2>
        <form id="approve-f" method="post" action="{{ route('admin.org-requests.approve', $r->id) }}" data-confirm="Create {{ $r->org_name }} and make {{ $r->contact_email }} its Owner?" style="display:flex;flex-direction:column;gap:10px">@csrf
          <div class="req-lims">
            <label class="f"><span>People limit</span><input class="in" type="number" name="people_limit" min="1" value="{{ old('people_limit', $limits['people']) }}"></label>
            <label class="f"><span>Apps limit</span><input class="in" type="number" name="apps_limit" min="0" value="{{ old('apps_limit', $limits['apps']) }}"></label>
          </div>
          <label class="f"><span>Branding allowed</span><select class="in" name="branding_allowed">@foreach(\App\Services\Brand::LEVELS as $k => $label)<option value="{{ $k }}" @selected(old('branding_allowed', 'clauth') === $k)>{{ $label }}</option>@endforeach</select></label>
          <button class="btn pri" type="submit">Approve {{ $r->org_name }}</button>
          <p class="small muted" style="margin:0">{{ $r->user_id ? 'Creates the organization, makes '.$r->contact_name.' its Owner and emails them. Owners must use 2-step before they can manage it.' : 'Creates the organization and invites '.$r->contact_email.' as its Owner (2-step required).' }}</p>
        </form>
        @if($r->user_id)
          <form method="post" action="{{ route('admin.org-requests.info', $r->id) }}" style="display:flex;flex-direction:column;gap:8px;border-top:1px solid var(--line-3);padding-top:12px">@csrf
            <label class="f"><span>Ask for more info <span class="hint">(emailed; they answer in Clauth)</span></span>
              <textarea class="in prose" name="message" maxlength="1000" placeholder="What would you like to know?">{{ old('message') }}</textarea></label>
            <button class="btn" type="submit">Send question</button>
          </form>
        @endif
        <form method="post" action="{{ route('admin.org-requests.reject', $r->id) }}" style="display:flex;flex-direction:column;gap:8px;border-top:1px solid var(--line-3);padding-top:12px" data-confirm="Decline the request for {{ $r->org_name }}?">@csrf
          <label class="f"><span>Note to {{ $r->contact_name }} <span class="hint">(emailed if you decline)</span></span>
            <textarea class="in prose" name="reason" maxlength="500" placeholder="Why it can’t go ahead, and what would change your mind…">{{ old('reason') }}</textarea></label>
          <button class="btn danger" type="submit">Decline</button>
        </form>
      </section>
    @elseif($r->status === 'info_requested')
      <section class="card pad" style="display:flex;flex-direction:column;gap:10px">
        <p class="small muted" style="margin:0">Waiting for {{ $r->contact_name }} to answer. It comes back to Waiting when they do.</p>
        <form method="post" action="{{ route('admin.org-requests.reject', $r->id) }}" data-confirm="Decline the request for {{ $r->org_name }}?">@csrf<button class="btn danger" type="submit">Decline instead</button></form>
      </section>
    @elseif($org)
      <a class="btn" href="{{ route('admin.orgs.show', $org->id) }}">Open {{ $org->name }}</a>
    @endif
  </aside>
</div>
@endsection
