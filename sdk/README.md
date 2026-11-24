# Clauth SDKs

| Package | Folder | What |
|---|---|---|
| `@clauth/js` | `sdk/js` | Browser sign-in: PKCE, state/nonce checks, renew once across tabs (Web Locks), `can()` on the `orgs` claim, `fetch` with the token |
| `@clauth/react` | `sdk/react` | `ClauthProvider`, `useClauth`, `SignedIn` / `SignedOut`, `SignInButton`, `ClauthCallback` |
| `@clauth/node` | `sdk/node` | Servers: verify access tokens against JWKS, Express `requireAuth` / `requirePermission`, client credentials, AuthZEN `check`, introspection |
| `@clauth/react-native` | `sdk/react-native` | React Native / Expo: your own sign-in screens over the flow API (identify → password / code → 2-step), browser fallback, secure storage, renewal |

Plain ES modules with TypeScript types: no build step. Not published to npm yet (`npm publish` from each folder
when ready). Tests: `npm test` in each folder (Node's own test runner, no installs).

```js
import { createClauth } from '@clauth/js';
const clauth = createClauth({ issuer: 'https://app.helloclauth.com', clientId: '<public client id>', redirectUri: 'https://app.example.com/callback' });
await clauth.signIn();                       // → Clauth
await clauth.handleRedirect();               // on /callback
const r = await clauth.fetch('/api/expenses'); // Bearer token, renewed when needed
clauth.can('expense.approve', 'acme');
```

```jsx
<ClauthProvider config={{ issuer, clientId, redirectUri }}>
  <SignedOut><SignInButton /></SignedOut>
  <SignedIn><App /></SignedIn>
</ClauthProvider>
// on the /callback route: <ClauthCallback />
```

Browser apps call `/oauth/token`, `/oauth/revoke` and `/userinfo` from their own origin: Clauth allows CORS for the
origins of a client's registered redirect URLs.

```js
// Node API
import { createClauthServer } from '@clauth/node';
const clauth = createClauthServer({ issuer: 'https://app.helloclauth.com', audience: 'xpenses' });
app.use('/api', clauth.requireAuth());
app.post('/api/approve', clauth.requirePermission('expense.approve', { orgFrom: (req) => req.body.org }), handler);
```

```js
// React Native (own screens)
const clauth = createNativeClauth({ issuer, clientId, redirectUri: 'com.acme.expenses:/auth', storage: SecureStoreAdapter });
const flow = await clauth.startFlow();
await flow.identify(email);           // flow.state.methods → ['password', 'email_code']
await flow.password(password);        // or flow.sendCode('email') then flow.code(code)
if (flow.step === 'two_step') await flow.totp(code);
if (flow.step === 'browser') openBrowser(flow.state.url);   // then clauth.handleRedirect(url)
```
