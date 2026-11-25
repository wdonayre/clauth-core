// @clauth/react — thin React layer over @clauth/js (no JSX, no build step).
import { createContext, createElement, useContext, useEffect, useMemo, useState } from 'react';
import { createClauth } from '@clauth/js';

const Ctx = createContext(null);

/** <ClauthProvider config={{ issuer, clientId, redirectUri }}> … </ClauthProvider> */
export function ClauthProvider({ config, client, children }) {
  const clauth = useMemo(() => client || createClauth(config), [client, config?.issuer, config?.clientId, config?.redirectUri]);
  const [user, setUser] = useState(() => clauth.user());
  useEffect(() => clauth.onChange(setUser), [clauth]);
  const value = useMemo(() => ({
    clauth, user, isSignedIn: !!user,
    signIn: (o) => clauth.signIn(o), signOut: (o) => clauth.signOut(o),
    getAccessToken: () => clauth.getAccessToken(), fetch: (i, o) => clauth.fetch(i, o),
    orgs: () => clauth.orgs(), can: (p, org) => clauth.can(p, org),
  }), [clauth, user]);
  return createElement(Ctx.Provider, { value }, children);
}

export function useClauth() {
  const v = useContext(Ctx);
  if (!v) throw new Error('useClauth() needs a <ClauthProvider> above it.');
  return v;
}

export const SignedIn = ({ children }) => (useClauth().isSignedIn ? children : null);
export const SignedOut = ({ children }) => (useClauth().isSignedIn ? null : children);

/**
 * A button that starts sign-in. Without children it says what Clauth tells it for this app ("Continue with CakesbyGrace
 * Account", or "Sign in with Clauth"). Extra props go to the <button>.
 */
export function SignInButton({ children, returnTo, loginHint, ...rest }) {
  const { signIn, clauth } = useClauth();
  const [label, setLabel] = useState(null);
  useEffect(() => {
    if (children || !clauth?.brand) return undefined;
    let live = true;
    clauth.brand().then((b) => { if (live) setLabel(b.button); }).catch(() => {});
    return () => { live = false; };
  }, [clauth, children]);
  return createElement('button', { type: 'button', ...rest, onClick: () => signIn({ returnTo, loginHint }) }, children ?? label ?? 'Sign in');
}

/** The app's brand from Clauth (null until loaded). */
export function useClauthBrand() {
  const { clauth } = useClauth();
  const [brand, setBrand] = useState(null);
  useEffect(() => {
    let live = true;
    clauth?.brand?.().then((b) => { if (live) setBrand(b); }).catch(() => {});
    return () => { live = false; };
  }, [clauth]);
  return brand;
}

/** Put on the redirect route: finishes sign-in, then calls onDone(returnTo) (default: go there). */
export function ClauthCallback({ onDone, onError, fallback = null }) {
  const { clauth } = useClauth();
  const [error, setError] = useState(null);
  useEffect(() => {
    let live = true;
    clauth.handleRedirect().then(({ returnTo }) => { if (live) (onDone || ((r) => location.replace(r || '/')))(returnTo); },
      (e) => { if (live) { setError(e); onError?.(e); } });
    return () => { live = false; };
  }, [clauth]);
  return error ? createElement('p', { role: 'alert' }, error.message) : fallback;
}
