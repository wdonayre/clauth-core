export interface ClauthServerConfig {
  issuer: string;
  /** Expected `aud` (your app key). Can also be given per call. */
  audience?: string;
  /** For getClientToken / check / introspect. */
  clientId?: string;
  clientSecret?: string;
  clockToleranceSeconds?: number;
  fetch?: typeof fetch;
}
export interface OrgClaim { roles: string[]; permissions: string[]; teams?: string[]; fields?: Record<string, unknown> }
export interface AccessClaims { iss: string; sub: string; aud: string | string[]; exp: number; client_id: string; scope: string; orgs?: Record<string, OrgClaim>; act?: { sub: string }; [k: string]: unknown }
export class ClauthError extends Error { code: string; status: number }
export interface ClauthServer {
  verifyAccessToken(token: string, opts?: { audience?: string }): Promise<AccessClaims>;
  can(claims: AccessClaims, permission: string, org?: string): boolean;
  requireAuth(opts?: { audience?: string }): (req: any, res: any, next: (e?: unknown) => void) => void;
  requirePermission(permission: string, opts?: { org?: string; orgFrom?: (req: any) => string | undefined }): (req: any, res: any, next: (e?: unknown) => void) => void;
  getClientToken(params?: Record<string, string>): Promise<string>;
  check(q: { subject: string; action: string; app?: string; org?: string }): Promise<boolean>;
  introspect(token: string): Promise<{ active: boolean; [k: string]: unknown }>;
  discovery(): Promise<Record<string, any>>;
}
export function createClauthServer(config: ClauthServerConfig): ClauthServer;
