// Clauth console: theme switch, ⌘K search, mobile menu, dialogs. Everything still works without JS.
(function () {
  const root = document.documentElement;
  // ---- Appearance: Light / Dark / Auto (remembered on this device) ----
  function applyTheme(mode) {
    if (mode === 'light' || mode === 'dark') root.dataset.theme = mode; else delete root.dataset.theme;
    root.dataset.mode = mode;
    document.querySelectorAll('[data-theme-switch] [data-mode]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.mode === mode)));
  }
  let saved = 'auto';
  try { saved = localStorage.getItem('clauth-theme') || 'auto'; } catch (e) {}
  applyTheme(saved);
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-theme-switch] [data-mode]');
    if (!b) return;
    try { if (b.dataset.mode === 'auto') localStorage.removeItem('clauth-theme'); else localStorage.setItem('clauth-theme', b.dataset.mode); } catch (err) {}
    applyTheme(b.dataset.mode);
  });

  // ---- Mobile menu ----
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-menu-toggle]');
    if (!t) return;
    const side = document.getElementById('side');
    const open = side.classList.toggle('open');
    t.setAttribute('aria-expanded', String(open));
  });

  // Close open <details> menus when clicking elsewhere.
  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.orgswitch[open], details.menu-d[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  // ---- <dialog> helpers: [data-open-dialog="#id"], [data-close-dialog] ----
  document.addEventListener('click', (e) => {
    const o = e.target.closest('[data-open-dialog]');
    if (o) { const d = document.querySelector(o.dataset.openDialog); if (d && d.showModal) { e.preventDefault(); d.showModal(); const f = d.querySelector('[autofocus],input:not([type=hidden]),select,textarea'); if (f) f.focus(); } }
    const c = e.target.closest('[data-close-dialog]');
    if (c) { const d = c.closest('dialog'); if (d) { e.preventDefault(); d.close(); } }
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('dlg')) e.target.close();
    const x = e.target.closest('details.modal [data-close]');
    if (x) { e.preventDefault(); x.closest('details.modal').removeAttribute('open'); }
  });
  // Re-open a dialog after a failed submit: <dialog data-open-on-load>
  document.querySelectorAll('dialog[data-open-on-load]').forEach((d) => d.showModal && d.showModal());

  // ---- Auto-submit filters: <select data-autosubmit> ----
  document.addEventListener('change', (e) => { const s = e.target.closest('[data-autosubmit]'); if (s && s.form) s.form.submit(); });

  // ---- ⌘K search ----
  const pal = document.querySelector('[data-palette]');
  if (!pal) return;
  const input = pal.querySelector('[data-palette-input]');
  const res = pal.querySelector('[data-palette-results]');
  const initial = res.innerHTML;
  const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  document.querySelectorAll('[data-kbd]').forEach((k) => { k.textContent = isMac ? '⌘K' : 'Ctrl K'; });
  let idx = 0, timer = null, last = '';
  function items() { return Array.from(res.querySelectorAll('.it')); }
  function mark() { items().forEach((it, i) => { it.classList.toggle('on', i === idx); it.setAttribute('aria-selected', String(i === idx)); if (i === idx) it.scrollIntoView({ block: 'nearest' }); }); }
  function open() { pal.classList.add('open'); input.value = ''; res.innerHTML = initial; idx = 0; mark(); setTimeout(() => input.focus(), 10); }
  function close() { pal.classList.remove('open'); }
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  function render(data, q) {
    const groups = [['people', 'People', 'av'], ['apps', 'Apps', 'ico sm'], ['orgs', 'Organizations', 'av muted']];
    let html = '';
    groups.forEach(([k, label, cls]) => {
      const list = data[k] || [];
      if (!list.length) return;
      html += `<div class="grp">${label}</div>`;
      list.forEach((r) => {
        const style = r.color ? ` style="background:${esc(r.color)};width:28px;height:28px"` : ' style="width:28px;height:28px;font-size:11px"';
        html += `<a class="it" role="option" href="${esc(r.href)}"><span class="${cls}"${style}>${esc(r.initials)}</span><span>${esc(r.title)}</span><span class="sub">${esc(r.sub)}</span></a>`;
      });
    });
    res.innerHTML = html || `<div class="empty"><b>No results for “${esc(q)}”</b><span>Try a name, an email or an app key.</span></div>`;
    idx = 0; mark();
  }
  input.addEventListener('input', () => {
    const q = input.value.trim();
    clearTimeout(timer);
    if (!q) { res.innerHTML = initial; idx = 0; mark(); return; }
    timer = setTimeout(() => {
      last = q;
      fetch(pal.dataset.url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((r) => r.json()).then((d) => { if (last === q) render(d, q); }).catch(() => {});
    }, 140);
  });
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.classList.contains('open') ? close() : open(); return; }
    if (e.key === '/' && !pal.classList.contains('open') && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) { e.preventDefault(); open(); return; }
    if (!pal.classList.contains('open')) return;
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, items().length - 1); mark(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); mark(); }
    else if (e.key === 'Enter') { const it = items()[idx]; if (it) { e.preventDefault(); location.href = it.href; } }
  });
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-palette-open]')) { open(); return; }
    if (e.target === pal) close();
  });
})();
