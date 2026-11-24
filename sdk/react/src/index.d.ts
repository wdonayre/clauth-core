import type { ReactNode, ButtonHTMLAttributes } from 'react';
import type { Clauth, ClauthConfig, OrgClaim, UserClaims } from '@clauth/js';

export interface ClauthContextValue {
  clauth: Clauth;
  user: UserClaims | null;
  isSignedIn: boolean;
  signIn: Clauth['signIn'];
  signOut: Clauth['signOut'];
  getAccessToken: Clauth['getAccessToken'];
  fetch: Clauth['fetch'];
  orgs(): Record<string, OrgClaim>;
  can(permission: string, org?: string): boolean;
}

export function ClauthProvider(props: { config?: ClauthConfig; client?: Clauth; children?: ReactNode }): JSX.Element;
export function useClauth(): ClauthContextValue;
export function SignedIn(props: { children?: ReactNode }): ReactNode;
export function SignedOut(props: { children?: ReactNode }): ReactNode;
export function SignInButton(props: ButtonHTMLAttributes<HTMLButtonElement> & { returnTo?: string; loginHint?: string }): JSX.Element;
export function ClauthCallback(props: { onDone?: (returnTo: string | null) => void; onError?: (e: Error) => void; fallback?: ReactNode }): ReactNode;
