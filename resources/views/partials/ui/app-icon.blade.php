{{-- App icon: logo when uploaded, else the colored letter. Params: $app, $size ('sm'|''|'lg') --}}
@php($logo = method_exists($app, 'logoUrl') ? $app->logoUrl() : null)
<span class="ico {{ $size ?? '' }}" style="background:{{ $app->color ?? '#0B0D12' }};{{ in_array(strtoupper(ltrim($app->color ?? '', '#')), ['F3B23C', 'FBBF24', '22D3EE', '4ADE80', 'FFFFFF', 'F7F8FA']) ? 'color:#0B0D12' : '' }}" aria-hidden="true">@if($logo)<img src="{{ $logo }}" alt="">@else{{ $app->letter() }}@endif</span>
