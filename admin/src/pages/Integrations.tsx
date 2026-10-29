import { useState } from 'react';
import { api, get, post, fmtDate } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Notice, Page, useAction, useAsync } from '../components/ui';

const INFO: Record<string, { title: string; does: string; key: string }> = {
  smartprs: {
    title: 'SmartPRS2 (HR & payroll)',
    does: 'Sign-in is allowed only inside the employee’s Field Force geofence set in SmartPRS2. Every app login/logout becomes an IN/OUT attendance punch (with location) in SmartPRS2.',
    key: 'SmartPRS2 → Time & Attendance → API Keys → new key with the “ingest” scope.',
  },
  smartept: {
    title: 'SmartEPT (enforcement & monitoring)',
    does: 'App login/logout is sent as an IN/OUT punch (attendance, gate and reports work as for biometric punches). Every GPS fix is sent as the field trail; leaving the geofence raises a GEOFENCE_EXIT violation.',
    key: 'SmartEPT → Integrations → API keys → new key with “ingest” and “read” scopes.',
  },
};

function Connection({ c, canEdit, reload }: { c: any; canEdit: boolean; reload: () => void }) {
  const [f, setF] = useState({ base_url: c.base_url ?? '', api_key: '', enabled: c.configured ? c.enabled : true, enforce_geofence: c.enforce_geofence });
  const [result, setResult] = useState<any>(null);
  const a = useAction();
  const info = INFO[c.system];
  const q = c.queue ?? {};
  return (
    <Card title={info.title} actions={<Badge value={c.configured && c.enabled ? (c.last_status?.startsWith('OK') ? 'ok' : 'warn') : 'info'} label={c.configured ? (c.enabled ? 'connected' : 'paused') : 'not connected'} />}>
      <p className="muted" style={{ marginTop: 0 }}>{info.does}</p>
      <ErrorBox error={a.error} />
      <Field label="Server URL" hint="e.g. https://hr.yourcompany.com — the address of that system, without /api.">
        <input type="url" autoComplete="off" name={`${c.system}-url`} value={f.base_url} disabled={!canEdit} onChange={(e) => setF({ ...f, base_url: e.target.value })} placeholder="https://" />
      </Field>
      <Field label={c.configured ? `API key (stored: ${c.api_key_hint}) — leave blank to keep` : 'API key'} hint={info.key}>
        <input type="password" autoComplete="new-password" name={`${c.system}-key`} value={f.api_key} disabled={!canEdit} onChange={(e) => setF({ ...f, api_key: e.target.value })} />
      </Field>
      <label className="row"><input type="checkbox" checked={f.enabled} disabled={!canEdit} onChange={(e) => setF({ ...f, enabled: e.target.checked })} /> Enabled</label>
      {c.system === 'smartprs' && (
        <label className="row"><input type="checkbox" checked={f.enforce_geofence} disabled={!canEdit} onChange={(e) => setF({ ...f, enforce_geofence: e.target.checked })} /> Block sign-in outside the geofence (off = record only)</label>
      )}
      <div className="row" style={{ marginTop: 12 }}>
        {canEdit && <button disabled={a.busy} onClick={() => a.run(async () => { setResult(await api('PUT', `/integrations/${c.system}`, f)); setF({ ...f, api_key: '' }); reload(); })}>Save & test</button>}
        {c.configured && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { setResult(await post(`/integrations/${c.system}/test`)); reload(); })}>Test connection</button>}
      </div>
      {result && <Notice tone={result.ok ? 'ok' : 'bad'}>{result.ok ? `Connected to ${result.detail}` : `Could not connect: ${result.detail}`}</Notice>}
      {c.configured && (
        <p className="muted">Last result: {c.last_status ?? '—'}{c.last_ok_at ? ` · last OK ${fmtDate(c.last_ok_at)}` : ''} · waiting: {q.pending ?? 0} · delivered: {q.sent ?? 0}{q.failed ? ` · rejected: ${q.failed}` : ''}</p>
      )}
    </Card>
  );
}

/** Google Maps keys for the field map — set here only (stored encrypted on the server; phones get the map-display key). */
function GoogleMapsCard({ canEdit }: { canEdit: boolean }) {
  const st = useAsync(() => get('/integrations/maps'));
  const [f, setF] = useState({ api_key: '', display_key: '', remove_display_key: false });
  const [result, setResult] = useState<any>(null);
  const a = useAction();
  const d = st.data;
  return (
    <Card title="Google Maps (field map)" actions={d && <Badge value={d.key_hint ? (d.last_status?.startsWith('OK') ? 'ok' : 'warn') : 'info'} label={d.key_hint ? (d.last_status?.startsWith('OK') ? 'working' : 'check key') : 'not set'} />}>
      <p className="muted" style={{ marginTop: 0 }}>Phone app field map: nearest customers by road, driving time, routes and navigation. In Google Cloud enable <b>Maps JavaScript API</b>, <b>Routes API</b> and <b>Geocoding API</b> (billing on). Use <b>two keys</b>: the server key stays on this server; only the map display key goes to phones.</p>
      <ErrorBox error={st.error ?? a.error} />
      <Field label={d?.key_hint ? `API key (stored: ${d.key_hint}) — leave blank to keep` : 'API key'}
        hint="Server key: used only by this server for distances, routes and address lookup — never sent to phones. In Google Cloud restrict it to Routes API and Geocoding API.">
        <input type="password" autoComplete="new-password" name="gmaps-key" value={f.api_key} disabled={!canEdit} onChange={(e) => setF({ ...f, api_key: e.target.value })} />
      </Field>
      <Field label={d?.display_key_hint ? `Map display key — needed for the phone map (stored: ${d.display_key_hint})` : 'Map display key — needed for the phone map'}
        hint="A second key, for showing the map on phones. In Google Cloud restrict it to Maps JavaScript API only, and “Websites” = this server’s address (e.g. https://rovexy.yourcompany.com/*). Without it phones show “Map not set up” (distances and the list still work).">
        <input type="password" autoComplete="new-password" name="gmaps-web-key" value={f.display_key} disabled={!canEdit || f.remove_display_key} onChange={(e) => setF({ ...f, display_key: e.target.value })} />
      </Field>
      {d?.display_key_hint && <label className="row"><input type="checkbox" checked={f.remove_display_key} disabled={!canEdit} onChange={(e) => setF({ ...f, remove_display_key: e.target.checked, display_key: '' })} /> Remove the map display key</label>}
      {canEdit && <div className="row" style={{ marginTop: 12 }}>
        <button disabled={a.busy || (!d?.key_hint && !f.api_key)} onClick={() => a.run(async () => { setResult(await api('PUT', '/integrations/maps', f)); setF({ api_key: '', display_key: '', remove_display_key: false }); st.reload(); })}>Save & test</button>
      </div>}
      {result && <Notice tone={result.ok ? 'ok' : 'bad'}>{result.detail}</Notice>}
      {d?.key_hint && !d?.display_key_hint && <Notice tone="warn">No map display key yet: phones can't show the map until you add one. (For safety, phones never receive the server key.)</Notice>}
      {d?.last_status && <p className="muted">Last test: {d.last_status}</p>}
    </Card>
  );
}

export function IntegrationsPage() {
  const { can } = useAuth();
  const list = useAsync(() => get('/integrations'));
  const a = useAction();
  const canEdit = can('telephony.configure');
  return (
    <Page title="Integrations" subtitle="Connect field-force sign-in, attendance and location to SmartPRS2 and SmartEPT, and set the Google Maps key for the field map."
      actions={canEdit && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => list.setData(await post('/integrations/flush')))}>Send waiting data now</button>}>
      <ErrorBox error={list.error ?? a.error} />
      <Notice tone="info">The employee code here must be the same as the Emp. Code in SmartPRS2 and the Employee Code in SmartEPT. Data is queued and retried automatically if a system is offline.</Notice>
      <div className="grid2">
        {list.data?.map((c: any) => <Connection key={c.system + (c.last_status ?? '')} c={c} canEdit={canEdit} reload={list.reload} />)}
      </div>
      <div className="grid2" style={{ marginTop: 16 }}>
        <GoogleMapsCard canEdit={canEdit} />
      </div>
    </Page>
  );
}
