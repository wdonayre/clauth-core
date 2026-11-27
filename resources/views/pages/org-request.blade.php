@extends('layouts.auth')
@section('title', __('Set up your organization'))
@section('no-brand', true)
@php($editing = (bool) $r)
@section('card')
  <span class="sico" aria-hidden="true">@include('partials.icon', ['i' => 'org', 'iconSize' => 22])</span>
  <div class="ttl">
    <h1>{{ $editing && $r->status === 'info_requested' ? __('Answer the reviewer') : __('Set up your organization') }}</h1>
    @if(! $open)
      <p class="lede">{{ __('Clauth isn’t open to new organizations yet. Contact :email if you’d like to hear when it is.', ['email' => \App\Services\Settings::get('support_email')]) }}</p>
    @elseif(! $user)
      <p class="lede">{{ __('First create a Clauth account, or sign in. Then tell us about your organization; a Clauth owner reviews every new one.') }}</p>
    @else
      <p class="lede">{{ __('Tell us a little about it. A Clauth owner reviews every new organization, usually within one working day.') }}</p>
    @endif
  </div>
  @if($open && ! $user)
    <div class="stack">
      @if(\App\Services\Settings::get('account_signup'))<a class="btn-auth pri" href="{{ url('/signup') }}">{{ __('Create an account') }}</a>@endif
      <a class="btn-auth sec" href="{{ route('login') }}">{{ __('Sign in') }}</a>
    </div>
  @elseif($open)
    @if($editing && $r->status === 'info_requested' && $r->info_message)
      <div class="notice info" role="status"><b>{{ __('The reviewer asked:') }}</b> {{ $r->info_message }}</div>
    @endif
    @include('partials.errors')
    <form class="stack" method="post" action="{{ $editing ? route('org-request.update') : route('org-request.store') }}" novalidate>
      @csrf
      <div class="hp" aria-hidden="true"><label>Website URL <input name="website_url" tabindex="-1" autocomplete="off"></label></div>
      <div class="field"><label for="on">{{ __('Organization name') }}</label><input class="input" id="on" name="org_name" value="{{ old('org_name', $r?->org_name) }}" required maxlength="120" autocomplete="organization"></div>
      <div class="field"><label for="os">{{ __('Short name') }}</label><input class="input mono" id="os" name="slug" value="{{ old('slug', $r?->slug) }}" required pattern="[a-z0-9][a-z0-9-]{2,60}" aria-describedby="os-h" autocapitalize="off" spellcheck="false">
        <span id="os-h" class="hint">{{ __('Lowercase letters, numbers and dashes, e.g.') }} <code>bakery-co</code>. {{ __('Your apps see it; it can’t change later.') }}</span></div>
      <div class="field"><label for="ow">{{ __('Website (optional)') }}</label><input class="input" id="ow" name="website" type="url" value="{{ old('website', $r?->website) }}" placeholder="https://"></div>
      <div class="field"><label for="oc">{{ __('Country') }}</label><input class="input" id="oc" name="country" value="{{ old('country', $r?->country ?? 'Philippines') }}" required maxlength="60" autocomplete="country-name"></div>
      <div class="field"><label for="oz">{{ __('How many people will sign in?') }}</label>
        <select class="input" id="oz" name="team_size" required>
          <option value="">{{ __('Choose…') }}</option>
          @foreach(\App\Models\OrganizationRequest::SIZES as $k => $label)<option value="{{ $k }}" @selected(old('team_size', $r?->team_size) === $k)>{{ $label }}</option>@endforeach
        </select></div>
      <div class="field"><label for="op">{{ __('What will you use Clauth for?') }}</label><textarea class="input" id="op" name="purpose" rows="4" required minlength="20" maxlength="2000">{{ old('purpose', $r?->purpose) }}</textarea></div>
      <p class="fine">{{ __('You’ll be its Owner: :name · :email. You can add more owners later.', ['name' => $user->name ?: $user->email, 'email' => $user->email]) }}</p>
      <label class="check top"><input type="checkbox" name="agree" value="1" @checked(old('agree', $editing)) required>
        <span>{{ __('I agree to the') }} <a href="{{ route('terms') }}" target="_blank">{{ __('Terms') }}</a> {{ __('and the') }} <a href="{{ route('dpa') }}" target="_blank">{{ __('Data Processing Agreement') }}</a> {{ __('on behalf of my organization, and I’ve read the') }} <a href="{{ route('privacy') }}" target="_blank">{{ __('Privacy policy') }}</a>.</span></label>
      <button class="btn-auth pri" type="submit">{{ $editing ? __('Send') : __('Send for review') }}</button>
      <p class="fine">{{ __('We’ll email you when it’s decided. Until then your account works, but you can’t add apps or invite people.') }}</p>
    </form>
  @endif
@endsection
@section('below')
  @if($user)<a href="{{ route('account') }}">{{ __('Back to My account') }}</a>@endif
@endsection
