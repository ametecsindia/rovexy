import { useState } from 'react';
import { api, get, post, fmtDate, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, LogoPlaceholder, Notice, Page, Stat, Table, useAction, useAsync } from '../components/ui';

export function BrandingPage() {
  const b = useAsync(() => get('/branding'));
  const [f, setF] = useState<any | null>(null);
  const [logo, setLogo] = useState<File | null>(null);
  const a = useAction();
  const v = f ?? b.data;
  return (
    <Page title="Company branding" subtitle="One configuration for the desktop console and the mobile app login. Without an uploaded logo both show a neutral placeholder.">
      <ErrorBox error={b.error ?? a.error} />
      {v && (
        <div className="grid2">
          <Card title="Settings">
            <Field label="Display name"><input value={v.display_name} onChange={(e) => setF({ ...v, display_name: e.target.value })} /></Field>
            <div className="form-grid">
              <Field label="Primary colour"><input type="color" value={v.primary_color} onChange={(e) => setF({ ...v, primary_color: e.target.value })} /></Field>
              <Field label="Accent colour"><input type="color" value={v.accent_color} onChange={(e) => setF({ ...v, accent_color: e.target.value })} /></Field>
            </div>
            <Field label="Logo (PNG/JPEG/WebP — large images are resized automatically)" hint="Upload only an official, approved logo file."><input type="file" accept="image/png,image/jpeg,image/webp" onChange={async (e) => { const f = e.target.files?.[0]; setLogo(f ? await fitLogo(f) : null); }} /></Field>
            <div className="row">
              <button disabled={a.busy} onClick={() => a.run(async () => {
                const fd = new FormData();
                fd.append('display_name', v.display_name);
                fd.append('primary_color', v.primary_color);
                fd.append('accent_color', v.accent_color);
                if (logo) fd.append('logo', logo);
                b.setData(await api('POST', '/branding', fd));
                setF(null);
                setLogo(null);
              })}>Save</button>
              {b.data?.logo_url && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => {
                const fd = new FormData();
                for (const k of ['display_name', 'primary_color', 'accent_color']) fd.append(k, v[k]);
                fd.append('remove_logo', '1');
                b.setData(await api('POST', '/branding', fd));
              })}>Remove logo</button>}
            </div>
          </Card>
          <Card title="Mobile login preview">
            <div style={{ border: '1px solid var(--border)', borderRadius: 16, padding: 20, maxWidth: 280, margin: '0 auto', textAlign: 'center' }}>
              <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 12 }}>
                {b.data?.logo_url && !logo ? <img src={b.data.logo_url} alt="Logo" style={{ maxHeight: 64, maxWidth: 180 }} /> : logo ? <img src={URL.createObjectURL(logo)} alt="New logo" style={{ maxHeight: 64, maxWidth: 180 }} /> : <LogoPlaceholder name={v.display_name} size={64} />}
              </div>
              <div style={{ fontWeight: 600, marginBottom: 12 }}>{v.display_name}</div>
              {['Server URL', 'User ID', 'Password'].map((l) => <input key={l} disabled placeholder={l} style={{ marginBottom: 8 }} />)}
              <button style={{ width: '100%', background: v.primary_color, borderColor: v.primary_color }} disabled>Sign In</button>
            </div>
          </Card>
        </div>
      )}
    </Page>
  );
}

export function RetentionPage() {
  const { can } = useAuth();
  const [est, setEst] = useState({ agents: 50, calls_per_day: 120, avg_talk_minutes: 3, retention_days: 180 });
  const estimate = useAsync(() => get(`/storage/estimate${qs(est)}`), [est.agents, est.calls_per_day, est.avg_talk_minutes, est.retention_days]);
  const jobs = useAsync(() => (can('settings.retention') ? get('/retention-jobs') : Promise.resolve({ data: [] })));
  const a = useAction();
  return (
    <Page title="Storage & retention" subtitle="Estimate storage before choosing long retention. Deletions are planned, reviewed, and approved by a second administrator.">
      <ErrorBox error={estimate.error ?? a.error} />
      <Card title="Storage estimate (recordings at ~32 kbit/s)">
        <div className="form-grid">
          {(['agents', 'calls_per_day', 'avg_talk_minutes', 'retention_days'] as const).map((k) => (
            <Field key={k} label={k.replace(/_/g, ' ')}><input type="number" value={est[k]} onChange={(e) => setEst({ ...est, [k]: Number(e.target.value) })} /></Field>
          ))}
        </div>
        {estimate.data && <div className="grid"><Stat label="Estimated storage" value={`${estimate.data.estimated_gb} GB`} /><Stat label="Currently stored" value={`${(estimate.data.current_bytes / 1e9).toFixed(2)} GB`} /></div>}
      </Card>
      {can('settings.retention') && (
        <Card title="Recording retention jobs" actions={<button disabled={a.busy} onClick={() => a.run(async () => { await post('/retention-jobs', { kind: 'recordings' }); await jobs.reload(); })}>Plan deletion of expired recordings</button>}>
          <Notice tone="info">Planning lists expired recordings (legal holds excluded) without deleting anything. Another administrator must approve.</Notice>
          <Table rows={jobs.data?.data} columns={[{ key: 'id', label: '#' }, { key: 'status', label: 'Status', render: (j: any) => <Badge value={j.status} /> },
            { key: 'count', label: 'Recordings', align: 'right', render: (j: any) => j.scope?.count }, { key: 'bytes', label: 'Size', align: 'right', render: (j: any) => `${((j.scope?.bytes ?? 0) / 1e6).toFixed(1)} MB` },
            { key: 'held', label: 'On hold', align: 'right', render: (j: any) => j.scope?.held }, { key: 'created_at', label: 'Planned', render: (j: any) => fmtDate(j.created_at) },
            { key: 'x', label: '', render: (j: any) => j.status === 'planned' && <button className="danger" disabled={a.busy} onClick={() => a.run(async () => { await post(`/retention-jobs/${j.id}/approve`); await jobs.reload(); })}>Approve deletion</button> }]} />
        </Card>
      )}
    </Page>
  );
}

export function AuditPage() {
  const { me } = useAuth();
  const [action, setAction] = useState('');
  const log = useAsync(() => get(`/audit${qs({ action })}`), [action]);
  return (
    <Page title="Audit logs" subtitle="Append-only record of privileged actions, monitoring sessions and recording access.">
      <ErrorBox error={log.error} />
      <Card actions={<input placeholder="Filter by action prefix (e.g. device.)" value={action} onChange={(e) => setAction(e.target.value)} style={{ width: 280 }} />}>
        <Table rows={log.data?.data} columns={[{ key: 'created_at', label: 'When', render: (e: any) => fmtDate(e.created_at, me?.company.timezone) }, { key: 'action', label: 'Action' },
          { key: 'actor', label: 'Actor', render: (e: any) => `${e.actor_type}${e.actor_id ? ` #${e.actor_id}` : ''}` }, { key: 'subject', label: 'Subject', render: (e: any) => e.subject_type ? `${e.subject_type} #${e.subject_id}` : '—' },
          { key: 'ip', label: 'IP' }, { key: 'meta', label: 'Detail', render: (e: any) => <span className="code">{e.meta}</span> }]} />
      </Card>
    </Page>
  );
}

export function HealthPage() {
  const h = useAsync(() => get('/health'));
  const rows = h.data ? Object.entries(h.data).filter(([k]) => k !== 'environment').map(([k, v]: [string, any]) => ({ id: k, k, v })) : null;
  return (
    <Page title="System health & integrations" subtitle="Simulated or development drivers are flagged explicitly." actions={<button className="secondary" onClick={h.reload}>Re-check</button>}>
      <ErrorBox error={h.error} />
      {h.data && h.data.environment !== 'production' && <Notice tone="warn">Environment: {h.data.environment}. Not a production deployment.</Notice>}
      <Card>
        <Table rows={rows} columns={[{ key: 'k', label: 'Component' },
          { key: 's', label: 'Status', render: (r: any) => r.v.simulated ? <Badge value="warn" label="simulated" /> : 'ok' in r.v ? <Badge value={r.v.ok} label={r.v.ok ? 'ok' : 'problem'} /> : 'configured' in r.v ? <Badge value={r.v.configured} label={r.v.configured ? 'configured' : 'not configured'} /> : <Badge value="info" label="info" /> },
          { key: 'd', label: 'Detail', render: (r: any) => <span className="code">{JSON.stringify(r.v)}</span> }]} />
      </Card>
    </Page>
  );
}

export function ReportsPage() {
  const { can } = useAuth();
  const [from, setFrom] = useState(() => new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 10));
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));
  const [portfolio, setPortfolio] = useState('');
  const [employee, setEmployee] = useState('');
  const pfs = useAsync(() => (can('customers.view') ? get<any[]>('/portfolios') : Promise.resolve([])));
  const emps = useAsync(() => (can('employees.view') ? get('/employees') : Promise.resolve({ data: [] })));
  const f = { portfolio, employee };
  const pfName = portfolio === 'manual' ? 'Added by hand' : (pfs.data ?? []).find((p: any) => String(p.id) === portfolio)?.name;
  const empName = (emps.data?.data ?? []).find((e: any) => String(e.id) === employee)?.name;
  const scope = [pfName ?? 'All portfolios', empName ?? 'all employees', `${from} to ${to}`].join(' · ');
  return (
    <Page title="Reports & exports" subtitle="CSV exports are protected against spreadsheet formula injection. Each export is audited.">
      <Card title="Filters">
        <div className="form-grid">
          <Field label="From"><input type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></Field>
          <Field label="To"><input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></Field>
          <Field label="Portfolio">
            <select value={portfolio} onChange={(e) => setPortfolio(e.target.value)}>
              <option value="">All portfolios</option>
              {(pfs.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name} ({p.total})</option>)}
              <option value="manual">Added by hand</option>
            </select>
          </Field>
          <Field label="Employee">
            <select value={employee} onChange={(e) => setEmployee(e.target.value)}>
              <option value="">All employees</option>
              {(emps.data?.data ?? []).filter((e: any) => e.roles?.some((x: any) => x.key === 'employee')).map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}
            </select>
          </Field>
        </div>
        <p className="muted small" style={{ margin: 0 }}>Exporting: {scope}</p>
      </Card>
      <div className="grid2">
        <Card title="Portfolio-wise summary">
          <p className="muted">One row per portfolio — cases, calls, connected, not called, callbacks, collections — plus an "All portfolios" total.</p>
          <a className="button" href={`/api/admin/reports/portfolio.csv${qs({ by: 'portfolio', from, to, ...f })}`}>Download portfolio-wise CSV</a>
        </Card>
        <Card title="Employee-wise summary">
          <p className="muted">One row per employee — their cases, calls, connect rate, cases not called, callbacks and money collected.</p>
          <a className="button" href={`/api/admin/reports/portfolio.csv${qs({ by: 'employee', from, to, ...f })}`}>Download employee-wise CSV</a>
        </Card>
      </div>
      <Card title="Call report (every call)">
        <p className="muted">One row per call: employee, customer, portfolio, result, talk time, outcome and payment.</p>
        <a className="button" href={`/api/admin/reports/calls.csv${qs({ from, to: `${to}T23:59:59`, ...f })}`}>Download call-by-call CSV</a>
      </Card>
    </Page>
  );
}

/** Shrinks a logo in the browser to fit the server limits (512 KB, 2048 px): ≤1024 px WebP, transparency kept. */
async function fitLogo(f: File): Promise<File> {
  const LIMIT = 500 * 1024;
  const img = await createImageBitmap(f).catch(() => null);
  if (!img || (f.size <= LIMIT && img.width <= 2048 && img.height <= 2048)) return f;   // already fine (or not an image: server says why)
  for (let side = 1024, q = 0.9; side >= 128; side = Math.round(side * 0.75), q = Math.max(0.6, q - 0.1)) {
    const k = Math.min(1, side / Math.max(img.width, img.height));
    const c = document.createElement('canvas');
    c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
    c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
    const blob = await new Promise<Blob | null>((ok) => c.toBlob(ok, 'image/webp', q));
    if (blob && blob.size <= LIMIT) return new File([blob], f.name.replace(/\.[^.]+$/, '') + '.webp', { type: 'image/webp' });
  }
  return f;
}
