@unless(\App\Services\Settings::get('legal_reviewed'))
  <div role="note" style="margin:0 0 16px;padding:10px 12px;border-radius:8px;border:1px solid var(--danger,#F87171);color:var(--danger,#F87171);font-size:13px">
    <b>Draft — needs review by a lawyer.</b> Clauth isn’t open to outside organizations until this text has been reviewed. Today it covers people signing in to The Cake Labs’ own businesses’ apps.
  </div>
@endunless
