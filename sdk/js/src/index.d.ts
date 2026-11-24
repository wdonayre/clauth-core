export interface ClauthConfig {
  /** e.g. https://app.helloclauth.com */
  issuer: string;
  /** The app's public (mobile/SPA) client id. */
  clientId: string;
  /** Registered redirect URL of that client — the page that calls handleRedirect(). */
  redirectUri: string;
  /** Default: "openid profile email clauth offline_access". */
  scope?: string;
  postLogoutRedirectUri?: string;
  /** Where tokens are kept. Default "session" (sessionStorage). */
  storage?: 'session' | 'local' | 'memory';
  /** Renew this many seconds before expiry. Default 60. */
  refreshBeforeSeconds?: number;
  fetch?: typeof fetch;
  navigate?: (url: string) => void;
}

export interface OrgClaim { roles: string[]; permissions: string[]; teams?: string[]; fields?: Record<string, string | number | boolean> }

export interface UserClaims { sub: string; email?: string; name?: string; amr?: string[]; iss: string; aud: string | string[]; exp: number; [k: string]: unknown }

export interface Clauth {
  signIn(opts?: { returnTo?: string; prompt?: 'login' | 'none' | 'consent'; loginHint?: string; lang?: string; redirect?: boolean }): Promise<string>;
  handleRedirect(url?: string): Promise<{ returnTo: string | null }>;
  isSignedIn(): boolean;
  user(): UserClaims | null;
  orgs(): Record<string, OrgClaim>;
  can(permission: string, org?: string): boolean;
  getAccessToken(): Promise<string>;
  fetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response>;
  signOut(opts?: { returnTo?: string; redirect?: boolean }): Promise<string>;
  onChange(fn: (user: UserClaims | null) => void): () => void;
}

export class ClauthError extends Error { code: string; constructor(code: string, description?: string) }
export function createClauth(config: ClauthConfig): Clauth;
export function decodeJwt(jwt: string): Record<string, unknown> | null;
