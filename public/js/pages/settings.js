// Settings, sign-in rules, My account, API tokens. Progressive only: every form works without this file.
(() => {
  // Settings left nav: highlight the section in view (links to other pages are marked data-nospy).
  const nav = document.querySelector('[data-subnav]');
  if (nav && 'IntersectionObserver' in window) {
    const links = [...nav.querySelectorAll('a[href*="#"]:not([data-nospy])')].filter((a) => a.getAttribute('href').startsWith('#'));
    const byId = new Map(links.map((a) => [a.getAttribute('href').slice(1), a]));
    const sections = [...byId.keys()].map((id) => document.getElementById(id)).filter(Boolean);
    const mark = (id) => links.forEach((a) => {
      const on = a.getAttribute('href') === '#' + id;
      a.classList.toggle('on', on);
      if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
    });
    if (sections.length) {
      const io = new IntersectionObserver((entries) => {
        const vis = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (vis[0]) mark(vis[0].target.id);
      }, { rootMargin: '-10% 0px -70% 0px' });
      sections.forEach((s) => io.observe(s));
    }
  }

  // Settings: show "unsaved changes" on the sticky save bar; Discard resets every field of the form.
  const form = document.getElementById('settings-form');
  const bar = document.querySelector('[data-savebar]');
  if (form && bar) {
    const note = bar.querySelector('[data-dirty-note]');
    const set = (dirty) => { bar.classList.toggle('dirty', dirty); if (note) note.hidden = !dirty; };
    const fields = () => [...form.elements].filter((el) => el.name && el.type !== 'hidden');
    const changed = () => fields().some((el) => (el.type === 'checkbox' || el.type === 'radio') ? el.checked !== el.defaultChecked : el.value !== el.defaultValue);
    document.addEventListener('input', (e) => { if (e.target.form === form) set(changed()); });
    document.addEventListener('change', (e) => { if (e.target.form === form) set(changed()); });
    form.addEventListener('reset', () => setTimeout(() => set(false), 0));
  }

  // Sign-in rules: the provider pills only matter when "Only these" is chosen.
  const modes = document.querySelectorAll('input[name="connections_mode"]');
  const pills = document.querySelector('[data-provider-pills]');
  if (modes.length && pills) {
    const sync = () => {
      const some = [...modes].some((m) => m.checked && m.value === 'some');
      pills.hidden = !some;
    };
    modes.forEach((m) => m.addEventListener('change', sync));
    sync();
  }
})();
