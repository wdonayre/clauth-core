@extends('layouts.console')
@section('title', 'Overview')
@push('head')<link rel="stylesheet" href="/css/pages/overview.css?v={{ filemtime(public_path('css/pages/overview.css')) }}">@endpush
@push('end')<script src="/js/pages/overview.js?v={{ filemtime(public_path('js/pages/overview.js')) }}" defer></script>@endpush
@section('content')
<header class="head">
  <div>
    <h1 id="greeting" data-name="{{ \Illuminate\Support\Str::after($greeting, ', ') }}">{{ $greeting }}</h1>
    <p>Here’s what’s happening across your apps today.</p>
  </div>
  <div class="actions">
    @if($canApps)<a class="btn" href="{{ route('admin.apps.index') }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Connect an app</a>@endif
    @if($canPeople)<a class="btn pri" href="{{ route('admin.users.index', ['invite' => 1]) }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Invite people</a>@endif
  </div>
</header>

<section class="stats" aria-label="Key numbers">
  @foreach($stats as $s)
    @php($tag = $s['href'] ? 'a' : 'div')
    <{{ $tag }} class="card stat" @if($s['href']) href="{{ $s['href'] }}" @endif>
      <span>{{ $s['label'] }}</span><b>{{ number_format($s['n']) }}</b><span class="sub {{ $s['tone'] ? $s['tone'].'-t' : '' }}">{{ $s['sub'] }}</span>
    </{{ $tag }}>
  @endforeach
</section>

<div class="grid2">
  <section class="card att" aria-labelledby="att-h">
    <div class="card-h"><h2 id="att-h">Needs your attention</h2>@if($attention)<span class="badge {{ collect($attention)->contains('tone', 'danger') ? 'bad' : 'warn' }}">{{ count($attention) }}</span>@endif</div>
    <div class="list">
      @forelse($attention as $a)
        <div class="row">
          <span class="av {{ ['danger' => 'bad', 'warn' => 'warn', 'info' => 'muted'][$a['tone']] }}" aria-hidden="true">{{ $a['glyph'] }}</span>
          <div class="grow"><div class="t">{{ $a['title'] }}</div>@if($a['sub'])<div class="sub">{{ $a['sub'] }}</div>@endif</div>
          <a class="btn" href="{{ $a['href'] }}">{{ $a['action'] }}<span class="sr-only">: {{ $a['title'] }}</span></a>
        </div>
      @empty
        <div class="empty"><b>All clear</b><span>Nothing needs you right now.</span></div>
      @endforelse
    </div>
  </section>

  <section class="card" aria-labelledby="apps-h">
    <div class="card-h"><h2 id="apps-h">Your apps</h2>@if($canApps)<a class="strong" href="{{ route('admin.apps.index') }}">View all</a>@endif</div>
    <div class="list">
      @forelse($apps as $a)
        @php($open = $canApps && auth()->user()->canManageApp($a))
        @php($tag = $open ? 'a' : 'div')
        <{{ $tag }} class="row" @if($open) href="{{ route('admin.apps.show', $a->key) }}" @endif>
          @include('partials.ui.app-icon', ['app' => $a])
          <div class="grow"><div class="strong">{{ $a->name }}</div>
            <div class="sub">{{ $a->grants_count }} {{ $a->grants_count === 1 ? 'person' : 'people' }} · {{ $signins[$a->id] ?? 0 }} signed in today</div></div>
          <span class="badge dot {{ ['live' => 'ok', 'paused' => 'warn'][$a->status] ?? 'muted' }}">{{ ['live' => 'Live', 'paused' => 'Paused', 'not_connected' => 'Not connected'][$a->status] ?? \Illuminate\Support\Str::headline($a->status) }}</span>
        </{{ $tag }}>
      @empty
        <div class="empty"><b>No apps yet</b><span>Connect an app so people can sign in to it with Clauth.</span></div>
      @endforelse
    </div>
  </section>
</div>

<section class="card feed" aria-labelledby="act-h">
  <div class="card-h"><h2 id="act-h">Recent activity</h2>@if($canPeople)<a class="strong" href="{{ route('admin.audit') }}">See all activity</a>@endif</div>
  <div class="list">
    @forelse($events as $e)@include('admin.partials.event', ['e' => $e, 'style' => 'feed'])@empty<div class="empty"><b>Nothing yet</b><span>Sign-ins and changes show up here.</span></div>@endforelse
  </div>
</section>
@endsection
