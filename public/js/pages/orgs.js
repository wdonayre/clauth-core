// Organization pages: small enhancements only (everything works without JS).
(function () {
  // Keep a ⋯ menu inside the viewport on narrow screens: open it to the left edge when it would overflow.
  document.addEventListener('toggle', (e) => {
    const d = e.target;
    if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('menu-d') || !d.open) return;
    const pop = d.querySelector('.pop');
    if (!pop) return;
    pop.style.left = ''; pop.style.right = '';
    const r = pop.getBoundingClientRect();
    if (r.left < 8) { pop.style.left = '0'; pop.style.right = 'auto'; }
    else if (r.right > window.innerWidth - 8) { pop.style.right = '0'; pop.style.left = 'auto'; }
  }, true);
  // Close an open ⋯ menu with Escape and return focus to its button.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.menu-d[open]').forEach((d) => { d.removeAttribute('open'); const s = d.querySelector('summary'); if (s) s.focus(); });
  });
  // Logo upload: picking a file sends the form (a Save button is shown without JS).
  document.addEventListener('change', (e) => {
    const i = e.target;
    if (i instanceof HTMLInputElement && i.type === 'file' && i.hasAttribute('data-autosubmit-file') && i.files.length && i.form) i.form.requestSubmit ? i.form.requestSubmit() : i.form.submit();
  });
})();
