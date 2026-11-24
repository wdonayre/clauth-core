@extends('layouts.auth')
@section('title', __('Use Clauth for your organization'))
@section('no-brand', true)
@section('card')
  <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'org', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ __('Use Clauth for your organization') }}</h1>
    @if(! $open)
      <p class="lede">{{ __('Clauth isn’t open to new organizations yet. Contact :email if you’d like to hear when it is.', ['email' => \App\Services\Settings::get('support_email')]) }}</p>
    @else
      <p class="lede">{{ __('Clauth gives your apps one sign-in, with roles per app. Tell us about your organization; we review every request by hand.') }}</p>
    @endif
  </div>
  @if($open)
    @include('partials.errors')
    <form class="stack" method="post" action="{{ url('/organizations/new') }}" novalidate>
      @csrf
      <div class="hp" aria-hidden="true"><label>Website URL <input name="website_url" tabindex="-1" autocomplete="off"></label></div>
      <div class="field"><label for="on">{{ __('Organization name') }}</label><input class="input" id="on" name="org_name" value="{{ old('org_name') }}" required maxlength="120" autocomplete="organization"></div>
      <div class="field"><label for="os">{{ __('Short name') }}</label><input class="input" id="os" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9][a-z0-9-]{2,60}" aria-describedby="os-h" autocapitalize="off" spellcheck="false">
        <span id="os-h" class="hint">{{ __('Lowercase letters, numbers and dashes, e.g.') }} <code>foo-ltd</code>. {{ __('Your apps see it in tokens; it can’t change later.') }}</span></div>
      <div class="field"><label for="ow">{{ __('Website (optional)') }}</label><input class="input" id="ow" name="website" type="url" value="{{ old('website') }}" placeholder="https://"></div>
      <div class="field"><label for="cn">{{ __('Your name') }}</label><input class="input" id="cn" name="contact_name" value="{{ old('contact_name') }}" required autocomplete="name"></div>
      <div class="field"><label for="ce">{{ __('Your work email') }}</label><input class="input" id="ce" name="contact_email" type="email" value="{{ old('contact_email') }}" required autocomplete="email"></div>
      <div class="field"><label for="op">{{ __('What will you use Clauth for?') }}</label><textarea class="input" id="op" name="purpose" rows="4" required minlength="20" maxlength="2000">{{ old('purpose') }}</textarea></div>
      <label class="check top"><input type="checkbox" name="agree" value="1" @checked(old('agree')) required>
        <span>{{ __('I agree to the') }} <a href="{{ route('terms') }}" target="_blank">{{ __('Terms') }}</a> {{ __('and the') }} <a href="{{ route('dpa') }}" target="_blank">{{ __('Data Processing Agreement') }}</a> {{ __('on behalf of my organization, and I’ve read the') }} <a href="{{ route('privacy') }}" target="_blank">{{ __('Privacy policy') }}</a>.</span></label>
      <button class="btn-auth pri" type="submit">{{ __('Send request') }}</button>
    </form>
  @endif
@endsection
