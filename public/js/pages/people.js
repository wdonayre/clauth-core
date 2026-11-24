// People pages: invite email chips + job-role summary, unsaved-changes bar on the person page, row menus.
// Everything works without this file (plain textarea, always-visible Save buttons).
(function () {
  document.documentElement.classList.add('js-ready');
  const emailRe = /^[^\s@,;<>"']+@[^\s@,;<>"']+\.[^\s@,;<>"']+$/;

  // ---- Invite: email addresses as chips (the hidden textarea still submits them) ----
  document.querySelectorAll('[data-emails]').forEach((wrap) => {
    const ta = wrap.querySelector('textarea');
    const form = wrap.closest('form');
    const submit = form && form.querySelector('[data-invite-submit]');
    const box = document.createElement('div');
    box.className = 'pp-chips';
    const input = document.createElement('input');
    input.type = 'text';
    input.id = ta.id;
    input.placeholder = 'Add more, separated by commas';
    input.autocomplete = 'off';
    input.setAttribute('inputmode', 'email');
    ta.id = ta.id + '-all';
    ta.hidden = true;
    ta.required = false;
    box.appendChild(input);
    wrap.insertBefore(box, ta);
    let list = [];

    function sync() {
      ta.value = list.join(', ');
      if (submit) {
        const n = list.length + (input.value.trim() ? 1 : 0);
        submit.textContent = n > 1 ? 'Send ' + n + ' invites' : 'Send invite';
      }
    }
    function render() {
      box.querySelectorAll('.tag').forEach((t) => t.remove());
      list.forEach((e, i) => {
        const tag = document.createElement('span');
        tag.className = 'tag' + (emailRe.test(e) ? '' : ' bad');
        tag.textContent = e;
        const x = document.createElement('button');
        x.type = 'button';
        x.textContent = '✕';
        x.setAttribute('aria-label', 'Remove ' + e);
        x.addEventListener('click', () => { list.splice(i, 1); render(); input.focus(); });
        tag.appendChild(x);
        box.insertBefore(tag, input);
      });
      sync();
    }
    function add(text) {
      text.split(/[\s,;]+/).map((s) => s.trim().replace(/^[<"']+|[>"']+$/g, '').toLowerCase()).filter(Boolean)
        .forEach((e) => { if (!list.includes(e)) list.push(e); });
      render();
    }
    add(ta.value);
    box.addEventListener('click', (e) => { if (e.target === box) input.focus(); });
    input.addEventListener('keydown', (e) => {
      if (['Enter', ',', ';', ' ', 'Tab'].includes(e.key) && input.value.trim()) {
        if (e.key !== 'Tab') e.preventDefault();
        add(input.value); input.value = ''; sync();
      } else if (e.key === 'Backspace' && !input.value && list.length) {
        list.pop(); render();
      } else if (e.key === 'Enter') {
        e.preventDefault();
      }
    });
    input.addEventListener('input', () => { if (/[,;\s]/.test(input.value)) { add(input.value); input.value = ''; } sync(); });
    input.addEventListener('paste', (e) => {
      const t = (e.clipboardData || window.clipboardData).getData('text');
      if (t) { e.preventDefault(); add(t); }
    });
    input.addEventListener('blur', () => { if (input.value.trim()) { add(input.value); input.value = ''; } });
    if (form) {
      form.addEventListener('submit', (e) => {
        if (input.value.trim()) { add(input.value); input.value = ''; }
        if (!list.length) { e.preventDefault(); input.setCustomValidity('Add at least one email address.'); input.reportValidity(); return; }
        const bad = list.filter((x) => !emailRe.test(x));
        if (bad.length) { e.preventDefault(); input.setCustomValidity('Check these addresses: ' + bad.join(', ')); input.reportValidity(); }
      });
      input.addEventListener('input', () => input.setCustomValidity(''));
    }
  });

  // ---- Invite: "Finance gives: Staff in Alab ERP" ----
  document.querySelectorAll('[data-invite-form]').forEach((form) => {
    const out = form.querySelector('[data-gives-summary]');
    if (!out) return;
    function show() {
      const picked = Array.from(form.querySelectorAll('input[name="profiles[]"]:checked'))
        .filter((c) => c.closest('[data-app-orgs]') && c.closest('[data-app-orgs]').style.display !== 'none');
      out.textContent = '';
      picked.forEach((c, i) => {
        if (i) out.appendChild(document.createElement('br'));
        out.appendChild(document.createTextNode(c.dataset.name + ' gives: '));
        const b = document.createElement('b');
        b.textContent = c.dataset.gives || 'no app roles yet';
        out.appendChild(b);
      });
      out.hidden = picked.length === 0;
    }
    form.addEventListener('change', () => setTimeout(show, 0));
    show();
  });

  // ---- Person › Access/Profile: show the save bar only once something changed ----
  document.querySelectorAll('[data-dirty-form]').forEach((form) => {
    const bar = form.querySelector('[data-savebar]');
    const outside = form.id ? document.querySelectorAll('[form="' + form.id + '"]') : [];
    const mark = () => { if (bar) bar.classList.add('dirty'); form.dataset.dirty = '1'; };
    form.addEventListener('change', mark);
    form.addEventListener('input', (e) => { if (e.target.name !== 'reason') mark(); });
    outside.forEach((el) => el.addEventListener('change', mark));
    form.addEventListener('reset', () => { if (bar) bar.classList.remove('dirty'); delete form.dataset.dirty; });
    form.addEventListener('submit', () => { delete form.dataset.dirty; });
  });
  window.addEventListener('beforeunload', (e) => {
    if (document.querySelector('[data-dirty-form][data-dirty]')) { e.preventDefault(); e.returnValue = ''; }
  });

  // ---- People list: row checkboxes + bulk bar ----
  const bulk = document.querySelector('[data-bulk]');
  if (bulk) {
    const rows = () => Array.from(document.querySelectorAll('[data-bulk-row]'));
    const all = document.querySelector('[data-select-all]');
    const count = bulk.querySelector('[data-bulk-count]');
    const clear = bulk.querySelector('[data-bulk-clear]');
    const update = () => {
      const picked = rows().filter((c) => c.checked);
      rows().forEach((c) => { const tr = c.closest('tr'); if (tr) tr.classList.toggle('pp-picked', c.checked); });
      bulk.classList.toggle('on', picked.length > 0);
      count.textContent = picked.length === 1 ? '1 person selected' : picked.length + ' people selected';
      if (clear) clear.hidden = picked.length === 0;
      if (all) { all.checked = picked.length > 0 && picked.length === rows().length; all.indeterminate = picked.length > 0 && picked.length < rows().length; }
    };
    document.addEventListener('change', (e) => {
      if (e.target === all) rows().forEach((c) => { c.checked = all.checked; });
      if (e.target === all || e.target.matches('[data-bulk-row]')) update();
    });
    if (clear) clear.addEventListener('click', () => { rows().forEach((c) => { c.checked = false; }); update(); if (all) all.focus(); });
    bulk.addEventListener('click', (e) => {
      const b = e.target.closest('button[type=submit]');
      if (!b) return;
      if (!rows().some((c) => c.checked)) { e.preventDefault(); return; }
      if (b.dataset.bulkConfirm && !window.confirm(b.dataset.bulkConfirm)) e.preventDefault();
    });
    update();
  }

  // ---- Security › Delete account: the button works once the email is typed exactly ----
  document.querySelectorAll('[data-delete-form]').forEach((form) => {
    const input = form.querySelector('[data-must-equal]');
    const btn = form.querySelector('[data-delete-submit]');
    if (!input || !btn) return;
    const check = () => { btn.disabled = input.value.trim().toLowerCase() !== input.dataset.mustEqual; };
    input.addEventListener('input', check);
    check();
  });

  // ---- Row ⋯ menus: keep them on screen inside scrolling tables; one open at a time ----
  document.querySelectorAll('details.menu-d').forEach((d) => {
    d.addEventListener('toggle', () => {
      const menu = d.querySelector('.pp-menu');
      if (!menu) return;
      if (!d.open) { menu.style.cssText = ''; return; }
      document.querySelectorAll('details.menu-d[open]').forEach((o) => { if (o !== d) o.removeAttribute('open'); });
      if (!d.closest('.scroll')) return;
      const r = d.querySelector('summary').getBoundingClientRect();
      menu.style.position = 'fixed';
      menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
      const h = menu.offsetHeight;
      menu.style.top = (r.bottom + 4 + h > window.innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4) + 'px';
    });
  });
  window.addEventListener('scroll', () => document.querySelectorAll('.scroll details.menu-d[open]').forEach((d) => d.removeAttribute('open')), { passive: true });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('details.menu-d[open]').forEach((d) => { d.removeAttribute('open'); d.querySelector('summary').focus(); });
  });
})();
