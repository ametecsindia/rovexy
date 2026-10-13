// Pure, testable security policy for the desktop shell (no Electron imports here).
import * as path from 'node:path';

export function secureWebPreferences(preloadPath: string) {
  return {
    sandbox: true,
    contextIsolation: true,
    nodeIntegration: false,
    nodeIntegrationInWorker: false,
    nodeIntegrationInSubFrames: false,
    webSecurity: true,
    allowRunningInsecureContent: false,
    experimentalFeatures: false,
    webviewTag: false,
    spellcheck: false,
    preload: preloadPath,
  } as const;
}

/** Only https, except http://localhost / 127.0.0.1 when explicitly in development mode. */
export function normaliseServerUrl(input: string, allowLocalHttp: boolean): string {
  let u: URL;
  try {
    u = new URL(input.trim());
  } catch {
    throw new Error('Enter a full URL, e.g. https://mdm.example.com');
  }
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(u.hostname);
  if (u.protocol !== 'https:' && !(allowLocalHttp && local && u.protocol === 'http:')) {
    throw new Error('The server must use https://');
  }
  if (u.username || u.password) throw new Error('Do not put credentials in the server URL.');
  if (u.search || u.hash) throw new Error('The server URL must not contain query strings or fragments.');
  return u.origin;
}

/** In-app navigation is limited to the configured server origin. */
export function isAllowedNavigation(serverOrigin: string, target: string): boolean {
  try {
    return new URL(target).origin === serverOrigin;
  } catch {
    return false;
  }
}

/** External links may open in the system browser only if they are https and not file/custom schemes. */
export function isSafeExternal(target: string): boolean {
  try {
    return new URL(target).protocol === 'https:';
  } catch {
    return false;
  }
}

/** Keeps downloads inside the chosen export folder. */
export function safeDownloadPath(folder: string, suggestedName: string): string {
  const base = path.basename(suggestedName).replace(/[<>:"/\\|?*\x00-\x1F]/g, '_').replace(/^\.+/, '').slice(0, 150) || 'download';
  const full = path.resolve(folder, base);
  if (path.dirname(full) !== path.resolve(folder)) throw new Error('Unsafe download path');
  return full;
}

/** No device permissions are needed by the console (it only receives remote media). */
export function permissionAllowed(permission: string): boolean {
  return permission === 'clipboard-sanitized-write';
}
