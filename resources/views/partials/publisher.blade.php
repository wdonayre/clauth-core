{{-- Who runs an app outside William's own organizations (SPEC-organizations §6.3). Empty for first-party apps. --}}
@if($app && ! $app->isFirstParty())
  <span class="publisher">{{ __('Made by') }} <strong>{{ $app->publisher?->name ?? __('an unknown organization') }}</strong>
    @if($app->publisher?->verified_at)<span class="vbadge ok">{{ __('Verified') }}</span>
    @else<span class="vbadge bad">{{ __('Unverified') }}</span>@endif
    — {{ __('an organization outside yours.') }}</span>
@endif
