@extends('layouts.auth')
@section('title', 'Use Clauth for your organization')
@section('card')
  <h1>Use Clauth for your organization</h1>
  @if(! $open)
    <p class="sub">Clauth isn’t open to new organizations yet. Contact {{ \App\Services\Settings::get('support_email') }} if you’d like to hear when it is.</p>
  @else
    <p class="sub">Clauth gives your apps one sign-in, with roles per app. Tell us about your organization; we review every request by hand.</p>
    @include('partials.errors')
    <form method="post" action="{{ url('/organizations/new') }}" novalidate>
      @csrf
      <div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website URL <input name="website_url" tabindex="-1" autocomplete="off"></label></div>
      <div class="field"><label for="on">Organization name</label><input class="input" id="on" name="org_name" value="{{ old('org_name') }}" required maxlength="120"></div>
      <div class="field"><label for="os">Short name</label><input class="input" id="os" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9][a-z0-9-]{2,60}" aria-describedby="os-h">
        <span id="os-h" class="small muted">Lowercase letters, numbers and dashes, e.g. <code>foo-ltd</code>. Your apps see it in tokens; it can’t change later.</span></div>
      <div class="field"><label for="ow">Website (optional)</label><input class="input" id="ow" name="website" type="url" value="{{ old('website') }}" placeholder="https://"></div>
      <div class="field"><label for="cn">Your name</label><input class="input" id="cn" name="contact_name" value="{{ old('contact_name') }}" required></div>
      <div class="field"><label for="ce">Your work email</label><input class="input" id="ce" name="contact_email" type="email" value="{{ old('contact_email') }}" required></div>
      <div class="field"><label for="op">What will you use Clauth for?</label><textarea class="input" id="op" name="purpose" rows="4" required minlength="20" maxlength="2000">{{ old('purpose') }}</textarea></div>
      <label style="display:flex;gap:8px;align-items:flex-start;margin:4px 0 16px"><input type="checkbox" name="agree" value="1" @checked(old('agree')) required>
        <span class="small">I agree to the <a href="{{ route('terms') }}" target="_blank">Terms</a> and the <a href="{{ route('dpa') }}" target="_blank">Data Processing Agreement</a> on behalf of my organization, and I’ve read the <a href="{{ route('privacy') }}" target="_blank">Privacy Policy</a>.</span></label>
      <button class="btn-auth pri" type="submit">Send request</button>
    </form>
  @endif
@endsection
