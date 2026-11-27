{{-- Setting up an organization (SPEC-signup.md): where the person's request is, or a way to start one. --}}
@if($orgRequest)
  @php($r = $orgRequest)
  <section class="card pad orgreq" aria-labelledby="orq-h">
    @if($r->status === 'pending')
      <div class="orq-top"><div><h2 id="orq-h">{{ $r->org_name }} is waiting for review</h2><p class="muted small">Sent {{ $r->created_at->format('j M Y, H:i') }}. We’ll email {{ $user->email }} when it’s decided.</p></div><span class="badge warn">Under review</span></div>
      <ol class="orq-steps" aria-label="Progress">
        <li class="done"><b>Sent</b><span>Email confirmed</span></li>
        <li class="now" aria-current="step"><b>Under review</b><span>Usually one working day</span></li>
        <li><b>Approved</b><span>You become its Owner</span></li>
      </ol>
      <p class="small muted" style="margin:0">When it’s approved you’ll turn on 2-step sign-in, then add your apps and invite your team.</p>
      <div class="actions"><a class="btn" href="{{ route('org-request.edit') }}">Edit request</a>
        <form method="post" action="{{ route('org-request.cancel') }}" data-confirm="Cancel the request for {{ $r->org_name }}?">@csrf<button class="btn danger" type="submit">Cancel request</button></form></div>
    @elseif($r->status === 'info_requested')
      <div class="orq-top"><div><h2 id="orq-h">We have a question about {{ $r->org_name }}</h2><p class="muted small">Answer it and your request goes back for review.</p></div><span class="badge warn">Needs your answer</span></div>
      <blockquote class="orq-q">{{ $r->info_message }}</blockquote>
      <div class="actions"><a class="btn pri" href="{{ route('org-request.edit') }}">Answer</a></div>
    @elseif($r->status === 'approved')
      <div class="orq-top"><div><h2 id="orq-h">{{ $r->org_name }} is approved</h2><p class="muted small">You’re its Owner. Turn on 2-step sign-in, add your apps, then invite your team.</p></div><span class="badge ok">Approved</span></div>
      @if($r->organization_id)<div class="actions"><a class="btn pri" href="{{ route('admin.orgs.show', $r->organization_id) }}">Open {{ $r->org_name }}</a></div>@endif
    @else
      <div class="orq-top"><div><h2 id="orq-h">{{ $r->status === 'expired' ? 'Your request for '.$r->org_name.' closed' : 'We couldn’t approve '.$r->org_name }}</h2>
        <p class="muted small">{{ $r->status === 'expired' ? 'It had no answer for '.\App\Models\OrganizationRequest::EXPIRES_DAYS.' days.' : ($r->reject_reason ?: 'You can send a new request.') }}</p></div></div>
      @if($orgsOpen)<div class="actions"><a class="btn" href="{{ route('org-request') }}">Send a new request</a></div>@endif
    @endif
  </section>
@elseif($orgsOpen && ! $ownsOrg)
  <section class="card pad orgreq" aria-labelledby="orq-h">
    <div class="orq-top"><div><h2 id="orq-h">Set up an organization</h2><p class="muted small">For a business or team that wants its people to sign in to its apps with Clauth. A Clauth owner reviews every new one.</p></div>
      <a class="btn" href="{{ route('org-request') }}">Get started</a></div>
  </section>
@endif
