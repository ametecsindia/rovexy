import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { del, get, post, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Notice, Page, toast, useAction, useAsync } from '../components/ui';

/*
 * Customers → Recording file names: named formats (one per bank, say) built from fixed text and data fields. Each portfolio
 * picks one (Portfolios page); the default covers the rest. Every recording download follows the format of its portfolio.
 */
type Part = { type: string; value?: string; format?: string };
type Format = { id?: string; name: string; sep: string; parts: Part[] };
type FieldDef = { key: string; label: string; formats: Record<string, string> | null };

export function RecordingFormatsPage() {
  const { can } = useAuth();
  const edit = can('customers.import');
  const d = useAsync(() => get<any>('/recording-formats'));
  const [open, setOpen] = useState<Format | null>(null);
  const a = useAction();
  const x = d.data;
  const done = async (msg: string) => { toast(msg); setOpen(null); await d.reload(); };
  return (
    <Page title="Recording file names" subtitle="Name downloaded call recordings the way each bank or lender wants. Pick a format for each portfolio on the Portfolios page; the default covers everything else."
      actions={x && !open ? <div className="row" style={{ gap: 8 }}>
        {can('recordings.download') && <Link className="button secondary" to="/recording-export">Download recordings</Link>}
        {edit && <button onClick={() => setOpen({ name: '', ...x.blank })}>New format</button>}
      </div> : undefined}>
      <ErrorBox error={d.error ?? a.error} />
      {open && x && <Editor meta={x} value={open} onCancel={() => setOpen(null)} onSaved={(m) => void done(m)} />}
      {x && !open && (
        <Card title="Formats">
          <div className="table-wrap"><table>
            <thead><tr><th>Name</th><th>Example</th><th>Used by portfolios</th><th /></tr></thead>
            <tbody>{x.formats.map((f: any) => (
              <tr key={f.id}>
                <td><strong>{f.name}</strong> {f.is_default && <Badge value="approved" label="default" />}</td>
                <td><code className="small">{f.example ?? '—'}</code></td>
                <td className="small">{f.portfolios.length ? f.portfolios.join(', ') : f.is_default ? <span className="muted">every portfolio without its own format</span> : <span className="muted">none yet</span>}</td>
                <td>{edit && <div className="row" style={{ gap: 6, justifyContent: 'flex-end', flexWrap: 'nowrap' }}>
                  <button className="ghost small" onClick={() => setOpen({ id: f.id, name: f.name, sep: f.sep, parts: f.parts })}>Edit</button>
                  {!f.is_default && <button className="ghost small" disabled={a.busy} onClick={() => void a.run(async () => { await post(`/recording-formats/${f.id}/default`); await done(`${f.name} is now the default`); })}>Make default</button>}
                  {x.formats.length > 1 && <button className="ghost small" disabled={a.busy} onClick={() => { if (confirm(`Delete "${f.name}"?${f.portfolios.length ? ' Its portfolios will use the default format.' : ''}`)) void a.run(async () => { await del(`/recording-formats/${f.id}`); await done(`${f.name} deleted`); }); }}>Delete</button>}
                </div>}</td>
              </tr>))}
            </tbody>
          </table></div>
          {x.formats.length === 1 && x.formats[0].id === 'standard' && <p className="muted small">This is the built-in format. Edit it, or add one per bank with <b>New format</b>.</p>}
        </Card>
      )}
    </Page>
  );
}

function Editor({ meta, value, onCancel, onSaved }: { meta: any; value: Format; onCancel: () => void; onSaved: (msg: string) => void }) {
  const fields: FieldDef[] = meta.fields;
  const byKey = Object.fromEntries(fields.map((f) => [f.key, f]));
  const [t, setT] = useState<Format>(value);
  const [makeDefault, setMakeDefault] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const a = useAction();
  useEffect(() => {
    const h = setTimeout(() => {
      post<any>('/recording-formats/preview', { template: t, call: meta.sample_call }).then((r) => { setPreview(r.name); setProblem(null); }, (e) => setProblem(e.message));
    }, 300);
    return () => clearTimeout(h);
  }, [t, meta.sample_call]);
  const set = (i: number, p: Part) => setT({ ...t, parts: t.parts.map((x, j) => (j === i ? p : x)) });
  const move = (i: number, d: number) => { const p = [...t.parts]; [p[i], p[i + d]] = [p[i + d], p[i]]; setT({ ...t, parts: p }); };
  const pick = (i: number, type: string) => set(i, type === 'text' ? { type, value: '' } : byKey[type].formats ? { type, format: Object.keys(byKey[type].formats!)[0] } : { type });
  const save = () => a.run(async () => {
    if (t.id) await put(`/recording-formats/${t.id}`, { name: t.name, template: t });
    else await post('/recording-formats', { name: t.name, template: t, make_default: makeDefault });
    onSaved(`Format "${t.name}" saved`);
  });
  return (
    <Card title={t.id ? `Edit format: ${value.name}` : 'New format'}>
      <Field label="Format name" hint="e.g. HDFC Bank. This name appears in the Portfolios and Recording export drop-downs.">
        <input value={t.name} onChange={(e) => setT({ ...t, name: e.target.value })} maxLength={60} required style={{ maxWidth: 360 }} autoFocus={!t.id} />
      </Field>
      <p className="muted">Build the file name from parts, left to right: <b>Fixed text</b> for words that never change (the bank's name), data fields for what changes per call.</p>
      <div style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
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
            <button type="button" className="ghost small" disabled={i === 0} onClick={() => move(i, -1)} aria-label="Move up">↑</button>
            <button type="button" className="ghost small" disabled={i === t.parts.length - 1} onClick={() => move(i, 1)} aria-label="Move down">↓</button>
            <button type="button" className="ghost small" disabled={t.parts.length === 1} onClick={() => setT({ ...t, parts: t.parts.filter((_, j) => j !== i) })}>Remove</button>
          </div>
        ))}
      </div>
      <div className="row" style={{ gap: 8, flexWrap: 'wrap', marginBottom: 12, alignItems: 'flex-end' }}>
        <button type="button" className="secondary" disabled={t.parts.length >= 12} onClick={() => setT({ ...t, parts: [...t.parts, { type: 'text', value: '' }] })}>+ Fixed text</button>
        <button type="button" className="secondary" disabled={t.parts.length >= 12} onClick={() => setT({ ...t, parts: [...t.parts, { type: 'customer_ref' }] })}>+ Data field</button>
        <Field label="Between parts">
          <select value={t.sep} onChange={(e) => setT({ ...t, sep: e.target.value })}>{Object.entries(meta.separators as Record<string, string>).map(([k, l]) => <option key={l} value={k}>{l}</option>)}</select>
        </Field>
      </div>
      {problem ? <Notice tone="warn">{problem}</Notice>
        : <p>Example: <code style={{ fontSize: '1rem' }}>{preview ?? (meta.sample_call ? '…' : 'appears once there is a recording')}</code></p>}
      {!t.id && <label className="row small" style={{ gap: 6, marginBottom: 10 }}><input type="checkbox" style={{ width: 'auto' }} checked={makeDefault} onChange={(e) => setMakeDefault(e.target.checked)} />Make this the default format</label>}
      <ErrorBox error={a.error} />
      <div className="row" style={{ gap: 8 }}>
        <button disabled={a.busy || !!problem || !t.name.trim()} onClick={() => void save()}>Save format</button>
        <button className="secondary" onClick={onCancel}>Cancel</button>
      </div>
    </Card>
  );
}
