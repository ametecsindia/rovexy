import { useState } from 'react';
import { useLiveReload } from '../lib/live';
import { Link, useSearchParams } from 'react-router-dom';
import { get, post, put, del, fmtDate, fmtSeconds, qs, fileUrl } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Icon, Initials, Modal, Notice, Page, RecordingPlayer, Table, useAction, useAsync } from '../components/ui';

const OUTCOMES = ['completed', 'no_answer', 'busy', 'rejected', 'failed', 'disconnected', 'agent_unanswered', 'cancelled'];
const today = () => new Date().toLocaleDateString('en-CA');   // local date (toISOString would give UTC)

export function CallsPage() {
  const { me, can } = useAuth();
  const [params] = useSearchParams();
  const [f, setF] = useState({ state: params.get('state') ?? '', recording_status: params.get('recording_status') ?? '', user_id: params.get('user_id') ?? '', from: '', to: '', disposition: '' });
  const range = { from: f.from ? `${f.from}T00:00:00` : '', to: f.to ? new Date(new Date(f.to).getTime() + 86400000).toISOString().slice(0, 10) + 'T00:00:00' : '' };
  const calls = useAsync(() => get(`/calls${qs({ ...f, ...range })}`), [f.state, f.recording_status, f.user_id, f.from, f.to, f.disposition]);
  useLiveReload(calls.reload);
  const emps = useAsync(() => (can('employees.view') ? get('/employees') : Promise.resolve({ data: [] })));
  const codes = useAsync(() => get<any[]>('/disposition-codes'));
  const [open, setOpen] = useState<number | null>(null);
  const [deleting, setDeleting] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const all: any[] | undefined = calls.data?.data;
  const rows = all?.filter((c: any) => !search || `${c.customer?.name ?? ''} ${c.customer?.customer_ref ?? ''} ${c.user?.name ?? ''} ${c.destination_masked ?? ''}`.toLowerCase().includes(search.toLowerCase()));
  const answered = rows?.filter((c: any) => c.answered_at).length ?? 0;
  const talk = rows?.reduce((a: number, c: any) => a + (c.talk_seconds ?? 0), 0) ?? 0;
  const recorded = rows?.filter((c: any) => c.recording_status === 'available').length ?? 0;
  const undisposed = rows?.filter((c: any) => c.disposition_status === 'pending' && c.ended_at).length ?? 0;
  return (
    <Page title="Calls & recordings" subtitle="Ring, talk and total time are measured separately. A completed call does not imply a recording exists."
      actions={<button className="secondary" onClick={calls.reload}>Refresh</button>}>
      <div className="filters filter-bar">
        <div className="search-box"><Icon name="search" size={16} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search customer, reference, employee…" aria-label="Search calls" /></div>
        <select value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })} aria-label="Employee"><option value="">All employees</option>{(emps.data?.data ?? []).map((e: any) => <option key={e.id} value={e.id}>{e.name}</option>)}</select>
        <input type="date" value={f.from} max={f.to || today()} onChange={(e) => setF({ ...f, from: e.target.value })} aria-label="From date" />
        <input type="date" value={f.to} min={f.from} max={today()} onChange={(e) => setF({ ...f, to: e.target.value })} aria-label="To date" />
        <button className="ghost" onClick={() => setF({ ...f, from: today(), to: today() })}>Today</button>
        <select value={f.state} onChange={(e) => setF({ ...f, state: e.target.value })} aria-label="Outcome"><option value="">All outcomes</option>{OUTCOMES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}</select>
        <select value={f.recording_status} onChange={(e) => setF({ ...f, recording_status: e.target.value })} aria-label="Recording"><option value="">All recordings</option>{['available', 'processing', 'pending', 'failed', 'not_required', 'deleted_by_retention'].map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}</select>
        <select value={f.disposition} onChange={(e) => setF({ ...f, disposition: e.target.value })} aria-label="Disposition"><option value="">All dispositions</option>{(codes.data ?? []).map((c: any) => <option key={c.code} value={c.code}>{c.label}</option>)}</select>
        {(f.user_id || f.from || f.to || f.state || f.recording_status || f.disposition) && <button className="ghost" onClick={() => setF({ state: '', recording_status: '', user_id: '', from: '', to: '', disposition: '' })}>Clear</button>}
      </div>
      <ErrorBox error={calls.error} />
      {rows && (
        <div className="summary-strip">
          <div><span>{rows.length}</span><small>calls{calls.data.total > (all?.length ?? 0) ? ` (latest ${all?.length} of ${calls.data.total})` : ''}</small></div>
          <div><span>{answered}<em> · {rows.length ? Math.round((answered / rows.length) * 100) : 0}%</em></span><small>connected</small></div>
          <div><span>{fmtSeconds(talk)}</span><small>talk time</small></div>
          <div><span>{recorded}</span><small>recordings</small></div>
          <div className={undisposed ? 'warn' : ''}><span>{undisposed}</span><small>without outcome</small></div>
        </div>
      )}
      <Card>
        <div className="table-calls"><Table rows={rows} empty="No calls match these filters." onRow={(c: any) => setOpen(c.id)} columns={[
          { key: 'initiated_at', label: 'When', render: (c: any) => { const d = new Date(c.initiated_at); return <div className="when"><strong>{d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZone: me?.company.timezone })}</strong><small>{d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', timeZone: me?.company.timezone })}</small></div>; } },
          { key: 'customer', label: 'Customer', render: (c: any) => c.customer ? <Link className="person" to={`/customers/${c.customer_id}`} onClick={(e) => e.stopPropagation()}><Initials name={c.customer.name} size={30} /><span className="stack"><span>{c.customer.name}</span><small className="muted">{c.customer.customer_ref} · {c.destination_masked}</small></span></Link> : <span className="muted">Manual · {c.destination_masked}</span> },
          { key: 'user', label: 'Employee', render: (c: any) => <span className="emp">{c.user?.name}</span> },
          { key: 'state', label: 'Outcome', render: (c: any) => <span className="outcome"><Badge value={c.state} /><span className="route">{c.calling_mode === 'sim' ? 'SIM' : 'Server'}</span></span> },
          { key: 'talk_seconds', label: 'Talk', align: 'right', render: (c: any) => <span className="mono">{fmtSeconds(c.talk_seconds)}</span> },
          { key: 'recording_status', label: 'Recording', render: (c: any) => <RecordingPlayer compact callId={c.id} status={c.recording_status} format={c.recording?.format} canPlay={can('recordings.play')} canDownload={can('recordings.download')} /> },
          { key: 'disposition', label: 'Outcome code', render: (c: any) => c.disposition?.code ? <span className="disp">{c.disposition.code.replace(/_/g, ' ').toLowerCase()}</span> : (c.disposition_status === 'pending' && c.ended_at ? <Badge value="pending" /> : <span className="muted">—</span>) },
          ...(can('settings.retention') ? [{ key: 'delete', label: '', render: (c: any) => <button className="icon-btn del" title="Delete call and recording" aria-label="Delete call" disabled={deleting === c.id}
            onClick={async (e) => { e.stopPropagation(); if (!confirm(`Delete this call with ${c.customer?.name ?? 'the customer'} and its recording? This cannot be undone.`)) return;
              setDeleting(c.id); try { await del(`/calls/${c.id}`); calls.reload(); } catch (err: any) { alert(err?.message ?? 'Could not delete'); } finally { setDeleting(null); } }}><Icon name="trash" size={16} /></button> }] : []),
        ]} /></div>
      </Card>
      {open && <CallDetail id={open} onClose={() => setOpen(null)} />}
    </Page>
  );
}

function CallDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const { can, me } = useAuth();
  const call = useAsync(() => get(`/calls/${id}`), [id]);
  const a = useAction();
  const c = call.data;
  return (
    <Modal title={`Call ${c?.uuid ?? ''}`} onClose={onClose}>
      <ErrorBox error={call.error ?? a.error} />
      {c && (
        <>
          <p><Badge value={c.state} /> {c.hangup_cause && <span className="muted">cause {c.hangup_cause}</span>} {c.failure_reason && <Badge value="failed" label={c.failure_reason} />}</p>
          <p>Ring {fmtSeconds(c.ring_seconds)} · Talk {fmtSeconds(c.talk_seconds)} · Total {fmtSeconds(c.total_seconds)} · Route <span className="tag">{c.calling_mode === 'sim' ? 'SIM (phone call log)' : `Server · ${c.agent_leg}`}</span></p>
          {c.customer && <p>Customer <Link to={`/customers/${c.customer_id}`}>{c.customer.name} ({c.customer.customer_ref})</Link> · Employee {c.user?.name}</p>}
          <Card title="Recording" actions={<Badge value={c.recording?.status ?? c.recording_status} />}>
            {c.recording?.status === 'available' ? (
              <>
                <p className="muted">{c.recording.format?.toUpperCase()} · {Math.round((c.recording.size_bytes ?? 0) / 1024)} KB{c.recording.duration_seconds ? ` · ${fmtSeconds(c.recording.duration_seconds)}` : ''} · SHA-256 <span className="code">{c.recording.sha256?.slice(0, 16)}…</span> · retained until {fmtDate(c.recording.retention_expires_at, me?.company.timezone)}</p>
                <RecordingPlayer callId={id} status="available" format={c.recording.format} canPlay={can('recordings.play')} canDownload={can('recordings.download')} />
              </>
            ) : c.recording?.status === 'failed' ? (
              <>
                <Notice tone="bad">Recording failed: {c.recording.last_error ?? 'unknown'} (attempts: {c.recording.attempts})</Notice>
                {c.calling_mode !== 'sim' && can('telephony.configure') && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await post(`/calls/${id}/recording/retry`); await call.reload(); })}>Retry fetch</button>}
              </>
            ) : <p className="muted">{c.recording ? 'Not yet available.' : 'No recording for this call.'}</p>}
          </Card>
          <Card title="Lifecycle events">
            <Table rows={c.events} columns={[{ key: 'occurred_at', label: 'Time', render: (e: any) => fmtDate(e.occurred_at, me?.company.timezone) }, { key: 'type', label: 'Event' }, { key: 'payload', label: 'Detail', render: (e: any) => e.payload?.cause ?? (e.payload?.source === 'phone_call_log' ? <small>Talk {e.payload.talk}s from the phone's call log{e.payload.diag && Object.keys(e.payload.diag).length > 0 && <><br />{Object.entries(e.payload.diag).map(([k, v]) => <span key={k}>{k.replace(/_/g, ' ')}: <b>{String(v)}</b>; </span>)}</>}</small> : '') }]} />
          </Card>
          {c.disposition && <Card title="Feedback" actions={c.disposition.payment_mode ? <Link to="/payments">Payments &amp; slips →</Link> : undefined}>
            <p><strong>{c.disposition.code.replace(/_/g, ' ').toLowerCase()}</strong> {c.disposition.notes}</p>
            {c.disposition.amount && <p>{c.disposition.payment_mode ? 'Received' : 'Promised'}: <strong>₹ {Number(c.disposition.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</strong>
              {c.disposition.payment_mode && <> · {c.disposition.payment_mode.replace(/_/g, ' ')}{c.disposition.payment_ref && <> · ref <span className="code">{c.disposition.payment_ref}</span></>}{c.disposition.paid_on && <> · paid {c.disposition.paid_on}</>} · <Badge value={c.disposition.review_status ?? 'pending'} label={!c.disposition.review_status || c.disposition.review_status === 'pending' ? 'to check' : c.disposition.review_status} /></>}</p>}
            {c.disposition.has_slip && <p><a href={fileUrl(`/api/admin/payments/${c.disposition.id}/slip`)} target="_blank" rel="noreferrer">View payment slip</a></p>}
            {c.disposition.follow_up_at && <p>{c.disposition.captures === 'promise' || c.disposition.amount ? 'Promise / follow-up date' : 'Follow-up'}: {fmtDate(c.disposition.follow_up_at, me?.company.timezone)}</p>}
          </Card>}
        </>
      )}
    </Modal>
  );
}

export function CallingConfigPage() {
  const codes = useAsync(() => get<any[]>('/disposition-codes'));
  const [rows, setRows] = useState<any[] | null>(null);
  const a = useAction();
  const list = rows ?? codes.data ?? [];
  return (
    <Page title="Call outcome codes" subtitle="The outcomes employees pick after each call. Calling hours, prefixes and recording mode are policy settings (Policy profiles).">
      <ErrorBox error={codes.error ?? a.error} />
      <Card title="Outcome codes" actions={<button className="secondary" onClick={() => setRows([...list, { code: '', label: '', requires_follow_up: false, active: true, captures: 'none' }])}>Add code</button>}>
        <Table rows={list.map((r, i) => ({ ...r, id: i }))} columns={[
          { key: 'code', label: 'Code', render: (r: any) => <input value={r.code} onChange={(e) => setRows(list.map((x, i) => i === r.id ? { ...x, code: e.target.value.toUpperCase() } : x))} /> },
          { key: 'label', label: 'Label', render: (r: any) => <input value={r.label} onChange={(e) => setRows(list.map((x, i) => i === r.id ? { ...x, label: e.target.value } : x))} /> },
          { key: 'cap', label: 'Asks for', render: (r: any) => <select value={r.captures ?? 'none'} onChange={(e) => setRows(list.map((x, i) => i === r.id ? { ...x, captures: e.target.value } : x))}><option value="none">Nothing extra</option><option value="promise">Promised amount</option><option value="payment">Payment + slip</option></select> },
          { key: 'f', label: 'Needs follow-up', render: (r: any) => <input type="checkbox" style={{ width: 'auto' }} checked={!!r.requires_follow_up} onChange={(e) => setRows(list.map((x, i) => i === r.id ? { ...x, requires_follow_up: e.target.checked } : x))} /> },
          { key: 'a', label: 'Active', render: (r: any) => <input type="checkbox" style={{ width: 'auto' }} checked={!!r.active} onChange={(e) => setRows(list.map((x, i) => i === r.id ? { ...x, active: e.target.checked } : x))} /> },
        ]} />
        <button disabled={a.busy || !rows} onClick={() => a.run(async () => { await put('/disposition-codes', { codes: list.map(({ code, label, requires_follow_up, active, captures }) => ({ code, label, requires_follow_up: !!requires_follow_up, active: !!active, captures: captures ?? 'none' })) }); setRows(null); await codes.reload(); })}>Save codes</button>
      </Card>
    </Page>
  );
}

export function FollowUpsPage() {
  const { me } = useAuth();
  const fu = useAsync(() => get('/follow-ups'));
  return (
    <Page title="Follow-ups & dispositions" subtitle="Open follow-ups created from call dispositions and offline notes.">
      <ErrorBox error={fu.error} />
      <Card>
        <Table rows={fu.data?.data} empty="No open follow-ups." columns={[{ key: 'due_at', label: 'Due', render: (f: any) => fmtDate(f.due_at, me?.company.timezone) },
          { key: 'customer', label: 'Customer', render: (f: any) => f.customer ? <Link to={`/customers/${f.customer_id}`}>{f.customer.name} <small className="muted">{f.customer.customer_ref}</small></Link> : `#${f.customer_id}` },
          { key: 'user', label: 'Employee', render: (f: any) => f.user?.name ?? `#${f.user_id}` }, { key: 'notes', label: 'Notes' },
          { key: 'overdue', label: '', render: (f: any) => new Date(f.due_at) < new Date() ? <Badge value="failed" label="overdue" /> : null }]} />
      </Card>
    </Page>
  );
}
