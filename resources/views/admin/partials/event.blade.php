{{-- One audit event as a readable sentence. Params: $e (AuditEvent, primed via AuditController::prime), $style ('feed' = overview row with avatar, default = Activity row with icon + time) --}}
@php($d = \App\Http\Controllers\Admin\AuditController::describe($e))
@if(($style ?? '') === 'feed')
<div class="row ev-row">
  <span class="av {{ $d['tone'] === 'bad' ? 'bad' : ($e->actor ? '' : 'muted') }}" aria-hidden="true">{{ $d['tone'] === 'bad' ? '!' : $d['initials'] }}</span>
  <div class="grow ev-text">{{ $d['text'] }}</div>
  <span class="muted nowrap" title="{{ $e->at->toIso8601String() }}"><time datetime="{{ $e->at->toIso8601String() }}">{{ $e->at->diffForHumans() }}</time></span>
</div>
@else
<div class="ev">
  <span class="ev-ic {{ $d['tone'] }}" aria-hidden="true">@include('partials.icon', ['i' => $d['icon'], 'iconSize' => 16])</span>
  <div class="grow">
    <div class="ev-text">{{ $d['text'] }}</div>
    @if($d['detail'])<div class="sub">{{ $d['detail'] }}</div>@endif
  </div>
  <span class="ev-when">
    @if($d['tone'] === 'bad' || $e->category === 'security')<span class="badge {{ $d['tone'] === 'bad' ? 'bad' : 'warn' }}">Security</span>@endif
    <time class="small muted nowrap" datetime="{{ $e->at->toIso8601String() }}" title="{{ $e->at->format('j M Y, H:i:s') }} UTC">{{ ($showDate ?? false) ? $e->at->diffForHumans() : $e->at->format('H:i') }}</time>
  </span>
</div>
@endif
