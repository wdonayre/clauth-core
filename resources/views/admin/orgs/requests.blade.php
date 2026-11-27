@extends('layouts.console')
@section('title', 'Organization requests')
@include('admin.orgs._assets')
@section('content')
<div class="crumbs"><a href="{{ route('admin.orgs.index') }}">Organizations</a><span aria-hidden="true">/</span><span>Requests</span></div>
<header class="head">
  <div><h1>Organization requests</h1><p>People who signed up and want to set up an organization. Approving creates it and makes them its Owner.</p></div>
</header>
@include('partials.errors')

<nav class="seg" aria-label="Show">
  @foreach(\App\Http\Controllers\Admin\OrgRequestController::TABS as $k => $label)
    <a class="{{ $tab === $k ? 'on' : '' }}" @if($tab === $k) aria-current="page" @endif href="{{ route('admin.org-requests.index', ['tab' => $k]) }}">{{ $label }} · {{ $counts[$k] }}</a>
  @endforeach
</nav>

<div class="card scroll">
  <table class="t">
    <thead><tr><th scope="col">Organization</th><th scope="col">Asked by</th><th scope="col">Checks</th><th scope="col">Came from</th><th scope="col">{{ $tab === 'waiting' || $tab === 'info' ? 'Sent' : 'Decided' }}</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
    <tbody>
      @forelse($requests as $r)
        @php($c = $checksFor($r))
        <tr>
          <td><b>{{ $r->org_name }}</b><div class="small muted mono">{{ $r->slug }}@if($r->team_size) · {{ $r->sizeLabel() }} people @endif</div></td>
          <td>{{ $r->contact_name }}<div class="small muted">{{ $r->contact_email }}</div></td>
          <td class="small">
            @if($c['free'])<div class="warn-t">! Personal email</div>@elseif($c['match'] === true)<div class="ok-t">✓ Email matches website</div>@elseif($c['match'] === false)<div class="warn-t">! Email not on the website’s domain</div>@else<div class="warn-t">! No website</div>@endif
            @unless($c['slug'])<div class="bad-t">✕ Short name taken</div>@endunless
            @if($r->status === 'info_requested')<div class="muted">Waiting for their answer</div>@endif
          </td>
          <td>{{ $r->sourceApp?->name ?? ($r->user_id ? 'Clauth sign-up' : 'Public form') }}</td>
          <td class="nowrap">{{ ($r->decided_at ?? $r->updated_at)->diffForHumans() }}@if(in_array($r->status, ['cancelled', 'expired'], true))<div class="small muted">{{ ucfirst($r->status) }}</div>@endif</td>
          <td><a class="btn sm" href="{{ route('admin.org-requests.show', $r->id) }}">{{ $r->status === 'pending' ? 'Review' : 'Open' }}</a></td>
        </tr>
      @empty
        <tr><td colspan="6" class="muted">Nothing here.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
{{ $requests->links() }}
<p class="small muted">Requests with no answer from either side for {{ \App\Models\OrganizationRequest::EXPIRES_DAYS }} days close by themselves, and the person is told.</p>
@endsection
