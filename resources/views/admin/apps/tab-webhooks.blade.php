{{-- App › Webhooks (SPEC-provisioning E1): where Clauth tells the app about access changes. --}}
@php($eventLabels = ['access.granted' => 'Access given', 'access.changed' => 'Role changed', 'access.removed' => 'Access removed', 'user.updated' => 'Name or email changed', 'user.disabled' => 'Account switched off', 'user.enabled' => 'Account switched on', 'invite.accepted' => 'Invite accepted'])
@if(session('webhook_secret'))
  <div class="flash info" role="status" style="flex-direction:column;align-items:stretch">
    <span>Signing secret (shown <b>once</b>; put it in the app’s .env as <code>CLAUTH_WEBHOOK_SECRET</code>):</span>
    <div class="secretbox"><code id="whsec">{{ session('webhook_secret') }}</code><button class="btn sm" type="button" data-copy="#whsec">Copy</button></div>
  </div>
@endif

<div class="stack">
  <div class="section-h"><div><h2>Webhooks</h2><p>Clauth tells {{ $app->name }} when someone gets access, changes role, loses access or is switched off, so the app can create or close their account before they sign in. Each request is signed with the endpoint’s secret.</p></div>
    @if($webhooks->count() < 5)<button class="btn" type="button" data-open-dialog="#add-webhook">@include('partials.icon', ['i' => 'plus', 'iconSize' => 16])Add an endpoint</button>@endif</div>

  @forelse($webhooks as $w)
    @php($ev = $w->events ? json_decode($w->events, true) : null)
    <section class="card" aria-labelledby="wh-{{ $w->id }}">
      <div class="card-h"><div style="min-width:0"><h2 id="wh-{{ $w->id }}" class="codev" style="font-size:14px">{{ $w->url }}</h2>
        <p>{{ $ev ? implode(', ', array_map(fn ($e) => $eventLabels[$e] ?? $e, $ev)) : 'All events' }}
          @if($w->last_success_at) · last delivered {{ \Illuminate\Support\Carbon::parse($w->last_success_at)->diffForHumans() }}@endif
          @if($w->consecutive_failures) · {{ $w->consecutive_failures }} failed in a row @endif</p></div>
        @if($w->status === 'active')<span class="badge ok dot">On</span>@else<span class="badge bad">Off</span>@endif</div>

      @php($log = $deliveries[$w->id] ?? collect())
      @if($log->isNotEmpty())
        <div class="scroll"><table class="t">
          <thead><tr><th>Event</th><th>When</th><th>Result</th><th></th></tr></thead>
          <tbody>
          @foreach($log as $d)
            <tr><td><code>{{ $d->event }}</code> <span class="small muted">#{{ $d->sequence }}</span></td>
              <td class="small">{{ \Illuminate\Support\Carbon::parse($d->created_at)->diffForHumans() }}</td>
              <td class="small">@if($d->status === 'delivered')<span class="badge ok">Delivered</span>@elseif($d->status === 'failed')<span class="badge bad">Failed</span>@else<span class="badge muted">Retrying</span>@endif
                {{ $d->last_status ? 'HTTP '.$d->last_status : $d->last_error }} · {{ $d->attempts }} {{ Str::plural('try', $d->attempts) }}</td>
              <td>@if($d->status !== 'delivered' && $w->status === 'active')<form method="post" action="{{ route('admin.apps.deliveries.redeliver', [$app->key, $d->id]) }}">@csrf<button class="btn sm">Send again</button></form>@endif</td></tr>
          @endforeach
          </tbody></table></div>
      @else
        <div class="set"><p style="margin:0">Nothing sent yet. Use Send test to check the endpoint.</p></div>
      @endif

      <div class="card-f" style="display:flex;gap:8px;flex-wrap:wrap">
        @if($w->status === 'active')
          <form method="post" action="{{ route('admin.apps.webhooks.test', [$app->key, $w->id]) }}">@csrf<button class="btn sm">Send test</button></form>
          <form method="post" action="{{ route('admin.apps.webhooks.update', [$app->key, $w->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="disabled"><button class="btn sm">Turn off</button></form>
        @else
          <form method="post" action="{{ route('admin.apps.webhooks.update', [$app->key, $w->id]) }}">@csrf @method('patch')<input type="hidden" name="status" value="active"><button class="btn sm pri">Turn on and resend</button></form>
        @endif
        <form method="post" action="{{ route('admin.apps.webhooks.update', [$app->key, $w->id]) }}" data-confirm="Make a new signing secret? The app must use the new one straight away.">@csrf @method('patch')<input type="hidden" name="rotate" value="1"><button class="btn sm">Make a new secret</button></form>
        <form method="post" action="{{ route('admin.apps.webhooks.destroy', [$app->key, $w->id]) }}" data-confirm="Remove this webhook? Its delivery log goes too.">@csrf @method('delete')<button class="btn sm danger">Remove</button></form>
      </div>
    </section>
  @empty
    <div class="card empty"><b>No webhooks yet</b><span>Without one, {{ $app->name }} only learns about a person when they first sign in. Apps using the Laravel kit receive these at <code>/clauth/webhook</code>.</span></div>
  @endforelse

  <section class="card" aria-labelledby="wh-pull">
    <div class="ch"><h2 id="wh-pull">Catching up</h2></div>
    <div class="set" style="border-top:0"><div style="min-width:0"><span class="codev">GET {{ rtrim(config('clauth.issuer'), '/') }}/api/v1/apps/{{ $app->key }}/people</span>
      <p style="margin-top:6px">Everyone with access, with the same <code>orgs</code> their token carries. Call it with the app’s server client (scope <code>users:read</code>); add <code>?since=</code> for changes only. The kit’s <code>php artisan clauth:sync</code> uses it.</p></div></div>
  </section>
</div>

<dialog id="add-webhook" class="dlg" aria-labelledby="add-webhook-h">
  <form method="post" action="{{ route('admin.apps.webhooks.store', $app->key) }}">@csrf
    <div class="dlg-h"><h2 id="add-webhook-h">Add a webhook endpoint</h2></div>
    <div class="dlg-b">
      <div class="f"><label for="wh-url">Address <span class="hint">— https only</span></label><input class="in" id="wh-url" name="url" type="url" required placeholder="https://{{ parse_url($app->home_url ?? 'https://app.example.com', PHP_URL_HOST) ?: 'app.example.com' }}/clauth/webhook"></div>
      <fieldset class="f"><legend>Events <span class="hint">— leave all unticked for every event</span></legend>
        @foreach($eventLabels as $k => $l)<label class="cb"><input type="checkbox" name="events[]" value="{{ $k }}"> {{ $l }} <code class="small">{{ $k }}</code></label>@endforeach
      </fieldset>
    </div>
    <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Add endpoint</button></div>
  </form>
</dialog>
