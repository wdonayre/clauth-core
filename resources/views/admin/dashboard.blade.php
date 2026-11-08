@extends('layouts.console')
@section('title', 'Overview')
@section('content')
<header class="head">
  <div><h1>Overview</h1><p>Everyone and every app that signs in through Clauth.</p></div>
  <div class="actions">
    @if(auth()->user()->isOwner())<a class="btn" href="{{ route('admin.apps.index') }}">Register app</a>@endif
    <a class="btn pri" href="{{ route('admin.users.index', ['invite' => 1]) }}">@include('partials.icon', ['i' => 'plus'])Invite user</a>
  </div>
</header>
<section class="stats" aria-label="Key numbers">
  @foreach($stats as $label => $n)<div class="card stat"><span class="lbl">{{ $label }}</span><b>{{ $n }}</b></div>@endforeach
</section>
<div class="grid2">
  <section class="card" aria-labelledby="apps-h">
    <div class="card-h"><h2 id="apps-h">Apps</h2>@if(auth()->user()->isOwner())<a class="small" href="{{ route('admin.apps.index') }}">All apps</a>@endif</div>
    <div class="list">
      @forelse($apps as $a)
        <div class="row"><span class="ico" style="background:{{ $a->color }}">{{ $a->letter() }}</span>
          <div class="grow"><b>{{ $a->name }}</b><br><span class="small muted">{{ $a->grants_count }} people · last token {{ $a->last_token_at?->diffForHumans() ?? 'never' }}</span></div>
          <span class="chip {{ ['live' => 'ok', 'paused' => 'warn'][$a->status] ?? '' }}">{{ ['live' => 'Live', 'paused' => 'Paused', 'not_connected' => 'Not connected'][$a->status] }}</span>
        </div>
      @empty<div class="empty">No apps yet.</div>@endforelse
    </div>
  </section>
  <section class="card" aria-labelledby="att-h">
    <div class="card-h"><h2 id="att-h">Needs attention</h2></div>
    <div class="list">
      @forelse($attention as $a)
        <div class="row"><span class="chip {{ ['warn' => 'warn', 'danger' => 'bad', 'info' => 'info'][$a['tone']] }}">{{ ['warn' => 'Soon', 'danger' => 'Now', 'info' => 'Info'][$a['tone']] }}</span>
          <a class="grow" href="{{ $a['href'] }}" style="color:#E8EDF2">{{ $a['text'] }}</a></div>
      @empty<div class="empty">All clear.</div>@endforelse
    </div>
  </section>
</div>
<section class="card" aria-labelledby="act-h">
  <div class="card-h"><h2 id="act-h">Recent activity</h2><a class="small" href="{{ route('admin.audit') }}">Audit log</a></div>
  <div class="list">
    @forelse($events as $e)@include('admin.partials.event', ['e' => $e])@empty<div class="empty">Nothing yet.</div>@endforelse
  </div>
</section>
@endsection
