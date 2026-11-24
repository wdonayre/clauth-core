{{-- App › Sign-in page: logo, style, accent, live preview (clauth.js hooks: data-brand-form / data-preview / data-preview-mode / data-swatch), custom domain. --}}
@php($designed = \App\Services\Themes::forApp($app)->keyBy(fn ($t) => 'designed:'.$t->key))
@php($curTheme = $app->signin_theme_id ? 'designed:'.\App\Models\SigninTheme::find($app->signin_theme_id)?->key : $app->login_theme)
@php($looks = ['classic' => ['#0B0D12', '#12161E', '#22D3EE'], 'daylight' => ['#F7F8FA', '#FFFFFF', $app->accent_color ?: '#0B0D12'], 'midnight' => ['#0B0D12', '#161B25', $app->accent_color ?: '#22D3EE'], 'auto' => ['linear-gradient(90deg,#F7F8FA 50%,#0B0D12 50%)', '#FFFFFF', $app->accent_color ?: '#0E7490']])
<div class="signin-wrap">
  <form class="card" method="post" action="{{ route('admin.apps.branding', $app->key) }}" enctype="multipart/form-data" data-brand-form>@csrf
    <div class="blk"><span class="lbl">Logo</span>
      <div class="logo-row">
        <span class="cur" style="{{ $app->logo_path ? 'background:#fff;border:1px solid var(--line)' : 'background:'.$app->color }}">@if($app->logo_path)<img src="{{ $app->logoUrl() }}" alt="Current logo">@else{{ $app->letter() }}@endif</span>
        <div class="f grow"><label for="lg">Upload new</label><input class="in" id="lg" type="file" name="logo" accept=".png,.svg,image/png,image/svg+xml"></div>
      </div>
      <div class="f"><label for="lgd">Logo for dark styles <span class="hint">— optional</span></label><input class="in" id="lgd" type="file" name="logo_dark" accept=".png,.svg,image/png,image/svg+xml"></div>
      @error('logo')<span class="err">{{ $message }}</span>@enderror @error('logo_dark')<span class="err">{{ $message }}</span>@enderror
      <p class="hint">Square PNG or SVG with a transparent background, at most 512 KB. @unless($app->logo_path)No logo yet: the Clauth logo shows on top.@endunless</p>
      @if($app->logo_path)<label class="cb"><input type="checkbox" name="remove_logo" value="1"> Remove logos</label>@endif
    </div>

    <fieldset class="blk"><legend class="lbl">Style</legend>
      <div class="opts">
        @foreach($themes as $k => $l)
          @php($lk = $looks[$k] ?? [$designed[$k]?->s('background', '#0B0D12') ?? '#0B0D12', $designed[$k]?->s('card', '#12161E') ?? '#12161E', $designed[$k]?->s('accent', '#22D3EE') ?? '#22D3EE'])
          <label class="opt"><input type="radio" name="login_theme" value="{{ $k }}" @checked($curTheme === $k)>
            <span class="look" aria-hidden="true" style="background:{{ $lk[0] }}"><i style="background:{{ $lk[1] }};border-bottom:4px solid {{ $lk[2] }}"></i></span>{{ $l }}</label>
        @endforeach
      </div>
      @error('login_theme')<span class="err">{{ $message }}</span>@enderror
      <p class="hint">Clauth Classic always uses Clauth cyan; the others use the accent below. Match device shows Daylight or Midnight, following each visitor’s light or dark setting. @if($app->publisher)Design your own under <a href="{{ route('admin.themes.index', $app->owner_organization_id) }}">{{ $app->publisher->name }} › Themes</a>.@endif</p>
    </fieldset>

    <div class="blk"><label class="lbl" for="acc">Accent colour</label>
      <div class="colorpick">
        @foreach(['#0B0D12', '#0E7490', '#E4572E', '#15803D', '#6366F1', '#FBBF24'] as $c)<button type="button" class="dotsw {{ strcasecmp((string) $app->accent_color, $c) === 0 ? 'on' : '' }}" data-swatch="{{ $c }}" style="background:{{ $c }}" aria-label="Use {{ $c }}"></button>@endforeach
        <input class="in mono" id="acc" name="accent_color" value="{{ old('accent_color', $app->accent_color) }}" placeholder="#FBBF24" pattern="#[0-9A-Fa-f]{6}">
      </div>
      @error('accent_color')<span class="err">{{ $message }}</span>@enderror
      <p class="hint">Button text switches to black or white automatically so it stays readable.</p>
    </div>

    <div class="blk"><span class="lbl">Ways to sign in</span>
      <div class="set" style="padding:0;border:0"><div><p style="margin:0">Password, Google, one-time codes, 2-step and sign-up for {{ $app->name }} are set in its sign-in rules.</p></div>
        <a class="btn" href="{{ route('admin.apps.policy', $app->key) }}">Sign-in rules</a></div>
    </div>

    <div class="blk" style="flex-direction:row;justify-content:flex-end;gap:8px;flex-wrap:wrap">
      <a class="btn" href="{{ $tabUrl('signin') }}">Discard</a><button class="btn pri">Publish changes</button>
    </div>
  </form>

  <section class="pv" aria-labelledby="pv-h">
    <div class="pv-modes"><span id="pv-h" class="small muted strong">Live preview</span>
      <span class="actions"><a class="chip info" href="#" data-preview-mode="signin">Sign in</a><a class="chip" href="#" data-preview-mode="register">Account setup</a></span></div>
    <div class="preview-frame"><iframe title="Sign-in page preview" data-preview src="{{ route('admin.apps.preview', [$app->key, 'mode' => 'signin']) }}"></iframe></div>
    <p class="small muted" style="margin:0">The preview follows the style and colour you pick; publish to apply logo changes. <a href="{{ route('admin.apps.preview', [$app->key, 'mode' => 'signin']) }}" target="_blank" rel="noopener">Open full-size preview</a></p>
  </section>
</div>

@php($dom = $app->signInDomain()?->load(['apps', 'organization']))
@php($share = session('domain_share'))
<section class="card" aria-labelledby="dom-h" id="domain">
  <div class="card-h">
    <div><h2 id="dom-h">Address</h2>
      <p>Optional. People sign in to {{ $app->name }} at its own address (e.g. <code>auth.{{ $app->key }}.com</code>) instead of <code>{{ \App\Services\Domains::mainHost() }}</code>. Tokens don’t change, and one sign-in still covers every app. A domain belongs to an organization and can be shared by several of its apps.</p></div>
    @if($dom)<span class="badge dot {{ ['active' => 'ok', 'problem' => 'bad', 'pending' => 'warn'][$dom->status] ?? 'info' }}">{{ $dom->label() }}</span>@else<span class="badge muted">{{ \App\Services\Domains::mainHost() }} (Clauth’s own)</span>@endif
  </div>
  @if(! $dom)
    <div class="set">
    @if($share)
      <div class="flash info" style="flex:1 1 100%"><span><b class="mono">{{ $share['host'] }}</b> is already verified{{ $share['org'] ? ' for '.$share['org'] : '' }} and used by {{ implode(', ', $share['apps']) }} ({{ $share['status'] }}). Share it with {{ $app->name }}? No DNS changes are needed.</span></div>
      <form class="actions" method="post" action="{{ route('admin.apps.domain.store', $app->key) }}">@csrf
        <input type="hidden" name="host" value="{{ $share['host'] }}"><input type="hidden" name="share" value="1">
        <button class="btn pri">Share with {{ $app->name }}</button><a class="btn" href="{{ $tabUrl('signin') }}">Cancel</a>
      </form>
    @else
      <form class="toolbar" method="post" action="{{ route('admin.apps.domain.store', $app->key) }}" style="flex:1 1 100%">@csrf
        <label class="sr-only" for="dh">Domain</label>
        <input class="in mono" id="dh" name="host" placeholder="auth.example.com" style="max-width:340px" value="{{ old('host') }}" required>
        <button class="btn pri">Add domain</button>
      </form>
      @error('host')<p class="err small" style="margin:0;flex:1 1 100%;color:var(--bad)">{{ $message }}</p>@enderror
      <p class="hint" style="margin:0;flex:1 1 100%">The domain belongs to {{ $app->publisher?->name ?? 'the app’s organization' }}. Already using it for another of its apps? Enter it here to share it.</p>
    @endif
    </div>
  @else
    @php($others = $dom->apps->where('id', '!=', $app->id))
    <div class="set" style="flex-direction:column;align-items:stretch">
      <div><b class="mono">{{ $dom->host }}</b>
        @if($dom->isServed()) — live since {{ $dom->active_at?->format('j M Y') }}. <a href="https://{{ $dom->host }}/login" target="_blank" rel="noopener">Open sign-in page</a>@endif
        <p>Owned by {{ $dom->organization?->name ?? '—' }} · used by {{ $dom->apps->pluck('name')->join(', ') }}@if($others->isNotEmpty()) (shared)@endif · {{ $dom->isTrusted() ? 'Trusted: people signed in to Clauth continue here without a prompt' : 'Not trusted: people signed in to Clauth confirm before continuing here' }}</p></div>
      @if($dom->last_error && ! $dom->isServed())<div class="flash {{ $dom->status === 'problem' ? 'bad' : 'info' }}">{{ $dom->last_error }}@if($dom->status === 'problem') Sign-in falls back to {{ \App\Services\Domains::mainHost() }} until this passes.@endif</div>@endif
      <span class="lbl">DNS records{{ $others->isNotEmpty() ? ' (shared — already set up for '.$others->pluck('name')->join(', ').')' : ' to add at your DNS provider' }}</span>
      <div class="card scroll"><table class="t dns">
        <thead><tr><th scope="col">Type</th><th scope="col">Name</th><th scope="col">Value</th><th scope="col">Why</th></tr></thead>
        <tbody>
          <tr><td>TXT</td><td><code id="txtn">{{ $dom->txtName() }}</code> <button class="btn sm ghost" type="button" data-copy="#txtn" aria-label="Copy TXT name">Copy</button></td><td><code id="txtv">{{ $dom->txtValue() }}</code> <button class="btn sm ghost" type="button" data-copy="#txtv" aria-label="Copy TXT value">Copy</button></td><td class="small muted">Proves you control the domain. Keep it: it’s re-checked daily.</td></tr>
          <tr><td>CNAME</td><td><code>{{ $dom->host }}</code></td><td><code id="cnv">{{ \App\Services\Domains::mainHost() }}</code> <button class="btn sm ghost" type="button" data-copy="#cnv" aria-label="Copy CNAME value">Copy</button></td><td class="small muted">Sends visitors to Clauth. Or an A record to <code>{{ implode(', ', config('clauth.server_ips')) }}</code>. Cloudflare proxy is fine.</td></tr>
        </tbody>
      </table></div>
      <p class="hint" style="margin:0">Steps: DNS verified → the server adds the domain → HTTPS certificate → live. Clauth checks every 5 minutes; you can check now. Last checked {{ $dom->last_checked_at?->diffForHumans() ?? 'never' }}@if($dom->dns_target) · DNS: {{ ['cname' => 'CNAME to Clauth', 'a' => 'A record to this server', 'proxied' => 'behind Cloudflare', 'other' => 'points somewhere else', 'none' => 'not resolving'][$dom->dns_target] ?? $dom->dns_target }}@endif.</p>
      @if($dom->isServed())<p class="hint" style="margin:0">In {{ $app->name }}’s <code>.env</code>: <code>CLAUTH_LOGIN_URL=https://{{ $dom->host }}</code> (kit 0.4+). Without it the app keeps sending people to {{ \App\Services\Domains::mainHost() }}.</p>@endif
    </div>
    <form class="set" method="post" action="{{ route('admin.apps.domain.update', $app->key) }}">@csrf @method('patch')
      <div class="f" style="flex:1 1 260px"><label for="ddn">Name on the page when no app is named</label>
        <input class="in" id="ddn" name="display_name" maxlength="80" placeholder="{{ $dom->apps->count() > 1 ? ($dom->organization?->name ?? '') : $app->name }}" value="{{ $dom->display_name }}">
        <span class="hint">Shown on password reset and other pages opened directly on {{ $dom->host }}. Applies to every app sharing it.</span></div>
      <div class="actions">
        @if(auth()->user()?->isOwner() && ! $dom->organization?->is_platform)
          <label class="cb"><input type="hidden" name="trusted" value="0"><input type="checkbox" name="trusted" value="1" @checked($dom->trusted)> Trust anyway (silent sign-in from {{ \App\Services\Domains::mainHost() }})</label>
        @endif
        <button class="btn">Save</button>
      </div>
    </form>
    <div class="card-f" style="justify-content:space-between">
      <form method="post" action="{{ route('admin.apps.domain.destroy', $app->key) }}" data-confirm="{{ $others->isNotEmpty() ? 'Stop using '.$dom->host.' for '.$app->name.'? '.$others->pluck('name')->join(', ').' keep using it.' : 'Remove '.$dom->host.'? Sign-in goes back to '.\App\Services\Domains::mainHost().' right away.' }}">@csrf @method('delete')<button class="btn danger">{{ $others->isNotEmpty() ? 'Stop using for '.$app->name : 'Remove domain' }}</button></form>
      <form method="post" action="{{ route('admin.apps.domain.check', $app->key) }}">@csrf<button class="btn">@include('partials.icon', ['i' => 'refresh', 'iconSize' => 16])Check now</button></form>
    </div>
  @endif
</section>
