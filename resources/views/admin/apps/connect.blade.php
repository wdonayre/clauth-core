{{-- "Connect an app" pop-up, steps 1–2 (step 3, the client ID + secret, opens on the new app's page). Posts to admin.apps.store. --}}
@php($reopen = request('new') || old('_form') === 'connect')
<dialog class="dlg connect" id="connect" aria-labelledby="connect-t" data-connect @if($reopen) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.apps.store') }}" novalidate>@csrf
    <input type="hidden" name="_form" value="connect">
    <div class="dlg-h">
      <div class="t"><h2 id="connect-t">Connect an app</h2><span class="stepno" aria-live="polite">Step <span data-stepno>1</span> of 3</span></div>
      <div class="steps" aria-hidden="true"><span class="on" data-bar="1"></span><span data-bar="2"></span><span data-bar="3"></span></div>
    </div>

    <div class="dlg-b" data-step="1">
      <div class="f"><label for="an">App name</label><input class="in" id="an" name="name" value="{{ old('name') }}" placeholder="My App" maxlength="80" required data-name>
        @error('name')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="ak">App key <span class="hint">— used in tokens (<code>aud</code>), can’t change later</span></label>
        <input class="in mono" id="ak" name="key" value="{{ old('key') }}" placeholder="my-app" pattern="[a-z][a-z0-9\-]{1,40}" required data-key>
        @error('key')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="ads">Short description <span class="hint">— optional</span></label><input class="in" id="ads" name="description" value="{{ old('description') }}" maxlength="120" placeholder="Expense tracking"></div>
      <fieldset class="f" style="border:0;padding:0;margin:0"><legend class="lbl" style="margin-bottom:6px">What kind of app?</legend>
        <div class="types">
          @foreach(['web' => ['Website', 'Has a server'], 'public' => ['Phone or desktop', 'No secret stored'], 'm2m' => ['Server only', 'No people sign in']] as $k => [$l, $d])
            <label class="type"><input type="radio" name="type" value="{{ $k }}" @checked(old('type', 'web') === $k) data-type>{{ $l }}<small>{{ $d }}</small></label>
          @endforeach
        </div>
        @error('type')<span class="err">{{ $message }}</span>@enderror</fieldset>
      <div class="f"><label for="acol">Icon color</label>
        <div class="colorpick">
          @foreach(['#0B0D12', '#0E7490', '#E4572E', '#15803D', '#6366F1', '#F3B23C'] as $c)<button class="dotsw" type="button" style="background:{{ $c }}" data-color="{{ $c }}" aria-label="Use {{ $c }}"></button>@endforeach
          <input class="in mono" id="acol" name="color" value="{{ old('color', '#0B0D12') }}" pattern="#[0-9A-Fa-f]{6}" data-color-in>
        </div>@error('color')<span class="err">{{ $message }}</span>@enderror</div>
    </div>

    <div class="dlg-b" data-step="2">
      <div class="f" data-redirect><label for="ar">Where to send people back after sign-in</label><input class="in mono" id="ar" name="redirect_uri" value="{{ old('redirect_uri') }}" placeholder="https://app.example.com/auth/clauth/callback">
        <span class="hint">Exact match. You can add more later on the Developer tab.</span>@error('redirect_uri')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="ah">Home page <span class="hint">— optional; its permissions file is read from here</span></label><input class="in mono" id="ah" name="home_url" value="{{ old('home_url') }}" placeholder="https://app.example.com">
        @error('home_url')<span class="err">{{ $message }}</span>@enderror</div>
      <div class="f"><label for="apub">Made by</label><select class="in" id="apub" name="publisher" required>
        @foreach($publishers as $o)<option value="{{ $o->id }}" @selected(old('publisher') === $o->id)>{{ $o->name }}</option>@endforeach</select>
        <span class="hint">The organization that publishes the app. Its owners, admins and developers manage it.</span>@error('publisher')<span class="err">{{ $message }}</span>@enderror</div>
      @if($orgs->isNotEmpty())
      <fieldset class="f" style="border:0;padding:0;margin:0"><legend class="lbl" style="margin-bottom:4px">Which organizations can use it?</legend>
        @foreach($orgs as $o)<label class="cb"><input type="checkbox" name="orgs[]" value="{{ $o->id }}" @checked(in_array($o->id, old('orgs', [])))> {{ $o->name }}</label>@endforeach
        <span class="hint">The organization that makes it can always use it.</span></fieldset>
      @endif
      <details @if($errors->hasAny(['privacy_url', 'terms_url', 'support_email'])) open @endif>
        <summary class="linkbtn" style="list-style:none;cursor:pointer">Privacy, terms and support</summary>
        <div class="form" style="margin-top:12px">
          <p class="hint" style="margin:0">Needed for apps of organizations outside your own businesses: shown on the consent screen.</p>
          <div class="f"><label for="ppriv">Privacy policy</label><input class="in mono" id="ppriv" name="privacy_url" value="{{ old('privacy_url') }}" placeholder="https://…/privacy">@error('privacy_url')<span class="err">{{ $message }}</span>@enderror</div>
          <div class="f"><label for="pterm">Terms</label><input class="in mono" id="pterm" name="terms_url" value="{{ old('terms_url') }}" placeholder="https://…/terms">@error('terms_url')<span class="err">{{ $message }}</span>@enderror</div>
          <div class="f"><label for="psup">Support email</label><input class="in" id="psup" name="support_email" type="email" value="{{ old('support_email') }}">@error('support_email')<span class="err">{{ $message }}</span>@enderror</div>
        </div>
      </details>
    </div>

    <div class="dlg-f">
      <button class="btn" type="button" data-back>Cancel</button>
      <span class="actions"><button class="btn pri" type="button" data-next>Next</button><button class="btn pri" type="submit" data-create>Create app</button></span>
    </div>
  </form>
</dialog>
