{{-- App status badge. Params: $a (Application) --}}
@if($a->isSuspended())<span class="badge bad dot">Suspended</span>
@elseif($a->status === 'paused')<span class="badge warn dot">Paused</span>
@elseif($a->status === 'not_connected')<span class="badge muted dot">Not connected</span>
@elseif($a->manifest_diff)<span class="badge warn dot" title="The app’s permissions file differs from roles edited in Clauth">Review roles</span>
@else<span class="badge ok dot">Live</span>@endif
