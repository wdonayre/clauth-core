{{-- Page CSS/JS for the organization pages. --}}
@push('head')<link rel="stylesheet" href="/css/pages/orgs.css?v={{ filemtime(public_path('css/pages/orgs.css')) }}">@endpush
@push('end')<script src="/js/pages/orgs.js?v={{ filemtime(public_path('js/pages/orgs.js')) }}" defer></script>@endpush
