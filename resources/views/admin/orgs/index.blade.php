@extends('layouts.console')
@section('title', 'Organizations')
@section('content')
<header class="head">
  <div><h1>Organizations</h1><p>The businesses people belong to. Each organization decides which apps its people can use and gives roles per app.</p></div>
  @if($canEdit)
  <details class="modal"><summary class="btn pri">@include('partials.icon', ['i' => 'plus'])New organization</summary>
    <div class="sheet"><div class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2>New organization</h2><a class="btn sm" href="{{ route('admin.orgs.index') }}">Close</a></div>
      <form class="form" method="post" action="{{ route('admin.orgs.store') }}" style="margin-top:16px">@csrf
        <div class="f"><label for="on">Name</label><input class="in" id="on" name="name" required></div>
        <div class="f"><label for="os">Slug</label><input class="in mono" id="os" name="slug" pattern="[a-z0-9][a-z0-9-]{1,60}" required><span class="small muted">Appears in tokens as the key of the <code>orgs</code> claim. Don’t change it later.</span></div>
        <label class="cb"><input type="checkbox" name="is_platform" value="1"> One of my own businesses (its apps skip the consent screen and its sign-in domains are trusted)</label>
        <div class="actions"><button class="btn pri">Create</button></div>
      </form></div></div></details>
  @endif
</header>
@if($requests->isNotEmpty())
  <section class="card" aria-labelledby="req-h" style="margin-bottom:20px">
    <div class="card-h"><h2 id="req-h">Requests to join ({{ $requests->count() }})</h2></div>
    <div class="list">
      @foreach($requests as $r)
        <div class="row" style="align-items:flex-start;flex-wrap:wrap">
          <span class="grow"><b>{{ $r->org_name }}</b> <span class="mono small">{{ $r->slug }}</span>@if($r->website) · <a href="{{ $r->website }}" target="_blank" rel="noopener noreferrer">{{ $r->website }}</a>@endif<br>
            <span class="small">{{ $r->contact_name }} · {{ $r->contact_email }} · {{ $r->created_at->diffForHumans() }}</span><br>
            <span class="small muted">{{ $r->purpose }}</span></span>
          <form method="post" action="{{ route('admin.org-requests.approve', $r->id) }}" data-confirm="Create {{ $r->org_name }} and invite {{ $r->contact_email }} as its owner?">@csrf<button class="btn pri sm">Approve</button></form>
          <form class="actions" method="post" action="{{ route('admin.org-requests.reject', $r->id) }}">@csrf
            <label class="sr-only" for="rr{{ $r->id }}">Note</label><input class="in" id="rr{{ $r->id }}" name="reason" placeholder="Optional note" style="max-width:200px">
            <button class="btn sm">Reject</button></form>
        </div>
      @endforeach
    </div>
  </section>
@endif
@error('request')<div class="flash bad">{{ $message }}</div>@enderror
<div class="grid2">
  @forelse($orgs as $o)
    @php($canRoles = $canEdit || in_array($o->id, $ownedOrgIds, true))
    <section class="card pad">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
        <div><h2>{{ $o->name }}</h2><p class="small muted mono" style="margin:4px 0 0">{{ $o->slug }} · {{ $o->users_count }} people</p></div>
        <div class="pills">
          @if($o->is_platform)<span class="chip info" title="One of William's own businesses">Own business</span>@elseif($o->verified_at)<span class="chip ok">Verified</span>@else<span class="chip warn">Unverified</span>@endif
          @if($o->suspended_at)<span class="chip bad" title="{{ $o->suspend_reason }}">Suspended</span>@endif
          <span class="chip {{ $o->status === 'active' ? 'ok' : 'bad' }}">{{ ucfirst($o->status) }}</span>
          <a class="btn sm" href="{{ route('admin.profiles.index', $o->id) }}">Access profiles</a>
        </div>
      </div>
      @if($o->suspended_at)<div class="flash bad" style="margin-top:12px">Suspended {{ $o->suspended_at->diffForHumans() }}: {{ $o->suspend_reason }}</div>@endif

      @if($o->installRequests->isNotEmpty())
        <p class="lbl" style="margin:16px 0 6px">Waiting for your approval</p>
        @foreach($o->installRequests as $a)
          <div class="flash info" style="margin:0 0 8px"><b>{{ $a->name }}</b> by {{ $a->publisher?->name ?? '—' }} wants to be available to {{ $o->name }}. It will receive the name, email and roles of the people you give access to.
            <div class="actions" style="margin-top:8px">
              <form method="post" action="{{ route('admin.orgs.requests.approve', [$o->id, $a->key]) }}">@csrf<button class="btn pri sm">Approve</button></form>
              <form method="post" action="{{ route('admin.orgs.apps.destroy', [$o->id, $a->key]) }}">@csrf @method('delete')<button class="btn sm">Decline</button></form>
            </div></div>
        @endforeach
      @endif

      <p class="lbl" style="margin:16px 0 6px">Owners, admins and developers</p>
      <div class="pills">@forelse($o->users as $u)<a class="chip" href="{{ route('admin.users.show', [$u->id, 'org' => $o->id]) }}">{{ $u->name }} · {{ $roles[$u->pivot->role] ?? $u->pivot->role }}</a>@empty<span class="small muted">None</span>@endforelse</div>
      @if($canRoles)
        <form class="actions" method="post" action="{{ route('admin.orgs.roles', $o->id) }}" style="margin-top:10px">@csrf
          <label class="sr-only" for="ae{{ $o->id }}">Member email</label><input class="in" id="ae{{ $o->id }}" type="email" name="email" placeholder="member email" style="max-width:220px" required>
          <label class="sr-only" for="ar{{ $o->id }}">Role</label>
          <select class="in" id="ar{{ $o->id }}" name="role" style="max-width:150px">@foreach($roles as $k => $label)<option value="{{ $k }}" @selected($k === 'admin')>{{ $label }}</option>@endforeach</select>
          <button class="btn sm">Set role</button>
        </form>
        <p class="small muted" style="margin:6px 0 0">Admins manage people and installed apps. Developers manage this organization's own apps. Owners can also change roles.</p>
      @endif

      <p class="lbl" style="margin:16px 0 6px">Apps people here can use</p>
      <div class="scroll"><table class="t"><tbody>
        @forelse($o->apps as $a)
          <tr><td><b>{{ $a->name }}</b>@if($a->owner_organization_id !== $o->id)<span class="small muted"> · by {{ $a->publisher?->name ?? '—' }}</span>@endif</td>
            <td style="text-align:right"><form method="post" action="{{ route('admin.orgs.apps.destroy', [$o->id, $a->key]) }}" data-confirm="Remove {{ $a->name }} from {{ $o->name }}? Everyone's access to it here is removed and they're signed out of it.">@csrf @method('delete')<button class="btn sm danger">Remove</button></form></td></tr>
        @empty<tr><td class="small muted">None yet.</td></tr>@endforelse
      </tbody></table></div>
      @php($more = $addable->reject(fn ($a) => $o->apps->contains('id', $a->id)))
      @if($more->isNotEmpty())
        <form class="actions" method="post" action="{{ route('admin.orgs.apps.store', $o->id) }}" style="margin-top:10px">@csrf
          <label class="sr-only" for="aa{{ $o->id }}">App</label>
          <select class="in" id="aa{{ $o->id }}" name="app_id" style="max-width:260px">@foreach($more as $a)<option value="{{ $a->id }}">{{ $a->name }}{{ $a->publisher && $a->owner_organization_id !== $o->id ? ' (by '.$a->publisher->name.')' : '' }}</option>@endforeach</select>
          <button class="btn sm">Add app</button>
        </form>
      @endif

      @if($o->deletion_requested_at)
        <div class="flash bad" style="margin-top:16px">Will be deleted on {{ $o->deletion_requested_at->addDays(\App\Services\OrgLifecycle::GRACE_DAYS)->format('j M Y') }}.
          @if($canRoles)<form method="post" action="{{ route('admin.orgs.deletion.cancel', $o->id) }}" style="display:inline">@csrf @method('delete')<button class="btn sm">Cancel deletion</button></form>@endif</div>
      @endif
      @if($o->needsTerms() && $canRoles)
        <form class="flash info" method="post" action="{{ route('admin.orgs.terms', $o->id) }}" style="margin-top:16px">@csrf
          <label class="cb"><input type="checkbox" name="agree" value="1" required> I accept the <a href="{{ route('terms') }}" target="_blank">Terms</a> and the <a href="{{ route('dpa') }}" target="_blank">Data Processing Agreement</a> for {{ $o->name }}.</label>
          <button class="btn sm pri">Accept</button> <span class="small muted">Needed before {{ $o->name }} can publish apps.</span></form>
      @endif
      @unless($o->is_platform)
        <p class="lbl" style="margin:16px 0 6px">Your domain</p>
        @if($o->domain && $o->domain_verified_at)
          <p class="small" style="margin:0"><b class="mono">{{ $o->domain }}</b> verified {{ $o->domain_verified_at->diffForHumans() }}.@unless($o->verified_at) Clauth will review {{ $o->name }} for the Verified badge.@endunless</p>
        @elseif($o->domain)
          <p class="small" style="margin:0 0 6px">Add this TXT record to <b class="mono">{{ $o->domain }}</b>'s DNS, then check:</p>
          <p class="small mono" style="margin:0 0 6px">{{ \App\Services\OrgLifecycle::txtName($o) }} &nbsp; TXT &nbsp; {{ \App\Services\OrgLifecycle::txtValue($o) }}</p>
          <form method="post" action="{{ route('admin.orgs.domain.check', $o->id) }}">@csrf<button class="btn sm">Check now</button></form>
        @endif
        @unless($o->domain_verified_at)
          <form class="actions" method="post" action="{{ route('admin.orgs.domain', $o->id) }}" style="margin-top:8px">@csrf
            <label class="sr-only" for="od{{ $o->id }}">Domain</label><input class="in mono" id="od{{ $o->id }}" name="domain" placeholder="example.com" value="{{ $o->domain }}" style="max-width:220px" required>
            <button class="btn sm">{{ $o->domain ? 'Change' : 'Prove domain' }}</button></form>
          <p class="small muted" style="margin:4px 0 0">Proving the domain your organization uses is the first step to the Verified badge and higher limits.</p>
        @endunless
      @endunless
      <div class="actions" style="margin-top:16px">
        <a class="btn sm" href="{{ route('admin.orgs.export', $o->id) }}">Export data (JSON)</a>
        @if(! $o->is_platform && $canRoles && ! $o->deletion_requested_at)
          <details><summary class="btn sm danger">Delete organization…</summary>
            <form class="actions" method="post" action="{{ route('admin.orgs.deletion', $o->id) }}" style="margin-top:8px">@csrf
              <label class="sr-only" for="dc{{ $o->id }}">Type {{ $o->slug }}</label><input class="in mono" id="dc{{ $o->id }}" name="confirm" placeholder="type {{ $o->slug }}" style="max-width:200px" required>
              <button class="btn sm danger">Delete in 30 days</button></form>
            <p class="small muted" style="margin:4px 0 0">People, access, invites, its apps and domains are deleted after 30 days. Export first.</p></details>
        @endif
      </div>
      @error('domain')<p class="small" style="color:var(--danger,#b91c1c)">{{ $message }}</p>@enderror
      @error('confirm')<p class="small" style="color:var(--danger,#b91c1c)">{{ $message }}</p>@enderror

      @if($canEdit)
        <form class="form" method="post" action="{{ route('admin.orgs.update', $o->id) }}" style="margin-top:16px">@csrf @method('put')
          <div class="f"><label for="n{{ $o->id }}">Name</label><input class="in" id="n{{ $o->id }}" name="name" value="{{ $o->name }}" required></div>
          <div class="f"><label for="s{{ $o->id }}">Status</label><select class="in" id="s{{ $o->id }}" name="status"><option value="active" @selected($o->status === 'active')>Active</option><option value="disabled" @selected($o->status === 'disabled')>Disabled (no app access)</option></select></div>
          <input type="hidden" name="is_platform" value="0"><label class="cb"><input type="checkbox" name="is_platform" value="1" @checked($o->is_platform)> One of my own businesses</label>
          @unless($o->is_platform)
            <input type="hidden" name="verified" value="0"><label class="cb"><input type="checkbox" name="verified" value="1" @checked($o->verified_at)> Verified (domain checked and reviewed: higher limits, Verified badge)</label>
            <details><summary class="small">Limits ({{ $o->verified_at ? 'verified' : 'unverified' }} defaults; leave blank to use them)</summary>
              <div class="grid2" style="margin-top:8px">
                @foreach(config('clauth.limits.'.($o->verified_at ? 'verified' : 'unverified')) as $k => $def)
                  <div class="f"><label for="l{{ $o->id }}{{ $k }}">{{ str_replace('_', ' ', ucfirst($k)) }} <span class="small muted">(used {{ \App\Services\Limits::used($o, $k) }})</span></label>
                    <input class="in" id="l{{ $o->id }}{{ $k }}" name="limits[{{ $k }}]" type="number" min="0" placeholder="{{ $def }}" value="{{ $o->limits[$k] ?? '' }}"></div>
                @endforeach
              </div></details>
          @endunless
          <div class="actions"><button class="btn">Save</button></div>
        </form>
        @unless($o->is_platform)
          @if($o->suspended_at)
            <form method="post" action="{{ route('admin.orgs.restore', $o->id) }}" style="margin-top:10px">@csrf @method('delete')<button class="btn sm">Restore organization</button></form>
          @else
            <form class="actions" method="post" action="{{ route('admin.orgs.suspend', $o->id) }}" style="margin-top:10px" data-confirm="Suspend {{ $o->name }}? Its apps stop signing anyone in right away.">@csrf
              <label class="sr-only" for="sr{{ $o->id }}">Reason</label><input class="in" id="sr{{ $o->id }}" name="reason" placeholder="Reason (shown to its staff)" style="max-width:280px" required>
              <button class="btn sm danger">Suspend</button></form>
          @endif
        @endunless
      @endif
    </section>
  @empty<div class="card empty">No organizations yet.</div>@endforelse
</div>
@endsection
