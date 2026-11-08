/*!
 * Clauth bar v1 — the same top bar in every app that signs in with Clauth.
 *
 *   <script src="https://auth.thecakelabs.com/bar/v1" defer></script>
 *   <clauth-bar src="/auth/clauth/bar" logout="/auth/clauth/logout" org="cakesbygrace"></clauth-bar>
 *
 * src     same-origin URL on the APP that returns Clauth's /api/v1/bar JSON for the signed-in person
 *         (the clauth-laravel kit provides /auth/clauth/bar). Not signed in → 401 → the bar hides itself.
 * logout  the app's sign-out URL (ends the app session, then Clauth's).
 * org     the organization slug the page is showing (optional).
 * read    same-origin URL the bar POSTs to when the person opens "What's new" (default: src + "/read");
 *         the app forwards it to Clauth's /api/v1/bar/read. Sends Laravel's X-XSRF-TOKEN when the cookie exists.
 *
 * Picking another organization fires a cancelable `clauth-org-change` event ({detail: {org}}) on the element.
 * If nobody calls preventDefault(), the bar stores the choice in the `clauth_org` cookie and reloads the page.
 * Served by Clauth at /bar/v1 (5-min cache). Backwards-compatible changes only; breaking ones go to /bar/v2.
 */
(() => {
  if (customElements.get('clauth-bar')) return;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const safeUrl = (u) => (/^(https?:)?\/\//i.test(u || '') || /^\/(?!\/)/.test(u || '') ? u : '#');
  const cookie = (n) => (document.cookie.split('; ').find((c) => c.startsWith(n + '=')) || '').split('=')[1];

  const CSS = `
    :host{display:block;position:sticky;top:0;z-index:2147483000;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
    :host([hidden]){display:none}
    *{box-sizing:border-box}
    .bar{height:48px;display:flex;align-items:center;gap:8px;padding:0 8px 0 14px;background:#0D1014;color:#E8EDF2;border-bottom:1px solid #1E252E;font-size:14px;line-height:1.2}
    .app{display:flex;align-items:center;gap:10px;min-width:0;color:inherit;text-decoration:none}
    .ico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font:800 13px Montserrat,Inter,sans-serif;color:#fff;flex:none;overflow:hidden}
    .ico img{max-width:100%;max-height:100%;object-fit:contain}
    .name{font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sp{flex:1}
    button{font:inherit;color:inherit}
    .round{width:40px;height:40px;border-radius:50%;border:0;background:transparent;color:#C4CDD8;display:flex;align-items:center;justify-content:center;cursor:pointer;flex:none}
    .round:hover,.round[aria-expanded="true"]{background:#1A2029;color:#fff}
    .round:focus-visible,.org:focus-visible,.item:focus-visible,.tile:focus-visible{outline:2px solid #22D3EE;outline-offset:2px}
    .av{width:32px;height:32px;border-radius:50%;background:#0E2A31;color:#22D3EE;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;border:2px solid transparent}
    .round[aria-expanded="true"] .av,.round:hover .av{border-color:#22D3EE}
    .org{display:inline-flex;align-items:center;gap:6px;height:32px;max-width:220px;padding:0 10px;border-radius:8px;border:1px solid #2A323D;background:#12161C;cursor:pointer;font-weight:600;font-size:13px}
    .org span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .org:hover,.org[aria-expanded="true"]{border-color:#22D3EE}
    .orgname{font-size:13px;color:#8B96A5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
    .panel{position:absolute;top:54px;right:8px;width:320px;max-width:calc(100vw - 16px);background:#12161C;border:1px solid #2A323D;border-radius:16px;box-shadow:0 18px 50px rgba(0,0,0,.45);color:#E8EDF2;padding:14px}
    .panel[hidden]{display:none}
    .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
    .tile{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 6px;border-radius:12px;border:1px solid transparent;color:#E8EDF2;text-decoration:none;font-weight:600;font-size:13px;text-align:center;word-break:break-word}
    .tile:hover{background:#1A2029}
    .tile.cur{border-color:#164E5A;background:#0E2A31}
    .tile .ico{width:44px;height:44px;border-radius:12px;font-size:18px}
    .item{display:flex;align-items:center;gap:10px;width:100%;min-height:44px;padding:0 12px;border:0;border-radius:10px;background:transparent;color:#E8EDF2;text-decoration:none;font-size:14px;cursor:pointer;text-align:left}
    .item:hover{background:#1A2029}
    .item.danger{color:#FCA5A5}
    .lbl{margin:2px 4px 8px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#8B96A5}
    .who{display:flex;flex-direction:column;align-items:center;gap:4px;padding:4px 0 12px;text-align:center}
    .who .av{width:56px;height:56px;font-size:20px}
    .who b{font-size:16px;margin-top:4px}
    .muted{color:#8B96A5;font-size:13px}
    .foot{border-top:1px solid #232A34;margin-top:10px;padding-top:10px;display:flex;justify-content:center;flex-wrap:wrap;gap:12px;font-size:12px;color:#8B96A5}
    .foot a{color:#8B96A5}
    .between{justify-content:space-between}
    .between a{color:#22D3EE;font-weight:600;text-decoration:none}
    .wm{font:900 12px Montserrat,Inter,sans-serif;letter-spacing:.02em}
    .wm .cl{color:transparent;-webkit-text-stroke:1px #E8EDF2}
    .wm .au{color:#22D3EE}
    .check{margin-left:auto;color:#22D3EE;font-weight:800}
    .bell{position:relative}
    .badge{position:absolute;top:5px;right:4px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;background:#EF4444;color:#fff;font:700 10.5px/17px Inter,sans-serif;text-align:center;border:2px solid #0D1014}
    .news{max-height:min(420px,70vh);overflow:auto;margin:0 -6px;padding:0 6px}
    .note{display:block;padding:10px;border-radius:10px;color:#E8EDF2;text-decoration:none}
    .note + .note{border-top:1px solid #1E252E;border-radius:0}
    a.note:hover{background:#1A2029}
    .note .t{font-weight:600;font-size:14px}
    .note .m{font-size:12px;color:#8B96A5;margin-top:2px}
    .note .b{font-size:13px;color:#C4CDD8;margin-top:6px;white-space:pre-line;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
    .new{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:5px;background:#EF4444;color:#fff;font-size:10.5px;font-weight:700;vertical-align:1px}
    .ver{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:5px;background:#1A2029;border:1px solid #2A323D;color:#C4CDD8;font:600 11px ui-monospace,Menlo,monospace}
    .empty{padding:18px 6px;text-align:center;color:#8B96A5;font-size:13px}
    @media (max-width:520px){.name{max-width:110px}.org{max-width:130px}.orgname{display:none}}
  `;

  class ClauthBar extends HTMLElement {
    static get observedAttributes() { return ['src', 'org', 'logout', 'read']; }

    constructor() {
      super();
      this.root = this.attachShadow({ mode: 'open' });
      this.data = null;
      this.open = null;
      this.onDoc = (e) => { if (this.open && !e.composedPath().includes(this)) this.toggle(null); };
      this.onKey = (e) => { if (e.key === 'Escape' && this.open) { const k = this.open; this.toggle(null); this.root.querySelector(`[data-menu="${k}"]`)?.focus(); } };
    }

    connectedCallback() {
      document.addEventListener('click', this.onDoc);
      document.addEventListener('keydown', this.onKey);
      const cached = this.cacheGet();
      if (cached) { this.data = cached; this.render(); }
      this.load();
    }

    disconnectedCallback() {
      document.removeEventListener('click', this.onDoc);
      document.removeEventListener('keydown', this.onKey);
    }

    attributeChangedCallback(name, oldV, newV) {
      if (oldV === newV || !this.isConnected) return;
      if (name === 'src') this.load(); else if (this.data) this.render();
    }

    get cacheKey() { return 'clauth-bar:' + (this.getAttribute('src') || ''); }

    cacheGet() {
      try { const v = JSON.parse(sessionStorage.getItem(this.cacheKey) || 'null'); return v && Date.now() - v.at < 300000 ? v.data : null; } catch { return null; }
    }

    async load() {
      const src = this.getAttribute('src') || '/auth/clauth/bar';
      try {
        const res = await fetch(src, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (res.status === 401 || res.status === 403) { this.hidden = true; try { sessionStorage.removeItem(this.cacheKey); } catch {} return; }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        this.data = await res.json();
        try { sessionStorage.setItem(this.cacheKey, JSON.stringify({ at: Date.now(), data: this.data })); } catch {}
        this.hidden = false;
        this.render();
      } catch (e) {
        if (!this.data) this.hidden = true; // keep a cached bar on a network blip
        console.warn('[clauth-bar]', e.message);
      }
    }

    currentOrg() {
      const orgs = this.data?.orgs || [];
      const want = this.getAttribute('org') || decodeURIComponent(cookie('clauth_org') || '');
      return orgs.find((o) => o.slug === want) || orgs[0] || null;
    }

    icon(app, big) {
      const inner = app.icon ? `<img src="${esc(safeUrl(app.icon))}" alt="">` : esc(app.letter);
      return `<span class="ico" style="background:${app.icon ? 'transparent' : esc(/^#[0-9a-f]{6}$/i.test(app.color) ? app.color : '#334155')}"${big ? '' : ' aria-hidden="true"'}>${inner}</span>`;
    }

    render() {
      const d = this.data;
      if (!d) return;
      const org = this.currentOrg();
      const orgs = d.orgs || [];
      const logout = this.getAttribute('logout');
      const roleLine = org ? `${org.role} · ${org.name}` : '';
      const o = this.open;
      const up = d.updates;
      const unread = up?.unread || 0;
      const fresh = this.freshIds || [];
      const date = (iso) => { try { return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }); } catch { return ''; } };

      this.root.innerHTML = `<style>${CSS}</style>
        <nav class="bar" aria-label="Clauth">
          <a class="app" href="${esc(safeUrl(d.app.url || '/'))}">${this.icon(d.app)}<span class="name">${esc(d.app.name)}</span></a>
          <span class="sp"></span>
          ${orgs.length > 1
            ? `<button type="button" class="org" data-menu="org" aria-haspopup="true" aria-expanded="${o === 'org'}">
                 <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M10 21v-4h4v4"/></svg>
                 <span>${esc(org?.name)}</span>
                 <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
               </button>`
            : (org ? `<span class="orgname">${esc(org.name)}</span>` : '')}
          ${up ? `<button type="button" class="round bell" data-menu="news" aria-label="What's new${unread ? `: ${unread} unread` : ''}" aria-haspopup="true" aria-expanded="${o === 'news'}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 20a2 2 0 0 0 4 0"/></svg>
            ${unread ? `<span class="badge" aria-hidden="true">${unread > 9 ? '9+' : unread}</span>` : ''}
          </button>` : ''}
          <button type="button" class="round" data-menu="apps" aria-label="Your apps" aria-haspopup="true" aria-expanded="${o === 'apps'}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">${[5, 12, 19].map((y) => [5, 12, 19].map((x) => `<circle cx="${x}" cy="${y}" r="2"/>`).join('')).join('')}</svg>
          </button>
          <button type="button" class="round" data-menu="acc" aria-label="Your account: ${esc(d.user.name)}" aria-haspopup="true" aria-expanded="${o === 'acc'}"><span class="av">${esc(d.user.initials)}</span></button>
        </nav>

        <div class="panel" role="menu" aria-label="Organization" ${o === 'org' ? '' : 'hidden'} style="width:300px">
          <p class="lbl">Use ${esc(d.app.name)} as</p>
          ${orgs.map((x) => `<button type="button" class="item" role="menuitemradio" aria-checked="${x.slug === org?.slug}" data-org="${esc(x.slug)}">
              <span><b style="font-weight:600">${esc(x.name)}</b><br><span class="muted">${esc(x.role)}</span></span>${x.slug === org?.slug ? '<span class="check">✓</span>' : ''}</button>`).join('')}
        </div>

        ${up ? `<div class="panel" role="menu" aria-label="What's new" ${o === 'news' ? '' : 'hidden'} style="width:360px">
          <p class="lbl">What's new</p>
          <div class="news">
            ${(up.items || []).length ? up.items.map((n) => {
              const inner = `<div class="t">${esc(n.title)}${n.version ? `<span class="ver">${esc(n.version)}</span>` : ''}${fresh.includes(n.id) ? '<span class="new">New</span>' : ''}</div>
                <div class="m">${esc(n.app)} · ${esc(date(n.at))}</div>${n.body ? `<div class="b">${esc(n.body)}</div>` : ''}`;
              return n.link ? `<a class="note" role="menuitem" href="${esc(safeUrl(n.link))}" target="_blank" rel="noopener">${inner}</a>` : `<div class="note" role="menuitem" tabindex="-1">${inner}</div>`;
            }).join('') : '<div class="empty">No updates yet.</div>'}
          </div>
        </div>` : ''}

        <div class="panel" role="menu" aria-label="Your apps" ${o === 'apps' ? '' : 'hidden'}>
          <div class="grid">
            ${(d.apps || []).map((a) => `<a class="tile${a.current ? ' cur' : ''}" role="menuitem" href="${esc(safeUrl(a.url))}"${a.current ? ' aria-current="page"' : ''}>${this.icon(a, true)}${esc(a.name)}</a>`).join('')}
          </div>
          <div class="foot between"><span>Only apps you have access to</span><a href="${esc(safeUrl(d.links.account))}">My account</a></div>
        </div>

        <div class="panel" role="menu" aria-label="Your account" ${o === 'acc' ? '' : 'hidden'}>
          <div class="who"><span class="av">${esc(d.user.initials)}</span><b>${esc(d.user.name)}</b><span class="muted">${esc(d.user.email)}</span>${roleLine ? `<span class="muted">${esc(roleLine)}</span>` : ''}</div>
          <a class="item" role="menuitem" href="${esc(safeUrl(d.links.account))}">My account — password, 2-step, devices</a>
          ${logout ? `<a class="item" role="menuitem" href="${esc(safeUrl(logout))}">Sign out of ${esc(d.app.name)}</a>` : ''}
          <a class="item danger" role="menuitem" href="${esc(safeUrl(d.links.signout_everywhere))}">Sign out everywhere</a>
          <div class="foot"><span>Powered by <span class="wm"><span class="cl">CL</span><span class="au">AUTH</span></span></span><a href="${esc(safeUrl(d.links.privacy))}">Privacy</a><a href="${esc(safeUrl(d.links.terms))}">Terms</a></div>
        </div>`;

      this.root.querySelectorAll('[data-menu]').forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); this.toggle(b.dataset.menu); }));
      this.root.querySelectorAll('[data-org]').forEach((b) => b.addEventListener('click', () => this.pickOrg(b.dataset.org)));
      if (o) this.root.querySelector('.panel:not([hidden]) [role^="menuitem"]')?.focus();
    }

    toggle(menu) {
      this.open = this.open === menu ? null : menu;
      if (this.open === 'news') this.markRead(); else this.freshIds = [];
      this.render();
    }

    markRead() {
      const up = this.data?.updates;
      const ids = (up?.items || []).filter((n) => n.unread).map((n) => n.id);
      if (!ids.length) return;
      this.freshIds = ids;                       // keep the "New" tags while the panel is open
      up.items.forEach((n) => { n.unread = false; });
      up.unread = 0;
      try { sessionStorage.setItem(this.cacheKey, JSON.stringify({ at: Date.now(), data: this.data })); } catch {}
      const xsrf = cookie('XSRF-TOKEN');
      fetch(this.getAttribute('read') || (this.getAttribute('src') || '/auth/clauth/bar') + '/read', {
        method: 'POST',
        credentials: 'same-origin',
        headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        body: JSON.stringify({ ids }),
      }).catch((e) => console.warn('[clauth-bar]', e.message));
    }

    pickOrg(slug) {
      this.open = null;
      const ev = new CustomEvent('clauth-org-change', { detail: { org: slug }, bubbles: true, composed: true, cancelable: true });
      if (this.dispatchEvent(ev)) {
        document.cookie = `clauth_org=${encodeURIComponent(slug)}; path=/; max-age=31536000; samesite=lax${location.protocol === 'https:' ? '; secure' : ''}`;
        location.reload();
      } else {
        this.setAttribute('org', slug);
        this.render();
      }
    }
  }

  customElements.define('clauth-bar', ClauthBar);
})();
