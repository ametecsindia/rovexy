import { useEffect, useRef, useState } from 'react';
import { useLiveReload } from '../lib/live';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { AddressHistory, ChangeDetail } from './AddressReport';
import { api, get, post, fmtDate, fmtSeconds, qs, fileUrl } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Notice, Page, RecordingPlayer, Stat, Table, useAction, useAsync } from '../components/ui';

type MapField = { key: string; label: string; required: boolean; kind: string };
type PType = { type: string; label: string; hint: string; case: string; value: string; due: string; date: string; amount: string; success: string; fields: { key: string; label: string; kind: string; options: string[] | null }[] };

/** A calendar date ("2026-11-12") as "12 Nov 2026" — no time, no time-zone shift. */
const fmtDay = (d?: string | null) => (d ? new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const inr = (v: any) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }));
const daysOverdue = (c: any) => {
  if (!c.oldest_due_date || (c.overdue_amount !== null && Number(c.overdue_amount) <= 0)) return null;
  return Math.max(0, Math.floor((Date.now() - new Date(c.oldest_due_date.slice(0, 10)).getTime()) / 86400000));
};

/** Portfolios: every uploaded lead file is a named portfolio. Upload → match columns → check → import, then assign it. */
export function ImportsPage() {
  const { can } = useAuth();
  const nav = useNavigate();
  const imports = useAsync(() => get('/imports'));
  const types = useAsync(() => get<PType[]>('/imports/types'));
  const [ptype, setPtype] = useState('');
  const typeOf = (k?: string | null) => types.data?.find((t) => t.type === (k || 'collections'));
  const [keepOther, setKeepOther] = useState(true);
  const portfolios = useAsync(() => get<any[]>('/portfolios'));
  const formats = useAsync(() => get<any>('/recording-formats'));
  const fmtName = (id: string | null) => { const l = formats.data?.formats ?? []; return (l.find((f: any) => f.id === id) ?? l.find((f: any) => f.is_default))?.name; };
  const [job, setJob] = useState<any | null>(null);
  const [name, setName] = useState('');
  const [recFormat, setRecFormat] = useState('');
  const [mapping, setMapping] = useState<Record<string, number | null>>({});
  const [onExisting, setOnExisting] = useState('update');
  const [preview, setPreview] = useState<any | null>(null);
  const a = useAction();

  useEffect(() => {
    const pinning = job?.status === 'completed' && (job.geo?.waiting ?? 0) > 0;   // G5: map pins are added in the background
    if (!job || (!['validating', 'committing'].includes(job.status) && !pinning)) return;
    const t = setInterval(async () => {
      const j = await get(`/imports/${job.id}`);
      setJob(j);
      if (j.status === 'completed' && !pinning) { void imports.reload(); void portfolios.reload(); }
    }, pinning ? 5000 : 1500);
    return () => clearInterval(t);
  }, [job]);

  const open = async (j: any) => {
    const full = j.fields ? j : await get(`/imports/${j.id}`);   // the field list of its portfolio type
    setJob(full); setName(full.portfolio ?? ''); setRecFormat(full.recording_format ?? ''); setMapping(full.mapping ?? {}); setPreview(null); setPtype(full.portfolio_type ?? 'collections');
    setKeepOther(full.options?.keep_other_columns ?? true);
  };
  const [renaming, setRenaming] = useState<{ id: number; name: string } | null>(null);
  const MAX_MB = 20;   // = config ammp.uploads.import_max_mb
  const upload = (file: File) => a.run(async () => {
    if (!/\.(csv|xlsx)$/i.test(file.name)) throw new Error('Choose a .csv or .xlsx file. Other formats (e.g. .xls, .pdf) cannot be read — in Excel use File → Save As → CSV or Excel Workbook.');
    if (file.size === 0) throw new Error('The file is empty.');
    if (file.size > MAX_MB * 1024 * 1024) throw new Error(`The file is larger than ${MAX_MB} MB. Split it into smaller files.`);
    if (!ptype) throw new Error('Choose the type of portfolio first (step 1).');
    const fd = new FormData();
    fd.append('file', file);
    fd.append('portfolio_type', ptype);
    if (name.trim()) fd.append('portfolio', name.trim());
    if (recFormat) fd.append('recording_format', recFormat);
    await open(await post('/imports', fd));
  });
  const check = () => a.run(async () => {
    if ((name.trim() && name.trim() !== job.portfolio) || (recFormat || null) !== (job.recording_format ?? null)) await api('PATCH', `/imports/${job.id}`, { ...(name.trim() ? { portfolio: name.trim() } : {}), recording_format: recFormat || null });
    setPreview((await post(`/imports/${job.id}/preview`, { mapping, on_existing: onExisting, keep_other_columns: keepOther })).result);
    await post(`/imports/${job.id}/validate`);
    setJob(await get(`/imports/${job.id}`));
  });
  const commit = () => a.run(async () => { await post(`/imports/${job.id}/commit`); setJob(await get(`/imports/${job.id}`)); });
  const busyJob = job && ['validating', 'committing'].includes(job.status);

  return (
    <Page title="Portfolios" subtitle="Each uploaded lead file is a portfolio. Upload it here, then give its cases to a group and its employees under Assignment."
      actions={can('customers.import') && <a className="button secondary" href={fileUrl(`/api/admin/imports/template${qs({ type: ptype || 'collections' })}`)}>
        Download sample file{ptype ? ` (${typeOf(ptype)?.label})` : ''}</a>}>
      <ErrorBox error={a.error ?? imports.error ?? portfolios.error} />
      <Card title="Portfolios">
        <Table rows={portfolios.data} empty="No portfolios yet — upload a lead file below." columns={[
          { key: 'name', label: 'Portfolio', render: (p: any) => renaming?.id === p.id
              ? <form className="row" onSubmit={(e) => { e.preventDefault(); void a.run(async () => { await api('PATCH', `/imports/${p.id}`, { portfolio: (renaming?.name ?? '').trim() }); setRenaming(null); await portfolios.reload(); }); }}>
                  <input value={renaming?.name ?? ''} onChange={(e) => setRenaming({ id: p.id, name: e.target.value })} maxLength={150} required aria-label="New portfolio name" autoFocus style={{ maxWidth: 240 }} />
                  <button type="submit" disabled={a.busy}>Save</button><button type="button" className="ghost" onClick={() => setRenaming(null)}>Cancel</button></form>
              : <><strong>{p.name}</strong>{can('customers.import') && <button type="button" className="ghost small" onClick={() => setRenaming({ id: p.id, name: p.name })} aria-label={`Rename ${p.name}`}>Rename</button>}<br /><small className="muted">{p.file} · {fmtDate(p.uploaded_at)}</small></> },
          { key: 'portfolio_type', label: 'Type', render: (p: any) => <span className="tag">{p.type_label ?? typeOf(p.portfolio_type)?.label}</span> },
          { key: 'recording_format', label: 'Recording file names', render: (p: any) => can('customers.import') && formats.data
              ? <select value={p.recording_format ?? ''} aria-label={`Recording format for ${p.name}`} style={{ minWidth: 200 }} disabled={a.busy}
                  onChange={(e) => void a.run(async () => { await api('PATCH', `/imports/${p.id}`, { recording_format: e.target.value || null }); await portfolios.reload(); })}>
                  <option value="">Default ({fmtName(null)})</option>
                  {formats.data.formats.map((f: any) => <option key={f.id} value={f.id}>{f.name}</option>)}
                </select>
              : <span className="small">{fmtName(p.recording_format) ?? '—'}</span> },
          { key: 'total', label: 'Cases', align: 'right' },
          { key: 'unassigned', label: 'Not assigned', align: 'right', render: (p: any) => p.unassigned ? <Badge value="pending" label={String(p.unassigned)} /> : <Badge value="ok" label="0" /> },
          { key: 'groups', label: 'Groups', render: (p: any) => p.groups.filter((g: any) => g.team_id).map((g: any) => <span key={g.team_id} className="tag">{g.name} · {g.total}</span>) },
          { key: 'go', label: '', render: (p: any) => can('customers.assign') && <button className="secondary" onClick={() => nav(`/allocation?portfolio=${p.id}`)}>Assign →</button> },
        ]} />
      </Card>

      {can('customers.import') && (
        <Card title={job ? `Upload: ${job.original_name}` : 'Upload a new portfolio'} actions={job && <><Badge value={job.status} /><button className="ghost" onClick={() => { setJob(null); setName(''); setRecFormat(''); setPtype(''); }}>New upload</button></>}>
          {!job ? (
            <>
            <h3 style={{ margin: '0 0 6px' }}>1. Type of portfolio</h3>
            <div className="type-grid" role="radiogroup" aria-label="Type of portfolio">
              {(types.data ?? []).map((t) => (
                <button key={t.type} type="button" role="radio" aria-checked={ptype === t.type} className={`type-card${ptype === t.type ? ' on' : ''}`} onClick={() => setPtype(t.type)}>
                  <strong>{t.label}</strong><small>{t.hint}</small>
                </button>))}
            </div>
            {ptype && <p className="small" style={{ margin: '8px 0 14px' }}>
              <a href={fileUrl(`/api/admin/imports/template${qs({ type: ptype })}`)}>Download the {typeOf(ptype)?.label} sample file</a> — its columns: Customer ID, name, phone, address…
              {' '}{typeOf(ptype)?.fields.map((f) => f.label).join(', ')}. Map pins are found from the address automatically.</p>}
            <div className="form-grid">
              <Field label="2. Portfolio name" hint="e.g. HDFC Oct-2026 Bucket 1. Leave blank to use the file name."><input value={name} onChange={(e) => setName(e.target.value)} /></Field>
              <RecFormatField formats={formats.data?.formats} value={recFormat} onChange={setRecFormat} label="3. Recording file names" />
              <Field label="4. File (.csv or .xlsx)" hint={ptype ? `Up to ${MAX_MB} MB. Needs Customer ID, name and Phone number columns — see the sample file.` : 'Choose the type of portfolio first.'}><input type="file" accept=".csv,.xlsx" disabled={a.busy} onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void upload(f); }} aria-label="Lead file" /></Field>
              {a.busy && <Notice tone="info">Uploading and reading the file…</Notice>}
            </div>
            </>
          ) : (
            <>
              <div className="form-grid">
                <Field label="Portfolio name"><input value={name} onChange={(e) => setName(e.target.value)} disabled={job.status === 'completed'} /></Field>
                <RecFormatField formats={formats.data?.formats} value={recFormat} onChange={setRecFormat} label="Recording file names" disabled={job.status === 'completed'} />
              </div>
              <p className="small" style={{ margin: '4px 0' }}>Type: <span className="tag">{typeOf(job.portfolio_type)?.label}</span></p>
              <h3 style={{ marginBottom: 4 }}>Match the columns of your file</h3>
              <p className="muted" style={{ marginTop: 0 }}>Most are matched automatically. * = required. A <b>Caller ID</b> column gives a lead straight to that employee; leave it out to assign later.</p>
              <div className="form-grid">
                {((job.fields ?? []) as MapField[]).map(({ key: f, label, required: req }) => (
                  <Field key={f} label={`${label}${req ? ' *' : ''}`}>
                    <select value={mapping[f] ?? ''} disabled={job.status === 'completed'} onChange={(e) => { setMapping({ ...mapping, [f]: e.target.value === '' ? null : Number(e.target.value) }); setPreview(null); }}>
                      <option value="">— not in file —</option>{(job.headers as string[]).map((h, i) => <option key={i} value={i}>{h}</option>)}
                    </select>
                  </Field>
                ))}
                <Field label="If a Customer ID already exists"><select value={onExisting} onChange={(e) => setOnExisting(e.target.value)}><option value="update">Update it</option><option value="skip">Skip the row</option></select></Field>
                <Field label="Other columns of the file" hint="Columns not matched above are kept with their own names and shown on the case.">
                  <label className="row" style={{ gap: 6 }}><input type="checkbox" checked={keepOther} disabled={job.status === 'completed'} onChange={(e) => { setKeepOther(e.target.checked); setPreview(null); }} /> Keep them</label></Field>
              </div>
              <div className="row">
                <button className={job.status === 'ready' ? 'secondary' : undefined} disabled={a.busy || busyJob || job.status === 'completed'} onClick={check}>Check file</button>
                <button disabled={a.busy || busyJob || job.status !== 'ready' || !job.valid_rows} onClick={commit}>Import {job.valid_rows ? `${job.valid_rows} leads` : ''} into “{name || job.portfolio}”</button>
              </div>
              {a.busy && !busyJob && <Notice tone="info">Working…</Notice>}
              {busyJob && <Notice tone="info">{job.status === 'validating' ? 'Checking every row…' : 'Importing… this page updates by itself.'}</Notice>}
              {job.status === 'uploaded' && !a.busy && <p className="muted">Next: press <b>Check file</b>. Nothing is imported until you press Import.</p>}
              {job.status === 'ready' && <Notice tone={job.error_rows ? 'warn' : 'ok'}>{job.total_rows} rows: {job.valid_rows} ready to import, {job.error_rows} with problems (they will be skipped). {job.error_rows > 0 && <a href={fileUrl(`/api/admin/imports/${job.id}/errors.csv`)}>Download the problem rows</a>}</Notice>}
              {job.status === 'completed' && <Notice tone="ok">Done: {job.created_count} new, {job.updated_count} updated, {job.skipped_count} skipped. {can('customers.assign') && <Link to={`/allocation?portfolio=${job.id}`}>Assign this portfolio →</Link>}</Notice>}
              {job.status === 'completed' && job.geo && (
                <p className="muted small">Map pins: <b>{job.geo.pinned}</b> of {job.geo.total} placed
                  {job.geo.waiting > 0 && <> · {job.geo.waiting} being looked up…</>}
                  {job.geo.area_only > 0 && <> · {job.geo.area_only} pinned at their area (address too vague — corrected at the door check-in)</>}
                  {job.geo.not_found > 0 && <> · {job.geo.not_found} not found on the map</>}
                  {job.geo.no_address > 0 && <> · {job.geo.no_address} without an address</>}</p>
              )}
              {job.status === 'failed' && <Notice tone="bad">Import failed: {job.failure}</Notice>}
              {preview && preview.errors.length > 0 && <Table rows={preview.errors.slice(0, 20).map((e: any, i: number) => ({ id: i, ...e }))} columns={[{ key: 'row', label: 'Row' }, { key: 'field', label: 'Column' }, { key: 'message', label: 'Problem' }]} />}
            </>
          )}
        </Card>
      )}

      {can('customers.import') && (
        <Card title="Upload history">
          <Table rows={imports.data?.data} onRow={(j: any) => ['uploaded', 'ready'].includes(j.status) ? void open(j) : undefined} columns={[
            { key: 'portfolio', label: 'Portfolio', render: (j: any) => j.portfolio ?? j.original_name },
            { key: 'portfolio_type', label: 'Type', render: (j: any) => typeOf(j.portfolio_type)?.label ?? '—' },
            { key: 'original_name', label: 'File' },
            { key: 'status', label: 'Status', render: (j: any) => ['uploaded', 'ready'].includes(j.status) ? <><Badge value={j.status} /> <small className="muted">not imported — click to finish</small></> : <Badge value={j.status} /> },
            { key: 'total_rows', label: 'Rows', align: 'right' }, { key: 'created_count', label: 'New', align: 'right' }, { key: 'updated_count', label: 'Updated', align: 'right' },
            { key: 'created_at', label: 'Uploaded', render: (j: any) => fmtDate(j.created_at) }]} />
        </Card>
      )}
    </Page>
  );
}

const STATUS_TONE: Record<string, string> = { new: 'info', in_progress: 'ok', follow_up: 'warn', closed: 'muted', do_not_call: 'bad' };
const StatusBadge = ({ s }: { s: string }) => <span className={`badge ${STATUS_TONE[s] ?? 'muted'}`}>{s.replace(/_/g, ' ')}</span>;

/** Which recording file-name format the portfolio's downloads use (Customers → Recording file names). */
function RecFormatField({ formats, value, onChange, label, disabled }: { formats?: any[]; value: string; onChange: (v: string) => void; label: string; disabled?: boolean }) {
  const def = formats?.find((f) => f.is_default);
  return (
    <Field label={label} hint="How this portfolio's call recordings are named when downloaded. Set up formats under Customers → Recording file names.">
      <select value={value} onChange={(e) => onChange(e.target.value)} disabled={disabled}>
        <option value="">Default{def ? ` (${def.name})` : ''}</option>
        {(formats ?? []).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
      </select>
    </Field>
  );
}

export function CustomersPage() {
  const { can } = useAuth();
  const nav = useNavigate();
  const [urlParams] = useSearchParams();
  const [f, setF] = useState({ q: '', status: '', assigned: '', team_id: '', import_id: urlParams.get('import_id') ?? '', portfolio_type: '' });
  const customers = useAsync(() => get(`/customers${qs(f)}`), [f.q, f.status, f.assigned, f.team_id, f.import_id, f.portfolio_type]);
  const ptypes = useAsync(() => get<any[]>('/imports/types'));
  const oneType = f.portfolio_type ? ptypes.data?.find((t) => t.type === f.portfolio_type) : null;
  // ponytail: first 6 file columns only, the case page shows all of them.
  const fileKeys = [...new Set<string>((customers.data?.data ?? []).flatMap((c: any) => Object.keys(c.details ?? {}).filter((k) => k.startsWith('x:'))))].slice(0, 6);
  useLiveReload(customers.reload);
  const emps = useAsync(() => (can('employees.view') ? get('/employees') : Promise.resolve({ data: [] })));
  const teams = useAsync(() => (can('employees.view') ? get<any[]>('/teams') : Promise.resolve([])));
  const portfolios = useAsync(() => get<any[]>('/portfolios'));
  const [sel, setSel] = useState<number[]>([]);
  // Assign panel: one caller, or random (even) between callers of one group — same rules as the Excel upload.
  const [mode, setMode] = useState<'single' | 'random'>('single');
  const [to, setTo] = useState('');
  const [teamId, setTeamId] = useState('');
  const [callerIds, setCallerIds] = useState<number[]>([]);
  const [done, setDone] = useState<string | null>(null);
  const a = useAction();
  const people: any[] = emps.data?.data ?? [];
  const teamName = (id: number | null) => (teams.data ?? []).find((t: any) => t.id === id)?.name;
  const callers = people.filter((e: any) => e.status === 'active' && e.roles.some((r: any) => r.key === 'employee'));
  const groupCallers = callers.filter((e: any) => e.teams.some((t: any) => String(t.id) === teamId && (t.pivot?.membership ?? 'member') === 'member'));
  const rows: any[] | undefined = customers.data?.data;
  const pageIds = (rows ?? []).map((c: any) => c.id);
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => sel.includes(id));
  const toggle = (id: number) => setSel((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
  const set = (k: string, v: string) => { setF({ ...f, [k]: v }); setSel([]); setDone(null); };
  const assign = () => a.run(async () => {
    const r = mode === 'random'
      ? await post('/customers/assign', { customer_ids: sel, mode: 'random', team_id: Number(teamId), user_ids: callerIds })
      : await post('/customers/assign', { customer_ids: sel, user_id: Number(to) });
    setDone(mode === 'random'
      ? `Assigned ${r.updated} customers: ` + Object.entries(r.per_caller).map(([uid, n]) => `${people.find((e: any) => e.id === Number(uid))?.name ?? uid} ${n}`).join(', ')
      : `Assigned ${r.updated} customers to ${people.find((e: any) => e.id === Number(to))?.name}.`);
    setSel([]);
    await customers.reload();
  });
  return (
    <Page title="Customers" subtitle="Phones receive only customers currently assigned to the signed-in employee; withdrawn customers are removed at the next sync.">
      <ErrorBox error={customers.error ?? a.error} />
      <Card>
        <div className="filters filter-bar">
          <input placeholder="Search name or Customer ID" value={f.q} onChange={(e) => set('q', e.target.value)} style={{ minWidth: 240, flex: '1 1 240px' }} />
          <select value={f.status} onChange={(e) => set('status', e.target.value)} aria-label="Status"><option value="">All statuses</option>{['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'].map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}</select>
          <select value={f.team_id} onChange={(e) => set('team_id', e.target.value)} aria-label="Group"><option value="">All groups</option>{(teams.data ?? []).map((t: any) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
          <select value={f.assigned} onChange={(e) => set('assigned', e.target.value)} aria-label="Caller"><option value="">All callers</option><option value="none">Not assigned to a caller</option>{callers.map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select>
          <select value={f.portfolio_type} onChange={(e) => set('portfolio_type', e.target.value)} aria-label="Type of portfolio"><option value="">All types</option>{(ptypes.data ?? []).map((t: any) => <option key={t.type} value={t.type}>{t.label}</option>)}</select>
          <select value={f.import_id} onChange={(e) => set('import_id', e.target.value)} aria-label="Portfolio"><option value="">All portfolios</option>{(portfolios.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
          {Object.values(f).some(Boolean) && <button className="ghost" onClick={() => { setF({ q: '', status: '', assigned: '', team_id: '', import_id: '', portfolio_type: '' }); setSel([]); }}>Clear</button>}
        </div>
        {can('customers.assign') && (
          <div className="assign-bar">
            <label className="row"><input type="checkbox" style={{ width: 'auto' }} checked={allOnPage} onChange={() => setSel(allOnPage ? [] : pageIds)} /> Select all on this page</label>
            <strong>{sel.length} selected</strong>
            <select value={mode} onChange={(e) => setMode(e.target.value as any)} aria-label="Assign mode"><option value="single">Assign to one caller</option><option value="random">Random allocation in a group</option></select>
            {mode === 'single' ? (
              <select value={to} onChange={(e) => setTo(e.target.value)} aria-label="Assign to"><option value="">Choose caller…</option>{callers.map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code}){e.teams[0] ? ` · ${e.teams[0].name}` : ''}</option>)}</select>
            ) : (
              <select value={teamId} onChange={(e) => { setTeamId(e.target.value); setCallerIds([]); }} aria-label="Group"><option value="">Choose group…</option>{(teams.data ?? []).map((t: any) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
            )}
            <button disabled={a.busy || sel.length === 0 || (mode === 'single' ? !to : !teamId)} onClick={assign}>Assign {sel.length || ''}</button>
            <button className="danger" disabled={a.busy || sel.length === 0} onClick={() => a.run(async () => { if (!confirm(`Withdraw ${sel.length} customers from their callers?`)) return; await post('/customers/withdraw', { customer_ids: sel }); setSel([]); await customers.reload(); })}>Withdraw</button>
            {mode === 'random' && teamId && (
              <div className="assign-callers">
                <span className="muted">Callers ({callerIds.length || 'all'} of {groupCallers.length}) — none ticked = all telecallers of the group, shared evenly at random:</span>
                {groupCallers.length === 0 && <span className="muted">No active telecallers in this group.</span>}
                {groupCallers.map((e: any) => (
                  <label key={e.id} className="row"><input type="checkbox" style={{ width: 'auto' }} checked={callerIds.includes(e.id)} onChange={(ev) => setCallerIds((c) => ev.target.checked ? [...c, e.id] : c.filter((x) => x !== e.id))} /> {e.name} <small className="muted">{e.employee_code}</small></label>
                ))}
              </div>
            )}
          </div>
        )}
        {done && <Notice tone="ok">{done}</Notice>}
        <Table rows={rows} empty="No customers match. Import a file under Customers → Imports." onRow={(c: any) => nav(`/customers/${c.id}`)} columns={[
          { key: 's', label: '', render: (c: any) => <input type="checkbox" checked={sel.includes(c.id)} onClick={(e) => e.stopPropagation()} onChange={() => toggle(c.id)} aria-label={`Select ${c.customer_ref}`} style={{ width: 'auto' }} /> },
          { key: 'customer_ref', label: 'Customer ID' }, { key: 'name', label: 'Name' }, { key: 'phone_masked', label: 'Phone' },
          { key: 'portfolio', label: 'Portfolio', render: (c: any) => c.last_import_id ? ((portfolios.data ?? []).find((p: any) => p.id === c.last_import_id)?.name ?? `Import #${c.last_import_id}`) : <small className="muted">added by hand</small> }, { key: 'status', label: 'Status', render: (c: any) => <StatusBadge s={c.status} /> },
          { key: 'assigned_user_id', label: 'Caller', render: (c: any) => people.find((e: any) => e.id === c.assigned_user_id)?.name ?? <span className="muted">not assigned</span> },
          { key: 'city', label: 'City', render: (c: any) => c.city ? <>{c.city}{c.pincode ? <small className="muted"> {c.pincode}</small> : null}</> : <span className="muted">—</span> },
          { key: 'assigned_team_id', label: 'Group', render: (c: any) => teamName(c.assigned_team_id) ?? people.find((e: any) => e.id === c.assigned_user_id)?.teams?.[0]?.name ?? <span className="muted">—</span> },
          { key: 'overdue_amount', label: oneType ? oneType.due : 'Overdue / to collect', align: 'right', render: (c: any) => c.overdue_amount !== null ? <span title={c.labels?.due}>{inr(c.overdue_amount)}</span> : <span className="muted">—</span> },
          ...(!oneType || oneType.type === 'collections'
            ? [{ key: 'dpd', label: 'DPD', align: 'right' as const, render: (c: any) => { if (c.portfolio_type && c.portfolio_type !== 'collections') return <span className="muted">—</span>; const d = daysOverdue(c); return d === null ? <span className="muted">—</span> : <span className={d > 60 ? 'bad-text' : undefined}>{d}</span>; } }]
            : [{ key: 'due_date', label: oneType.date, render: (c: any) => c.due_date ? fmtDay(c.due_date) : <span className="muted">—</span> }]),
          { key: 'portfolio_type', label: 'Type', render: (c: any) => <span className="small">{c.labels?.label}</span> },
          // The file's own columns (e.g. "Relative" in a Custom file), when the list is narrowed to one portfolio or type.
          ...((f.import_id || oneType) ? fileKeys.map((k) => ({ key: k, label: k.slice(2), render: (c: any) => c.details?.[k] ?? <span className="muted">—</span> })) : []),
          { key: 'follow_up_on', label: 'Follow-up', render: (c: any) => c.follow_up_on?.slice(0, 10) ?? '—' },
        ]} />
        {customers.data?.total > (rows?.length ?? 0) && <p className="muted">Showing {rows?.length} of {customers.data.total}. For big batches use <Link to="/allocation">Customers → Allocation</Link> (all or part of the matching cases at once).</p>}
      </Card>
    </Page>
  );
}

const TABS = ['Overview', 'Customer details', 'Loan & dues', 'Payments', 'Calls & recordings', 'Follow-ups & commitments', 'Address & map'] as const;
const PROMISE_TONE: Record<string, string> = { kept: 'verified', broken: 'failed', open: 'pending' };

/** Full case profile for supervisors: dues, address + map, activity, payments, calls with recordings (if allowed), follow-ups. */
export function CustomerDetailPage() {
  const { id } = useParams();
  const { me, can } = useAuth();
  const c = useAsync(() => get(`/customers/${id}`), [id]);
  useLiveReload(c.reload);
  const emps = useAsync(() => (can('employees.view') ? get('/employees') : Promise.resolve({ data: [] })));
  const [to, setTo] = useState('');
  const [params] = useSearchParams();
  const [tab, setTab] = useState<(typeof TABS)[number]>(params.get('tab') === 'address' ? 'Address & map' : 'Overview');
  const [note, setNote] = useState('');
  const a = useAction();
  const d = c.data;
  const tz = me?.company.timezone;
  const du = d?.dues ?? {};
  const lb = d?.labels ?? { label: 'Collections & Recovery', value: 'Outstanding', due: 'Overdue', amount: 'Collected' };
  const coll = !d?.portfolio_type || d.portfolio_type === 'collections';
  const typeFields = (d?.details ?? []).filter((x: any) => !String(x.key).startsWith('x:'));
  const fileCols = (d?.details ?? []).filter((x: any) => String(x.key).startsWith('x:'));
  const tabName = (t: string) => (t === 'Loan & dues' && !coll ? `${lb.label} details` : t);
  const address = d ? [d.address, d.area, d.city, d.state, d.pincode].filter(Boolean).join(', ') : '';
  const dest = d?.location ? `${d.location.lat},${d.location.lng}` : address;
  const directions = dest ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(dest)}` : null;
  const kv = (rows: [string, any][]) => (
    <table className="kv"><tbody>{rows.map(([k, v]) => <tr key={k}><th>{k}</th><td>{v === null || v === undefined || v === '' ? <span className="muted">Not available</span> : v}</td></tr>)}</tbody></table>
  );
  return (
    <Page title={d ? d.name : 'Customer'} subtitle={d ? [d.customer_ref, d.portfolio ?? 'Added by hand', d.assigned_user ? `${d.assigned_user.name}${d.team ? ` · ${d.team.name}` : ''}` : 'Not assigned'].join(' · ') : undefined}
      actions={<><Link className="button secondary" to="/customers">← All customers</Link>{directions && <a className="button secondary" href={directions} target="_blank" rel="noreferrer">Get directions</a>}<button className="secondary" onClick={c.reload}>Refresh</button></>}>
      <ErrorBox error={c.error ?? a.error} />
      {d && (
        <>
          {d.withdrawn_at && <Notice tone="warn">Withdrawn on {fmtDate(d.withdrawn_at, tz)} — not visible on any phone.</Notice>}
          <div className="kpis">
            <Stat label="Status" value={<StatusBadge s={d.status} />} />
            <Stat label={coll ? 'Overdue now' : lb.due} value={inr(coll ? du.overdue_now : du.overdue_amount)} tone={du.days_overdue ? 'bad' : undefined} />
            {coll ? <Stat label="Days overdue" value={du.days_overdue ?? '—'} /> : <Stat label={lb.date} value={fmtDay(du.due_date)} />}
            <Stat label={coll ? 'Outstanding' : lb.value} value={inr(coll ? du.remaining_balance : du.outstanding)} />
            <Stat label={`${lb.amount} (verified)`} value={inr(d.activity.collected_verified)} />
            <Stat label="Next action" value={d.next_action ? fmtDate(d.next_action.due_at, tz) : '—'} />
          </div>
          <div className="tabs" role="tablist">{TABS.map((t) => <button key={t} role="tab" className={`tab${tab === t ? ' on' : ''}`} onClick={() => setTab(t)}>{tabName(t)}</button>)}</div>

          {tab === 'Overview' && (
            <div className="grid2">
              <Card title={coll ? 'Collection status' : 'Status'}>
                {coll && du.outstanding === null && du.overdue_amount === null && <Notice tone="info">Loan and due amounts were not in the uploaded file for this case.</Notice>}
                {kv([...(coll ? [['Overdue now', inr(du.overdue_now)], ['Days overdue', du.days_overdue], ['Next instalment', du.emi_amount !== null ? `${inr(du.emi_amount)}${du.due_date ? ` on ${du.due_date}` : ''}` : null],
                  ['Outstanding', inr(du.remaining_balance)]] as [string, any][] : [[lb.due, du.overdue_amount !== null ? inr(du.overdue_amount) : null], [lb.date, du.due_date ? fmtDay(du.due_date) : null],
                  [lb.value, du.outstanding !== null ? inr(du.outstanding) : null], ...typeFields.slice(0, 3).map((x: any) => [x.label, x.text])] as [string, any][]), ['Waiting for payment check', d.activity.collected_pending ? inr(d.activity.collected_pending) : '—'],
                  ['Last call', d.activity.last_call_at ? `${fmtDate(d.activity.last_call_at, tz)}${d.activity.last_outcome_label ? ` · ${d.activity.last_outcome_label}` : ''}` : 'Not called yet'],
                  ['Latest promise', d.promises[0] ? <>{inr(d.promises[0].amount)} by {d.promises[0].date} <Badge value={PROMISE_TONE[d.promises[0].status]} label={d.promises[0].status} /></> : 'None']])}
                {can('customers.assign') && !d.withdrawn_at && (
                  <div className="row" style={{ marginTop: 12 }}>
                    <select value={to} onChange={(e) => setTo(e.target.value)} aria-label="Reassign to" style={{ maxWidth: 240 }}><option value="">Transfer to…</option>{(emps.data?.data ?? []).filter((e: any) => e.status === 'active' && e.id !== d.assigned_user_id).map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code}){e.teams[0] ? ` · ${e.teams[0].name}` : ''}</option>)}</select>
                    <button disabled={a.busy || !to} onClick={() => a.run(async () => { await post('/customers/assign', { customer_ids: [d.id], user_id: Number(to) }); setTo(''); await c.reload(); })}>Transfer</button>
                    <select value="" onChange={(e) => e.target.value && a.run(async () => { await post(`/customers/${d.id}/status`, { status: e.target.value }); await c.reload(); })} aria-label="Change status" style={{ maxWidth: 180 }}>
                      <option value="">Change status…</option>{['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'].filter((s) => s !== d.status).map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
                    </select>
                    <button className="danger" disabled={a.busy} onClick={() => a.run(async () => { await post('/customers/withdraw', { customer_ids: [d.id] }); await c.reload(); })}>Withdraw</button>
                  </div>
                )}
              </Card>
              <Card title="Activity">
                <div className="row" style={{ marginBottom: 10 }}>
                  <input placeholder="Add a remark for this case" value={note} onChange={(e) => setNote(e.target.value)} style={{ flex: 1 }} />
                  <button disabled={a.busy || !note.trim()} onClick={() => a.run(async () => { await post(`/customers/${d.id}/note`, { text: note.trim() }); setNote(''); await c.reload(); })}>Add</button>
                </div>
                <ul className="timeline">
                  {d.timeline.length === 0 && <li className="muted">No activity yet.</li>}
                  {d.timeline.map((t: any, i: number) => (
                    <li key={i} className={`tl-${t.type}`}>
                      <strong>{timelineTitle(t)}</strong>
                      {t.amount !== null && t.amount !== undefined && <div>{t.payment_mode ? `Payment ${inr(t.amount)} · ${t.payment_mode} · ${t.review_status}` : `Promised ${inr(t.amount)} by ${t.promise_date ?? '—'}`}</div>}
                      {t.type !== 'assignment' && t.notes && <div>{t.notes}</div>}
                      <small className="muted">{fmtDate(t.at, tz)}{t.by ? ` · ${t.by}` : ''}</small>
                    </li>
                  ))}
                </ul>
              </Card>
            </div>
          )}

          {tab === 'Customer details' && (
            <div className="grid2">
              <Card title="Customer">{kv([['Name', d.name], ['Case ID', d.customer_ref], ['Phone', d.phone], ['Alternate', d.alt_phone], ['Email', d.email], ['Preferred contact time', d.preferred_time], ['Added', fmtDate(d.created_at, tz)]])}</Card>
              {fileCols.length > 0 && <Card title="More from the uploaded file"><div className="kv-grid">{fileCols.map((x: any) => <div key={x.key}><small>{x.label}</small>{x.text}</div>)}</div></Card>}
              <Card title="Assignment">
                {kv([['Portfolio', d.portfolio ?? 'Added by hand'], ['Campaign', d.campaign], ['Employee', d.assigned_user ? `${d.assigned_user.name} (${d.assigned_user.employee_code})` : 'Group pool / not assigned'], ['Group', d.team?.name], ['Assigned on', d.assigned_at ? fmtDate(d.assigned_at, tz) : null]])}
                <h4>Assignment history</h4>
                <ul className="timeline">{d.timeline.filter((t: any) => t.type === 'assignment').map((t: any, i: number) => <li key={i}>{t.notes} <small className="muted">· {fmtDate(t.at, tz)} · {t.by}</small></li>)}</ul>
              </Card>
            </div>
          )}

          {tab === 'Loan & dues' && !coll && (
            <Card title={`${lb.label} details`}>
              {typeFields.length + fileCols.length ? <div className="kv-grid">{[...typeFields, ...fileCols].map((x: any) => <div key={x.key}><small>{x.label}</small>{x.text}</div>)}</div>
                : <p className="muted">No {lb.label} details in the uploaded file for this case.</p>}
            </Card>
          )}
          {tab === 'Loan & dues' && coll && (
            <>
              <Notice tone="info">Amounts as uploaded on {du.balance_as_of ?? '—'}. Only payments the office has verified are taken off; promises and unchecked payments are not.</Notice>
              <div className="grid2">
                <Card title="Overdue">{kv([['Overdue now', inr(du.overdue_now)], ['Overdue (as uploaded)', inr(du.overdue_amount)], ['Days overdue', du.days_overdue], ['Oldest unpaid instalment', du.oldest_due_date], ['Overdue instalments', du.overdue_installments], ['Next instalment', du.emi_amount !== null ? inr(du.emi_amount) : null], ['Next due date', du.due_date]])}</Card>
                <Card title="Account">{kv([['Loan / account amount', du.loan_amount !== null ? inr(du.loan_amount) : null], ['Total outstanding (as uploaded)', du.outstanding !== null ? inr(du.outstanding) : null], ['Principal outstanding', du.principal_os !== null ? inr(du.principal_os) : null], ['Interest outstanding', du.interest_os !== null ? inr(du.interest_os) : null], ['Late fees / charges', du.charges !== null ? inr(du.charges) : null], ['Verified payments since upload', inr(du.verified_since_upload)], ['Remaining balance', du.remaining_balance !== null ? inr(du.remaining_balance) : null], ['Last payment (from file)', du.last_paid_amount !== null ? `${inr(du.last_paid_amount)}${du.last_paid_on ? ` on ${du.last_paid_on}` : ''}` : null]])}</Card>
              </div>
              <p className="muted">Instalment-wise schedule: not in the uploaded file (one row per case), so only the summary is shown.</p>
            </>
          )}

          {tab === 'Payments' && (
            <Card title="Payment history" actions={can('payments.verify') && <Link className="button secondary" to="/payments">Check payments →</Link>}>
              <Table rows={d.payments.map((p: any, i: number) => ({ id: i, ...p }))} empty="No payments recorded." columns={[
                { key: 'paid_on', label: 'Paid on' }, { key: 'amount', label: 'Amount', align: 'right', render: (p: any) => inr(p.amount) },
                { key: 'mode_label', label: 'Mode' }, { key: 'ref', label: 'Reference' }, { key: 'by', label: 'Recorded by' },
                { key: 'status', label: 'Check', render: (p: any) => <Badge value={p.status === 'verified' ? 'verified' : p.status === 'rejected' ? 'failed' : 'pending'} label={p.status} /> },
                { key: 'has_slip', label: 'Slip', render: (p: any) => p.has_slip ? 'Yes' : '—' },
              ]} />
            </Card>
          )}

          {tab === 'Calls & recordings' && (
            <Card title="Call history">
              <Table rows={d.calls} empty="No calls yet." columns={[
                { key: 'initiated_at', label: 'When', render: (x: any) => fmtDate(x.initiated_at, tz) },
                { key: 'user', label: 'Employee', render: (x: any) => x.user?.name },
                { key: 'destination_masked', label: 'Number', render: (x: any) => <>{x.destination_masked} <small className="muted">{x.which_number}</small></> },
                { key: 'state', label: 'Result', render: (x: any) => <Badge value={x.state} /> },
                { key: 'talk_seconds', label: 'Talk', align: 'right', render: (x: any) => fmtSeconds(x.talk_seconds) },
                { key: 'disposition', label: 'Outcome', render: (x: any) => x.disposition ? <><span className="tag">{x.disposition.code}</span>{x.disposition.notes && <div className="muted small">{x.disposition.notes}</div>}</> : '—' },
                { key: 'recording', label: 'Recording', render: (x: any) => <RecordingPlayer callId={x.id} status={x.recording_status} format={x.recording?.format} canPlay={can('recordings.play')} canDownload={can('recordings.download')} /> },
              ]} />
            </Card>
          )}

          {tab === 'Follow-ups & commitments' && (
            <div className="grid2">
              <Card title="Callbacks">
                <Table rows={d.follow_ups} empty="No callbacks." columns={[
                  { key: 'due_at', label: 'Due', render: (f: any) => fmtDate(f.due_at, tz) },
                  { key: 'status', label: 'Status', render: (f: any) => <Badge value={f.status === 'open' ? (new Date(f.due_at) < new Date() ? 'failed' : 'pending') : 'completed'} label={f.status === 'open' && new Date(f.due_at) < new Date() ? 'overdue' : f.status} /> },
                  { key: 'by', label: 'By' }, { key: 'notes', label: 'Notes' },
                ]} />
              </Card>
              <Card title="Promises to pay">
                <Table rows={d.promises.map((p: any, i: number) => ({ id: i, ...p }))} empty="No promises." columns={[
                  { key: 'date', label: 'Promised for' }, { key: 'amount', label: 'Amount', align: 'right', render: (p: any) => inr(p.amount) },
                  { key: 'status', label: 'Status', render: (p: any) => <Badge value={PROMISE_TONE[p.status]} label={p.status} /> }, { key: 'by', label: 'By' },
                ]} />
              </Card>
            </div>
          )}

          {tab === 'Address & map' && (
            <>
              <Card title="Current address" actions={<>{address && <button className="secondary" onClick={() => navigator.clipboard?.writeText(address)}>Copy address</button>}{directions && <a className="button" href={directions} target="_blank" rel="noreferrer">Get directions</a>}</>}>
                <p>{address || <span className="muted">Address not available.</span>}</p>
                {d.pin === 'approximate' && <Notice tone="warn">Approximate pin: only the area / pincode of this address was found on the map. The employee's GPS check-in at the door sets the exact spot.</Notice>}
                {d.pin === 'verified' && <p className="small"><Badge value="verified" label="pin verified at the door" /></p>}
                {d.address_history?.[0] ? <ChangeDetail c={d.address_history[0]} tz={tz} />
                  : d.location ? <CaseMap lat={d.location.lat} lng={d.location.lng} label={d.name} />
                  : <Notice tone="info">No map pin yet — it is found from the address automatically{address ? '; directions use the address meanwhile' : ' (this case has no address)'}.</Notice>}
              </Card>
              <Card title={`Address history${d.address_history?.length ? ` · ${d.address_history.length}` : ''}`} actions={<Link className="button secondary" to="/address-report">All address changes →</Link>}>
                <AddressHistory rows={d.address_history ?? []} tz={tz} />
              </Card>
            </>
          )}
        </>
      )}
    </Page>
  );
}

function timelineTitle(t: any): string {
  switch (t.type) {
    case 'call': return `Call · ${t.outcome ?? t.state?.replace(/_/g, ' ') ?? ''}`;
    case 'entry': return `Recorded without a call · ${t.outcome ?? ''}`;
    case 'callback': return `Callback for ${new Date(t.due_at).toLocaleString()}${t.status !== 'open' ? ` (${t.status})` : ''}`;
    case 'note': return 'Remark';
    case 'location': return 'Address / map location';
    case 'visit': return 'Field visit · reached the customer';
    case 'status': return `Status: ${t.meta?.from?.replace(/_/g, ' ')} → ${t.meta?.to?.replace(/_/g, ' ')}`;
    default: return t.notes ?? t.type;
  }
}

/** Recorded customer location (from the uploaded file) on OpenStreetMap. */
function CaseMap({ lat, lng, label }: { lat: number; lng: number; label: string }) {
  const el = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!el.current) return;
    const m = L.map(el.current, { scrollWheelZoom: false }).setView([lat, lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
    L.circleMarker([lat, lng], { radius: 9, color: '#fff', weight: 2, fillColor: '#c9a35e', fillOpacity: 1 }).bindPopup(label).addTo(m);
    return () => { m.remove(); };
  }, [lat, lng, label]);
  return <div ref={el} className="map" role="region" aria-label="Customer location map" />;
}
