{{-- One line under the organization header: people · apps · sign-in page. --}}
<p class="orgmeta">{{ $peopleCount }} {{ $peopleCount === 1 ? 'person' : 'people' }} · {{ $appsCount }} {{ Str::plural('app', $appsCount) }}@if($signInHost) · sign-in page <span class="mono">{{ $signInHost }}</span>@endif</p>
