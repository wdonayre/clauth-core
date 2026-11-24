import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createClauth } from '../src/index.js';

const ISS = 'https://auth.test';
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const jwt = (c) => `${b64({ alg: 'RS256' })}.${b64(c)}.sig`;

function setup(extra = {}) {
  const calls = [];
  let nonce = null;
  let n = 0;
  const fetch = async (url, init = {}) => {
    calls.push({ url, body: init.body ? Object.fromEntries(new URLSearchParams(init.body)) : null });
    if (url.endsWith('/.well-known/openid-configuration')) {
      return Response.json({ authorization_endpoint: `${ISS}/oauth/authorize`, token_endpoint: `${ISS}/oauth/token`,
        revocation_endpoint: `${ISS}/oauth/revoke`, end_session_endpoint: `${ISS}/oauth/logout` });
    }
    if (url.endsWith('/oauth/token')) {
      await new Promise((r) => setTimeout(r, 5));
      n++;
      return Response.json({ access_token: jwt({ orgs: { acme: { roles: ['finance'], permissions: ['expense.approve'] } }, n }), refresh_token: `r${n}`, expires_in: 900,
        id_token: jwt({ iss: ISS, aud: 'cid', sub: 'usr_1', email: 'maria@acme.co', nonce, exp: Math.floor(Date.now() / 1000) + 600 }) });
    }
    return new Response('{}', { status: 200 });
  };
  let navigated = null;
  const c = createClauth({ issuer: ISS + '/', clientId: 'cid', redirectUri: 'https://app.test/cb', storage: 'memory', fetch, navigate: (u) => { navigated = u; }, ...extra });
  return { c, calls, setNonce: (x) => { nonce = x; }, nav: () => navigated };
}

test('sign-in round trip with PKCE, state and nonce', async () => {
  const { c, calls, setNonce, nav } = setup();
  await c.signIn({ returnTo: '/home', loginHint: 'maria@acme.co' });
  const q = new URL(nav()).searchParams;
  assert.equal(q.get('code_challenge_method'), 'S256');
  assert.equal(q.get('login_hint'), 'maria@acme.co');
  assert.ok(q.get('code_challenge').length >= 43);
  setNonce(q.get('nonce'));
  const { returnTo } = await c.handleRedirect(`https://app.test/cb?code=abc&state=${q.get('state')}`);
  assert.equal(returnTo, '/home');
  const tok = calls.find((x) => x.url.endsWith('/oauth/token')).body;
  assert.equal(tok.grant_type, 'authorization_code');
  assert.ok(tok.code_verifier.length >= 43);
  assert.equal(c.user().email, 'maria@acme.co');
  assert.ok(c.can('expense.approve', 'acme'));
  assert.equal(c.can('expense.approve', 'other'), false);
});

test('a forged state or a wrong nonce is refused', async () => {
  const { c, nav, setNonce } = setup();
  await c.signIn();
  await assert.rejects(c.handleRedirect('https://app.test/cb?code=abc&state=forged'), { code: 'invalid_state' });
  await c.signIn();
  setNonce('not-the-one');
  const q = new URL(nav()).searchParams;
  await assert.rejects(c.handleRedirect(`https://app.test/cb?code=abc&state=${q.get('state')}`), { code: 'invalid_id_token' });
  assert.equal(c.isSignedIn(), false);
});

test('parallel getAccessToken calls renew once', async () => {
  const { c, calls, setNonce, nav } = setup({ refreshBeforeSeconds: 100000 }); // every token counts as "about to expire"
  await c.signIn();
  const q = new URL(nav()).searchParams;
  setNonce(q.get('nonce'));
  await c.handleRedirect(`https://app.test/cb?code=abc&state=${q.get('state')}`);
  const tokens = () => calls.filter((x) => x.url.endsWith('/oauth/token'));
  assert.equal(tokens().length, 1);
  const [a, b, d] = await Promise.all([c.getAccessToken(), c.getAccessToken(), c.getAccessToken()]);
  assert.equal(tokens().length, 2); // one renewal for three callers
  assert.equal(tokens()[1].body.grant_type, 'refresh_token');
  assert.equal(tokens()[1].body.refresh_token, 'r1');
  assert.ok(a === b && b === d);
});

test('sign-out revokes the refresh token and goes to Clauth', async () => {
  const { c, calls, setNonce, nav } = setup();
  await c.signIn();
  const q = new URL(nav()).searchParams;
  setNonce(q.get('nonce'));
  await c.handleRedirect(`https://app.test/cb?code=abc&state=${q.get('state')}`);
  const url = await c.signOut({ returnTo: 'https://app.test/', redirect: false });
  assert.ok(url.startsWith('https://auth.test/oauth/logout?'));
  assert.equal(new URL(url).searchParams.get('post_logout_redirect_uri'), 'https://app.test/');
  assert.equal(calls.find((x) => x.url.endsWith('/oauth/revoke')).body.token, 'r1');
  assert.equal(c.isSignedIn(), false);
});
