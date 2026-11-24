@extends('layouts.console')
@section('title', 'Themes · '.($org?->name ?? 'Platform'))
@push('head')<link rel="stylesheet" href="/css/pages/apps.css?v={{ filemtime(public_path('css/pages/apps.css')) }}">
@php($v = $edit?->settings ?? [])
@php($fontsUsed = collect([$v['font'] ?? 'Inter', $v['heading_font'] ?? null])->filter()->unique()->reject(fn ($f) => $f === 'Inter'))
@if($fontsUsed->isNotEmpty())<link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $fontsUsed->map(fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@400;600;700')->join('&') }}&display=swap">@endif
@endpush
@section('content')
@php($v = $edit?->settings ?? [])
<nav class="crumbs" aria-label="Breadcrumb">
  <a href="{{ route('admin.orgs.index') }}">Organizations</a><span aria-hidden="true">/</span>
  @if($org)<a href="{{ Route::has('admin.orgs.show') ? route('admin.orgs.show', $org->id) : route('admin.orgs.index') }}">{{ $org->name }}</a>@else<span>Platform</span>@endif
  <span aria-hidden="true">/</span><span>Themes</span>
</nav>
<header class="head">
  <div><h1>Themes</h1>
    <p>How {{ $org?->name ?? 'every organization' }}’s sign-in pages look: colours, font, corners, buttons, and a split layout with your name and a tagline. Pick a theme per app under App › Sign-in page.</p></div>
  @if($edit)<div class="actions"><a class="btn pri" href="{{ route('admin.themes.index', $orgKey) }}">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])New theme</a></div>@endif
</header>

<div class="themes-wrap">
  <section class="card" aria-labelledby="th-list">
    <div class="card-h"><div><h2 id="th-list">Themes <span class="muted" style="font-weight:500">{{ $themes->count() }}</span></h2><p>Clauth Classic, Daylight and Midnight are always there too.</p></div></div>
    <div class="list">
      @forelse($themes as $t)
        @php($u = \App\Services\Themes::usages($t))
        @php($n = count($u['apps']) + count($u['domains']))
        <a class="row theme-row" href="{{ route('admin.themes.index', [$orgKey, 'edit' => $t->id]) }}" @if($edit?->id === $t->id) aria-current="true" style="background:var(--soft)" @endif>
          <span class="sw-prev" style="background:{{ $t->s('background', $t->s('base', 'midnight') === 'daylight' ? '#F7F8FA' : '#0B0D12') }}" aria-hidden="true"><i style="background:{{ $t->s('accent', '#22D3EE') }}"></i></span>
          <div class="grow"><b>{{ $t->name }}</b> <span class="small muted mono">{{ $t->key }}</span>
            <div class="sub">{{ $bases[$t->s('base', 'classic')] }} · {{ $t->s('font', 'Inter') }} · {{ $t->s('layout', 'center') === 'split' ? 'split' : 'centered' }}</div></div>
          <span class="badge {{ $n ? 'info' : 'muted' }}">{{ $n ? 'Used by '.$n : 'Not used' }}</span>
        </a>
      @empty<div class="empty"><b>No themes yet</b><span>Make one on the right. The built-in styles are always available.</span></div>@endforelse
    </div>
  </section>

  <form class="card" method="post" action="{{ $edit ? route('admin.themes.update', [$orgKey, $edit->id]) : route('admin.themes.store', $orgKey) }}" data-theme-form>@csrf
    @if($edit)@method('put')@endif
    <div class="card-h"><div><h2>{{ $edit ? 'Edit '.$edit->name : 'New theme' }}</h2>
      @if($edit)@php($u = \App\Services\Themes::usages($edit))<p>Key <span class="mono">{{ $edit->key }}</span> · used by {{ implode(', ', array_merge($u['apps'], $u['domains'])) ?: 'nothing yet' }}</p>@else<p>Start from a built-in style and change what you need.</p>@endif</div></div>

    <div class="set" style="padding-top:4px">
      <div class="tprev" data-theme-preview style="flex:1 1 100%">
        <div class="tcard"><b>Sign in to {{ $org?->name ?? 'your app' }}</b><span data-tagline class="small" hidden></span>
          <span class="tin">Email</span><span class="tin">Password</span><span class="tbtn">Continue</span></div>
      </div>
    </div>

    <div class="fields">
      <div class="f"><label for="tn">Name</label><input class="in" id="tn" name="name" value="{{ old('name', $edit?->name) }}" required maxlength="80" placeholder="Cakes by Grace pink">@error('name')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="tb">Start from</label><select class="in" id="tb" name="base">@foreach($bases as $k => $l)<option value="{{ $k }}" @selected(old('base', $v['base'] ?? 'midnight') === $k)>{{ $l }}</option>@endforeach</select></div>
      @foreach(['accent' => 'Accent', 'background' => 'Page background', 'card' => 'Card'] as $k => $l)
        <div class="f"><label for="t{{ $k }}">{{ $l }}</label>
          <div class="colorin"><input type="color" value="{{ old($k, $v[$k] ?? '') ?: '#22D3EE' }}" aria-label="Pick {{ strtolower($l) }} colour" data-color-for="#t{{ $k }}">
            <input class="in mono" id="t{{ $k }}" name="{{ $k }}" value="{{ old($k, $v[$k] ?? '') }}" placeholder="Built-in" pattern="#[0-9A-Fa-f]{6}"></div>
          @error($k)<span class="err">{{ $message }}</span>@enderror</div>
      @endforeach
      <div class="f"><label for="tr">Corner radius (px)</label><input class="in" id="tr" type="number" name="radius" min="0" max="28" value="{{ old('radius', $v['radius'] ?? 12) }}"></div>
      <div class="f"><label for="tf">Font</label><select class="in" id="tf" name="font">@foreach($fonts as $f)<option @selected(old('font', $v['font'] ?? 'Inter') === $f)>{{ $f }}</option>@endforeach</select></div>
      <div class="f"><label for="th">Headings</label><select class="in" id="th" name="heading_font"><option value="">Same as text</option>@foreach($fonts as $f)<option @selected(old('heading_font', $v['heading_font'] ?? '') === $f)>{{ $f }}</option>@endforeach</select></div>
      <div class="f"><label for="tbt">Buttons</label><select class="in" id="tbt" name="buttons">@foreach(['rounded' => 'Rounded', 'pill' => 'Pill', 'square' => 'Square'] as $k => $l)<option value="{{ $k }}" @selected(old('buttons', $v['buttons'] ?? 'rounded') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="f"><label for="tl">Layout</label><select class="in" id="tl" name="layout"><option value="center" @selected(old('layout', $v['layout'] ?? 'center') === 'center')>Centered card</option><option value="split" @selected(old('layout', $v['layout'] ?? '') === 'split')>Split: your name on the left</option></select></div>
      <div class="f"><label for="tt">Tagline <span class="hint">— split layout</span></label><input class="in" id="tt" name="tagline" maxlength="120" value="{{ old('tagline', $v['tagline'] ?? '') }}" placeholder="Fresh cakes, every day."></div>
    </div>
    @if($errors->any() && ! $errors->hasAny(['name', 'accent', 'background', 'card']))<div class="flash bad" style="margin:0 22px 16px">{{ $errors->first() }}</div>@endif
    <div class="card-f" style="justify-content:space-between">
      <span>@if($edit)<button class="btn danger" type="submit" form="del-theme">Delete theme</button>@endif</span>
      <span class="actions">@if($edit)<a class="btn" href="{{ route('admin.themes.index', $orgKey) }}">Cancel</a>@endif<button class="btn pri">{{ $edit ? 'Save theme' : 'Create theme' }}</button></span>
    </div>
  </form>
</div>
@if($edit)
  <form id="del-theme" method="post" action="{{ route('admin.themes.destroy', [$orgKey, $edit->id]) }}" data-confirm="Delete {{ $edit->name }}? Apps using it go back to their built-in style." hidden>@csrf @method('delete')</form>
@endif
@endsection
@push('end')<script src="/js/pages/apps.js?v={{ filemtime(public_path('js/pages/apps.js')) }}" defer></script>@endpush
