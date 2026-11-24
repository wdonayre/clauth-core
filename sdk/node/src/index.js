// @clauth/node — Clauth on the server side. No dependencies (Node ≥ 18: fetch + crypto).
import { createPublicKey, verify as cryptoVerify } from 'node:crypto';

export class ClauthError extends Error {
  constructor(code, message, status = 401) { super(message || code); this.name = 'ClauthError'; this.code = code; this.status = status; }
}

const b64json = (s) => JSON.parse(Buffer.from(s, 'base64url').toString('utf8'));

/**
 * @param {import('./index').ClauthServerConfig} config
 */
export function createClauthServer(config) {
  const issuer = String(config.issuer).replace(/\/+$/, '');
  const fetchImpl = config.fetch || globalThis.fetch;
  const leeway = config.clockToleranceSeconds ?? 30;
  let meta = null;
  let keys = new Map();
  let keysAt = 0;
  let cc = null;

  async function discovery() {
    if (!meta) {
      const r = await fetchImpl(`${issuer}/.well-known/openid-configuration`);
      if (!r.ok) throw new ClauthError('discovery_failed', `Could not read ${issuer} discovery`, 500);
      meta = await r.json();
    }
    return meta;
  }

  async function key(kid) {
    const fresh = Date.now() - keysAt < 10 * 60 * 1000;
    if (!keys.has(kid) || !fresh) {
      // Re-read JWKS at most every 30 s when a kid is unknown (key rotation), else every 10 minutes.
      if (keys.has(kid) || Date.now() - keysAt > 30 * 1000) {
        const r = await fetchImpl((await discovery()).jwks_uri || `${issuer}/.well-known/jwks.json`);
        if (!r.ok) throw new ClauthError('jwks_failed', 'Could not read JWKS', 500);
        const { keys: list = [] } = await r.json();
        keys = new Map(list.filter((k) => k.kty === 'RSA').map((k) => [k.kid, createPublicKey({ key: k, format: 'jwk' })]));
        keysAt = Date.now();
      }
    }
    const k = keys.get(kid);
    if (!k) throw new ClauthError('invalid_token', 'Unknown signing key');
    return k;
  }

  /** Verify a Clauth access token: RS256 signature (JWKS), issuer, audience, expiry. Returns its claims. */
  async function verifyAccessToken(token, opts = {}) {
    const parts = String(token || '').split('.');
    if (parts.length !== 3) throw new ClauthError('invalid_token', 'Not a JWT');
    let header, claims;
    try { header = b64json(parts[0]); claims = b64json(parts[1]); } catch { throw new ClauthError('invalid_token', 'Malformed token'); }
    if (header.alg !== 'RS256') throw new ClauthError('invalid_token', 'Unexpected algorithm');
    const ok = cryptoVerify('RSA-SHA256', Buffer.from(`${parts[0]}.${parts[1]}`), await key(header.kid), Buffer.from(parts[2], 'base64url'));
    if (!ok) throw new ClauthError('invalid_token', 'Bad signature');
    const now = Math.floor(Date.now() / 1000);
    if (String(claims.iss).replace(/\/+$/, '') !== issuer) throw new ClauthError('invalid_token', 'Wrong issuer');
    const aud = opts.audience ?? config.audience;
    const auds = Array.isArray(claims.aud) ? claims.aud : [claims.aud];
    if (aud && !auds.includes(aud)) throw new ClauthError('invalid_token', 'Wrong audience');
    if (typeof claims.exp !== 'number' || claims.exp < now - leeway) throw new ClauthError('invalid_token', 'Expired');
    if (typeof claims.nbf === 'number' && claims.nbf > now + leeway) throw new ClauthError('invalid_token', 'Not yet valid');
    return claims;
  }

  /** Does this token's orgs claim grant the permission (in org, or any org)? */
  function can(claims, permission, org) {
    const orgs = claims?.orgs || {};
    return org ? !!orgs[org]?.permissions?.includes(permission) : Object.values(orgs).some((o) => o?.permissions?.includes(permission));
  }

  /** Express/Connect middleware: verifies the Bearer token and puts claims on req.auth. */
  function requireAuth(opts = {}) {
    return async (req, res, next) => {
      try {
        const h = req.headers?.authorization || '';
        const m = /^Bearer\s+(.+)$/i.exec(h);
        if (!m) throw new ClauthError('invalid_token', 'Missing Bearer token');
        req.auth = await verifyAccessToken(m[1], opts);
        next();
      } catch (e) {
        const status = e.status || 401;
        res.statusCode = status;
        if (res.setHeader) res.setHeader('WWW-Authenticate', `Bearer error="${e.code || 'invalid_token'}"`);
        res.end ? res.end(JSON.stringify({ error: e.code || 'invalid_token', error_description: e.message })) : next(e);
      }
    };
  }

  /** Middleware after requireAuth: 403 unless the token has the permission (org from opts.org or opts.orgFrom(req)). */
  function requirePermission(permission, opts = {}) {
    return (req, res, next) => {
      const org = opts.org ?? (opts.orgFrom ? opts.orgFrom(req) : undefined);
      if (can(req.auth, permission, org)) return next();
      res.statusCode = 403;
      res.end ? res.end(JSON.stringify({ error: 'insufficient_permission', permission })) : next(new ClauthError('forbidden', permission, 403));
    };
  }

  /** A client-credentials access token for this server's client (cached until a minute before expiry). */
  async function getClientToken(params = {}) {
    if (!config.clientId || !config.clientSecret) throw new ClauthError('no_client', 'clientId and clientSecret are needed', 500);
    const keyName = JSON.stringify(params);
    if (cc && cc.key === keyName && cc.exp - 60 > Date.now() / 1000) return cc.token;
    const r = await fetchImpl((await discovery()).token_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded',
      Authorization: 'Basic ' + Buffer.from(`${encodeURIComponent(config.clientId)}:${encodeURIComponent(config.clientSecret)}`).toString('base64') },
      body: new URLSearchParams({ grant_type: 'client_credentials', ...params }).toString() });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new ClauthError(j.error || 'token_error', j.error_description, r.status);
    cc = { key: keyName, token: j.access_token, exp: Date.now() / 1000 + (j.expires_in || 900) };
    return cc.token;
  }

  /** AuthZEN: can this person (or agent) do action in this app (and org)? Needs a client with the authzen scope. */
  async function check({ subject, action, app, org }) {
    const token = await getClientToken({ scope: 'authzen' });
    const r = await fetchImpl(`${issuer}/access/v1/evaluation`, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ subject: { type: String(subject).startsWith('agt_') ? 'agent' : 'user', id: subject }, action: { name: action },
        resource: { type: 'app', ...(app ? { id: app } : {}), ...(org ? { properties: { org } } : {}) } }) });
    if (!r.ok) throw new ClauthError('authzen_failed', `AuthZEN answered ${r.status}`, 502);
    return (await r.json()).decision === true;
  }

  /** Is this token still active (RFC 7662)? Needs clientId/clientSecret of the same app. */
  async function introspect(token) {
    const r = await fetchImpl((await discovery()).introspection_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ token, client_id: config.clientId, client_secret: config.clientSecret }).toString() });
    return r.ok ? r.json() : { active: false };
  }

  return { verifyAccessToken, can, requireAuth, requirePermission, getClientToken, check, introspect, discovery };
}
