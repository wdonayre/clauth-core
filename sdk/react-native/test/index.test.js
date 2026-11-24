import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createNativeClauth, sha256 } from '../src/index.js';

test('sha256 matches Node for PKCE-length inputs', () => {
  for (const s of ['', 'abc', 'x'.repeat(55), 'x'.repeat(64), 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'ñ✓ unicode']) {
    assert.equal(Buffer.from(sha256(s)).toString('hex'), createHash('sha256').update(s).digest('hex'));
  }
});

test('own screens: identify → password → 2-step → tokens, with PKCE', async () => {
  const seen = [];
  let challenge, stateSent;
  const fetch = async (url, init = {}) => {
    const body = init.body && init.headers['Content-Type'] === 'application/json' ? JSON.parse(init.body) : Object.fromEntries(new URLSearchParams(init.body || ''));
    seen.push({ url, body });
    if (url.endsWith('openid-configuration')) return Response.json({ token_endpoint: 'https://a.test/oauth/token', authorization_endpoint: 'https://a.test/oauth/authorize', revocation_endpoint: 'https://a.test/oauth/revoke' });
    if (url.endsWith('/flow/execute')) {
      if (!body.flow_id) { challenge = body.code_challenge; stateSent = body.state; return Response.json({ flow_id: 'f1', step: 'identify' }); }
      if (body.action === 'identify') return Response.json({ flow_id: 'f1', step: 'method', methods: ['password'] });
      if (body.action === 'password') return body.password === 'pw' ? Response.json({ flow_id: 'f1', step: 'two_step', methods: ['totp'] }) : Response.json({ title: 'Not accepted', detail: 'Wrong' }, { status: 401 });
      if (body.action === 'totp') return Response.json({ flow_id: 'f1', step: 'done', code: 'CODE', state: stateSent });
    }
    if (url.endsWith('/oauth/token')) {
      const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
      return Response.json({ access_token: `x.${b64({ orgs: { acme: { roles: ['finance'], permissions: ['expense.approve'] } } })}.y`, refresh_token: 'r1', expires_in: 900, id_token: `x.${b64({ sub: 'usr_1', email: 'maria@acme.co' })}.y` });
    }
    return new Response('{}', { status: 404 });
  };
  const c = createNativeClauth({ issuer: 'https://a.test', clientId: 'mob', redirectUri: 'com.acme.app:/auth', fetch });
  const flow = await c.startFlow();
  assert.equal(flow.step, 'identify');
  await flow.identify('maria@acme.co');
  await assert.rejects(flow.password('nope'), { code: 'Not accepted', status: 401 });
  await flow.password('pw');
  assert.equal(flow.step, 'two_step');
  await flow.totp('123456');
  assert.equal(flow.step, 'done');
  const tok = seen.find((s) => s.url.endsWith('/oauth/token')).body;
  assert.equal(Buffer.from(sha256(tok.code_verifier)).toString('base64url'), challenge);
  assert.equal((await c.user()).email, 'maria@acme.co');
  assert.ok(await c.can('expense.approve', 'acme'));
  await c.signOut();
  assert.equal(await c.isSignedIn(), false);
  assert.equal(seen.at(-1).body.token, 'r1');
});
