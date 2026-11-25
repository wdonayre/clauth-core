// Organization sub-pages (job roles, who has what, teams & fields, API, agents, connections, messages).
// Progressive only: every form and filter still works without this file.
(function () {
  // Live row filter: <input data-filter-rows="#table tbody tr">; rows carry data-row-text (or their text).
  document.querySelectorAll('[data-filter-rows]').forEach((input) => {
    const rows = () => Array.from(document.querySelectorAll(input.dataset.filterRows));
    const count = document.querySelector('[data-filter-count]');
    const all = rows().filter((r) => r.dataset.rowText !== undefined).length;
    const m = count && count.textContent.match(/of (\d+)/);
    const total = m ? Number(m[1]) : all;
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let shown = 0;
      rows().forEach((r) => {
        if (r.dataset.rowText === undefined) return;
        const hit = !q || r.dataset.rowText.includes(q);
        r.hidden = !hit;
        if (hit) shown++;
      });
      if (count) count.textContent = 'Showing ' + shown + ' of ' + total + (total === 1 ? ' person.' : ' people.');
    });
  });

  // Field type: show the Choices box only for the Choice type.
  document.querySelectorAll('[data-field-type]').forEach((sel) => {
    const box = sel.form && sel.form.querySelector('[data-enum-only]');
    if (!box) return;
    const sync = () => { box.hidden = sel.value !== 'enum'; };
    sel.addEventListener('change', sync);
    sync();
  });

  // Add a sign-in provider: the Provider dropdown prefills the form, swaps Apple's fields in, points the guide link
  // at that provider's setup page and previews the redirect URL from the name.
  document.querySelectorAll('[data-provider-dialog]').forEach((dlg) => {
    const sel = dlg.querySelector('[data-provider-select]');
    const type = dlg.querySelector('[data-provider-type]');
    const name = dlg.querySelector('[data-provider-name]');
    const guide = dlg.querySelector('[data-provider-guide]');
    const prev = dlg.querySelector('[data-redirect-preview]');
    if (!sel || !type || !name) return;
    const presetNames = Array.from(sel.options).map((o) => o.dataset.name).filter(Boolean);
    const slug = (s) => s.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    const preview = () => {
      if (!prev) return;
      const key = slug((prev.dataset.prefix || '') + name.value).slice(0, 32) || 'connection';
      prev.textContent = prev.dataset.base + key + '/callback';
    };
    const apply = (first) => {
      const o = sel.selectedOptions[0];
      type.value = o.dataset.type;
      dlg.querySelectorAll('[data-fields-for]').forEach((fs) => {
        const on = fs.dataset.fieldsFor === o.dataset.type;
        fs.hidden = !on; fs.disabled = !on; fs.style.display = on ? 'flex' : 'none';
      });
      const iss = dlg.querySelector('[data-fields-for="oidc"] [name="config[issuer]"]');
      const scopes = dlg.querySelector('[data-fields-for="oidc"] [name="config[scopes]"]');
      const want = o.dataset.issuer || '';
      if (!first) {
        if (!name.value || presetNames.includes(name.value)) name.value = o.dataset.name || '';
        if (iss) iss.value = want.includes('<') ? '' : want;
        if (scopes && o.dataset.scopes) scopes.value = o.dataset.scopes;
      }
      if (iss) iss.placeholder = want.includes('<') ? want : 'https://';
      if (guide) { guide.href = o.dataset.guide; guide.textContent = o.dataset.guideLabel + ' ↗'; }
      preview();
    };
    sel.addEventListener('change', () => apply(false));
    name.addEventListener('input', preview);
    apply(true);
  });

  // Close any open ⋯ menu when a dialog opens from it.
  document.addEventListener('click', (e) => {
    const o = e.target.closest('.menu-d [data-open-dialog]');
    if (o) { const d = o.closest('details.menu-d'); if (d) d.removeAttribute('open'); }
  });
})();
