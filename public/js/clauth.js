// Clauth — tiny progressive enhancements. Everything works without JS.
document.addEventListener('click', (e) => {
  const copy = e.target.closest('[data-copy]');
  if (copy) {
    const el = document.querySelector(copy.dataset.copy);
    if (el && navigator.clipboard) {
      navigator.clipboard.writeText(el.innerText.trim()).then(() => {
        const t = copy.textContent; copy.textContent = 'Copied'; setTimeout(() => (copy.textContent = t), 1500);
      });
    }
  }
  const sw = e.target.closest('[data-swatch]');
  if (sw) {
    const input = document.querySelector('input[name="accent_color"]');
    if (input) { input.value = sw.dataset.swatch; input.dispatchEvent(new Event('input', { bubbles: true })); }
  }
  const mode = e.target.closest('[data-preview-mode]');
  if (mode) {
    e.preventDefault();
    document.querySelectorAll('[data-preview-mode]').forEach((m) => m.classList.toggle('info', m === mode));
    refreshPreview(mode.dataset.previewMode);
  }
});

document.addEventListener('submit', (e) => {
  const msg = e.target.dataset && e.target.dataset.confirm;
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Password strength meter (length + variety; the server does the real checks).
document.querySelectorAll('[data-meter]').forEach((input) => {
  const bar = document.querySelector(input.dataset.meter);
  input.addEventListener('input', () => {
    const v = input.value;
    let s = Math.min(v.length / 16, 1) * 60;
    s += [/[a-z]/, /[A-Z]/, /\d/, /[^\w\s]/, /\s/].filter((r) => r.test(v)).length * 8;
    bar.style.width = Math.min(s, 100) + '%';
  });
});

// Branding preview follows the form without saving.
let previewMode = 'signin';
function refreshPreview(m) {
  if (m) previewMode = m;
  const frame = document.querySelector('[data-preview]');
  const form = document.querySelector('[data-brand-form]');
  if (!frame || !form) return;
  const url = new URL(frame.src, location.href);
  const theme = form.querySelector('input[name="login_theme"]:checked');
  url.searchParams.set('mode', previewMode);
  if (theme) url.searchParams.set('theme', theme.value);
  const acc = form.querySelector('input[name="accent_color"]').value;
  if (/^#[0-9A-Fa-f]{6}$/.test(acc)) url.searchParams.set('accent', acc); else url.searchParams.delete('accent');
  frame.src = url.toString();
}
const bf = document.querySelector('[data-brand-form]');
if (bf) { bf.addEventListener('change', () => refreshPreview()); bf.addEventListener('input', (e) => { if (e.target.name === 'accent_color') refreshPreview(); }); }

// Invite dialog: only show apps enabled for the chosen org.
const orgSel = document.querySelector('[data-org-filter]');
function filterApps() {
  document.querySelectorAll('[data-app-orgs]').forEach((card) => {
    const ok = card.dataset.appOrgs.split(' ').includes(orgSel.value);
    card.style.display = ok ? '' : 'none';
    if (!ok) {
      const none = card.querySelector('input[value=""]'); if (none) none.checked = true;
      card.querySelectorAll('input[type=checkbox]').forEach((c) => { c.checked = false; });
    }
  });
}
if (orgSel) { orgSel.addEventListener('change', filterApps); filterApps(); }
