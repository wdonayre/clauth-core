# Clauth SDKs

| Package | Folder | What |
|---|---|---|
| `@clauth/js` | `sdk/js` | Browser sign-in: PKCE, state/nonce checks, renew once across tabs (Web Locks), `can()` on the `orgs` claim, `fetch` with the token |
| `@clauth/react` | `sdk/react` | `ClauthProvider`, `useClauth`, `SignedIn` / `SignedOut`, `SignInButton`, `ClauthCallback` |

Plain ES modules with TypeScript types: no build step. Not published to npm yet (`npm publish` from each folder
when ready). Tests: `cd sdk/js && npm test` (Node's own test runner).

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
