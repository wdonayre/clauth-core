@extends('layouts.console')
@section('title', 'Messages · '.($org?->name ?? 'Platform'))
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@section('content')
@if($org)
  @include('partials.ui.org-tabs', ['org' => $org])
  <div class="intro"><p>Write the invite email {{ $org->name }}’s new people get. Leave a message empty to use the built-in one. Plain text; links become clickable.</p></div>
@else
  <div class="head"><div><h1>Platform messages</h1><p>Write the emails and texts Clauth sends, in each language. Leave a message empty to use the built-in one. Plain text; links become clickable.</p></div></div>
@endif

@foreach($keys as $key => [$channel, $label, $ph])
  <section class="card" aria-labelledby="msg-{{ $key }}">
    <div class="card-h">
      <div><h2 id="msg-{{ $key }}">{{ $label }}</h2>
        <p>You can use: <span class="mono">@foreach($ph as $p)&#123;&#123;{{ $p }}&#125;&#125; @endforeach</span></p></div>
      <span class="badge {{ $channel === 'sms' ? 'muted' : 'info' }}">@include('partials.icon', ['i' => $channel === 'sms' ? 'phone' : 'mail', 'iconSize' => 14]){{ $channel === 'sms' ? 'Text message' : 'Email' }}</span>
    </div>
    <div class="locales">
      @foreach($locales as $code => $lname)
        @php($r = $rows[$key.'|'.$code] ?? null)
        @php($mine = old('key') === $key && old('locale') === $code)
        <form class="locale" method="post" action="{{ route('admin.messages.save', $orgKey) }}">@csrf
          <input type="hidden" name="key" value="{{ $key }}"><input type="hidden" name="locale" value="{{ $code }}">
          <div class="top"><b>{{ $lname }}</b>@if($r)<span class="badge ok">Custom</span>@else<span class="badge muted">Built-in</span>@endif</div>
          @if($channel === 'email')
            <label class="f"><span>Subject</span><input class="in" name="subject" maxlength="190" value="{{ $mine ? old('subject') : $r?->subject }}"></label>
          @endif
          <label class="f"><span>Message</span><textarea class="in prose" name="body" rows="{{ $channel === 'sms' ? 3 : 7 }}" maxlength="{{ $channel === 'sms' ? 320 : 5000 }}" placeholder="Empty = the built-in message">{{ $mine ? old('body') : $r?->body }}</textarea>
            @if($mine)@error('body')<span class="err">{{ $message }}</span>@enderror @endif</label>
          <div class="actions" style="justify-content:flex-end">
            @if($r)<button class="btn sm" name="body" value="" formnovalidate>Use built-in</button>@endif
            <button class="btn sm pri">Save</button>
          </div>
        </form>
      @endforeach
    </div>
  </section>
@endforeach
@endsection
