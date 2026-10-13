const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const s = require('../dist/security.js');

test('renderer hardening flags', () => {
  const p = s.secureWebPreferences('/x/preload.js');
  assert.equal(p.sandbox, true);
  assert.equal(p.contextIsolation, true);
  assert.equal(p.nodeIntegration, false);
  assert.equal(p.webSecurity, true);
  assert.equal(p.allowRunningInsecureContent, false);
  assert.equal(p.webviewTag, false);
});

test('server URL must be https and clean', () => {
  assert.equal(s.normaliseServerUrl('https://mdm.acme.in/admin', false), 'https://mdm.acme.in');
  assert.throws(() => s.normaliseServerUrl('http://mdm.acme.in', false), /https/);
  assert.throws(() => s.normaliseServerUrl('https://u:p@mdm.acme.in', false), /credentials/);
  assert.throws(() => s.normaliseServerUrl('https://mdm.acme.in/?token=1', false), /query/);
  assert.throws(() => s.normaliseServerUrl('http://localhost:8000', false));
  assert.equal(s.normaliseServerUrl('http://localhost:8000', true), 'http://localhost:8000');
  assert.throws(() => s.normaliseServerUrl('http://evil.example', true));
});

test('navigation locked to server origin; externals https only', () => {
  assert.equal(s.isAllowedNavigation('https://mdm.acme.in', 'https://mdm.acme.in/admin/devices'), true);
  assert.equal(s.isAllowedNavigation('https://mdm.acme.in', 'https://mdm.acme.in.evil.com/'), false);
  assert.equal(s.isAllowedNavigation('https://mdm.acme.in', 'http://mdm.acme.in/'), false);
  assert.equal(s.isSafeExternal('https://www.openstreetmap.org/'), true);
  assert.equal(s.isSafeExternal('file:///C:/Windows/system32/calc.exe'), false);
  assert.equal(s.isSafeExternal('ms-msdt:/id'), false);
});

test('downloads stay inside the export folder', () => {
  const dir = path.resolve('/exports');
  assert.equal(s.safeDownloadPath(dir, 'call-1.mp3'), path.join(dir, 'call-1.mp3'));
  assert.equal(s.safeDownloadPath(dir, '../../evil.exe'), path.join(dir, 'evil.exe'));
  assert.equal(s.safeDownloadPath(dir, 'a:b?.csv'), path.join(dir, 'a_b_.csv'));
});

test('permission requests denied by default', () => {
  for (const p of ['media', 'geolocation', 'notifications', 'openExternal', 'fullscreen']) assert.equal(s.permissionAllowed(p), false);
});
