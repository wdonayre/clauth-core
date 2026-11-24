@extends('layouts.console')
@section('title', 'Who has what · '.$org->name)
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@push('end')<script src="/js/pages/org-sub.js?v={{ filemtime(public_path('js/pages/org-sub.js')) }}" defer></script>@endpush
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])

<div class="intro">
  <p>Every person's role in every {{ $org->name }} app, in one view.</p>
  <div class="actions">
    <a class="btn" href="{{ route('admin.profiles.alignment.csv', $org->id) }}">@include('partials.icon', ['i' => 'download', 'iconSize' => 16])Download CSV</a>
  </div>
</div>

<form class="toolbar" method="get" action="{{ route('admin.profiles.alignment', $org->id) }}" role="search">
  <label class="searchin"><span class="sr-only">Search people</span>@include('partials.icon', ['i' => 'search', 'iconSize' => 16])
    <input class="in" type="search" name="q" value="{{ $q }}" placeholder="Search by name, email or job role" data-filter-rows="#mx tbody tr"></label>
  <input type="hidden" name="filter" value="{{ $filter }}">
  <nav class="seg" aria-label="Show">
    @foreach(['all' => 'Everyone', 'exceptions' => 'Exceptions (set by hand)', 'conflicts' => 'Job roles disagree', 'notset' => 'Job role not set', 'noprofile' => 'No job role'] as $k => $l)
      <a class="{{ $filter === $k ? 'on' : '' }}" href="{{ route('admin.profiles.alignment', array_filter([$org->id, 'filter' => $k === 'all' ? null : $k, 'q' => $q ?: null])) }}" @if($filter === $k) aria-current="page" @endif>{{ $l }}</a>
    @endforeach
  </nav>
</form>

<div class="legend" aria-label="Legend">
  <span><span class="badge info">Role</span> from a job role</span>
  <span><span class="badge warn">Role</span> set by hand</span>
  <span><span class="none">—</span> no access</span>
  <span><span class="badge bad">job role not set</span> a job role doesn’t say yet</span>
</div>

<div class="card scroll">
  <table class="t mx" id="mx">
    <thead><tr><th scope="col">Person</th><th scope="col">Job role</th>@foreach($apps as $a)<th scope="col"><span style="display:inline-flex;align-items:center;gap:8px">@include('partials.ui.app-icon', ['app' => $a, 'size' => 'sm']){{ $a->name }}</span></th>@endforeach</tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr data-row-text="{{ mb_strtolower($r['user']->name.' '.$r['user']->email.' '.$r['profiles']->pluck('name')->implode(' ')) }}">
          <td>@include('partials.ui.person', ['u' => $r['user'], 'href' => route('admin.users.show', [$r['user']->id, 'org' => $org->id])])</td>
          <td class="{{ $r['profiles']->isEmpty() ? 'none' : '' }}">{{ $r['profiles']->isEmpty() ? '—' : $r['profiles']->pluck('name')->implode(', ') }}</td>
          @foreach($apps as $a)
            @php($c = $r['cells'][$a->id] ?? null)
            @if(! $c || (! $c['role'] && ! $c['override'] && ! $c['conflict'] && ! $c['unmapped']))
              <td class="none">—</td>
            @else
              <td><div class="cell">
                @if($c['role'])
                  @if($c['override'])<span class="badge warn" title="Exception, set by hand{{ $c['reason'] ? ': '.$c['reason'] : '' }}">{{ $c['role']->name }}</span>
                  @else<span class="badge info" title="From {{ implode(', ', $c['via']) }}">{{ $c['role']->name }}</span>@endif
                @elseif($c['override'])<span class="badge warn" title="Exception, set by hand{{ $c['reason'] ? ': '.$c['reason'] : '' }}">No access</span>
                @else<span class="none">—</span>@endif
                @if($c['conflict'] || $c['unmapped'])
                  <span class="flags">
                    @if($c['conflict'])<span class="badge warn">job roles disagree</span>@endif
                    @if($c['unmapped'])<span class="badge bad">job role not set</span>@endif
                  </span>
                @endif
              </div></td>
            @endif
          @endforeach
        </tr>
      @empty
        <tr><td colspan="{{ $apps->count() + 2 }}"><div class="empty"><b>Nobody matches</b><span>{{ $total }} {{ Str::plural('person', $total) }} in {{ $org->name }}. Try another filter.</span></div></td></tr>
      @endforelse
    </tbody>
  </table>
</div>
<p class="hint" style="margin:0"><span data-filter-count>Showing {{ $rows->count() }} of {{ $total }} {{ Str::plural('person', $total) }}.</span> Exceptions are roles set by hand on a person's page; they win over job roles.</p>
@endsection
