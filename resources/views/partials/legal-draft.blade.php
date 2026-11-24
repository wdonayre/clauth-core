@unless(\App\Services\Settings::get('legal_reviewed'))
  <div role="note" class="draft">
    <b>Draft — needs review by a lawyer.</b> {{ __('Clauth isn’t open to outside organizations until this text has been reviewed. Today it covers people signing in to The Cake Labs’ own businesses’ apps.') }}
  </div>
@endunless
