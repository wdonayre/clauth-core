@extends('layouts.console')
@section('title', $org->name.' · People')
@include('admin.orgs._assets')
@section('org-actions')
  <a class="btn pri" href="{{ route('admin.users.index', ['invite' => 1, 'org' => $org->id]) }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Invite people</a>
@endsection
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])
@include('admin.orgs._meta')

@if($accessRequests->isNotEmpty())
  <section class="card pad acc-reqs" aria-labelledby="ar-h">
    <h2 id="ar-h" style="font-size:15px">{{ $accessRequests->count() === 1 ? '1 person asked for access' : $accessRequests->count().' people asked for access' }}</h2>
    <p class="small muted" style="margin:0">They have a Clauth account but aren’t in {{ $org->name }}. Approving adds them as a member with the role you pick.</p>
    @foreach($accessRequests as $ar)
      <div class="acc-req">
        <div class="grow"><b>{{ $ar->user->name }}</b> <span class="muted small">{{ $ar->user->email }}</span><div class="small">wants <b>{{ $ar->app->name }}</b> · {{ $ar->created_at->diffForHumans() }}@if($ar->note) · “{{ $ar->note }}”@endif</div></div>
        <form method="post" action="{{ route('admin.orgs.access-requests.approve', [$org->id, $ar->id]) }}" class="acc-f">@csrf
          <label class="sr-only" for="ar-role-{{ $ar->id }}">Role in {{ $ar->app->name }}</label>
          <select class="in" id="ar-role-{{ $ar->id }}" name="role" required>@foreach($ar->app->roles->where('assignable', true)->where('tier', '!=', 'owner') as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select>
          <button class="btn sm pri" type="submit">Approve</button>
        </form>
        <form method="post" action="{{ route('admin.orgs.access-requests.decline', [$org->id, $ar->id]) }}" data-confirm="Decline {{ $ar->user->name }}’s request?">@csrf<button class="btn sm" type="submit">Decline</button></form>
      </div>
    @endforeach
  </section>
@endif

<div class="org-split">
  <div class="main" style="display:flex;flex-direction:column;gap:12px">
    <form class="toolbar" method="get" action="{{ route('admin.orgs.show', $org->id) }}" role="search">
      <label class="searchin"><span class="sr-only">Search people</span>@include('partials.icon', ['i' => 'search', 'iconSize' => 16])<input class="in" type="search" name="q" value="{{ $q }}" placeholder="Search by name or email"></label>
      <label class="sr-only" for="role-f">Role in this organization</label>
      <select class="in" id="role-f" name="role" data-autosubmit style="max-width:200px">
        <option value="">Everyone</option>
        @foreach($roles as $k => $label)<option value="{{ $k }}" @selected($role === $k)>{{ Str::plural($label) }}</option>@endforeach
      </select>
      <noscript><button class="btn">Filter</button></noscript>
    </form>

    <div class="card scroll">
      <table class="t people-t">
        <thead><tr><th scope="col">Person</th><th scope="col">In this organization</th><th scope="col">Job role</th><th scope="col">Apps</th></tr></thead>
        <tbody>
          @forelse($people as $u)
            @php($r = $u->pivot->role)
            <tr>
              <td>@include('partials.ui.person', ['u' => $u, 'href' => route('admin.users.show', [$u->id, 'org' => $org->id])])
                @if($u->pivot->deactivated_at)<span class="badge muted" style="margin-left:44px">Deactivated</span>@endif</td>
              <td>
                @if($canRoles)
                  <form method="post" action="{{ route('admin.orgs.roles', $org->id) }}">@csrf
                    <input type="hidden" name="email" value="{{ $u->email }}">
                    <label class="sr-only" for="role-{{ $u->id }}">Role of {{ $u->name }} in {{ $org->name }}</label>
                    <select class="in rolesel" id="role-{{ $u->id }}" name="role" data-autosubmit>
                      @foreach($roles as $k => $label)<option value="{{ $k }}" @selected($r === $k)>{{ $label }}</option>@endforeach
                    </select>
                    <noscript><button class="btn sm">Save</button></noscript>
                  </form>
                @else
                  <span class="badge {{ in_array($r, ['owner', 'admin'], true) ? 'info' : 'muted' }}">{{ $roles[$r] ?? $r }}</span>
                @endif
              </td>
              <td>@if($jobRoles->has($u->id)){{ $jobRoles[$u->id]->pluck('name')->implode(', ') }}@else<span class="muted">—</span>@endif</td>
              <td>{{ $appCounts[$u->id] ?? 0 }}</td>
            </tr>
          @empty
            <tr><td colspan="4"><div class="empty">@if($q !== '' || $role)<b>No one matches</b><span>Try another name or role.</span>@else<b>No one here yet</b><span>Invite people to {{ $org->name }}.</span>@endif</div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($people->hasPages())
      <div class="pager"><span>{{ $people->firstItem() }}–{{ $people->lastItem() }} of {{ $people->total() }}</span>
        <nav aria-label="Pages">@if($people->previousPageUrl())<a class="btn sm" href="{{ $people->previousPageUrl() }}">Previous</a>@endif @if($people->nextPageUrl())<a class="btn sm" href="{{ $people->nextPageUrl() }}">Next</a>@endif</nav></div>
    @endif
    @if($canRoles)<p class="small muted" style="margin:0">Admins manage people and installed apps. Developers manage this organization’s own apps. Owners can also change roles.</p>@endif
  </div>

  <aside>
    <section class="card" aria-labelledby="jr-h">
      <div class="card-h"><h2 id="jr-h" style="font-size:15px">Job roles</h2>@if(Route::has('admin.profiles.index'))<a href="{{ route('admin.profiles.index', $org->id) }}" style="font-weight:500">Manage</a>@endif</div>
      <p class="small muted" style="margin:0;padding:0 18px 12px">A job role gives someone the right role in every app at once.</p>
      @forelse($profiles->take(6) as $p)
        <a class="jr" href="{{ route('admin.profiles.show', [$org->id, $p->id]) }}"><span><b style="font-weight:500">{{ $p->name }}</b><span class="sub" style="display:block">Roles in {{ $appsPer[$p->id] ?? 0 }} {{ Str::plural('app', $appsPer[$p->id] ?? 0) }}</span></span>
          <span class="badge muted">{{ $p->users_count }} {{ $p->users_count === 1 ? 'person' : 'people' }}</span></a>
      @empty
        <div class="jr"><span class="sub">No job roles yet.</span></div>
      @endforelse
    </section>
    @if($suggestion && Route::has('admin.profiles.suggest.accept'))
      @php($n = $suggestion['people']->count())
      <section class="card pad sug-card" aria-labelledby="sug-h">
        <b id="sug-h">Suggestion</b>
        <span class="small muted">{{ $n }} {{ $n === 1 ? 'person has' : 'people have' }} the same app roles by hand. Make them a job role?</span>
        <form method="post" action="{{ route('admin.profiles.suggest.accept', $org->id) }}">@csrf
          <input type="hidden" name="key" value="{{ $suggestion['key'] }}"><input type="hidden" name="name" value="{{ $suggestion['name'] }}">
          <button class="btn" @disabled($suggestion['blocked'])>Create “{{ $suggestion['name'] }}”</button>
          @if($suggestionCount > 1 && Route::has('admin.profiles.suggest'))<a class="small" href="{{ route('admin.profiles.suggest', $org->id) }}">See all {{ $suggestionCount }}</a>@endif
        </form>
        @if($suggestion['blocked'])<span class="small muted">{{ $suggestion['blocked'] }}</span>@endif
      </section>
    @endif
  </aside>
</div>
@endsection
