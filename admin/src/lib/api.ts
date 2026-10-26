// Same-origin JSON client. Cookie session + Laravel CSRF (XSRF-TOKEN cookie → X-XSRF-TOKEN header).
// No tokens are ever stored in localStorage/sessionStorage.

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string, public body?: unknown) {
    super(message);
  }
}

// The company's workspace: from the address /w/<slug>/admin, else the build setting (on-premises builds).
const pathWorkspace = /^\/w\/([a-z0-9][a-z0-9-]{1,38}[a-z0-9])\/admin(?:\/|$)/.exec(location.pathname)?.[1];
const workspace = pathWorkspace ?? (import.meta.env.VITE_WORKSPACE as string | undefined) ?? undefined;
export const currentWorkspace = (): string | undefined => workspace;
/** A download link (<a href>) for an API path: adds ?workspace= because links can't send the X-Workspace header. */
export const fileUrl = (path: string): string => workspace ? `${path}${path.includes('?') ? '&' : '?'}workspace=${encodeURIComponent(workspace)}` : path;
export const consoleBase = pathWorkspace ? `/w/${pathWorkspace}/admin` : '/admin';

function xsrf(): string | undefined {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : undefined;
}

let csrfReady: Promise<void> | null = null;
export function ensureCsrf(): Promise<void> {
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined);
  return csrfReady;
}

export function headers(extra: Record<string, string> = {}): Record<string, string> {
  const h: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...extra };
  const t = xsrf();
  if (t) h['X-XSRF-TOKEN'] = t;
  if (workspace) h['X-Workspace'] = workspace;
  return h;
}

// '/api/admin' for the company console, '/api/super' for the Super Admin console (main.tsx picks it from the URL).
let base = '/api/admin';
export const setApiBase = (b: string) => { base = b; };
export const apiBase = () => base;

// The app shell registers this so an expired / revoked session goes straight back to the sign-in screen.
let onUnauthorized: (() => void) | null = null;
export const setOnUnauthorized = (fn: (() => void) | null) => { onUnauthorized = fn; };

export async function api<T = any>(method: string, path: string, body?: unknown): Promise<T> {
  if (method !== 'GET') await ensureCsrf();
  const isForm = body instanceof FormData;
  const res = await fetch(`${base}${path}`, {
    method,
    credentials: 'same-origin',
    headers: headers(isForm || body === undefined ? {} : { 'Content-Type': 'application/json' }),
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
  });
  const text = await res.text();
  const data = text ? safeJson(text) : null;
  if (!res.ok) {
    const code = (data && (data.error as string)) || (res.status === 419 ? 'csrf_expired' : `http_${res.status}`);
    const serverMsg = data && typeof data.message === 'string' && data.message !== 'Please sign in.' ? (data.message as string) : null;
    const msg = res.status === 401 ? serverMsg ?? friendly('session_expired') : (data && (data.message as string)) || friendly(code);
    if (res.status === 419) csrfReady = null;
    if (res.status === 401 && onUnauthorized && !path.startsWith('/auth/')) onUnauthorized();
    throw new ApiError(res.status, code, msg, data);
  }
  return data as T;
}

function safeJson(t: string): any {
  try {
    return JSON.parse(t);
  } catch {
    return { message: t.slice(0, 200) };
  }
}

export function friendly(code: string): string {
  const map: Record<string, string> = {
    invalid_credentials: 'Email or password is incorrect.',
    invalid_code: 'That code is not valid. Check the time on your authenticator device.',
    mfa_required: 'Two-step verification is required.',
    session_expired: 'Your session expired. Sign in again.',
    forbidden: 'You do not have permission for this action.',
    csrf_expired: 'Security token expired. Please retry.',
    policy_conflicts: 'Resolve the listed policy conflicts before publishing.',
    approval_code_mismatch: 'The code does not match the code shown on the phone.',
  };
  return map[code] ?? code.replace(/_/g, ' ');
}

export const get = <T = any>(p: string) => api<T>('GET', p);
export const post = <T = any>(p: string, b?: unknown) => api<T>('POST', p, b ?? {});
export const put = <T = any>(p: string, b?: unknown) => api<T>('PUT', p, b ?? {});
export const patch = <T = any>(p: string, b?: unknown) => api<T>('PATCH', p, b ?? {});
export const del = <T = any>(p: string) => api<T>('DELETE', p);

export function qs(params: Record<string, string | number | boolean | undefined | null>): string {
  const s = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') s.set(k, String(v));
  const q = s.toString();
  return q ? `?${q}` : '';
}

export function fmtDate(iso?: string | null, tz?: string): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-IN', { timeZone: tz, dateStyle: 'medium', timeStyle: 'short' });
}

export function fmtSeconds(s?: number | null): string {
  if (s === null || s === undefined) return '—';
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  return h ? `${h}h ${m}m` : m ? `${m}m ${s % 60}s` : `${s}s`;
}

/** Download a file from the API (Excel / CSV) with the session cookie; server errors are thrown as ApiError, not shown as a raw page. */
export async function download(path: string, body?: unknown): Promise<void> {
  const res = await fetch(`${base}${path}`, body === undefined ? { credentials: 'same-origin', headers: headers() }
    : { method: 'POST', credentials: 'same-origin', headers: { ...headers(), 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) {
    const data = safeJson(await res.text());
    if (res.status === 401 && onUnauthorized) onUnauthorized();
    throw new ApiError(res.status, data?.error ?? `http_${res.status}`, data?.message ?? friendly(data?.error ?? `http_${res.status}`), data);
  }
  const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? 'export';
  const url = URL.createObjectURL(await res.blob());
  const a = Object.assign(document.createElement('a'), { href: url, download: decodeURIComponent(name) });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/** Opens a print-ready page (the browser's "Save as PDF") in a new tab. */
export function openPrint(path: string): void {
  window.open(`${base}${path}`, '_blank', 'noopener');
}
