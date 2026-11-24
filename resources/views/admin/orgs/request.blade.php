@extends('layouts.console')
@section('title', $r->org_name.' · Request')
@include('admin.orgs._assets')
@section('content')
@php($pending = $r->status === 'pending')
<div class="crumbs"><a href="{{ route('admin.orgs.index') }}">Organizations</a><span aria-hidden="true">/</span><span>Requests</span></div>
<header class="head">
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px"><h1>{{ $r->org_name }} wants to use Clauth</h1>
    @if($pending)<span class="badge warn">Waiting for you</span>@elseif($r->status === 'approved')<span class="badge ok">Approved</span>@else<span class="badge bad">Declined</span>@endif</div>
</header>

<div class="org-split">
  <section class="card main" aria-labelledby="dt-h">
    <div class="card-h"><h2 id="dt-h">Their request</h2></div>
    <dl class="kv req-kv">
      <dt>Organization</dt><dd>{{ $r->org_name }}</dd>
      <dt>Short name</dt><dd class="mono">{{ $r->slug }}</dd>
      <dt>Website</dt><dd>@if($r->website)<a href="{{ $r->website }}" target="_blank" rel="noopener noreferrer">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($r->website, '/')) }}</a>@else<span class="muted">—</span>@endif</dd>
      <dt>Contact</dt><dd>{{ $r->contact_name }} · {{ $r->contact_email }}</dd>
      <dt>Wants to use it for</dt><dd>{{ $r->purpose }}</dd>
      <dt>Agreed to</dt><dd>Terms and Data Processing Agreement, version {{ config('clauth.terms_version') }}</dd>
      <dt>Sent</dt><dd>{{ $r->created_at->diffForHumans() }}</dd>
      @unless($pending)
        <dt>Decided</dt><dd>{{ $r->decided_at?->diffForHumans() ?? '—' }}</dd>
        @if($r->reject_reason)<dt>Note sent</dt><dd>{{ $r->reject_reason }}</dd>@endif
      @endunless
    </dl>
  </section>

  <aside>
    <section class="card pad" aria-labelledby="ck-h">
      <h2 id="ck-h" style="font-size:15px;margin-bottom:6px">Checks</h2>
      <div class="chk">@if($checks['email'])<span class="y" aria-hidden="true">✓</span>Email address confirmed @else<span class="x" aria-hidden="true">✕</span>Email address not confirmed @endif</div>
      <div class="chk">@if($checks['match'] === true)<span class="y" aria-hidden="true">✓</span>Email matches the website’s domain @elseif($checks['match'] === false)<span class="q" aria-hidden="true">?</span>Email is on a different domain from the website @else<span class="q" aria-hidden="true">?</span>No website given @endif</div>
      @if($pending)
        <div class="chk">@if($checks['slug'])<span class="y" aria-hidden="true">✓</span>Short name is free @else<span class="x" aria-hidden="true">✕</span>Short name is taken now — decline with a note @endif</div>
        <div class="chk"><span class="q" aria-hidden="true">?</span>Domain not proven yet — they can do this after approval</div>
      @endif
    </section>

    @if($pending)
      <section class="card pad" style="display:flex;flex-direction:column;gap:12px">
        <form id="reject-f" method="post" action="{{ route('admin.org-requests.reject', $r->id) }}">@csrf
          <label class="f"><span>Note to {{ $r->contact_name }} <span class="hint">(optional, emailed if you decline)</span></span>
            <textarea class="in prose" name="reason" maxlength="500" placeholder="Why it can’t go ahead…">{{ old('reason') }}</textarea></label>
        </form>
        <form id="approve-f" method="post" action="{{ route('admin.org-requests.approve', $r->id) }}" data-confirm="Create {{ $r->org_name }} and invite {{ $r->contact_email }} as its owner?">@csrf</form>
        <div class="actions"><button class="btn pri" form="approve-f">Approve</button><button class="btn danger" form="reject-f">Decline</button></div>
        <p class="small muted" style="margin:0">Approving creates the organization with {{ $r->contact_name }} as owner (2-step verification required) and starter limits.</p>
      </section>
    @elseif($org)
      <a class="btn" href="{{ route('admin.orgs.show', $org->id) }}">Open {{ $org->name }}</a>
    @endif
  </aside>
</div>
@endsection
