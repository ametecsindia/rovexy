import { useState } from 'react';
import { Link } from 'react-router-dom';
import { fileUrl, get, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { RANGES, rangeName } from '../lib/periods';
import { Card, ErrorBox, Field, Notice, Page, toast, useAsync } from '../components/ui';

/* Reports → Recording export: many call recordings in one ZIP with an index.csv, named by the portfolio's format or one picked here. */
const mb = (b: number) => (b >= 1073741824 ? `${(b / 1073741824).toFixed(1)} GB` : `${Math.max(0.1, b / 1048576).toFixed(1)} MB`);

export function RecordingExportPage() {
  return (
    <Page title="Recording export" subtitle="Download call recordings in bulk. File names follow each portfolio's recording format (Customers → Recording file names), or the one you pick here.">
      <BulkExport />
    </Page>
  );
}

function BulkExport() {
  const { can } = useAuth();
  const [[from, to], setRange] = useState<[string, string]>(() => RANGES[2][1]());
  const [f, setF] = useState({ portfolio: '', employee: '', outcome: '', min_talk: '', format: '' });
  const pfs = useAsync(() => (can('customers.view') ? get<any[]>('/portfolios') : Promise.resolve([])));
  const emps = useAsync(() => (can('employees.view') ? get<any>('/employees') : Promise.resolve({ data: [] })));
  const codes = useAsync(() => get<any[]>('/disposition-codes'));
  const params = qs({ from, to, ...f });
  const count = useAsync(() => get<any>(`/reports/recordings/count${params}`), [params]);
  const c = count.data;
  return (
    <Card title="Download recordings (ZIP)">
      <div className="seg" style={{ marginBottom: 10 }}>
        {RANGES.filter(([l]) => l !== 'All time').map(([l, fn]) => <button key={l} className={rangeName(from, to) === l ? 'on' : ''} onClick={() => setRange(fn())}>{l}</button>)}
      </div>
      <div className="form-grid">
        <Field label="From"><input type="date" value={from} max={to} onChange={(e) => setRange([e.target.value, to])} /></Field>
        <Field label="To"><input type="date" value={to} min={from} onChange={(e) => setRange([from, e.target.value])} /></Field>
        <Field label="Portfolio"><select value={f.portfolio} onChange={(e) => { const id = e.target.value; const p = (pfs.data ?? []).find((x: any) => String(x.id) === id);
          setF({ ...f, portfolio: id, format: id && id !== 'manual' ? (p?.recording_format ?? c?.default_id ?? '') : id === 'manual' ? (c?.default_id ?? '') : '' }); }}><option value="">All portfolios</option>
          {(pfs.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}<option value="manual">Added by hand</option></select></Field>
        <Field label="Employee"><select value={f.employee} onChange={(e) => setF({ ...f, employee: e.target.value })}><option value="">All employees</option>
          {(emps.data?.data ?? []).map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select></Field>
        <Field label="Call outcome"><select value={f.outcome} onChange={(e) => setF({ ...f, outcome: e.target.value })}><option value="">All outcomes</option>
          {(codes.data ?? []).map((d: any) => <option key={d.code} value={d.code}>{d.label}</option>)}</select></Field>
        <Field label="Recording file names" hint={f.portfolio && c?.portfolio_format && f.format === c.portfolio_format ? "This portfolio's format" : undefined}>
          <select value={f.format} onChange={(e) => setF({ ...f, format: e.target.value })}>
            <option value="">Each portfolio's own format</option>
            {(c?.formats ?? []).map((x: any) => <option key={x.id} value={x.id}>{x.name}{x.id === c.default_id ? ' (default)' : ''}</option>)}
          </select>
        </Field>
        <Field label="Talk time at least (seconds)"><input type="number" min={0} value={f.min_talk} onChange={(e) => setF({ ...f, min_talk: e.target.value })} placeholder="0" /></Field>
      </div>
      <p className="muted small">Branch, team and employee chosen in the bar at the top also apply. Test calls are never included. Formats are set up under <Link to="/recording-formats">Customers → Recording file names</Link>.</p>
      <ErrorBox error={count.error} />
      {c && (c.count === 0 ? <Notice tone="info">No recordings for these filters.</Notice> : <>
        <p><b>{c.count.toLocaleString('en-IN')}</b> recording{c.count === 1 ? '' : 's'} · about {mb(c.bytes)}{c.count > c.max ? <span className="bad"> · only the first {c.max.toLocaleString('en-IN')} go in one ZIP: choose a shorter period</span> : null}</p>
        <p className="muted small" style={{ marginBottom: 4 }}>Files will be named like:</p>
        <ul className="small" style={{ marginTop: 0 }}>{c.names.map((n: string) => <li key={n}><code>{n}</code></li>)}</ul>
        <a className="button" href={fileUrl(`/api/admin/reports/recordings.zip${params}`)} onClick={() => toast('Preparing the ZIP: large exports can take a minute')}>Download {c.count > c.max ? c.max.toLocaleString('en-IN') : c.count.toLocaleString('en-IN')} recordings (ZIP)</a>
        <p className="muted small">The ZIP also has index.csv: one row per file with the call, employee, customer, outcome and a SHA-256 checksum.</p>
      </>)}
    </Card>
  );
}
