import { useEffect, useState } from 'react';
import { fileUrl, get, post, put, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { RANGES, rangeName } from '../lib/periods';
import { Card, ErrorBox, Field, Notice, Page, toast, useAction, useAsync } from '../components/ui';

/*
 * Reports → Recording export: (1) the company's recording file-name format, built from fixed text boxes and data fields
 * (Bank_Agency_CustomerID_Date…), used by every recording download; (2) many recordings in one ZIP with an index.csv.
 */
type Part = { type: string; value?: string; format?: string };
type Template = { sep: string; parts: Part[] };
type FieldDef = { key: string; label: string; formats: Record<string, string> | null };

const mb = (b: number) => (b >= 1073741824 ? `${(b / 1073741824).toFixed(1)} GB` : `${Math.max(0.1, b / 1048576).toFixed(1)} MB`);

export function RecordingExportPage() {
  const { can } = useAuth();
  const meta = useAsync(() => get<any>('/reports/recordings/format'));
  return (
    <Page title="Recording export" subtitle="Download call recordings in bulk, named the way your bank or lender wants. The file-name format applies to every recording download in ROVEXY.">
      <ErrorBox error={meta.error} />
      {meta.data && <NameFormat meta={meta.data} canSave={can('telephony.configure')} onSaved={() => void meta.reload()} />}
      <BulkExport />
    </Page>
  );
}

function NameFormat({ meta, canSave, onSaved }: { meta: any; canSave: boolean; onSaved: () => void }) {
  const fields: FieldDef[] = meta.fields;
  const byKey = Object.fromEntries(fields.map((f) => [f.key, f]));
  const [t, setT] = useState<Template>(meta.template);
  const [preview, setPreview] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const a = useAction();
  const dirty = JSON.stringify(t) !== JSON.stringify(meta.template);
  useEffect(() => {
    const h = setTimeout(() => {
      post<any>('/reports/recordings/format/preview', { template: t, call: meta.sample_call }).then((r) => { setPreview(r.name); setProblem(null); }, (e) => setProblem(e.message));
    }, 300);
    return () => clearTimeout(h);
  }, [t, meta.sample_call]);
  const set = (i: number, p: Part) => setT({ ...t, parts: t.parts.map((x, j) => (j === i ? p : x)) });
  const move = (i: number, d: number) => { const p = [...t.parts]; [p[i], p[i + d]] = [p[i + d], p[i]]; setT({ ...t, parts: p }); };
  const pick = (i: number, type: string) => set(i, type === 'text' ? { type, value: '' } : byKey[type].formats ? { type, format: Object.keys(byKey[type].formats!)[0] } : { type });
  return (
    <Card title="Recording file name">
      <p className="muted">Build the name from parts, left to right. Use <b>Fixed text</b> for words that never change (e.g. the bank's name), and data fields for what changes per call. Saved once, it is used for single downloads, the ZIP below and the audit pack.</p>
      <div className="stack" style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
        {t.parts.map((p, i) => (
          <div key={i} className="row" style={{ gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <span className="muted small" style={{ width: 22 }}>{i + 1}.</span>
            <select value={p.type} onChange={(e) => pick(i, e.target.value)} aria-label={`Part ${i + 1}`} style={{ flex: '1 1 200px', maxWidth: 260 }}>
              <option value="text">Fixed text…</option>
              {fields.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
            </select>
            {p.type === 'text' && <input value={p.value ?? ''} onChange={(e) => set(i, { ...p, value: e.target.value })} placeholder="e.g. HDFC" maxLength={40} aria-label={`Text for part ${i + 1}`} style={{ flex: '1 1 160px', maxWidth: 240 }} />}
            {p.type !== 'text' && byKey[p.type]?.formats && (
              <select value={p.format} onChange={(e) => set(i, { ...p, format: e.target.value })} aria-label={`Format for part ${i + 1}`} style={{ flex: '1 1 160px', maxWidth: 240 }}>
                {Object.entries(byKey[p.type].formats!).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            )}
            <button type="button" className="ghost small" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Move left">↑</button>
            <button type="button" className="ghost small" disabled={i === t.parts.length - 1} onClick={() => move(i, 1)} aria-label="Move right">↓</button>
            <button type="button" className="ghost small" disabled={t.parts.length === 1} onClick={() => setT({ ...t, parts: t.parts.filter((_, j) => j !== i) })} aria-label="Remove">Remove</button>
          </div>
        ))}
      </div>
      <div className="row" style={{ gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        <button type="button" className="secondary" disabled={t.parts.length >= 12} onClick={() => setT({ ...t, parts: [...t.parts, { type: 'text', value: '' }] })}>+ Fixed text</button>
        <button type="button" className="secondary" disabled={t.parts.length >= 12} onClick={() => setT({ ...t, parts: [...t.parts, { type: 'customer_ref' }] })}>+ Data field</button>
        <Field label="Between parts">
          <select value={t.sep} onChange={(e) => setT({ ...t, sep: e.target.value })}>{Object.entries(meta.separators as Record<string, string>).map(([k, l]) => <option key={l} value={k}>{l}</option>)}</select>
        </Field>
      </div>
      {problem ? <Notice tone="warn">{problem}</Notice>
        : <p>Example: <code style={{ fontSize: '1rem' }}>{preview ?? (meta.sample_call ? '…' : 'appears once there is a recording')}</code></p>}
      <ErrorBox error={a.error} />
      {canSave ? (
        <div className="row" style={{ gap: 8 }}>
          <button disabled={a.busy || !dirty || !!problem} onClick={() => void a.run(async () => { await put('/reports/recordings/format', { template: t }); toast('File-name format saved: every recording download now uses it'); onSaved(); })}>Save format</button>
          {dirty && <button className="secondary" onClick={() => setT(meta.template)}>Undo changes</button>}
          <button className="ghost" onClick={() => setT(meta.default)}>Use the default</button>
          {!meta.saved && <span className="muted small">Not saved yet: downloads use the default (Customer ID_Employee code_Date_Time).</span>}
        </div>
      ) : <p className="muted small">Only people who configure calling can change the format.</p>}
    </Card>
  );
}

function BulkExport() {
  const { can } = useAuth();
  const [[from, to], setRange] = useState<[string, string]>(() => RANGES[2][1]());
  const [f, setF] = useState({ portfolio: '', employee: '', outcome: '', min_talk: '' });
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
        <Field label="Portfolio"><select value={f.portfolio} onChange={(e) => setF({ ...f, portfolio: e.target.value })}><option value="">All portfolios</option>
          {(pfs.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}<option value="manual">Added by hand</option></select></Field>
        <Field label="Employee"><select value={f.employee} onChange={(e) => setF({ ...f, employee: e.target.value })}><option value="">All employees</option>
          {(emps.data?.data ?? []).map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select></Field>
        <Field label="Call outcome"><select value={f.outcome} onChange={(e) => setF({ ...f, outcome: e.target.value })}><option value="">All outcomes</option>
          {(codes.data ?? []).map((d: any) => <option key={d.code} value={d.code}>{d.label}</option>)}</select></Field>
        <Field label="Talk time at least (seconds)"><input type="number" min={0} value={f.min_talk} onChange={(e) => setF({ ...f, min_talk: e.target.value })} placeholder="0" /></Field>
      </div>
      <p className="muted small">Branch, team and employee chosen in the bar at the top also apply. Test calls are never included.</p>
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
