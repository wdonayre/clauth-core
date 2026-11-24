@extends('layouts.console')
@section('title', 'Sign-in & senders · '.($org?->name ?? 'Platform'))
@push('head')<link rel="stylesheet" href="/css/pages/org-sub.css?v={{ filemtime(public_path('css/pages/org-sub.css')) }}">@endpush
@push('end')<script src="/js/pages/org-sub.js?v={{ filemtime(public_path('js/pages/org-sub.js')) }}" defer></script>@endpush
@php
  $fieldInput = function ($name, $def, $value, $type, $prefix) {
      [$label, $rule] = $def; $secret = $def[2] ?? false; $id = $prefix.'-'.$name;
      if (str_contains($rule, 'boolean')) {
          return '<label class="cb"><input type="hidden" name="config['.$name.']" value="0"><input type="checkbox" name="config['.$name.']" value="1"'.($value ? ' checked' : '').'> '.e($label).'</label>';
      }
      if ($name === 'domains' || $name === 'apps') {
          return '<label class="f" for="'.$id.'"><span>'.e($label).'</span><textarea class="in mono" id="'.$id.'" name="config['.$name.']" rows="2" placeholder="'.($name === 'apps' ? 'xpenses' : 'acme.com').'">'.e(implode("\n", (array) $value)).'</textarea></label>';
      }
      if ($name === 'driver' || $name === 'encryption') {
          $opts = $name === 'driver' ? \App\Services\Connections::SMS_DRIVERS : ['' => 'None', 'tls' => 'STARTTLS', 'ssl' => 'SSL'];
          return '<label class="f" for="'.$id.'"><span>'.e($label).'</span><select class="in" id="'.$id.'" name="config['.$name.']">'.collect($opts)->map(fn ($l, $k) => '<option value="'.e($k).'"'.((string) $value === (string) $k ? ' selected' : '').'>'.e($l).'</option>')->implode('').'</select></label>';
      }
      return '<label class="f" for="'.$id.'"><span>'.e($label).($secret && $value ? ' <span class="hint">(set — leave empty to keep)</span>' : '').'</span><input class="in'.($secret ? ' mono' : '').'" id="'.$id.'" name="config['.$name.']" '.($secret ? 'type="password" autocomplete="new-password"' : 'value="'.e((string) $value).'"').'></label>';
  };
  $examples = ['oidc' => 'Microsoft 365', 'oauth' => 'Our portal', 'meta' => 'Facebook', 'sms' => 'Semaphore', 'email-smtp' => 'Our mail server', 'http' => 'HR system check', 'authzen-pdp' => 'Our policy engine'];
  $icons = ['oidc' => 'key', 'oauth' => 'link', 'meta' => 'globe', 'sms' => 'phone', 'email-smtp' => 'mail', 'http' => 'code', 'authzen-pdp' => 'shield'];
  $groups = $connections->groupBy(fn ($c) => $c->isSignIn() ? 'signin' : 'other');
  $newType = old('type');
@endphp
@section('content')
@if($org)
  @include('partials.ui.org-tabs', ['org' => $org])
  <div class="intro"><p>Let {{ $org->name }}'s people sign in with the company account they already have (Microsoft 365, Okta, Google Workspace…), and send {{ $org->name }}'s invites from its own email address. Secrets are stored encrypted and never shown again.</p></div>
@else
  <div class="head"><div><h1>Platform sign-in &amp; SMS</h1><p>Facebook and other sign-in buttons for everyone, and the SMS gateway for text-message codes. Google is set on the server. Secrets are stored encrypted and never shown again.</p></div></div>
@endif

@foreach(['signin' => ['Sign-in providers', 'Buttons on the sign-in page. Register the redirect URL shown for each one with the provider.'], 'other' => [$org ? 'Senders & checks' : 'SMS & checks', 'Where Clauth sends email or text messages from, and outside systems it asks before letting people in.']] as $g => [$title, $text])
  <section class="card" aria-labelledby="g-{{ $g }}">
    <div class="card-h"><div><h2 id="g-{{ $g }}">{{ $title }} ({{ ($groups[$g] ?? collect())->count() }})</h2><p>{{ $text }}</p></div></div>
    @if(($groups[$g] ?? collect())->isEmpty())
      <div class="empty"><b>None yet</b><span>Add one below.</span></div>
    @else
      <div class="scroll">
        <table class="t">
          <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Last used</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            @foreach($groups[$g] as $c)
              <tr>
                <td><b style="font-weight:500">{{ $c->name }}</b>
                  @if($c->isSignIn())<div class="small muted">Redirect URL: <code>{{ \App\Services\Domains::mainUrl('login/c/'.$c->key.'/callback') }}</code></div>@endif</td>
                <td>{{ $types[$c->type] ?? $c->type }}</td>
                <td><span class="badge dot {{ $c->status === 'active' ? 'ok' : 'muted' }}">{{ $c->status === 'active' ? 'On' : 'Off' }}</span></td>
                <td class="muted nowrap">{{ $c->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                <td class="act"><button class="btn sm" type="button" data-open-dialog="#conn-{{ $c->id }}">Manage</button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>
@endforeach

<section class="card" aria-labelledby="add-h">
  <div class="card-h"><div><h2 id="add-h">Add a connection</h2>
    <p>@if($org)Microsoft Entra: issuer <code>https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0</code>. Okta: <code>https://&lt;your-org&gt;.okta.com</code>. A provider signs in only people whose email is on the listed domains and who belong to {{ $org->name }} alone (or new people, if account creation is on).@else Semaphore, Twilio or Vonage keys come from their dashboards. Text-message sign-in and the daily limit are in Settings.@endif</p></div></div>
  <div class="types">
    @foreach($types as $type => $label)
      <div class="prov"><b style="font-weight:600;display:flex;align-items:center;gap:8px">@include('partials.icon', ['i' => $icons[$type] ?? 'link', 'iconSize' => 16]){{ $label }}</b>
        <button class="btn sm" type="button" data-open-dialog="#add-{{ $type }}">Add</button></div>
    @endforeach
  </div>
</section>

@foreach($types as $type => $label)
  <dialog class="dlg" id="add-{{ $type }}" aria-labelledby="add-{{ $type }}-h" @if($newType === $type && $errors->any() && ! $errors->has('test')) data-open-on-load @endif>
    <form method="post" action="{{ route('admin.connections.store', $orgKey) }}">@csrf
      <input type="hidden" name="type" value="{{ $type }}">
      <div class="dlg-h"><h2 id="add-{{ $type }}-h">Add {{ $label }}</h2></div>
      <div class="dlg-b">
        <label class="f"><span>Name {{ in_array($type, ['oidc', 'oauth', 'meta']) ? '(shown as “Continue with …”)' : '' }}</span><input class="in" name="name" required maxlength="80" placeholder="{{ $examples[$type] ?? '' }}" value="{{ $newType === $type ? old('name') : '' }}"></label>
        @foreach($fields[$type] as $name => $def){!! $fieldInput($name, $def, $name === 'show_button' ? true : null, $type, 'n'.$type) !!}@endforeach
      </div>
      <div class="dlg-f"><button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Add</button></div>
    </form>
  </dialog>
@endforeach

@foreach($connections as $c)
  <dialog class="dlg wide" id="conn-{{ $c->id }}" aria-labelledby="conn-{{ $c->id }}-h">
    <form method="post" action="{{ route('admin.connections.update', [$orgKey, $c->id]) }}">@csrf @method('put')
      <div class="dlg-h"><h2 id="conn-{{ $c->id }}-h">{{ $c->name }}</h2><p>{{ $types[$c->type] ?? $c->type }} · last used {{ $c->last_used_at?->diffForHumans() ?? 'never' }}</p></div>
      <div class="dlg-b">
        @if($c->isSignIn())
          <div class="pastebox" style="border:0;padding:0"><span class="small muted">Register this redirect URL with the provider:</span>
            <div class="line"><span class="code" id="cb-{{ $c->id }}">{{ \App\Services\Domains::mainUrl('login/c/'.$c->key.'/callback') }}</span><button class="btn sm" type="button" data-copy="#cb-{{ $c->id }}">Copy</button></div></div>
        @endif
        <div class="frow">
          <label class="f"><span>Name{{ $c->isSignIn() ? ' (button label)' : '' }}</span><input class="in" name="name" value="{{ $c->name }}" required maxlength="80"></label>
          <label class="f"><span>Status</span><select class="in" name="status"><option value="active" @selected($c->status === 'active')>On</option><option value="disabled" @selected($c->status !== 'active')>Off</option></select></label>
        </div>
        @foreach(\App\Services\Connections::fields($c->type, (bool) $org) as $name => $def){!! $fieldInput($name, $def, $c->cfg($name), $c->type, 'c'.$c->id) !!}@endforeach
      </div>
      <div class="dlg-f">
        <button class="btn danger" type="submit" form="conn-del-{{ $c->id }}" style="margin-right:auto">Delete</button>
        <button class="btn" type="button" data-close-dialog>Cancel</button><button class="btn pri">Save</button>
      </div>
    </form>
    <form method="post" action="{{ route('admin.connections.test', [$orgKey, $c->id]) }}" class="dlg-f" style="justify-content:flex-start">@csrf
      @if($c->type === 'sms')<label class="sr-only" for="tp-{{ $c->id }}">Mobile number for the test</label><input class="in" id="tp-{{ $c->id }}" name="phone" type="tel" placeholder="Your mobile number" style="max-width:220px">@endif
      <button class="btn">{{ ['sms' => 'Send test text', 'email-smtp' => 'Send test email to me', 'oidc' => 'Check discovery'][$c->type] ?? 'Check' }}</button>
      <span class="hint">Checks it works without saving changes.</span>
    </form>
    <form id="conn-del-{{ $c->id }}" method="post" action="{{ route('admin.connections.destroy', [$orgKey, $c->id]) }}" hidden data-confirm="Delete {{ $c->name }}? People linked to it sign in another way.">@csrf @method('delete')</form>
  </dialog>
@endforeach
@endsection
