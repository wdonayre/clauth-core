@extends('layouts.console')
@section('title', 'Themes · '.($org?->name ?? 'Platform'))
@section('content')
<header class="head"><div><h1>Themes</h1>
  <p>Design how {{ $org?->name ?? 'every organization' }}’s sign-in pages look: colors, font, corners, buttons, and a split layout with your name and a tagline. Pick a theme per app under App › Sign-in page, or per sign-in domain.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>
@php($v = $edit?->settings ?? [])
<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Themes ({{ $themes->count() }})</h2></div>
    <div class="list">
      @forelse($themes as $t)
        @php($u = \App\Services\Themes::usages($t))
        <a class="row" href="{{ route('admin.themes.index', [$orgKey, 'edit' => $t->id]) }}">
          <span class="ico" style="background:{{ $t->s('accent', '#22D3EE') }}"></span>
          <div class="grow"><b>{{ $t->name }}</b> <span class="small muted mono">{{ $t->key }}</span><br>
            <span class="small muted">{{ $bases[$t->s('base', 'classic')] }} · {{ $t->s('font', 'Inter') }} · {{ $t->s('layout', 'center') === 'split' ? 'split' : 'centered' }} · used by {{ count($u['apps']) + count($u['domains']) }}</span></div>
        </a>
      @empty<div class="empty">No themes yet. The built-in styles (Clauth Classic, Daylight, Midnight) are always there.</div>@endforelse
    </div>
  </section>

  <form class="card pad form" method="post" action="{{ $edit ? route('admin.themes.update', [$orgKey, $edit->id]) : route('admin.themes.store', $orgKey) }}">@csrf
    @if($edit)@method('put')@endif
    <h2>{{ $edit ? 'Edit '.$edit->name : 'New theme' }}</h2>
    <div class="f"><label for="tn">Name</label><input class="in" id="tn" name="name" value="{{ old('name', $edit?->name) }}" required maxlength="80" placeholder="Cakes by Grace pink"></div>
    <div class="f"><label for="tb">Start from</label><select class="in" id="tb" name="base">@foreach($bases as $k => $l)<option value="{{ $k }}" @selected(($v['base'] ?? 'midnight') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="grid2" style="gap:10px">
      @foreach(['accent' => 'Accent', 'background' => 'Page background', 'card' => 'Card'] as $k => $l)
        <div class="f"><label for="t{{ $k }}">{{ $l }}</label><input class="in mono" id="t{{ $k }}" name="{{ $k }}" value="{{ old($k, $v[$k] ?? '') }}" placeholder="#RRGGBB" pattern="#[0-9A-Fa-f]{6}"></div>
      @endforeach
      <div class="f"><label for="tr">Corner radius (px)</label><input class="in" id="tr" type="number" name="radius" min="0" max="28" value="{{ old('radius', $v['radius'] ?? 12) }}"></div>
      <div class="f"><label for="tf">Font</label><select class="in" id="tf" name="font">@foreach($fonts as $f)<option @selected(($v['font'] ?? 'Inter') === $f)>{{ $f }}</option>@endforeach</select></div>
      <div class="f"><label for="th">Headings</label><select class="in" id="th" name="heading_font"><option value="">Same as text</option>@foreach($fonts as $f)<option @selected(($v['heading_font'] ?? '') === $f)>{{ $f }}</option>@endforeach</select></div>
      <div class="f"><label for="tbt">Buttons</label><select class="in" id="tbt" name="buttons">@foreach(['rounded' => 'Rounded', 'pill' => 'Pill', 'square' => 'Square'] as $k => $l)<option value="{{ $k }}" @selected(($v['buttons'] ?? 'rounded') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="f"><label for="tl">Layout</label><select class="in" id="tl" name="layout"><option value="center" @selected(($v['layout'] ?? 'center') === 'center')>Centered card</option><option value="split" @selected(($v['layout'] ?? '') === 'split')>Split: your name on the left</option></select></div>
    </div>
    <div class="f"><label for="tt">Tagline (split layout)</label><input class="in" id="tt" name="tagline" maxlength="120" value="{{ old('tagline', $v['tagline'] ?? '') }}" placeholder="Fresh cakes, every day."></div>
    @if($errors->any())<div class="small bad">{{ $errors->first() }}</div>@endif
    <div class="actions"><button class="btn pri">{{ $edit ? 'Save theme' : 'Create theme' }}</button>
      @if($edit)<a class="btn" href="{{ route('admin.themes.index', $orgKey) }}">New theme</a>@endif</div>
    @if($edit)
      @php($u = \App\Services\Themes::usages($edit))
      <p class="small muted">Key <span class="mono">{{ $edit->key }}</span> · used by {{ implode(', ', array_merge($u['apps'], $u['domains'])) ?: 'nothing yet' }}</p>
    @endif
  </form>
</div>
@if($edit)
  <form method="post" action="{{ route('admin.themes.destroy', [$orgKey, $edit->id]) }}" data-confirm="Delete {{ $edit->name }}? Apps using it go back to their built-in style." style="margin-top:12px">@csrf @method('delete')<button class="btn sm danger">Delete theme</button></form>
@endif
@endsection
