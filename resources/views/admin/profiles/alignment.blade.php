@extends('layouts.console')
@section('title', 'Who has what · '.$org->name)
@section('content')
<header class="head"><div><h1>Who has what</h1><p>Every person in {{ $org->name }} and their role in each app, with where it comes from.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.profiles.alignment.csv', $org->id) }}">Download CSV</a>
    <a class="btn" href="{{ route('admin.profiles.index', $org->id) }}">Access profiles</a></div></header>
<nav class="tabs">
  @foreach(['all' => 'Everyone', 'exceptions' => 'Exceptions', 'conflicts' => 'Profiles disagree', 'notset' => 'Profile not set', 'noprofile' => 'No profile'] as $k => $l)
    <a class="{{ $filter === $k ? 'on' : '' }}" href="{{ route('admin.profiles.alignment', [$org->id, 'filter' => $k]) }}">{{ $l }}</a>
  @endforeach
</nav>
<section class="card"><div class="scroll"><table class="t">
  <thead><tr><th scope="col">Person</th><th scope="col">Profiles</th>@foreach($apps as $a)<th scope="col">{{ $a->name }}</th>@endforeach</tr></thead>
  <tbody>
    @forelse($rows as $r)
      <tr>
        <td><a href="{{ route('admin.users.show', [$r['user']->id, 'org' => $org->id]) }}"><b>{{ $r['user']->name }}</b></a><br><span class="small muted">{{ $r['user']->email }}</span></td>
        <td>@forelse($r['profiles'] as $p)<span class="chip">{{ $p->name }}</span> @empty<span class="chip warn">none</span>@endforelse</td>
        @foreach($apps as $a)
          @php($c = $r['cells'][$a->id] ?? null)
          <td>
            @if($c)
              {{ $c['role']?->name ?? '—' }}
              @if($c['override'])<br><span class="chip warn" title="{{ $c['reason'] }}">exception</span>
              @elseif($c['role'])<br><span class="small muted">via {{ implode(', ', $c['via']) }}</span>@endif
              @if($c['conflict'])<br><span class="chip warn">profiles disagree</span>@endif
              @if($c['unmapped'])<br><span class="chip bad">profile not set</span>@endif
            @endif
          </td>
        @endforeach
      </tr>
    @empty<tr><td colspan="{{ $apps->count() + 2 }}" class="empty">Nobody matches.</td></tr>@endforelse
  </tbody>
</table></div></section>
@endsection
