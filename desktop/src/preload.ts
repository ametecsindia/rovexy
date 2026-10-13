import { contextBridge, ipcRenderer } from 'electron';

// Minimal, explicit bridge. Exposed only to the local setup page and the configured server origin
// (navigation to any other origin is blocked by the main process).
contextBridge.exposeInMainWorld('ammpDesktop', {
  getConfig: () => ipcRenderer.invoke('ammp:get-config'),
  saveServer: (url: string) => ipcRenderer.invoke('ammp:save-server', String(url)),
  chooseExportFolder: () => ipcRenderer.invoke('ammp:choose-export-folder'),
});
