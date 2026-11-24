export interface SecureStorage { getItem(k: string): Promise<string | null>; setItem(k: string, v: string): Promise<void>; removeItem(k: string): Promise<void> }
export interface NativeClauthConfig {
  issuer: string;
  /** The app's public (mobile) client. */
  clientId: string;
  /** Registered redirect URI, e.g. "com.acme.expenses:/auth". */
  redirectUri: string;
  scope?: string;
  /** e.g. { getItem: SecureStore.getItemAsync, setItem: SecureStore.setItemAsync, removeItem: SecureStore.deleteItemAsync }. Default: memory only. */
  storage?: SecureStorage;
  /** Random bytes (default crypto.getRandomValues; with Expo: expo-crypto getRandomBytes). */
  random?: (n: number) => Uint8Array;
  fetch?: typeof fetch;
}
export type FlowStep = 'identify' | 'method' | 'code' | 'two_step' | 'browser' | 'done';
export interface FlowState { flow_id: string; step: FlowStep; methods?: string[]; url?: string; reason?: string; phone?: string; sent_to?: string; [k: string]: unknown }
export interface Flow {
  readonly step: FlowStep;
  readonly state: FlowState;
  identify(email: string): Promise<Flow>;
  password(password: string): Promise<Flow>;
  sendCode(via?: 'email' | 'sms'): Promise<Flow>;
  code(code: string): Promise<Flow>;
  totp(code: string): Promise<Flow>;
  sendSms(): Promise<Flow>;
  sms(code: string): Promise<Flow>;
}
export interface NativeClauth {
  startFlow(opts?: { scope?: string }): Promise<Flow>;
  authorizeUrl(opts?: { scope?: string; loginHint?: string }): Promise<string>;
  handleRedirect(url: string): Promise<void>;
  isSignedIn(): Promise<boolean>;
  user(): Promise<Record<string, unknown> | null>;
  orgs(): Promise<Record<string, { roles: string[]; permissions: string[] }>>;
  can(permission: string, org?: string): Promise<boolean>;
  getAccessToken(): Promise<string>;
  fetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response>;
  signOut(): Promise<void>;
  onChange(fn: (user: Record<string, unknown> | null) => void): () => void;
}
export class ClauthError extends Error { code: string; status?: number; errors?: Record<string, string[]> }
export function createNativeClauth(config: NativeClauthConfig): NativeClauth;
export function sha256(text: string): Uint8Array;
