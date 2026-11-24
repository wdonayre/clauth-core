{{-- Activity tab: this person's audit events as sentences, grouped by day. --}}
<section class="card pp-activity" aria-label="{{ $user->name }}’s activity">
  @forelse($days as $day => $items)
    <h2 class="pp-day">{{ $day }}</h2>
    @foreach($items as $it)
      <div class="pp-ev">
        <span class="pp-dot" style="background:{{ $it['tone'] }}" aria-hidden="true"></span>
        <div class="grow">
          <div>@foreach($it['parts'] as $p){!! isset($p['b']) ? '<b>'.e($p['b']).'</b>' : (isset($p['badge']) ? '<span class="badge info">'.e($p['badge']).'</span>' : e($p['t'] ?? '')) !!}@endforeach</div>
          @if($it['sub'])<div class="small muted">{{ $it['sub'] }}</div>@endif
        </div>
        <time class="small muted nowrap" datetime="{{ $it['e']->at->toIso8601String() }}" title="{{ $it['e']->at->toDayDateTimeString() }}">{{ $it['e']->at->isToday() || $it['e']->at->isYesterday() ? $it['e']->at->format('H:i') : $it['e']->at->format('M j') }}</time>
      </div>
    @endforeach
  @empty
    <div class="empty"><b>Nothing yet</b><span>Sign-ins, access changes and security events show up here.</span></div>
  @endforelse
</section>
<div class="pager pp-narrowpage">
  <a class="strong" href="{{ route('admin.audit', ['q' => $user->email]) }}">Open in Activity with more filters</a>
  @if($events->hasPages())
    <div class="actions">
      @if($events->onFirstPage())<span class="btn disabled" aria-disabled="true">Newer</span>@else<a class="btn" href="{{ $events->previousPageUrl() }}" rel="prev">Newer</a>@endif
      @if($events->hasMorePages())<a class="btn" href="{{ $events->nextPageUrl() }}" rel="next">Older</a>@else<span class="btn disabled" aria-disabled="true">Older</span>@endif
    </div>
  @endif
</div>
