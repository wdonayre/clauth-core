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

  // Close any open ⋯ menu when a dialog opens from it.
  document.addEventListener('click', (e) => {
    const o = e.target.closest('.menu-d [data-open-dialog]');
    if (o) { const d = o.closest('details.menu-d'); if (d) d.removeAttribute('open'); }
  });
})();
