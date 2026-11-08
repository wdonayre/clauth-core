@if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="notice bad" role="alert">{{ $errors->first() }}</div>@endif
