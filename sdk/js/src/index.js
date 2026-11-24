// @clauth/js — browser sign-in with Clauth (OpenID Connect, authorization code + PKCE). No dependencies.
// SPEC-platform §10. Tokens come straight from Clauth's token endpoint over TLS, so the ID token is checked for
// iss / aud / nonce / exp, not by signature (OIDC Core §3.1.3.7). APIs should verify access tokens against JWKS.

const enc = new TextEncoder();
const b64url = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const random = (n = 32) => b64url(crypto.getRandomValues(new Uint8Array(n)));
const sha256 = async (s) => b64url(await crypto.subtle.digest('SHA-256', enc.encode(s)));

/** Decode a JWT's claims (no signature check). */
export function decodeJwt(jwt) {
  const part = String(jwt || '').split('.')[1];
  if (!part) return null;
  const json = decodeURIComponent(atob(part.replace(/-/g, '+').replace(/_/g, '/')).split('').map((c) => '%' + c.charCodeAt(0).toString(16).padStart(2, '0')).join(''));
  return JSON.parse(json);
}

function makeStore(kind) {
  if (kind === 'memory' || typeof window === 'undefined') {
    const m = new Map();
    return { get: (k) => m.get(k) ?? null, set: (k, v) => m.set(k, v), del: (k) => m.delete(k) };
  }
  const s = kind === 'local' ? window.localStorage : window.sessionStorage;
  return { get: (k) => s.getItem(k), set: (k, v) => s.setItem(k, v), del: (k) => s.removeItem(k) };
}

export class ClauthError extends Error {
  constructor(code, description) {
    super(description || code);
    this.name = 'ClauthError';
    this.code = code;
  }
}

/**
 * @param {import('./index').ClauthConfig} config
 * @returns {import('./index').Clauth}
 */
export function createClauth(config) {
  const issuer = String(config.issuer).replace(/\/+$/, '');
  const clientId = config.clientId;
  const redirectUri = config.redirectUri;
  const scope = config.scope || 'openid profile email clauth offline_access';
  const store = makeStore(config.storage || 'session');
  const flowStore = makeStore(config.storage === 'memory' ? 'memory' : 'session');
  const key = `clauth:${clientId}`;
  const flowKey = `clauth:flow:${clientId}`;
  const fetchImpl = config.fetch || ((...a) => globalThis.fetch(...a));
  const skew = config.refreshBeforeSeconds ?? 60;
  const listeners = new Set();
  let discovery = null;
  let inflight = null;

  const read = () => { try { return JSON.parse(store.get(key) || 'null'); } catch { return null; } };
  const write = (t) => { t ? store.set(key, JSON.stringify(t)) : store.del(key); listeners.forEach((f) => { try { f(t ? decodeJwt(t.id_token) : null); } catch {} }); };

  async function meta() {
    if (!discovery) {
      const r = await fetchImpl(`${issuer}/.well-known/openid-configuration`);
      if (!r.ok) throw new ClauthError('discovery_failed', `Could not read ${issuer}/.well-known/openid-configuration`);
      discovery = await r.json();
    }
    return discovery;
  }

  async function tokenRequest(body) {
    const m = await meta();
    const r = await fetchImpl(m.token_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ client_id: clientId, ...body }).toString() });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new ClauthError(j.error || 'token_error', j.error_description);
    return j;
  }

  function save(j, nonce) {
    const now = Math.floor(Date.now() / 1000);
    const prev = read();
    const idToken = j.id_token || prev?.id_token || null;
    if (j.id_token) {
      const c = decodeJwt(j.id_token);
      const aud = Array.isArray(c.aud) ? c.aud : [c.aud];
      if (String(c.iss).replace(/\/+$/, '') !== issuer || !aud.includes(clientId) || (nonce && c.nonce !== nonce) || c.exp < now - 60) {
        throw new ClauthError('invalid_id_token', 'The ID token did not pass its checks (iss, aud, nonce, exp).');
      }
    }
    const t = { access_token: j.access_token, refresh_token: j.refresh_token || prev?.refresh_token || null, id_token: idToken,
      expires_at: now + (Number(j.expires_in) || 900) };
    write(t);
    return t;
  }

  async function refreshNow() {
    const run = async () => {
      // Another tab may have renewed while we waited for the lock: use its tokens.
      const t = read();
      if (t && t.expires_at - skew > Math.floor(Date.now() / 1000)) return t;
      if (!t?.refresh_token) { write(null); throw new ClauthError('login_required', 'Sign in again.'); }
      try {
        return save(await tokenRequest({ grant_type: 'refresh_token', refresh_token: t.refresh_token }));
      } catch (e) {
        if (e.code === 'invalid_grant') write(null);
        throw e;
      }
    };
    const locks = typeof navigator !== 'undefined' && navigator.locks;
    return locks ? locks.request(`clauth-refresh-${clientId}`, run) : run();
  }

  const api = {
    /** Send the person to Clauth to sign in. */
    async signIn(opts = {}) {
      const m = await meta();
      const verifier = random(48), state = random(), nonce = random();
      flowStore.set(flowKey, JSON.stringify({ verifier, state, nonce, returnTo: opts.returnTo ?? (typeof location !== 'undefined' ? location.href : null) }));
      const params = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: redirectUri, scope, state, nonce,
        code_challenge: await sha256(verifier), code_challenge_method: 'S256' });
      if (opts.prompt) params.set('prompt', opts.prompt);
      if (opts.loginHint) params.set('login_hint', opts.loginHint);
      if (opts.lang) params.set('lang', opts.lang);
      const url = `${m.authorization_endpoint}?${params}`;
      if (opts.redirect === false) return url;
      (config.navigate || ((u) => { location.assign(u); }))(url);
      return url;
    },

    /** On the redirect page: finish signing in. Returns { returnTo }. */
    async handleRedirect(url = typeof location !== 'undefined' ? location.href : '') {
      const q = new URL(url).searchParams;
      const flow = JSON.parse(flowStore.get(flowKey) || 'null');
      flowStore.del(flowKey);
      if (q.get('error')) throw new ClauthError(q.get('error'), q.get('error_description'));
      if (!flow || !q.get('code') || q.get('state') !== flow.state) throw new ClauthError('invalid_state', 'This sign-in did not start here, or it expired. Sign in again.');
      save(await tokenRequest({ grant_type: 'authorization_code', code: q.get('code'), redirect_uri: redirectUri, code_verifier: flow.verifier }), flow.nonce);
      return { returnTo: flow.returnTo };
    },

    isSignedIn: () => !!read(),
    /** ID token claims (sub, email, name, amr…), or null. */
    user: () => decodeJwt(read()?.id_token),
    /** The access token's `orgs` claim: { [orgSlug]: { roles, permissions, teams?, fields? } }. */
    orgs: () => decodeJwt(read()?.access_token)?.orgs ?? {},
    /** Does the person have this permission (in this organization, or any)? */
    can(permission, org) {
      const o = api.orgs();
      return org ? !!o[org]?.permissions?.includes(permission) : Object.values(o).some((x) => x.permissions?.includes(permission));
    },

    /** A valid access token; renews it (once, across tabs) when it is about to expire. */
    async getAccessToken() {
      const t = read();
      if (!t) throw new ClauthError('login_required', 'Not signed in.');
      if (t.expires_at - skew > Math.floor(Date.now() / 1000)) return t.access_token;
      inflight ||= refreshNow().finally(() => { inflight = null; });
      return (await inflight).access_token;
    },

    /** fetch() with the access token; one retry after a 401. */
    async fetch(input, init = {}) {
      const go = async () => fetchImpl(input, { ...init, headers: { ...(init.headers || {}), Authorization: `Bearer ${await api.getAccessToken()}` } });
      let r = await go();
      if (r.status === 401 && read()?.refresh_token) {
        const t = read(); write({ ...t, expires_at: 0 });
        r = await go();
      }
      return r;
    },

    /** Sign out here and at Clauth. */
    async signOut(opts = {}) {
      const t = read();
      write(null);
      const m = await meta();
      if (t?.refresh_token) {
        await fetchImpl(m.revocation_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ client_id: clientId, token: t.refresh_token }).toString() }).catch(() => {});
      }
      const params = new URLSearchParams({ client_id: clientId });
      if (t?.id_token) params.set('id_token_hint', t.id_token);
      const back = opts.returnTo ?? config.postLogoutRedirectUri;
      if (back) params.set('post_logout_redirect_uri', back);
      const url = `${m.end_session_endpoint}?${params}`;
      if (opts.redirect === false) return url;
      (config.navigate || ((u) => { location.assign(u); }))(url);
      return url;
    },

    /** Called with the user claims (or null) whenever sign-in state changes. Returns an unsubscribe function. */
    onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
  };
  return api;
}
