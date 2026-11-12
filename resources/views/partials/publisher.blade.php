{{-- Who runs an app outside William's own organizations (SPEC-organizations §6.3). Empty for first-party apps. --}}
@if($app && ! $app->isFirstParty())
  <span class="publisher" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap">by <strong>{{ $app->publisher?->name ?? 'an unknown organization' }}</strong>
    @if($app->publisher?->verified_at)<span style="font-size:11px;padding:1px 6px;border-radius:999px;border:1px solid currentColor;opacity:.8">Verified</span>
    @else<span style="font-size:11px;padding:1px 6px;border-radius:999px;border:1px solid var(--danger,#F87171);color:var(--danger,#F87171)">Unverified</span>@endif
  </span>
@endif
