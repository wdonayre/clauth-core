import { test } from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync, sign } from 'node:crypto';
import { createClauthServer } from '../src/index.js';

const ISS = 'https://auth.test';
const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'k1', alg: 'RS256', use: 'sig' };
const enc = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const mint = (claims, kid = 'k1') => {
  const head = enc({ alg: 'RS256', kid, typ: 'JWT' }) + '.' + enc(claims);
  return head + '.' + sign('RSA-SHA256', Buffer.from(head), privateKey).toString('base64url');
};
const now = () => Math.floor(Date.now() / 1000);
const good = () => ({ iss: ISS, aud: 'xpenses', sub: 'usr_1', exp: now() + 300, orgs: { acme: { roles: ['finance'], permissions: ['expense.approve'] } } });

function server(extra = {}) {
  const calls = [];
  const fetch = async (url, init = {}) => {
    calls.push({ url, init });
    if (url.endsWith('/.well-known/openid-configuration')) return Response.json({ jwks_uri: `${ISS}/.well-known/jwks.json`, token_endpoint: `${ISS}/oauth/token`, introspection_endpoint: `${ISS}/oauth/introspect` });
    if (url.endsWith('/jwks.json')) return Response.json({ keys: [jwk] });
    if (url.endsWith('/oauth/token')) return Response.json({ access_token: 'cc-token', expires_in: 900 });
    if (url.endsWith('/access/v1/evaluation')) return Response.json({ decision: JSON.parse(init.body).action.name === 'expense.approve' });
    return new Response('{}', { status: 404 });
  };
  return { s: createClauthServer({ issuer: ISS, audience: 'xpenses', clientId: 'c', clientSecret: 's', fetch, ...extra }), calls };
}

test('verifies signature, issuer, audience and expiry', async () => {
  const { s } = server();
  const c = await s.verifyAccessToken(mint(good()));
  assert.equal(c.sub, 'usr_1');
  assert.ok(s.can(c, 'expense.approve', 'acme'));
  assert.equal(s.can(c, 'expense.approve', 'other'), false);
  await assert.rejects(s.verifyAccessToken(mint({ ...good(), aud: 'cebpos' })), { message: 'Wrong audience' });
  await assert.rejects(s.verifyAccessToken(mint({ ...good(), iss: 'https://evil.test' })), { message: 'Wrong issuer' });
  await assert.rejects(s.verifyAccessToken(mint({ ...good(), exp: now() - 120 })), { message: 'Expired' });
  const t = mint(good());
  await assert.rejects(s.verifyAccessToken(t.slice(0, -4) + 'AAAA'), { message: 'Bad signature' });
  await assert.rejects(s.verifyAccessToken(mint(good(), 'unknown')), { message: 'Unknown signing key' });
});

test('express middleware: 401 without a token, 403 without the permission', async () => {
  const { s } = server();
  const run = (mw, req) => new Promise((resolve) => {
    const res = { statusCode: 200, headers: {}, setHeader(k, v) { this.headers[k] = v; }, end(body) { resolve({ status: this.statusCode, body }); } };
    mw(req, res, () => resolve({ status: 'next', req }));
  });
  assert.equal((await run(s.requireAuth(), { headers: {} })).status, 401);
  const ok = await run(s.requireAuth(), { headers: { authorization: 'Bearer ' + mint(good()) } });
  assert.equal(ok.status, 'next');
  assert.equal((await run(s.requirePermission('expense.approve', { org: 'acme' }), ok.req)).status, 'next');
  assert.equal((await run(s.requirePermission('report.export'), ok.req)).status, 403);
});

test('client credentials are cached and AuthZEN check works', async () => {
  const { s, calls } = server();
  assert.equal(await s.check({ subject: 'usr_1', action: 'expense.approve', app: 'xpenses', org: 'acme' }), true);
  assert.equal(await s.check({ subject: 'agt_9', action: 'report.export' }), false);
  assert.equal(calls.filter((c) => c.url.endsWith('/oauth/token')).length, 1);
  const body = JSON.parse(calls.find((c) => c.url.endsWith('/evaluation')).init.body);
  assert.deepEqual(body.resource, { type: 'app', id: 'xpenses', properties: { org: 'acme' } });
});
