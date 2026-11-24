// Apps pages: instant search on the list, "Connect an app" steps, new-role "Start from", color pickers, theme preview.
(function () {
  const slug = (s, sep) => String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, sep).replace(new RegExp('^[^a-z]+|' + (sep === '_' ? '_' : '-') + '+$', 'g'), '').slice(0, 40);

  // Key follows the name until someone edits the key by hand.
  document.querySelectorAll('form').forEach((form) => {
    const name = form.querySelector('[data-name]');
    const key = form.querySelector('[data-key]');
    if (!name || !key) return;
    let touched = key.value !== '';
    key.addEventListener('input', () => { touched = key.value !== ''; });
    name.addEventListener('input', () => { if (!touched) key.value = slug(name.value, key.dataset.keySep || '-'); });
  });

  // ---- Apps list: filter cards as you type (the form still works as a normal search) ----
  const search = document.querySelector('[data-app-search]');
  if (search) {
    const none = document.querySelector('[data-app-none]');
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      let shown = 0;
      document.querySelectorAll('[data-app-card]').forEach((c) => {
        const ok = !q || c.dataset.appCard.includes(q);
        c.hidden = !ok; if (ok) shown++;
      });
      if (none) none.hidden = shown > 0 || !q;
    });
  }

  // ---- Connect an app: 2 steps in one form ----
  const dlg = document.querySelector('[data-connect]');
  if (dlg) {
    const form = dlg.querySelector('form');
    const panels = Array.from(form.querySelectorAll('[data-step]'));
    const back = form.querySelector('[data-back]');
    const next = form.querySelector('[data-next]');
    const create = form.querySelector('[data-create]');
    const redirect = form.querySelector('[data-redirect]');
    let step = 1;
    // Open on the step that has a server error, if any.
    const errStep = panels.find((p) => p.querySelector('.err'));
    if (errStep) step = Number(errStep.dataset.step);
    function show() {
      panels.forEach((p) => { p.hidden = Number(p.dataset.step) !== step; });
      dlg.querySelector('[data-stepno]').textContent = String(step);
      dlg.querySelectorAll('[data-bar]').forEach((b) => b.classList.toggle('on', Number(b.dataset.bar) <= step));
      back.textContent = step === 1 ? 'Cancel' : 'Back';
      next.hidden = step !== 1;
      create.hidden = step !== 2;
    }
    function syncType() {
      const t = form.querySelector('input[name="type"]:checked');
      const m2m = t && t.value === 'm2m';
      redirect.hidden = m2m;
      redirect.querySelector('input').required = !m2m;
    }
    form.addEventListener('change', (e) => { if (e.target.name === 'type') syncType(); });
    back.addEventListener('click', () => { if (step === 1) dlg.close(); else { step = 1; show(); } });
    next.addEventListener('click', () => {
      const bad = Array.from(panels[0].querySelectorAll('input,select')).find((i) => !i.checkValidity());
      if (bad) { bad.reportValidity(); return; }
      step = 2; show();
      const f = panels[1].querySelector('input:not([type=hidden]):not([hidden])'); if (f) f.focus();
    });
    form.addEventListener('submit', (e) => {
      const bad = Array.from(form.querySelectorAll('[data-step="2"] input,[data-step="2"] select')).find((i) => !i.closest('[hidden]') && !i.checkValidity());
      if (bad) { e.preventDefault(); bad.reportValidity(); }
    });
    // Enter on step 1 goes to step 2 instead of submitting.
    panels[0].addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); next.click(); } });
    syncType(); show();
  }

  // ---- Color: swatch buttons + native picker next to a hex input ----
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-color]');
    if (b) {
      const input = b.closest('form').querySelector('[data-color-in]');
      if (input) input.value = b.dataset.color;
      b.parentElement.querySelectorAll('[data-color]').forEach((x) => x.classList.toggle('on', x === b));
    }
    const sw = e.target.closest('[data-swatch]');
    if (sw) sw.parentElement.querySelectorAll('[data-swatch]').forEach((x) => x.classList.toggle('on', x === sw));
  });
  document.querySelectorAll('[data-color-for]').forEach((picker) => {
    const input = document.querySelector(picker.dataset.colorFor);
    if (!input) return;
    picker.addEventListener('input', () => { input.value = picker.value.toUpperCase(); input.dispatchEvent(new Event('input', { bubbles: true })); });
    input.addEventListener('input', () => { if (/^#[0-9A-Fa-f]{6}$/.test(input.value)) picker.value = input.value; });
  });

  // ---- New role: "Start from" ticks that role's actions ----
  const from = document.querySelector('[data-copy-from]');
  if (from) {
    from.addEventListener('change', () => {
      const opt = from.selectedOptions[0];
      const ids = (opt && opt.dataset.perms ? opt.dataset.perms.split(' ') : []);
      from.form.querySelectorAll('[data-perm]').forEach((c) => { c.checked = ids.includes(c.value); });
    });
  }

  // ---- Themes: mini preview follows the form ----
  const tf = document.querySelector('[data-theme-form]');
  const tp = document.querySelector('[data-theme-preview]');
  if (tf && tp) {
    const bases = { classic: ['#0B0D12', '#12161E', '#22D3EE', '#F2F5F9'], daylight: ['#F7F8FA', '#FFFFFF', '#0B0D12', '#0B0D12'], midnight: ['#0B0D12', '#161B25', '#22D3EE', '#F2F5F9'] };
    const hex = (v) => (/^#[0-9A-Fa-f]{6}$/.test(v || '') ? v : null);
    const val = (n) => { const el = tf.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
    const ink = (h) => { const n = parseInt(h.slice(1), 16); const l = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255; return l > 0.6 ? '#0B0D12' : '#FFFFFF'; };
    function paint() {
      const b = bases[val('base')] || bases.midnight;
      const bg = hex(val('background')) || b[0];
      const card = hex(val('card')) || b[1];
      const acc = hex(val('accent')) || b[2];
      const r = Math.max(0, Math.min(28, parseInt(val('radius') || '12', 10) || 0));
      const btnR = { pill: '999px', square: '0' }[val('buttons')] || Math.min(r, 14) + 'px';
      tp.style.background = bg;
      const c = tp.querySelector('.tcard');
      c.style.background = card; c.style.borderRadius = r + 'px'; c.style.color = ink(card);
      c.style.fontFamily = '"' + (val('font') || 'Inter') + '", system-ui, sans-serif';
      tp.querySelector('.tcard b').style.fontFamily = '"' + (val('heading_font') || val('font') || 'Inter') + '", system-ui, sans-serif';
      tp.querySelectorAll('.tin').forEach((i) => { i.style.borderRadius = btnR; });
      const btn = tp.querySelector('.tbtn'); btn.style.background = acc; btn.style.color = ink(acc); btn.style.borderRadius = btnR;
      const tag = tp.querySelector('[data-tagline]'); if (tag) { tag.textContent = val('tagline'); tag.hidden = val('layout') !== 'split' || !val('tagline'); }
    }
    tf.addEventListener('input', paint); tf.addEventListener('change', paint); paint();
  }
})();
