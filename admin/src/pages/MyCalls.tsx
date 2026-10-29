import { useEffect, useState } from 'react';
import { get, post, qs } from '../lib/api';
import { Badge, Card, ErrorBox, Notice, Page, Pager, Table, useAction, useAsync } from '../components/ui';

/**
 * G14: a telecaller calls their own customers from the console. Click2Call: the telecaller's work phone rings first;
 * when they answer, the customer is dialled. The call, recording and outcome are kept exactly as for calls from the app.
 */
const STATE: Record<string, string> = { initiated: 'Ringing your phone…', agent_answered: 'Calling the customer…', ringing: 'Customer\'s phone is ringing…',
  answered: 'Connected', connected: 'Connected', completed: 'Call ended', disconnected: 'Call ended', failed: 'Call failed', cancelled: 'Cancelled', busy: 'Customer busy', no_answer: 'No answer' };

export function MyCallsPage() {
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const list = useAsync(() => get(`/my/customers${qs({ q, page })}`), [q, page]);
  const [call, setCall] = useState<any | null>(null);
  const a = useAction();
  useEffect(() => {
    if (!call || call.terminal) return;
    const t = setInterval(async () => { try { setCall({ ...(await get(`/my/calls/${call.call_id}`)), name: call.name }); } catch { /* keep the last state */ } }, 3000);
    return () => clearInterval(t);
  }, [call]);
  const d = list.data;
  const start = (c: any, which: string) => a.run(async () => setCall({ ...(await post(`/my/customers/${c.id}/call`, { which })), name: c.name }));
  return (
    <Page title="My calls" subtitle="Call your customers from here. Your work phone rings first; answer it and ROVEXY dials the customer. Calls are recorded and kept like calls from the app.">
      <ErrorBox error={list.error ?? a.error} />
      {d && !d.can_call && <Notice tone="warn">Click2Call is not enabled for you, or not set up for the company. Ask your administrator (Calling configuration → People &amp; access).</Notice>}
      {d?.can_call && <p className="muted small">Your work phone: {d.work_number ?? 'not set — ask your administrator'}</p>}
      {call && (
        <Card title={`Call · ${call.name}`} actions={<Badge value={call.terminal ? (call.state === 'failed' ? 'bad' : 'muted') : 'ok'} label={call.terminal ? 'ended' : 'live'} />}>
          <p style={{ marginTop: 0, fontSize: '1.1rem' }}>{STATE[call.state] ?? call.state}{call.talk_seconds ? ` · ${call.talk_seconds} s` : ''}{call.failure_reason ? ` · ${call.failure_reason.replace(/_/g, ' ')}` : ''}</p>
          <div className="row">
            {!call.terminal && <button className="danger" disabled={a.busy} onClick={() => void a.run(async () => { await post(`/my/calls/${call.call_id}/hangup`); setCall({ ...(await get(`/my/calls/${call.call_id}`)), name: call.name }); })}>Hang up</button>}
            {call.terminal && <button className="secondary" onClick={() => setCall(null)}>Close</button>}
            {call.terminal && <small className="muted">Recording: {call.recording_status?.replace(/_/g, ' ') ?? '—'}. Record the outcome on the customer in the app or ask your manager.</small>}
          </div>
        </Card>
      )}
      <Card>
        <input placeholder="Search name, customer ID or phone" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} style={{ maxWidth: 360, marginBottom: 10 }} />
        <Table rows={d?.data} empty="No customers are assigned to you." columns={[
          { key: 'name', label: 'Customer', render: (c: any) => <span>{c.name}<br /><small className="muted">{c.customer_ref} · {c.phone}{c.city ? ` · ${c.city}` : ''}</small></span> },
          { key: 'status', label: 'Status', render: (c: any) => <Badge value="info" label={String(c.status).replace(/_/g, ' ')} /> },
          { key: 'follow_up_on', label: 'Follow-up', render: (c: any) => c.follow_up_on?.slice(0, 10) ?? '' },
          { key: 'overdue_amount', label: 'Overdue ₹', align: 'right', render: (c: any) => (c.overdue_amount ? Number(c.overdue_amount).toLocaleString('en-IN') : '') },
          { key: 'call', label: '', render: (c: any) => <span className="row">
            <button disabled={a.busy || !d?.can_call || (call && !call.terminal)} onClick={() => void start(c, 'primary')}>Call</button>
            {c.has_alternate && <button className="secondary" disabled={a.busy || !d?.can_call || (call && !call.terminal)} onClick={() => void start(c, 'alternate')}>Alternate</button>}
          </span> },
        ]} />
        <Pager meta={d} onPage={setPage} />
      </Card>
    </Page>
  );
}
