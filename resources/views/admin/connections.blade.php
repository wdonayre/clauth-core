@extends('layouts.console')
@section('title', 'Sign-in & senders · '.($org?->name ?? 'Platform'))
@section('content')
@php($fieldInput = function ($name, $def, $value, $type, $prefix) {
    [$label, $rule] = $def; $secret = $def[2] ?? false; $id = $prefix.'-'.$name;
    if (str_contains($rule, 'boolean')) {
        return '<label class="cb"><input type="hidden" name="config['.$name.']" value="0"><input type="checkbox" name="config['.$name.']" value="1"'.($value ? ' checked' : '').'> '.e($label).'</label>';
    }
    if ($name === 'domains') {
        return '<div class="f"><label for="'.$id.'">'.e($label).'</label><textarea class="in mono" id="'.$id.'" name="config[domains]" rows="2" placeholder="acme.com">'.e(implode("\n", (array) $value)).'</textarea></div>';
    }
    if ($name === 'driver' || $name === 'encryption') {
        $opts = $name === 'driver' ? \App\Services\Connections::SMS_DRIVERS : ['' => 'None', 'tls' => 'STARTTLS', 'ssl' => 'SSL'];
        return '<div class="f"><label for="'.$id.'">'.e($label).'</label><select class="in" id="'.$id.'" name="config['.$name.']">'.collect($opts)->map(fn ($l, $k) => '<option value="'.e($k).'"'.((string) $value === (string) $k ? ' selected' : '').'>'.e($l).'</option>')->implode('').'</select></div>';
    }
    return '<div class="f"><label for="'.$id.'">'.e($label).($secret && $value ? ' <span class="muted small">(set — leave empty to keep)</span>' : '').'</label><input class="in'.($secret ? ' mono' : '').'" id="'.$id.'" name="config['.$name.']" '.($secret ? 'type="password" autocomplete="new-password"' : 'value="'.e((string) $value).'"').'></div>';
})
<header class="head"><div><h1>Sign-in &amp; senders</h1>
  <p>@if($org)Let {{ $org->name }}'s people sign in with the company account they already have (Microsoft 365, Okta, Google Workspace…), and send {{ $org->name }}'s invites from its own email address.@else Platform-wide connections: Facebook and other sign-in buttons for everyone, and the SMS gateway for text-message codes. Google is set on the server.@endif
  Secrets are stored encrypted and never shown again.</p></div>
  <div class="actions"><a class="btn" href="{{ route('admin.orgs.index') }}">Organizations</a></div></header>
@error('test')<div class="flash bad">{{ $message }}</div>@enderror

<div class="grid2">
  <section class="card">
    <div class="card-h"><h2>Connections ({{ $connections->count() }})</h2></div>
    <div class="list">
      @forelse($connections as $c)
        <details class="row" style="display:block">
          <summary style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;cursor:pointer"><b>{{ $c->name }}</b>
            <span class="chip">{{ $types[$c->type] ?? $c->type }}</span>
            <span class="chip {{ $c->status === 'active' ? 'ok' : 'bad' }}">{{ $c->status === 'active' ? 'On' : 'Off' }}</span>
            <span class="small muted">last used {{ $c->last_used_at?->diffForHumans() ?? 'never' }}</span></summary>
          @if($c->isSignIn())<p class="small muted" style="margin:10px 0 0">Redirect URL to register with the provider: <code class="mono">{{ \App\Services\Domains::mainUrl('login/c/'.$c->key.'/callback') }}</code></p>@endif
          <form class="form" method="post" action="{{ route('admin.connections.update', [$orgKey, $c->id]) }}" style="margin-top:10px">@csrf @method('put')
            <div class="f"><label>Name (button label)</label><input class="in" name="name" value="{{ $c->name }}" required maxlength="80"></div>
            <div class="f"><label>Status</label><select class="in" name="status"><option value="active" @selected($c->status === 'active')>On</option><option value="disabled" @selected($c->status !== 'active')>Off</option></select></div>
            @foreach(\App\Services\Connections::fields($c->type, (bool) $org) as $name => $def){!! $fieldInput($name, $def, $c->cfg($name), $c->type, 'c'.$c->id) !!}@endforeach
            <div class="actions"><button class="btn sm pri">Save</button></div>
          </form>
          <form method="post" action="{{ route('admin.connections.test', [$orgKey, $c->id]) }}" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">@csrf
            @if($c->type === 'sms')<input class="in" name="phone" type="tel" placeholder="Your mobile number" style="max-width:200px">@endif
            <button class="btn sm">{{ ['sms' => 'Send test text', 'email-smtp' => 'Send test email to me', 'oidc' => 'Check discovery'][$c->type] ?? 'Check' }}</button></form>
          <form method="post" action="{{ route('admin.connections.destroy', [$orgKey, $c->id]) }}" data-confirm="Delete {{ $c->name }}?" style="margin-top:8px">@csrf @method('delete')<button class="btn sm danger">Delete</button></form>
        </details>
      @empty<div class="empty">No connections yet.</div>@endforelse
    </div>
  </section>

  <section class="card pad">
    <h2>Add a connection</h2>
    @foreach($types as $type => $label)
      <details style="margin-top:10px"><summary class="btn sm">{{ $label }}</summary>
        <form class="form" method="post" action="{{ route('admin.connections.store', $orgKey) }}" style="margin-top:10px">@csrf
          <input type="hidden" name="type" value="{{ $type }}">
          <div class="f"><label>Name {{ in_array($type, ['oidc', 'oauth', 'meta']) ? '(shown as “Continue with …”)' : '' }}</label><input class="in" name="name" required maxlength="80" placeholder="{{ ['oidc' => 'Microsoft 365', 'oauth' => 'Our portal', 'meta' => 'Facebook', 'sms' => 'Semaphore', 'email-smtp' => 'Our mail server'][$type] }}"></div>
          @foreach($fields[$type] as $name => $def){!! $fieldInput($name, $def, $name === 'show_button' ? true : null, $type, 'n'.$type) !!}@endforeach
          <div class="actions"><button class="btn pri">Add</button></div>
        </form>
      </details>
    @endforeach
    @if($errors->any() && ! $errors->has('test'))<div class="small bad" style="margin-top:10px">{{ $errors->first() }}</div>@endif
    <p class="small muted" style="margin-top:14px">@if($org)Microsoft Entra: issuer <code class="mono">https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0</code>. Okta: <code class="mono">https://&lt;your-org&gt;.okta.com</code>. A provider signs in only people whose email is on the listed domains and who belong to {{ $org->name }} alone (or new people, if account creation is on).@else Semaphore, Twilio or Vonage keys come from their dashboards. Text-message sign-in and the daily limit are in Settings.@endif</p>
  </section>
</div>
@endsection
