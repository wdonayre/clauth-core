@extends('layouts.console')
@section('title', $org->name.' · Settings')
@include('admin.orgs._assets')
@section('content')
@include('partials.ui.org-tabs', ['org' => $org])
@include('admin.orgs._meta')
@php($deleteOn = $org->deletion_requested_at?->copy()->addDays(\App\Services\OrgLifecycle::GRACE_DAYS))

<div style="display:flex;flex-direction:column;gap:16px;max-width:960px">
  @if($deleteOn)
    <div class="flash bad" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><span class="grow">{{ $org->name }} will be deleted on {{ $deleteOn->format('j M Y') }}.</span>
      @if($canOwn)<form method="post" action="{{ route('admin.orgs.deletion.cancel', $org->id) }}">@csrf @method('delete')<button class="btn sm">Cancel deletion</button></form>@endif</div>
  @endif
  @if($org->suspended_at)<div class="flash bad">Suspended {{ $org->suspended_at->diffForHumans() }}: {{ $org->suspend_reason }}</div>@endif

  <section class="card" aria-labelledby="g-h"><h2 id="g-h" class="ch">General</h2>
    @if($canOwn)
      <form method="post" action="{{ route('admin.orgs.general', $org->id) }}">@csrf @method('put')
        <div class="frow card-body">
          <label class="f"><span>Name</span><input class="in" name="name" value="{{ old('name', $org->name) }}" required maxlength="120">@error('name')<span class="err">{{ $message }}</span>@enderror</label>
          <label class="f"><span>Short name <span class="hint">— apps see this in tokens</span></span><input class="in mono" value="{{ $org->slug }}" disabled></label>
          <label class="f"><span>Website</span><input class="in" type="url" name="website" value="{{ old('website', $org->website) }}" placeholder="https://example.com" maxlength="190">@error('website')<span class="err">{{ $message }}</span>@enderror</label>
        </div>
        <div class="card-f"><button class="btn pri">Save</button></div>
      </form>
    @else
      <dl class="kv card-body"><dt>Name</dt><dd>{{ $org->name }}</dd><dt>Short name</dt><dd class="mono">{{ $org->slug }}</dd>
        <dt>Website</dt><dd>@if($org->website)<a href="{{ $org->website }}" target="_blank" rel="noopener noreferrer">{{ $org->website }}</a>@else<span class="muted">—</span>@endif</dd></dl>
      <p class="small muted" style="margin:0;padding:0 22px 18px">Only owners of {{ $org->name }} can change these.</p>
    @endif
  </section>

  @php($logoUrl = \App\Http\Controllers\Admin\OrgController::logoUrl($org))
  <section class="card" aria-labelledby="l-h"><h2 id="l-h" class="ch">Logo</h2>
    <div class="set" style="border-top:0">
      <div class="logo-set">
        <span class="ologo xl {{ $org->is_platform ? 'own' : '' }} {{ $logoUrl ? 'img' : '' }}" aria-hidden="true">@if($logoUrl)<img src="{{ $logoUrl }}" alt="">@else{{ mb_strtoupper(mb_substr($org->name, 0, 2)) }}@endif</span>
        <div><b>{{ $logoUrl ? 'Your logo' : 'No logo yet' }}</b><p>Shown next to {{ $org->name }} across Clauth. PNG or SVG, up to 512 KB; square works best.</p>
          @error('logo')<span class="err" role="alert">{{ $message }}</span>@enderror</div>
      </div>
      <div class="logo-set">
        <form method="post" action="{{ route('admin.orgs.logo', $org->id) }}" enctype="multipart/form-data">@csrf
          <label class="btn">@include('partials.icon', ['i' => $logoUrl ? 'refresh' : 'plus', 'iconSize' => 16]){{ $logoUrl ? 'Replace' : 'Upload' }}<input class="sr-only" type="file" name="logo" accept="image/png,image/svg+xml,.png,.svg" required data-autosubmit-file></label>
          <noscript><button class="btn pri">Save logo</button></noscript>
        </form>
        @if($logoUrl)
          <form method="post" action="{{ route('admin.orgs.logo.destroy', $org->id) }}" data-confirm="Remove {{ $org->name }}’s logo?">@csrf @method('delete')<button class="btn ghost">Remove</button></form>
        @endif
      </div>
    </div>
  </section>

  <section class="card" aria-labelledby="s-h"><h2 id="s-h" class="ch">Status &amp; limits</h2>
    <div class="set"><div><b>Status</b><p>@if($org->is_platform)Your own business — no usage limits.@elseif($org->verified_at)Verified — higher limits.@else Unverified — starter limits until Clauth reviews it.@endif</p></div>
      @if($org->suspended_at)<span class="badge bad">Suspended</span>@elseif($org->status !== 'active')<span class="badge bad">{{ ucfirst($org->status) }}</span>@else<span class="badge ok">Active</span>@endif</div>
    @unless($org->is_platform)
      <div class="set"><div><b>Domain proven</b><p>@if($org->domain_verified_at){{ $org->domain }} · verified {{ $org->domain_verified_at->format('M j') }}@elseif($org->domain){{ $org->domain }} · waiting for the TXT record @else Not yet. <a href="{{ route('admin.orgs.domain.show', $org->id) }}">Prove your domain</a>@endif</p></div>
        <span class="badge {{ $org->verified_at ? 'info' : 'muted' }}">{{ $org->verified_at ? 'Verified' : 'Unverified' }}</span></div>
    @endunless
    @if($canEdit)
      <form method="post" action="{{ route('admin.orgs.update', $org->id) }}" class="card-body" style="border-top:1px solid var(--line-3);display:flex;flex-direction:column;gap:14px">@csrf @method('put')
        <input type="hidden" name="name" value="{{ $org->name }}">
        <p class="lbl" style="margin:0">Clauth owner controls</p>
        <label class="f" style="max-width:320px"><span>Status</span><select class="in" name="status"><option value="active" @selected($org->status === 'active')>Active</option><option value="disabled" @selected($org->status === 'disabled')>Disabled (no app access)</option></select></label>
        <input type="hidden" name="is_platform" value="0"><label class="cb"><input type="checkbox" name="is_platform" value="1" @checked($org->is_platform)><span>One of my own businesses</span></label>
        @unless($org->is_platform)
          <input type="hidden" name="verified" value="0"><label class="cb"><input type="checkbox" name="verified" value="1" @checked($org->verified_at)><span>Verified<span class="hint">Domain checked and reviewed: higher limits, Verified badge.</span></span></label>
          <details><summary class="small" style="cursor:pointer;min-height:32px">Limits ({{ $org->verified_at ? 'verified' : 'unverified' }} defaults; leave blank to use them)</summary>
            <div class="frow" style="margin-top:10px">
              @foreach(config('clauth.limits.'.($org->verified_at ? 'verified' : 'unverified')) as $k => $def)
                <label class="f"><span>{{ str_replace('_', ' ', ucfirst($k)) }} <span class="hint">(used {{ \App\Services\Limits::used($org, $k) }})</span></span>
                  <input class="in" name="limits[{{ $k }}]" type="number" min="0" placeholder="{{ $def }}" value="{{ $org->limits[$k] ?? '' }}"></label>
              @endforeach
            </div></details>
        @endunless
        <div><button class="btn">Save</button></div>
      </form>
    @endif
  </section>

  <section class="card" aria-labelledby="si-h"><h2 id="si-h" class="ch">Sign-in</h2>
    <div class="set" style="border-top:0"><div><b>Sign-in rules</b><p>How people in {{ $org->name }} sign in, and what they must set up first.</p></div><a class="btn" href="{{ route('admin.orgs.policy', $org->id) }}">Open</a></div>
    @if(Route::has('admin.themes.index'))<div class="set"><div><b>Themes</b><p>How sign-in pages look for {{ $org->name }}’s apps.</p></div><a class="btn" href="{{ route('admin.themes.index', $org->id) }}">Open</a></div>@endif
    @if(Route::has('admin.connections.index'))<div class="set"><div><b>Sign-in providers &amp; senders</b><p>Extra sign-in buttons (Microsoft, Okta, other OIDC), the text-message gateway and {{ $org->name }}’s own email sender.</p></div><a class="btn" href="{{ route('admin.connections.index', $org->id) }}">Open</a></div>@endif
    @if(Route::has('admin.messages.index'))<div class="set"><div><b>Messages</b><p>Change the wording of sign-in pages and emails people from {{ $org->name }} see.</p></div><a class="btn" href="{{ route('admin.messages.index', $org->id) }}">Open</a></div>@endif
  </section>

  @unless($org->is_platform)
    <section class="card" aria-labelledby="t-h"><h2 id="t-h" class="ch">Agreements</h2>
      @if($org->needsTerms())
        @if($canOwn)
          <form class="card-body" method="post" action="{{ route('admin.orgs.terms', $org->id) }}" style="display:flex;flex-direction:column;gap:10px">@csrf
            <label class="cb"><input type="checkbox" name="agree" value="1" required><span>I accept the <a href="{{ route('terms') }}" target="_blank">Terms</a> and the <a href="{{ route('dpa') }}" target="_blank">Data Processing Agreement</a> for {{ $org->name }}.<span class="hint">Needed before {{ $org->name }} can publish apps.</span></span></label>
            <div><button class="btn pri">Accept</button></div></form>
        @else
          <div class="set" style="border-top:0"><div><b>Terms not accepted yet</b><p>An owner of {{ $org->name }} needs to accept the current Terms before it can publish apps.</p></div><span class="badge warn">Needed</span></div>
        @endif
      @else
        <div class="set" style="border-top:0"><div><b>Terms and Data Processing Agreement</b><p>Version {{ $org->terms_version }}@if($org->terms_accepted_at) · accepted {{ $org->terms_accepted_at->format('M j, Y') }}@endif</p></div><span class="badge ok">Accepted</span></div>
      @endif
    </section>
  @endunless

  <section class="card" aria-labelledby="d-h"><h2 id="d-h" class="ch">Your data</h2>
    <div class="set" style="border-top:0"><div><b>Download everything</b><p>People, access, job roles, teams and activity as one file (JSON).</p></div>
      <a class="btn" href="{{ route('admin.orgs.export', $org->id) }}">@include('partials.icon', ['i' => 'download', 'iconSize' => 16])Export</a></div>
  </section>

  @if(! $org->is_platform && ($canEdit || $canOwn))
    <section class="card dangerz" aria-labelledby="z-h"><h2 id="z-h" class="ch">Danger zone</h2>
      @if($canEdit)
        <div class="set" style="border-top:0"><div><b>{{ $org->suspended_at ? 'Restore organization' : 'Suspend organization' }}</b><p>{{ $org->suspended_at ? 'Its apps can sign people in again.' : 'Nobody here can sign in to its apps until you restore it.' }}</p></div>
          @if($org->suspended_at)
            <form method="post" action="{{ route('admin.orgs.restore', $org->id) }}">@csrf @method('delete')<button class="btn">Restore</button></form>
          @else
            <button class="btn danger" type="button" data-open-dialog="#suspend-dlg">Suspend</button>
          @endif
        </div>
      @endif
      @if($canOwn && ! $deleteOn)
        <div class="set"><div><b>Delete organization</b><p>Deleted {{ \App\Services\OrgLifecycle::GRACE_DAYS }} days after you ask, unless you cancel. People in no other organization are removed.</p></div>
          <button class="btn danger" type="button" data-open-dialog="#delete-dlg">Delete</button></div>
      @endif
    </section>
  @endif
</div>

@if($canEdit && ! $org->is_platform && ! $org->suspended_at)
  <dialog class="dlg" id="suspend-dlg" aria-labelledby="suspend-h" @error('reason') data-open-on-load @enderror>
    <form method="post" action="{{ route('admin.orgs.suspend', $org->id) }}">@csrf
      <div class="dlg-h"><h2 id="suspend-h">Suspend {{ $org->name }}?</h2><p>Its apps stop signing anyone in right away.</p></div>
      <div class="dlg-b"><label class="f"><span>Reason <span class="hint">(shown to its staff)</span></span><input class="in" name="reason" required maxlength="500">@error('reason')<span class="err">{{ $message }}</span>@enderror</label></div>
      <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn danger solid">Suspend</button></div>
    </form>
  </dialog>
@endif
@if($canOwn && ! $org->is_platform && ! $deleteOn)
  <dialog class="dlg" id="delete-dlg" aria-labelledby="delete-h" @error('confirm') data-open-on-load @enderror>
    <form method="post" action="{{ route('admin.orgs.deletion', $org->id) }}">@csrf
      <div class="dlg-h"><h2 id="delete-h">Delete {{ $org->name }}?</h2><p>People, access, invites, its apps and domains are deleted after {{ \App\Services\OrgLifecycle::GRACE_DAYS }} days. Export first.</p></div>
      <div class="dlg-b"><label class="f"><span>Type <b class="mono">{{ $org->slug }}</b> to confirm</span><input class="in mono" name="confirm" required autocomplete="off">@error('confirm')<span class="err">{{ $message }}</span>@enderror</label></div>
      <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn danger solid">Delete in {{ \App\Services\OrgLifecycle::GRACE_DAYS }} days</button></div>
    </form>
  </dialog>
@endif
@endsection
