import { useState } from 'react';
import { useLiveReload } from '../lib/live';
import { Link } from 'react-router-dom';
import { get, post, fmtDate, qs, fileUrl } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Icon, Modal, Notice, Page, Stat, Table, useAction, useAsync } from '../components/ui';

const inr = (v: any) => `₹ ${Number(v ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const slipUrl = (id: number, dl = false) => fileUrl(`/api/admin/payments/${id}/slip${dl ? '?dl=1' : ''}`);   // ?workspace= for /w/<slug>/admin
const lastTo = () => { try { return localStorage.getItem('pay.mailto') ?? ''; } catch { return ''; } };
const emails = (s: string) => s.split(/[,;\s]+/).map((x) => x.trim()).filter(Boolean);

/** Payments reported by employees in the call feedback: check the slip, verify / reject, e-mail it. */
export function PaymentsPage() {
  const { can } = useAuth();
  const [f, setF] = useState({ status: 'pending', mode: '', user_id: '', from: '', to: '' });
  const list = useAsync(() => get(`/payments${qs(f)}`), [f.status, f.mode, f.user_id, f.from, f.to]);
  useLiveReload(list.reload);
  const emps = useAsync(() => (can('employees.view') ? get('/employees') : Promise.resolve({ data: [] })));
  const [open, setOpen] = useState<any | null>(null);
  const d = list.data;
  const t = d?.totals ?? {};
  const modes: Record<string, string> = d?.modes ?? {};
  return (
    <Page title="Payments & slips" subtitle="Payments employees reported after a call, with the slip they uploaded. Check each slip, then verify or reject it and e-mail it if needed."
      actions={<button className="secondary" onClick={list.reload}>Refresh</button>}>
      <ErrorBox error={list.error} />
      <div className="kpis">
        <Stat label={`To check (${t.pending?.count ?? 0})`} value={inr(t.pending?.amount)} tone="warn" />
        <Stat label={`Verified (${t.verified?.count ?? 0})`} value={inr(t.verified?.amount)} tone="ok" />
        <Stat label={`Rejected (${t.rejected?.count ?? 0})`} value={inr(t.rejected?.amount)} tone="bad" />
      </div>
      <div className="filters filter-bar">
        <select value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} aria-label="Status">
          <option value="pending">To check</option><option value="verified">Verified</option><option value="rejected">Rejected</option><option value="">All</option>
        </select>
        <select value={f.mode} onChange={(e) => setF({ ...f, mode: e.target.value })} aria-label="Payment mode"><option value="">All modes</option>{Object.entries(modes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        <select value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })} aria-label="Employee"><option value="">All employees</option>{(emps.data?.data ?? []).map((e: any) => <option key={e.id} value={e.id}>{e.name}</option>)}</select>
        <input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} aria-label="Paid from" />
        <input type="date" value={f.to} min={f.from} onChange={(e) => setF({ ...f, to: e.target.value })} aria-label="Paid to" />
      </div>
      <Card>
        <Table rows={d?.data} empty="No payments here." onRow={setOpen} columns={[
          { key: 'paid_on', label: 'Paid on', render: (p: any) => p.paid_on ?? '—' },
          { key: 'customer', label: 'Customer', render: (p: any) => p.call?.customer ? <>{p.call.customer.name} <small className="muted">{p.call.customer.customer_ref}</small></> : '—' },
          { key: 'user', label: 'Employee', render: (p: any) => p.user?.name ?? '—' },
          { key: 'amount', label: 'Amount', align: 'right', render: (p: any) => <strong>{inr(p.amount)}</strong> },
          { key: 'mode', label: 'Mode', render: (p: any) => modes[p.payment_mode] ?? p.payment_mode },
          { key: 'ref', label: 'Reference', render: (p: any) => p.payment_ref ? <span className="code">{p.payment_ref}</span> : <span className="muted">—</span> },
          { key: 'slip', label: 'Slip', render: (p: any) => p.has_slip ? <Badge value="available" label="uploaded" /> : <Badge value="pending" label="no slip" /> },
          { key: 'status', label: 'Status', render: (p: any) => <Badge value={p.review_status ?? 'pending'} label={p.review_status === 'pending' || !p.review_status ? 'to check' : p.review_status} /> },
          { key: 'mail', label: '', render: (p: any) => p.emailed_at ? <span title={`E-mailed to ${p.emailed_to}`}><Icon name="check" size={16} /> mailed</span> : null },
        ]} />
      </Card>
      {open && <PaymentDetail p={open} modes={modes} onClose={() => setOpen(null)} onChanged={() => { setOpen(null); list.reload(); }} />}
    </Page>
  );
}

function PaymentDetail({ p, modes, onClose, onChanged }: { p: any; modes: Record<string, string>; onClose: () => void; onChanged: () => void }) {
  const { can, me } = useAuth();
  const tz = me?.company.timezone;
  const a = useAction();
  const [note, setNote] = useState(p.review_note ?? '');
  const [mail, setMail] = useState({ to: lastTo(), cc: '', message: '' });
  const [sent, setSent] = useState<string | null>(null);
  const isPdf = (p.slip_mime ?? '').includes('pdf');
  const review = (status: string) => a.run(async () => { await post(`/payments/${p.id}/review`, { status, note }); onChanged(); });
  return (
    <Modal title={`Payment · ${inr(p.amount)}`} onClose={onClose}>
      <ErrorBox error={a.error} />
      <div className="grid2">
        <div>
          <table className="kv"><tbody>
            <tr><th>Customer</th><td>{p.call?.customer ? <Link to={`/customers/${p.call.customer_id}`}>{p.call.customer.name} ({p.call.customer.customer_ref})</Link> : '—'}</td></tr>
            <tr><th>Amount</th><td><strong>{inr(p.amount)}</strong></td></tr>
            <tr><th>Mode</th><td>{modes[p.payment_mode] ?? p.payment_mode}</td></tr>
            <tr><th>Reference / UTR</th><td>{p.payment_ref || '—'}</td></tr>
            <tr><th>Paid on</th><td>{p.paid_on ?? '—'}</td></tr>
            <tr><th>Outcome</th><td>{p.code.replace(/_/g, ' ').toLowerCase()}</td></tr>
            <tr><th>Employee</th><td>{p.user?.name} <small className="muted">{p.user?.employee_code}</small></td></tr>
            <tr><th>Reported</th><td>{fmtDate(p.submitted_at, tz)}</td></tr>
            <tr><th>Notes</th><td>{p.notes || '—'}</td></tr>
            <tr><th>Status</th><td><Badge value={p.review_status ?? 'pending'} label={!p.review_status || p.review_status === 'pending' ? 'to check' : p.review_status} />{p.reviewer && <small className="muted"> by {p.reviewer.name} · {fmtDate(p.reviewed_at, tz)}</small>}</td></tr>
            {p.emailed_at && <tr><th>E-mailed</th><td>{p.emailed_to} · {fmtDate(p.emailed_at, tz)}</td></tr>}
          </tbody></table>
        </div>
        <div>
          {p.has_slip ? (
            <>
              {isPdf ? <iframe title="Payment slip" src={slipUrl(p.id)} style={{ width: '100%', height: 360, border: '1px solid var(--border)', borderRadius: 8 }} />
                : <a href={slipUrl(p.id)} target="_blank" rel="noreferrer"><img src={slipUrl(p.id)} alt="Payment slip" style={{ width: '100%', maxHeight: 360, objectFit: 'contain', border: '1px solid var(--border)', borderRadius: 8 }} /></a>}
              <p><a href={slipUrl(p.id)} target="_blank" rel="noreferrer">Open full size</a> · <a href={slipUrl(p.id, true)}>Download</a></p>
            </>
          ) : <Notice tone="warn">No slip was uploaded for this payment.</Notice>}
        </div>
      </div>
      {can('payments.verify') && (
        <>
          <Card title="Check">
            <Field label="Note (optional — e.g. why it was rejected)"><input value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} /></Field>
            <div className="row">
              <button disabled={a.busy} onClick={() => review('verified')}>Verify</button>
              <button className="danger" disabled={a.busy} onClick={() => review('rejected')}>Reject</button>
              {p.review_status && p.review_status !== 'pending' && <button className="ghost" disabled={a.busy} onClick={() => review('pending')}>Back to “to check”</button>}
            </div>
          </Card>
          <Card title="E-mail slip and details">
            <Field label="To" hint="One or more addresses, separated by commas."><input type="text" value={mail.to} onChange={(e) => setMail({ ...mail, to: e.target.value })} placeholder="accounts@company.com" /></Field>
            <Field label="Cc (optional)"><input type="text" value={mail.cc} onChange={(e) => setMail({ ...mail, cc: e.target.value })} /></Field>
            <Field label="Message (optional)"><textarea rows={3} value={mail.message} onChange={(e) => setMail({ ...mail, message: e.target.value })} /></Field>
            <button className="secondary" disabled={a.busy || emails(mail.to).length === 0} onClick={() => a.run(async () => {
              const r = await post(`/payments/${p.id}/email`, { to: emails(mail.to), cc: emails(mail.cc), message: mail.message || undefined });
              try { localStorage.setItem('pay.mailto', mail.to); } catch { /* private mode */ }
              setSent(r.mailer === 'log' ? 'Saved to the server log only — e-mail is not set up yet (MAIL_MAILER=log in backend/.env).' : `Sent to ${emails(mail.to).join(', ')}.`);
            })}>Send e-mail</button>
            {sent && <Notice tone={sent.startsWith('Sent') ? 'ok' : 'warn'}>{sent}</Notice>}
          </Card>
        </>
      )}
    </Modal>
  );
}
