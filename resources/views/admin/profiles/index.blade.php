@extends('layouts.console')
@section('title', 'Job roles · '.$org->name)
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@push('end')<script src="/js/pages/org-sub.js?v={{ filemtime(public_path('js/pages/org-sub.js')) }}" defer></script>@endpush
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])

<div class="intro">
  <p>A job role gives someone the right role in every app at once. Give people a job role instead of setting each app by hand.</p>
  <div class="actions">
    <a class="btn" href="{{ route('admin.profiles.alignment', $org->id) }}">Who has what</a>
    <a class="btn" href="{{ route('admin.profiles.suggest', $org->id) }}">Suggest job roles @if($suggestionCount)<span class="badge info">{{ $suggestionCount }}</span>@endif</a>
    <button class="btn pri" type="button" data-open-dialog="#new-role">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New job role</button>
  </div>
</div>

@if($profiles->isNotEmpty())
  @foreach($unmapped as $u)
    <div class="flash info" role="status">@include('partials.icon', ['i' => 'info', 'iconSize' => 18])
      <span class="grow"><b>{{ $u['app']->name }}</b> isn't set in {{ $u['profiles']->count() }} {{ Str::plural('job role', $u['profiles']->count()) }} ({{ $u['profiles']->pluck('name')->implode(', ') }}). People with only those job roles get no access to it yet.
      <a href="{{ route('admin.profiles.app', [$org->id, $u['app']->key]) }}">Set {{ $u['app']->name }} for every profile</a></span></div>
  @endforeach
@endif

<div class="jr">
  <nav class="card jr-nav" aria-label="Job roles">
    @forelse($profiles as $p)
      @php($n = $mapped[$p->id] ?? 0)
      @php($on = $profile && $profile->id === $p->id)
      <a class="jr-item {{ $on ? 'on' : '' }}" href="{{ route('admin.profiles.show', [$org->id, $p->id]) }}" @if($on) aria-current="page" @endif>
        <span><b>{{ $p->name }}</b><span class="sub">{{ $p->users_count }} {{ Str::plural('person', $p->users_count) }}</span></span>
        <span class="badge {{ $n < $appCount ? 'warn' : 'ok' }}" title="Apps this job role sets">{{ $n }}/{{ $appCount }} apps set</span>
      </a>
    @empty
      <div class="empty"><b>No job roles yet</b><span>Make one for each job in {{ $org->name }}, like Owner, Finance or Production.</span></div>
    @endforelse
    @if($suggestion)
      <div class="jr-sug">
        <span class="badge warn">Suggested</span>
        <span>{{ $suggestion['people']->count() }} {{ Str::plural('person', $suggestion['people']->count()) }} share the same roles. Make a “{{ $suggestion['name'] }}” job role?</span>
        <form method="post" action="{{ route('admin.profiles.suggest.accept', $org->id) }}">@csrf
          <input type="hidden" name="key" value="{{ $suggestion['key'] }}"><input type="hidden" name="name" value="{{ $suggestion['name'] }}">
          <button class="btn sm" @disabled($suggestion['blocked'])>Create it</button>
          @if($suggestionCount > 1)<a class="small" href="{{ route('admin.profiles.suggest', $org->id) }}">See all {{ $suggestionCount }}</a>@endif
        </form>
      </div>
    @endif
  </nav>

  @if($profile)
    @include('admin.profiles.show')
  @else
    <section class="card jr-main"><div class="empty">
      <b>Start with your first job role</b>
      <span>Each job role picks one role in every app {{ $org->name }} uses.</span>
      <button class="btn pri" type="button" data-open-dialog="#new-role">New job role</button>
    </div></section>
  @endif
</div>

<dialog class="dlg" id="new-role" aria-labelledby="new-role-h" @if($errors->has('name') && old('_form') === 'new-role') data-open-on-load @endif>
  <form method="post" action="{{ route('admin.profiles.store', $org->id) }}">@csrf <input type="hidden" name="_form" value="new-role">
    <div class="dlg-h"><h2 id="new-role-h">New job role</h2><p>Then pick the role it gives in each app.</p></div>
    <div class="dlg-b">
      <label class="f"><span>Name</span><input class="in" name="name" placeholder="Finance" required maxlength="80" value="{{ old('_form') === 'new-role' ? old('name') : '' }}"></label>
      <label class="f"><span>Description</span><input class="in" name="description" placeholder="Pays bills, approves claims, runs reports" maxlength="190" value="{{ old('_form') === 'new-role' ? old('description') : '' }}"></label>
      <p class="hint" style="margin:0">People can hold more than one job role. When two give different roles in the same app, the higher tier wins.</p>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Create job role</button></div>
  </form>
</dialog>
@endsection
