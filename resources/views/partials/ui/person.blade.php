{{-- Avatar + name + email. Params: $u (User), $href (optional), $sub (optional override for second line) --}}
@php($tag = isset($href) && $href ? 'a' : 'span')
<{{ $tag }} class="person" @if($tag === 'a') href="{{ $href }}" @endif>
  <span class="av {{ $u->disabled_at ?? null ? 'muted' : '' }}" aria-hidden="true">{{ $u->initials() }}</span>
  <span class="who"><b>{{ $u->name }}</b><span>{{ $sub ?? $u->email }}</span></span>
</{{ $tag }}>
