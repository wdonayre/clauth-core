{{-- App › People: who has access in this app, by organization, with their role and where it comes from. --}}
@php($me = auth()->user())
@php($peopleOrgs = $app->organizations->filter(fn ($o) => $me->isOwner() || in_array($o->id, $me->devOrgIds(), true)))
@php($filtered = request('q') || request('org') || request('role'))
<form class="toolbar" method="get" action="{{ route('admin.apps.show', $app->key) }}" role="search">
  <input type="hidden" name="tab" value="people">
  <label class="searchin"><span class="sr-only">Search people</span>@include('partials.icon', ['i' => 'search', 'iconSize' => 16])
    <input class="in" type="search" name="q" value="{{ request('q') }}" placeholder="Search people"></label>
  <label><span class="sr-only">Organization</span><select class="in" name="org" data-autosubmit style="width:auto">
    <option value="">Organization: All</option>
    @foreach($peopleOrgs as $o)<option value="{{ $o->id }}" @selected(request('org') === $o->id)>{{ $o->name }}</option>@endforeach</select></label>
  <label><span class="sr-only">Role</span><select class="in" name="role" data-autosubmit style="width:auto">
    <option value="">Role: All</option>
    @foreach($app->roles as $r)<option value="{{ $r->id }}" @selected((string) request('role') === (string) $r->id)>{{ $r->name }}</option>@endforeach</select></label>
  <noscript><button class="btn">Filter</button></noscript>
  @if(Route::has('admin.users.index'))<a class="btn pri" href="{{ route('admin.users.index') }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Give someone access</a>@endif
</form>

<section class="card"><div class="scroll"><table class="t">
  <thead><tr><th scope="col">Person</th><th scope="col">Organization</th><th scope="col">Role</th><th scope="col">From</th><th scope="col">Last used</th></tr></thead>
  <tbody>
  @forelse($grants as $g)
    @php($from = $via[$g->user_id.'|'.$g->organization_id.'|'.$g->app_role_id] ?? [])
    @php($last = $lastUsed[$g->user_id] ?? null)
    <tr>
      <td>@if($g->user)@include('partials.ui.person', ['u' => $g->user, 'href' => route('admin.users.show', [$g->user_id, 'org' => $g->organization_id])])@else<span class="muted">Removed person</span>@endif</td>
      <td>{{ $g->organization?->name }}</td>
      <td><span class="badge {{ in_array($g->role?->tier, ['owner', 'admin'], true) ? 'info' : 'muted' }}">{{ $g->role?->name }}</span></td>
      <td>@if($g->source === 'profile')<span class="muted">{{ $from ? implode(', ', $from).' job role' : 'Job role' }}</span>@else<span class="badge warn">Set by hand</span>@endif</td>
      <td class="muted nowrap">{{ $last ? \Illuminate\Support\Carbon::parse($last)->diffForHumans() : 'Never' }}</td>
    </tr>
  @empty
    <tr><td colspan="5"><div class="empty">
      @if($filtered)<b>Nobody matches</b><span>Try another name, organization or role.</span><a class="btn sm" href="{{ $tabUrl('people') }}">Clear filters</a>
      @else<b>Nobody has access yet</b><span>Give people a role in {{ $app->name }} from their page under People, or map it to a job role.</span>@endif
    </div></td></tr>
  @endforelse
  </tbody>
</table></div></section>
@if($grants->isNotEmpty())<p class="small muted" style="margin:-8px 0 0">{{ $grants->count() }} of {{ $peopleCount }} shown. Only organizations you work in are listed.</p>@endif
