<div class="row">
  <span class="chip {{ ['security' => 'bad', 'signin' => 'info', 'role' => 'ok', 'app' => 'warn'][$e->category] ?? '' }}">{{ ucfirst($e->category) }}</span>
  <div class="grow"><b>{{ \App\Services\Audit::label($e->event) }}</b>
    <span class="muted"> · {{ $e->actor?->name ?? ucfirst($e->actor_type) }}@if($e->app) · {{ $e->app->name }}@endif</span>
    @if($e->after && in_array($e->category, ['role', 'app', 'admin']))<br><span class="small muted mono">{{ \Illuminate\Support\Str::limit(json_encode($e->after, JSON_UNESCAPED_SLASHES), 140) }}</span>@endif
  </div>
  <span class="small muted" title="{{ $e->at->toIso8601String() }}">{{ $e->at->diffForHumans() }}@if($e->ip) · {{ $e->ip }}@endif</span>
</div>
