import { app, BrowserWindow, dialog, ipcMain, Menu, session, shell } from 'electron';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { isAllowedNavigation, isSafeExternal, normaliseServerUrl, permissionAllowed, safeDownloadPath, secureWebPreferences } from './security';

type Config = { serverOrigin?: string; exportFolder?: string };
const DEV = process.env.AMMP_DESKTOP_DEV === '1';
const configPath = () => path.join(app.getPath('userData'), 'config.json');
const readConfig = (): Config => { try { return JSON.parse(fs.readFileSync(configPath(), 'utf8')); } catch { return {}; } };
const writeConfig = (c: Config) => fs.writeFileSync(configPath(), JSON.stringify(c, null, 2), { mode: 0o600 });

let win: BrowserWindow | null = null;

app.enableSandbox();
if (!app.requestSingleInstanceLock()) app.quit();

function loadTarget() {
  const cfg = readConfig();
  if (!win) return;
  if (cfg.serverOrigin) void win.loadURL(`${cfg.serverOrigin}/admin`);
  else void win.loadFile(path.join(__dirname, '..', 'static', 'setup.html'));
}

function createWindow() {
  win = new BrowserWindow({
    width: 1360, height: 860, minWidth: 900, minHeight: 600, show: false, backgroundColor: '#f5f7fa',
    webPreferences: secureWebPreferences(path.join(__dirname, 'preload.js')),
  });
  win.once('ready-to-show', () => win?.show());

  const wc = win.webContents;
  wc.on('will-navigate', (e, url) => {
    const origin = readConfig().serverOrigin;
    const isSetup = url.startsWith('file://') && url.endsWith('setup.html');
    if (!(origin && isAllowedNavigation(origin, url)) && !isSetup) e.preventDefault();
  });
  wc.on('will-redirect', (e, url) => {
    const origin = readConfig().serverOrigin;
    if (!(origin && isAllowedNavigation(origin, url))) e.preventDefault();
  });
  wc.setWindowOpenHandler(({ url }) => {
    const origin = readConfig().serverOrigin;
    if (origin && isAllowedNavigation(origin, url)) return { action: 'deny' }; // keep a single window; same-origin links load in place
    if (isSafeExternal(url)) void shell.openExternal(url);
    return { action: 'deny' };
  });
  wc.on('will-attach-webview', (e) => e.preventDefault());
  loadTarget();
}

app.whenReady().then(() => {
  const ses = session.defaultSession;
  ses.setPermissionRequestHandler((_wc, permission, cb) => cb(permissionAllowed(permission)));
  ses.setPermissionCheckHandler((_wc, permission) => permissionAllowed(permission));
  // TLS: no certificate bypass. On-prem servers must present a certificate trusted by Windows (public CA or the
  // company's internal CA installed in the Windows certificate store).
  app.on('certificate-error', (e, _wc, _url, _err, _cert, cb) => { e.preventDefault(); cb(false); });
  ses.on('will-download', (_e, item) => {
    const folder = readConfig().exportFolder;
    if (folder) item.setSavePath(safeDownloadPath(folder, item.getFilename()));
  });

  ipcMain.handle('ammp:get-config', () => readConfig());
  ipcMain.handle('ammp:save-server', async (_e, input: unknown) => {
    const origin = normaliseServerUrl(String(input ?? ''), DEV);
    const res = await fetch(`${origin}/up`).catch(() => null);
    if (!res || !res.ok) throw new Error('Could not reach the server health endpoint (/up). Check the URL and certificate.');
    writeConfig({ ...readConfig(), serverOrigin: origin });
    loadTarget();
    return origin;
  });
  ipcMain.handle('ammp:choose-export-folder', async () => {
    const r = await dialog.showOpenDialog(win!, { properties: ['openDirectory', 'createDirectory'], title: 'Choose export folder' });
    if (r.canceled || !r.filePaths[0]) return readConfig().exportFolder ?? null;
    writeConfig({ ...readConfig(), exportFolder: r.filePaths[0] });
    return r.filePaths[0];
  });

  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { label: 'File', submenu: [
      { label: 'Change server…', click: () => { writeConfig({ ...readConfig(), serverOrigin: undefined }); loadTarget(); } },
      { label: 'Choose export folder…', click: () => win && void dialog.showOpenDialog(win, { properties: ['openDirectory', 'createDirectory'] }).then((r) => { if (!r.canceled && r.filePaths[0]) writeConfig({ ...readConfig(), exportFolder: r.filePaths[0] }); }) },
      { type: 'separator' }, { role: 'quit' },
    ] },
    { label: 'View', submenu: [{ role: 'reload' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { role: 'resetZoom' }, ...(DEV ? [{ role: 'toggleDevTools' } as const] : [])] },
  ]));
  createWindow();
});

app.on('second-instance', () => { if (win) { if (win.isMinimized()) win.restore(); win.focus(); } });
app.on('window-all-closed', () => app.quit());
