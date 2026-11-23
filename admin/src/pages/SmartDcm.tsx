import { useState } from 'react';
import { api, get, post, fmtDate } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Notice, Page, Table, useAction, useAsync } from '../components/ui';

/**
 * Administration → SmartDCM (23 Nov 2026): connect ROVEXY to the company's SmartDCM.
 * From SmartDCM: portfolios, cases and allocation, users and teams, calling (CRM id, numbers, Smart31, WebRTC).
 * To SmartDCM: outcomes, PTPs, payments, calls with recordings, field visits, notes and re-allocations.
 */
const OPTIONS: [string, string][] = [
  ['auto_create_users', 'Create SmartDCM users that are not in ROVEXY yet (telecallers, FOS, team leaders, managers). Matched first by employee code, e-mail, then mobile.'],
  ['share_passwords', 'Same password as SmartDCM (when SmartDCM shares it). People then sign in to ROVEXY with their SmartDCM password.'],
  ['use_calling', 'Use SmartDCM’s calling account: CRM id, company numbers, Smart31 pool and WebRTC come from SmartDCM.'],
  ['send_results', 'Send work done in ROVEXY back to SmartDCM: outcomes, PTPs, payments, calls with recordings, field visits, remarks.'],
  ['send_allocations', 'Send re-allocations made in ROVEXY back to SmartDCM.'],
];

export function SmartDcmPage() {
  const { can } = useAuth();
  const st = useAsync(() => get('/integrations/smartdcm'));
  const d = st.data;
  const canEdit = can('telephony.configure');
  return (
    <Page title="SmartDCM" subtitle="Connect ROVEXY with your SmartDCM: portfolios, allocation, users and calling come from SmartDCM; field and calling results go back to it."
      actions={d?.configured && <button className="secondary" onClick={st.reload}>Refresh</button>}>
      <ErrorBox error={st.error} />
      {d && !d.in_plan && <Notice tone="warn">SmartDCM integration is not part of your ROVEXY plan. Contact Ametecs to add it.</Notice>}
      {d && <div className="grid2">
        <ConnectionCard d={d} canEdit={canEdit && d.in_plan} reload={st.reload} />
        <StatusCard d={d} canEdit={canEdit && d.in_plan} reload={st.reload} />
      </div>}
      {d?.inbound && <div style={{ marginTop: 16 }}><InboundCard d={d} canEdit={canEdit} /></div>}
      {d?.unmatched_users?.length > 0 && <div style={{ marginTop: 16 }}>
        <Card title={`SmartDCM users not in ROVEXY · ${d.unmatched_users.length}`}>
          <p className="muted" style={{ marginTop: 0 }}>Not created automatically. Add them under Team → People with the same employee code, or raise the plan's user limit; the next sync links them.</p>
          <Table rows={d.unmatched_users} columns={[{ key: 'name', label: 'Name' }, { key: 'role', label: 'SmartDCM role' }, { key: 'reason', label: 'Why' }, { key: 'id', label: 'SmartDCM id' }]} />
        </Card>
      </div>}
    </Page>
  );
}

function ConnectionCard({ d, canEdit, reload }: { d: any; canEdit: boolean; reload: () => void }) {
  const [f, setF] = useState({ base_url: d.base_url ?? '', api_key: '', enabled: d.configured ? d.enabled : true, options: { ...d.options } as Record<string, boolean> });
  const [result, setResult] = useState<any>(null);
  const a = useAction();
  return (
    <Card title="Connection" actions={<Badge value={d.configured && d.enabled ? (d.last_status?.startsWith('OK') ? 'ok' : 'warn') : 'info'} label={d.configured ? (d.enabled ? 'connected' : 'paused') : 'not connected'} />}>
      <ErrorBox error={a.error} />
      <Field label="SmartDCM address" hint="The address you open SmartDCM with, e.g. https://yourcompany.smartdcm.app — without /api.">
        <input type="url" autoComplete="off" name="smartdcm-url" value={f.base_url} disabled={!canEdit} onChange={(e) => setF({ ...f, base_url: e.target.value })} placeholder="https://" />
      </Field>
      <Field label={d.configured ? `ROVEXY API key from SmartDCM (stored: ${d.api_key_hint}) — leave blank to keep` : 'ROVEXY API key from SmartDCM'}
        hint="In SmartDCM: Settings → Integrations → ROVEXY → Create API key. It belongs to your CRM id only.">
        <input type="password" autoComplete="new-password" name="smartdcm-key" value={f.api_key} disabled={!canEdit} onChange={(e) => setF({ ...f, api_key: e.target.value })} />
      </Field>
      {OPTIONS.map(([k, label]) => (
        <label key={k} className="row" style={{ alignItems: 'flex-start', flexWrap: 'nowrap', marginBottom: 6 }}><input type="checkbox" style={{ marginTop: 4, flex: 'none' }} checked={!!f.options[k]} disabled={!canEdit} onChange={(e) => setF({ ...f, options: { ...f.options, [k]: e.target.checked } })} /> <span>{label}</span></label>
      ))}
      <label className="row"><input type="checkbox" checked={f.enabled} disabled={!canEdit} onChange={(e) => setF({ ...f, enabled: e.target.checked })} /> <b>Enabled</b></label>
      {canEdit && <div className="row" style={{ marginTop: 12 }}>
        <button disabled={a.busy || !f.base_url || (!d.configured && !f.api_key)} onClick={() => a.run(async () => { setResult(await api('PUT', '/integrations/smartdcm', f)); setF({ ...f, api_key: '' }); reload(); })}>Save &amp; connect</button>
        {d.configured && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { setResult(await post('/integrations/smartdcm/test')); reload(); })}>Test connection</button>}
      </div>}
      {result && <Notice tone={result.ok ? 'ok' : 'bad'}>{result.ok ? `Connected to ${result.detail}` : `Could not connect: ${result.detail}`}</Notice>}
      {result?.inbound_secret && <SecretOnce secret={result.inbound_secret} />}
    </Card>
  );
}

function StatusCard({ d, canEdit, reload }: { d: any; canEdit: boolean; reload: () => void }) {
  const [result, setResult] = useState<any>(null);
  const a = useAction();
  const q = d.queue ?? {};
  if (!d.configured) {
    return <Card title="What the connection does">
      <ul style={{ margin: 0, paddingLeft: 18 }}>
        <li>SmartDCM portfolios appear under Customers → Portfolios; cases keep their SmartDCM allocation.</li>
        <li>SmartDCM users, teams and branches appear under Team → People (same employee code).</li>
        <li>Click2Call, Smart31 and browser (WebRTC) calls use the SmartDCM calling account and numbers.</li>
        <li>Outcomes, PTPs, payments, calls with recordings and field visits go back to the SmartDCM case.</li>
        <li>Each case shows its SmartDCM history in ROVEXY.</li>
      </ul>
    </Card>;
  }
  return (
    <Card title="Sync">
      <ErrorBox error={a.error} />
      <table className="kv"><tbody>
        <tr><th>Last sync</th><td>{d.synced_at ? fmtDate(d.synced_at) : '—'}</td></tr>
        <tr><th>Result</th><td>{d.last_status ?? '—'}</td></tr>
        <tr><th>CRM id</th><td>{d.crm_id ?? '—'}</td></tr>
        <tr><th>Linked</th><td>{d.linked.portfolios} portfolios · {d.linked.cases.toLocaleString('en-IN')} cases · {d.linked.users} users · {d.linked.teams} teams</td></tr>
        <tr><th>Browser calling (WebRTC)</th><td>{d.webrtc ? <Badge value="ok" label="available" /> : <span className="muted">not offered by SmartDCM</span>}</td></tr>
        <tr><th>Sent to SmartDCM</th><td>waiting {q.pending ?? 0} · delivered {q.sent ?? 0}{q.failed ? ` · rejected ${q.failed}` : ''}</td></tr>
      </tbody></table>
      <p className="muted small">Reads what changed every 5 minutes, and at once when SmartDCM notifies ROVEXY. Results are sent within a minute and retried if SmartDCM is offline.</p>
      {canEdit && <div className="row">
        <button disabled={a.busy || !d.enabled} onClick={() => a.run(async () => { setResult(await post('/integrations/smartdcm/sync')); reload(); })}>Sync now</button>
        <button className="secondary" disabled={a.busy || !d.enabled} onClick={() => a.run(async () => { if (confirm('Read all portfolios, cases and users from SmartDCM again? Nothing is deleted.')) { setResult(await post('/integrations/smartdcm/resync')); reload(); } })}>Read everything again</button>
        <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await post('/integrations/flush'); reload(); })}>Send waiting results now</button>
      </div>}
      {result && <Notice tone={result.ok ? 'ok' : 'bad'}>{result.detail}</Notice>}
    </Card>
  );
}

function InboundCard({ d, canEdit }: { d: any; canEdit: boolean }) {
  const [secret, setSecret] = useState<string | null>(null);
  const a = useAction();
  return (
    <Card title="Enter these in SmartDCM (Settings → Integrations → ROVEXY)">
      <p className="muted" style={{ marginTop: 0 }}>SmartDCM uses them to tell ROVEXY at once when cases or users change, and to fetch call recordings for its case history.</p>
      <ErrorBox error={a.error} />
      <table className="kv"><tbody>
        <tr><th>ROVEXY webhook URL</th><td><code>{d.inbound.webhook_url}</code></td></tr>
        <tr><th>Recording URL</th><td><code>{d.inbound.recording_url}</code></td></tr>
        <tr><th>ROVEXY secret</th><td>{d.inbound.secret_hint ?? '—'} <small className="muted">(shown in full only when created)</small></td></tr>
      </tbody></table>
      {canEdit && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { if (confirm('Create a new ROVEXY secret? SmartDCM stops reaching ROVEXY until you paste the new one there.')) setSecret((await post('/integrations/smartdcm/secret')).inbound_secret); })}>New secret</button>}
      {secret && <SecretOnce secret={secret} />}
    </Card>
  );
}

function SecretOnce({ secret }: { secret: string }) {
  return <Notice tone="warn">ROVEXY secret — copy it now into SmartDCM (Settings → Integrations → ROVEXY); it is not shown again:<br /><code style={{ userSelect: 'all', wordBreak: 'break-all' }}>{secret}</code>
    <button className="secondary" style={{ marginLeft: 8 }} onClick={() => navigator.clipboard?.writeText(secret)}>Copy</button></Notice>;
}

/** Customer page tab: this case's history in SmartDCM (read live). */
export function SmartDcmHistory({ customerId }: { customerId: number }) {
  const t = useAsync(() => get(`/customers/${customerId}/smartdcm`), [customerId]);
  const d = t.data;
  return (
    <Card title={`SmartDCM history${d?.case_id ? ` · case ${d.case_id}` : ''}`} actions={<>{d?.case_url && <a className="button secondary" href={d.case_url} target="_blank" rel="noreferrer">Open in SmartDCM</a>}<button className="secondary" onClick={t.reload}>Refresh</button></>}>
      <ErrorBox error={t.error} />
      {d?.error && <Notice tone="warn">SmartDCM could not be reached: {d.error}</Notice>}
      <Table rows={(d?.data ?? []).map((r: any, i: number) => ({ id: i, ...r }))} empty={t.loading ? 'Loading…' : 'No history in SmartDCM.'} columns={[
        { key: 'at', label: 'When', render: (r: any) => r.at ? fmtDate(r.at) : '' },
        { key: 'type', label: 'What', render: (r: any) => <span>{[r.type, r.code].filter(Boolean).join(' · ')}{r.amount ? ` · ₹${Number(r.amount).toLocaleString('en-IN')}` : ''}{r.ptp_date ? ` · PTP ${r.ptp_date}` : ''}</span> },
        { key: 'by', label: 'By' },
        { key: 'notes', label: 'Remarks' },
      ]} />
    </Card>
  );
}
