@extends('layouts.console')
@section('title', 'People')
@push('head')<link rel="stylesheet" href="/css/pages/people.css?v={{ filemtime(public_path('css/pages/people.css')) }}">@endpush
@section('content')
@php($filters = request()->only('q', 'org', 'app', 'status'))
<header class="head">
  <div><h1>People</h1><p>Everyone who can sign in, and what they can use.</p></div>
  <div class="actions">
    <a class="btn" href="{{ route('admin.users.index', array_merge($filters, ['export' => 'csv'])) }}">@include('partials.icon', ['i' => 'download', 'iconSize' => 16])Export</a>
    <a class="btn pri" href="{{ route('admin.users.index', array_merge($filters, ['invite' => 1])) }}" data-open-dialog="#invite-dlg">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Invite people</a>
  </div>
</header>

@if($invited)
  <div class="flash info pp-invited" role="status">
    <div class="grow">
      <b>{{ count($invited) === 1 ? 'Invite' : count($invited).' invites' }} {{ collect($invited)->every('sent') ? 'sent' : 'created' }}.</b>
      Each link works once and for 7 days{{ collect($invited)->every('sent') ? '' : ' — copy the ones that weren’t emailed' }}.
      @foreach($invited as $i => $inv)
        <div class="secretbox" style="margin-top:8px"><span class="small">{{ $inv['email'] }}@unless($inv['sent']) · not emailed @endunless</span><code id="invlink{{ $i }}">{{ $inv['link'] }}</code><button class="btn sm" type="button" data-copy="#invlink{{ $i }}">Copy invite link</button></div>
      @endforeach
    </div>
  </div>
@endif

<form class="toolbar" method="get" action="{{ route('admin.users.index') }}" role="search" aria-label="Filter people">
  <div class="seg" role="group" aria-label="Status">
    @foreach(['all' => 'All', 'active' => 'Active', 'invited' => 'Invited', 'disabled' => 'Turned off'] + ($counts['locked'] || $status === 'locked' ? ['locked' => 'Locked'] : []) as $k => $l)
      <a class="{{ $status === $k ? 'on' : '' }}" href="{{ route('admin.users.index', array_merge(request()->except('page', 'status', 'invite'), $k === 'all' ? [] : ['status' => $k])) }}" @if($status === $k) aria-current="page" @endif>{{ $l }} <span class="pp-n">{{ $counts[$k] }}</span></a>
    @endforeach
  </div>
  @if($status !== 'all')<input type="hidden" name="status" value="{{ $status }}">@endif
  <label class="searchin"><span class="sr-only">Search people</span>
    <input class="in" type="search" name="q" value="{{ request('q') }}" placeholder="Search by name or email" autocomplete="off">
    @include('partials.icon', ['i' => 'search', 'iconSize' => 16])</label>
  <label class="pp-filter"><span class="sr-only">Organization</span>
    <select class="in" name="org" data-autosubmit>
      <option value="">Organization: All</option>
      @foreach($orgs as $o)<option value="{{ $o->slug }}" @selected($currentOrg?->id === $o->id)>{{ $o->name }}</option>@endforeach
    </select></label>
  <label class="pp-filter"><span class="sr-only">App</span>
    <select class="in" name="app" data-autosubmit>
      <option value="">App: All</option>
      @foreach($apps as $a)<option value="{{ $a->key }}" @selected(request('app') === $a->key)>{{ $a->name }}</option>@endforeach
    </select></label>
  <button class="btn" type="submit">Search</button>
  @if(request()->hasAny(['q', 'org', 'app']))<a class="linkbtn" href="{{ route('admin.users.index', $status === 'all' ? [] : ['status' => $status]) }}">Clear</a>@endif
</form>

@if($rows)
{{-- Bulk bar: row checkboxes submit with this form (form="pp-bulk"). With JS it shows only once someone is selected. --}}
<form class="pp-bulk" id="pp-bulk" method="post" action="{{ route('admin.users.bulk') }}" data-bulk aria-label="Act on selected people">@csrf
  <span class="pp-bulk-n" data-bulk-count aria-live="polite">Tick people in the list to act on several at once.</span>
  <div class="pp-bulk-a">
    <button class="btn sm" type="submit" name="action" value="reinvite">@include('partials.icon', ['i' => 'mail', 'iconSize' => 16])Resend invites</button>
    <button class="btn sm" type="submit" name="action" value="enable">Turn back on</button>
    <button class="btn sm danger" type="submit" name="action" value="disable" data-bulk-confirm="Turn off the selected people? They are signed out of every app.">Turn off</button>
    @if($profiles->isNotEmpty())
      <span class="pp-bulk-g">
        <label class="sr-only" for="bulk-profile">Job role to add</label>
        <select class="in" id="bulk-profile" name="profile_id">
          <option value="">Job role…</option>
          @foreach($orgs as $o)
            @if($profiles->get($o->id, collect())->isNotEmpty())
              <optgroup label="{{ $o->name }}">@foreach($profiles->get($o->id) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</optgroup>
            @endif
          @endforeach
        </select>
        <button class="btn sm" type="submit" name="action" value="add-profile">Add job role</button>
      </span>
    @endif
    <span class="pp-bulk-g">
      <label class="sr-only" for="bulk-org">Organization to remove them from</label>
      <select class="in" id="bulk-org" name="organization_id">
        @if($orgs->count() > 1)<option value="">Organization…</option>@endif
        @foreach($orgs as $o)<option value="{{ $o->id }}" @selected($currentOrg?->id === $o->id)>{{ $o->name }}</option>@endforeach
      </select>
      <button class="btn sm danger" type="submit" name="action" value="remove-org" data-bulk-confirm="Remove the selected people from this organization? They lose its job roles and app access.">Remove from organization</button>
    </span>
    <button class="btn sm ghost" type="button" data-bulk-clear hidden>Clear selection</button>
  </div>
</form>
@endif

<section class="card scroll" aria-label="People">
  <table class="t pp-table">
    <thead><tr><th scope="col" class="pp-sel"><label class="pp-ck"><input type="checkbox" data-select-all aria-label="Select all on this page"></label></th><th scope="col">Name</th><th scope="col">Organizations</th><th scope="col">Access</th><th scope="col">2-step</th><th scope="col">Last active</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      @php($u = $r['user'])
      @php($inv = $pending[$u->email] ?? null)
      @if($u->status === 'invited')
        <tr class="pp-pending">
          <td class="pp-sel"><label class="pp-ck"><input type="checkbox" name="ids[]" value="{{ $u->id }}" form="pp-bulk" data-bulk-row aria-label="Select {{ $u->email }}"></label></td>
          <td><a class="person" href="{{ route('admin.users.show', $u->id) }}"><span class="av pending" aria-hidden="true">?</span>
            <span class="who"><b>{{ $u->email }}</b><span>@if($inv)Invited by {{ $inv['invite']->invited_by === auth()->id() ? 'you' : ($inv['invite']->inviter?->name ?? 'Clauth') }} · {{ $inv['invite']->created_at->diffForHumans() }}@else Invited @endif</span></span></a></td>
          <td>{{ $inv['invite']->organization?->name ?? $r['orgs'] }}</td>
          <td>
            @if($inv && ($inv['badges'] || $inv['apps']))
              @foreach($inv['badges'] as $b)<span class="badge muted">{{ $b }}</span> @endforeach
              @if($inv['apps'])<span class="small muted">in {{ count($inv['apps']) === 1 ? $inv['apps'][0] : count($inv['apps']).' apps' }}</span>@endif
            @else<span class="muted">No access yet</span>@endif
          </td>
          <td class="muted">—</td>
          <td>@if($inv && $inv['expired'])<span class="badge bad">Invite expired</span>@else<span class="badge warn">Invite pending</span>@endif</td>
          <td class="pp-act">
            <form method="post" action="{{ route('admin.users.action', [$u->id, 'reinvite']) }}">@csrf<button class="btn sm" type="submit" aria-label="Resend invite to {{ $u->email }}">Resend</button></form>
          </td>
        </tr>
      @else
        @php($off = $u->status === 'disabled')
        <tr class="{{ $off ? 'pp-off' : '' }}">
          <td class="pp-sel"><label class="pp-ck"><input type="checkbox" name="ids[]" value="{{ $u->id }}" form="pp-bulk" data-bulk-row aria-label="Select {{ $u->name }}"></label></td>
          <td><a class="person" href="{{ route('admin.users.show', $u->id) }}"><span class="av {{ $off ? 'muted' : '' }}" aria-hidden="true">{{ $u->initials() }}</span>
            <span class="who"><b>{{ $u->name }}</b><span>{{ $u->email }}</span></span></a></td>
          <td>{{ $r['orgs'] ?: '—' }}</td>
          <td>
            @if($r['apps'])
              @foreach($r['badges'] as $b)<span class="badge {{ $b['tone'] }}">{{ $b['text'] }}</span> @endforeach
              <span class="small muted">in {{ $r['apps'] === 1 ? ($r['appNames'][0] ?? '1 app') : $r['apps'].' apps' }}</span>
            @else<span class="muted">No access</span>@endif
          </td>
          <td><span class="badge {{ $r['mfa']['tone'] }}">{{ $r['mfa']['text'] }}</span></td>
          <td>
            @if($off)<span class="badge bad">Turned off</span>
            @elseif($u->isLocked())<span class="badge bad" title="Locked until {{ $u->locked_until->format('H:i') }}">Locked</span>
            @else<span class="muted" @if($u->last_login_at) title="{{ $u->last_login_at->toDayDateTimeString() }}" @endif>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</span>@endif
          </td>
          <td class="pp-act">
            <details class="menu-d">
              <summary class="btn ghost icon" aria-label="More actions for {{ $u->name }}">@include('partials.icon', ['i' => 'more', 'iconSize' => 18])</summary>
              <div class="pp-menu" role="menu">
                <a role="menuitem" href="{{ route('admin.users.show', $u->id) }}">Access</a>
                <a role="menuitem" href="{{ route('admin.users.show', [$u->id, 'tab' => 'security']) }}">Security &amp; sessions</a>
                <a role="menuitem" href="{{ route('admin.users.show', [$u->id, 'tab' => 'activity']) }}">Activity</a>
                @if($u->id !== auth()->id() && ! ($u->isOwner() && ! auth()->user()->isOwner()))
                  @if($u->isLocked())<form method="post" action="{{ route('admin.users.action', [$u->id, 'unlock']) }}">@csrf<button role="menuitem" type="submit">Unlock</button></form>@endif
                  @if($off)
                    <form method="post" action="{{ route('admin.users.action', [$u->id, 'enable']) }}">@csrf<button role="menuitem" type="submit">Turn back on</button></form>
                  @else
                    <form method="post" action="{{ route('admin.users.action', [$u->id, 'disable']) }}" data-confirm="Turn off {{ $u->name }}? They are signed out of every app.">@csrf<button role="menuitem" type="submit" class="pp-danger">Turn off</button></form>
                  @endif
                @endif
              </div>
            </details>
          </td>
        </tr>
      @endif
    @empty
      <tr><td colspan="7"><div class="empty"><b>No one here yet</b><span>@if(request()->hasAny(['q', 'org', 'app']))Try a different search or filter.@else Invite people to get started.@endif</span></div></td></tr>
    @endforelse
    </tbody>
  </table>
</section>
@if($users->total())
  <nav class="pager" aria-label="Pages">
    <span>Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}</span>
    <div class="actions">
      @if($users->onFirstPage())<span class="btn disabled" aria-disabled="true">Previous</span>@else<a class="btn" href="{{ $users->previousPageUrl() }}" rel="prev">Previous</a>@endif
      @if($users->hasMorePages())<a class="btn" href="{{ $users->nextPageUrl() }}" rel="next">Next</a>@else<span class="btn disabled" aria-disabled="true">Next</span>@endif
    </div>
  </nav>
@endif

@include('admin.users.partials.invite')
@endsection
@push('end')<script src="/js/pages/people.js?v={{ filemtime(public_path('js/pages/people.js')) }}" defer></script>@endpush
