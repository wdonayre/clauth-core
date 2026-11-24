// @clauth/react-native — Clauth sign-in for React Native / Expo, no native modules required.
// Your own screens: startFlow() walks Clauth's flow API (identify → password / code → 2-step → done). When only the
// browser can continue (provider buttons, 2-step setup, consent) the flow answers step "browser" with a URL: open it
// with expo-web-browser / AuthSession and pass the redirect to handleRedirect(). Tokens go to the storage you give
// (expo-secure-store), never AsyncStorage by default.
import { sha256 } from './sha256.js';

const b64url = (bytes) => {
  let s = ''; for (const b of bytes) s += String.fromCharCode(b);
  return (typeof btoa === 'function' ? btoa(s) : Buffer.from(s, 'binary').toString('base64')).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};
const decode = (jwt) => {
  try {
    const p = String(jwt).split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const s = typeof atob === 'function' ? atob(p) : Buffer.from(p, 'base64').toString('binary');
    return JSON.parse(decodeURIComponent(s.split('').map((c) => '%' + c.charCodeAt(0).toString(16).padStart(2, '0')).join('')));
  } catch { return null; }
};

export class ClauthError extends Error {
  constructor(code, message, extra = {}) { super(message || code); this.name = 'ClauthError'; this.code = code; Object.assign(this, extra); }
}

/** @param {import('./index').NativeClauthConfig} config */
export function createNativeClauth(config) {
  const issuer = String(config.issuer).replace(/\/+$/, '');
  const fetchImpl = config.fetch || ((...a) => globalThis.fetch(...a));
  const random = config.random || ((n) => globalThis.crypto.getRandomValues(new Uint8Array(n)));
  const mem = new Map();
  const storage = config.storage || { getItem: async (k) => mem.get(k) ?? null, setItem: async (k, v) => { mem.set(k, v); }, removeItem: async (k) => { mem.delete(k); } };
  const key = `clauth.${config.clientId}`.replace(/[^A-Za-z0-9._-]/g, '_');
  const scope = config.scope || 'openid profile email clauth offline_access';
  const listeners = new Set();
  let cache, inflight, meta;

  const read = async () => { if (cache === undefined) cache = JSON.parse((await storage.getItem(key)) || 'null'); return cache; };
  const write = async (t) => { cache = t; t ? await storage.setItem(key, JSON.stringify(t)) : await storage.removeItem(key); listeners.forEach((f) => f(t ? decode(t.id_token) : null)); };
  const discovery = async () => (meta ||= await (await fetchImpl(`${issuer}/.well-known/openid-configuration`)).json());
  const pkce = () => { const verifier = b64url(random(48)); return { verifier, challenge: b64url(sha256(verifier)) }; };

  async function token(body) {
    const r = await fetchImpl((await discovery()).token_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ client_id: config.clientId, ...body }).toString() });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new ClauthError(j.error || 'token_error', j.error_description);
    const prev = await read();
    await write({ access_token: j.access_token, refresh_token: j.refresh_token || prev?.refresh_token || null, id_token: j.id_token || prev?.id_token || null,
      expires_at: Math.floor(Date.now() / 1000) + (j.expires_in || 900) });
  }

  async function flowCall(body) {
    const r = await fetchImpl(`${issuer}/api/v1/flow/execute`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ client_id: config.clientId, ...body }) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new ClauthError(j.title || 'flow_error', j.detail || j.title, { status: r.status, errors: j.errors });
    return j;
  }

  const api = {
    /** Start your own sign-in screens. Returns a Flow whose `step` says what to ask next. */
    async startFlow(opts = {}) {
      const p = pkce();
      const state = b64url(random(16));
      let s = await flowCall({ redirect_uri: config.redirectUri, scope: opts.scope || scope, code_challenge: p.challenge, code_challenge_method: 'S256', state });
      const flow = {
        get step() { return s.step; }, get state() { return s; },
        async next(action, extra = {}) {
          s = await flowCall({ flow_id: s.flow_id, action, ...extra });
          if (s.step === 'done') {
            if (s.state !== state) throw new ClauthError('invalid_state', 'The sign-in answer did not match this flow.');
            await token({ grant_type: 'authorization_code', code: s.code, redirect_uri: config.redirectUri, code_verifier: p.verifier });
          }
          if (s.step === 'browser') await storage.setItem(`${key}.pkce`, JSON.stringify({ verifier: p.verifier, state }));
          return flow;
        },
        identify: (email) => flow.next('identify', { email }),
        password: (password) => flow.next('password', { password }),
        sendCode: (via = 'email') => flow.next('send_code', { via }),
        code: (code) => flow.next('code', { code }),
        totp: (code) => flow.next('totp', { code }),
        sendSms: () => flow.next('send_sms'),
        sms: (code) => flow.next('sms', { code }),
      };
      return flow;
    },

    /** Browser sign-in URL (for provider buttons, or a "Sign in with the browser" link). */
    async authorizeUrl(opts = {}) {
      const p = pkce(); const state = b64url(random(16));
      await storage.setItem(`${key}.pkce`, JSON.stringify({ verifier: p.verifier, state }));
      const q = new URLSearchParams({ response_type: 'code', client_id: config.clientId, redirect_uri: config.redirectUri, scope: opts.scope || scope, state,
        code_challenge: p.challenge, code_challenge_method: 'S256', ...(opts.loginHint ? { login_hint: opts.loginHint } : {}) });
      return `${(await discovery()).authorization_endpoint}?${q}`;
    },

    /** After the browser comes back to your redirect URI (a flow "browser" step or authorizeUrl()). */
    async handleRedirect(url) {
      const q = new URL(url).searchParams;
      const saved = JSON.parse((await storage.getItem(`${key}.pkce`)) || 'null');
      await storage.removeItem(`${key}.pkce`);
      if (q.get('error')) throw new ClauthError(q.get('error'), q.get('error_description'));
      if (!saved || !q.get('code') || (q.get('state') && q.get('state') !== saved.state)) throw new ClauthError('invalid_state', 'Start signing in again.');
      await token({ grant_type: 'authorization_code', code: q.get('code'), redirect_uri: config.redirectUri, code_verifier: saved.verifier });
    },

    async isSignedIn() { return !!(await read()); },
    async user() { return decode((await read())?.id_token); },
    async orgs() { return decode((await read())?.access_token)?.orgs ?? {}; },
    async can(permission, org) {
      const o = await api.orgs();
      return org ? !!o[org]?.permissions?.includes(permission) : Object.values(o).some((x) => x.permissions?.includes(permission));
    },

    /** A valid access token, renewed once when it's about to expire (parallel callers share the renewal). */
    async getAccessToken() {
      const t = await read();
      if (!t) throw new ClauthError('login_required', 'Not signed in.');
      if (t.expires_at - 60 > Date.now() / 1000) return t.access_token;
      if (!t.refresh_token) { await write(null); throw new ClauthError('login_required', 'Sign in again.'); }
      inflight ||= token({ grant_type: 'refresh_token', refresh_token: t.refresh_token })
        .catch(async (e) => { if (e.code === 'invalid_grant') await write(null); throw e; })
        .finally(() => { inflight = null; });
      await inflight;
      return (await read()).access_token;
    },

    async fetch(input, init = {}) {
      return fetchImpl(input, { ...init, headers: { ...(init.headers || {}), Authorization: `Bearer ${await api.getAccessToken()}` } });
    },

    async signOut() {
      const t = await read();
      await write(null);
      if (t?.refresh_token) {
        await fetchImpl((await discovery()).revocation_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ client_id: config.clientId, token: t.refresh_token }).toString() }).catch(() => {});
      }
    },

    onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
  };
  return api;
}

export { sha256 };
