{{-- Invite people pop-up (InviteModal). Needs $orgs, $apps, $profiles (grouped by org id), $gives. --}}
@php($openInvite = request('invite') || $errors->hasAny(['emails', 'email', 'name', 'organization_id', 'roles', 'profiles', 'note']))
<dialog class="dlg pp-invite" id="invite-dlg" aria-labelledby="invite-h" @if($openInvite) data-open-on-load @endif>
  <form method="post" action="{{ route('admin.users.invite') }}" data-invite-form>@csrf
    <div class="dlg-h pp-dlg-h">
      <div><h2 id="invite-h">Invite people</h2><p>They get an email to set a password and, if needed, 2-step.</p></div>
      <button class="btn ghost icon" type="button" data-close-dialog aria-label="Close">@include('partials.icon', ['i' => 'x', 'iconSize' => 18])</button>
    </div>
    <div class="dlg-b">
      <div class="f">
        <label for="inv-emails">Email addresses</label>
        <div class="pp-emails" data-emails>
          <textarea class="in prose" id="inv-emails" name="emails" rows="2" placeholder="ana@example.com, rico@example.com" required>{{ old('emails', old('email')) }}</textarea>
        </div>
        <span class="hint">Separate several addresses with commas.</span>
        @error('emails')<span class="err">{{ $message }}</span>@enderror
        @error('email')<span class="err">{{ $message }}</span>@enderror
      </div>

      <div class="frow">
        <div class="f"><label for="inv-org">Organization</label>
          <select class="in" id="inv-org" name="organization_id" required data-org-filter>
            @foreach($orgs as $o)<option value="{{ $o->id }}" @selected(old('organization_id', isset($currentOrg) ? $currentOrg?->id : null) === $o->id)>{{ $o->name }}</option>@endforeach
          </select>
          @error('organization_id')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div class="f"><span id="inv-roles-l">Job role</span>
          @foreach($orgs as $o)
            <div class="pills" data-app-orgs="{{ $o->id }}" role="group" aria-labelledby="inv-roles-l">
              @forelse($profiles->get($o->id, collect()) as $p)
                <label class="pill"><input type="checkbox" name="profiles[]" value="{{ $p->id }}" data-gives="{{ $gives[$p->id] ?? '' }}" data-name="{{ $p->name }}" @checked(in_array($p->id, (array) old('profiles', [])))><span>{{ $p->name }}</span></label>
              @empty<span class="hint">{{ $o->name }} has no job roles yet — choose apps by hand below.</span>@endforelse
            </div>
          @endforeach
        </div>
      </div>
      <div class="callout" data-gives-summary hidden></div>

      <details class="pp-hand" @if(old('roles') && array_filter((array) old('roles'))) open @endif>
        <summary>Choose apps by hand <span class="hint">— or add exceptions to the job role</span></summary>
        <div class="pp-hand-b">
          @forelse($apps as $a)
            <div class="pp-app" data-app-orgs="{{ $a->organizations->pluck('id')->implode(' ') }}">
              <div class="pp-app-h">@include('partials.ui.app-icon', ['app' => $a, 'size' => 'sm'])<b id="inv-app-{{ $a->id }}">{{ $a->name }}</b></div>
              <div class="pills" role="radiogroup" aria-labelledby="inv-app-{{ $a->id }}">
                <label class="pill"><input type="radio" name="roles[{{ $a->id }}]" value="" @checked(! old("roles.{$a->id}"))><span>From job role</span></label>
                @foreach($a->roles as $r)<label class="pill"><input type="radio" name="roles[{{ $a->id }}]" value="{{ $r->id }}" @checked((string) old("roles.{$a->id}") === (string) $r->id)><span>{{ $r->name }}</span></label>@endforeach
              </div>
            </div>
          @empty<span class="hint">No apps are installed yet.</span>@endforelse
          <span class="hint">Only apps installed in the chosen organization are shown.</span>
        </div>
      </details>

      <label class="cb"><input type="checkbox" name="require_mfa" value="1" @checked(old('require_mfa'))> Require 2-step from the start</label>
      @if(auth()->user()->isOwner() || auth()->user()->ownedOrgIds() !== [])
        <label class="cb"><input type="checkbox" name="is_org_admin" value="1" @checked(old('is_org_admin'))> Make them an admin of this organization</label>
      @endif
      <div class="f"><label for="inv-note">Add a personal note <span class="hint">(optional)</span></label>
        <textarea class="in prose" id="inv-note" name="note" rows="2" maxlength="500" placeholder="Welcome to the team!" aria-describedby="inv-note-h">{{ old('note') }}</textarea>
        <span class="hint" id="inv-note-h">Shown in the invite email. Up to 500 characters.</span>
        @error('note')<span class="err">{{ $message }}</span>@enderror</div>
      <input type="hidden" name="send" value="0">
      <label class="cb"><input type="checkbox" name="send" value="1" @checked(old('send', '1'))> Email the invites now <span class="hint">— otherwise you get links to share yourself</span></label>
    </div>
    <div class="dlg-f pp-dlg-f">
      <button class="btn" type="button" data-close-dialog>Cancel</button>
      <button class="btn pri" type="submit" data-invite-submit>Send invites</button>
    </div>
  </form>
</dialog>
