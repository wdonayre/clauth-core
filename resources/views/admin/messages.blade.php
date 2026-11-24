@extends('layouts.console')
@section('title', 'Messages · '.($org?->name ?? 'Platform'))
@section('content')
<header class="head"><div><h1>Messages</h1>
  <p>@if($org)Write the invite email {{ $org->name }}’s new people get.@else Write the emails and texts Clauth sends, in each language.@endif Leave a message empty to use the built-in one. Plain text; links become clickable. Use the <span class="mono">&#123;&#123;placeholders&#125;&#125;</span> listed.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>
@if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
@foreach($keys as $key => [$channel, $label, $ph])
  <section class="card pad" style="margin-bottom:16px">
    <h2>{{ $label }} <span class="chip">{{ $channel === 'sms' ? 'text message' : 'email' }}</span></h2>
    <p class="small muted mono" style="margin:4px 0 12px">@foreach($ph as $p)&#123;&#123;{{ $p }}&#125;&#125; @endforeach</p>
    <div class="grid2">
      @foreach($locales as $code => $lname)
        @php($r = $rows[$key.'|'.$code] ?? null)
        <form class="form" method="post" action="{{ route('admin.messages.save', $orgKey) }}">@csrf
          <input type="hidden" name="key" value="{{ $key }}"><input type="hidden" name="locale" value="{{ $code }}">
          <b>{{ $lname }}</b> @if($r)<span class="chip info">custom</span>@else<span class="chip">built-in</span>@endif
          @if($channel === 'email')<div class="f"><label>Subject</label><input class="in" name="subject" maxlength="190" value="{{ $r?->subject }}"></div>@endif
          <div class="f"><label>Message</label><textarea class="in" name="body" rows="{{ $channel === 'sms' ? 2 : 6 }}" maxlength="{{ $channel === 'sms' ? 320 : 5000 }}">{{ $r?->body }}</textarea></div>
          <div class="actions"><button class="btn sm pri">Save</button></div>
        </form>
      @endforeach
    </div>
  </section>
@endforeach
@endsection
