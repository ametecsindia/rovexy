import { useEffect, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { get, post, put, qs, fmtDate } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useLiveReload } from '../lib/live';
import { Badge, Card, ErrorBox, Field, Initials, Kpi, Modal, Notice, Page, Pager, Table, toast, useAction, useAsync } from '../components/ui';

/**
 * Call monitoring (27 Nov 2026): Live calls with "Listen in" (SmartDCM Live calls → Join me) and SmartRMI
 * (AI call analysis from the SmartRMI engine + angry-call alerts).
 */

const since = (iso?: string | null) => {
  if (!iso) return '—';
  const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s`;
};
const tone = (v?: string | null) => {
  const x = (v ?? '').toLowerCase();
  return ['high', 'severe', 'critical', 'rude', 'angry', 'negative'].includes(x) ? 'failed' : ['medium', 'moderate', 'neutral'].includes(x) ? 'pending' : x ? 'completed' : 'muted';
};
const Score = ({ v }: { v?: number | null }) => v == null ? <span className="muted">—</span>
  : <strong style={{ color: v >= 70 ? 'var(--ok, #15803d)' : v >= 40 ? 'var(--warn, #b45309)' : 'var(--bad, #b91c1c)' }}>{Math.round(v)}</strong>;

export function LiveCallsPage() {
  const { me } = useAuth();
  const live = useAsync(() => get<any>('/monitoring/live'));
  useLiveReload(live.reload, ['call.'], 5_000);
  const [, tick] = useState(0);
  useEffect(() => { const t = setInterval(() => tick((n) => n + 1), 1000); return () => clearInterval(t); }, []);
  const a = useAction();
  const join = (c: any) => a.run(async () => {
    const r = await post<any>(`/monitoring/calls/${c.id}/join`);
    toast(r.message ?? 'Your phone will ring now.');
    sessions.reload();
  });
  const sessions = useAsync(() => get<any>('/monitoring/sessions'));
  const d = live.data;
  return (
    <Page title="Live calls" subtitle="Click2Call calls in progress. Listen in: the gateway rings your phone and adds you to the call silently. Every listen is recorded in the audit log.">
      <ErrorBox error={live.error ?? a.error} />
      {d && !d.configured && <Notice tone="warn">Listening in is not switched on yet. Ametecs has to add Telozar's conference API in Super Admin → Settings → Click2Call. You can already see live calls here.</Notice>}
      {d && !d.listen_number && <Notice tone="warn">Your profile has no phone number, so the gateway cannot ring you. Add it under Branches, teams &amp; people.</Notice>}
      <Notice>Only Click2Call calls can be joined. SIM calls stay on the agent's phone; Android does not let any app listen to them.</Notice>
      <Card title={`On a call now (${d?.calls?.length ?? 0})`} actions={<button className="secondary sm" onClick={() => live.reload()}>Refresh</button>}>
        <Table rows={d?.calls} empty="No Click2Call call in progress." columns={[
          { key: 'u', label: 'Agent', render: (c: any) => <span className="row"><Initials name={c.user?.name ?? '?'} size={26} /> {c.user?.name}{c.user?.employee_code ? <span className="muted"> · {c.user.employee_code}</span> : null}</span> },
          { key: 'cust', label: 'Customer', render: (c: any) => c.customer ? <Link to={`/customers/${c.customer.id}`}>{c.customer.name ?? c.customer.customer_ref}</Link> : c.destination_masked },
          { key: 'n', label: 'Number', render: (c: any) => <span className="code">{c.destination_masked}</span> },
          { key: 'state', label: 'State', render: (c: any) => <Badge value={c.state} /> },
          { key: 't', label: 'Duration', align: 'right', render: (c: any) => since(c.answered_at ?? c.initiated_at) },
          { key: 'go', label: '', align: 'right', render: (c: any) => <button disabled={a.busy || !d?.configured || !d?.listen_number} onClick={() => join(c)}>Listen in</button> },
        ]} />
      </Card>
      <Card title="Recent listens">
        <Table rows={sessions.data?.data} empty="Nobody has listened in yet." columns={[
          { key: 'created_at', label: 'When', render: (s: any) => fmtDate(s.created_at, me?.company.timezone) },
          { key: 'by', label: 'Listened by', render: (s: any) => s.user?.name },
          { key: 'agent', label: 'Agent', render: (s: any) => s.call?.user?.name ?? '—' },
          { key: 'cust', label: 'Customer', render: (s: any) => s.call?.customer?.name ?? s.call?.customer?.customer_ref ?? '—' },
          { key: 'phone', label: 'Rang', render: (s: any) => <span className="code">{s.listen_masked}</span> },
          { key: 'status', label: 'Result', render: (s: any) => <Badge value={s.status === 'joined' ? 'completed' : 'failed'} label={s.status === 'joined' ? 'joined' : (s.error ?? 'failed')} /> },
        ]} />
      </Card>
    </Page>
  );
}

function useRmiSettings() {
  return useAsync(() => get<any>('/rmi/settings'));
}

function RmiGate({ s, children }: { s: ReturnType<typeof useRmiSettings>; children: ReactNode }) {
  if (s.data && !s.data.licensed) return <Notice tone="warn">SmartRMI is not in your plan. Contact Ametecs to add it (Plan &amp; licence).</Notice>;
  return <>{s.data && !s.data.connected && <Notice>The SmartRMI engine is not connected yet. Call monitoring → Settings shows the engine's address and key.</Notice>}{children}</>;
}

function Range({ f, setF }: { f: any; setF: (x: any) => void }) {
  return (
    <div className="row wrap">
      <Field label="From"><input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
      <Field label="To"><input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
    </div>
  );
}

const monthStart = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toLocaleDateString('en-CA'); };

export function RmiOverviewPage() {
  const s = useRmiSettings();
  const [f, setF] = useState({ from: monthStart(), to: new Date().toLocaleDateString('en-CA') });
  const o = useAsync(() => (s.data?.licensed ? get<any>(`/rmi/overview${qs(f)}`) : Promise.resolve(null)), [f.from, f.to, s.data?.licensed]);
  const d = o.data;
  return (
    <Page title="SmartRMI" subtitle="AI monitoring of recorded calls: score, tone, aggression and compliance risk for every analysed call, with angry-call alerts to supervisors.">
      <ErrorBox error={s.error ?? o.error} />
      <RmiGate s={s}>
        <Range f={f} setF={setF} />
        {d && <>
          <div className="kpi-grid">
            <Kpi icon="chart" tone="blue" label="Calls analysed" value={d.analysed} />
            <Kpi icon="check" tone="green" label="Average score" value={d.avg_score ?? '—'} sub="out of 100" />
            <Kpi icon="warning" tone="gold" label="Angry calls" value={d.angry} />
            <Kpi icon="shield" tone="teal" label="High compliance risk" value={d.high_risk} />
            <Kpi icon="event" tone="purple" label="Open alerts" value={<Link to="/rmi/alerts">{d.open_alerts}</Link>} />
          </div>
          {Object.keys(d.tones ?? {}).length > 0 && <Card title="Agent tone">
            <p className="row wrap">{Object.entries(d.tones).map(([t, n]) => <span key={t} className="tag"><Badge value={tone(t)} label={t} /> {String(n)}</span>)}</p>
          </Card>}
          <Card title="By agent">
            <Table rows={d.agents} empty="No analysed calls in this period." columns={[
              { key: 'name', label: 'Agent', render: (r: any) => <Link to={`/rmi/calls?user_id=${r.user_id}`}>{r.name}</Link> },
              { key: 'analysed', label: 'Calls analysed', align: 'right' },
              { key: 'avg_score', label: 'Average score', align: 'right', render: (r: any) => <Score v={r.avg_score} /> },
              { key: 'angry', label: 'Angry calls', align: 'right', render: (r: any) => r.angry ? <Badge value="failed" label={String(r.angry)} /> : 0 },
            ]} />
          </Card>
        </>}
      </RmiGate>
    </Page>
  );
}

export function RmiCallsPage() {
  const { me } = useAuth();
  const s = useRmiSettings();
  const [params] = useSearchParams();
  const [f, setF] = useState({ from: monthStart(), to: new Date().toLocaleDateString('en-CA'), user_id: params.get('user_id') ?? '', angry: params.get('angry') ?? '', risk: '', max_score: '' });
  const [page, setPage] = useState(1);
  const list = useAsync(() => (s.data?.licensed ? get<any>(`/rmi/calls${qs({ ...f, page })}`) : Promise.resolve(null)), [JSON.stringify(f), page, s.data?.licensed]);
  const [open, setOpen] = useState<number | null>(null);
  return (
    <Page title="Analysed calls" subtitle="Every call the SmartRMI engine has analysed. Open one for the transcript and coaching points.">
      <ErrorBox error={s.error ?? list.error} />
      <RmiGate s={s}>
        <div className="row wrap">
          <Range f={f} setF={(x) => { setF(x); setPage(1); }} />
          <Field label="Show"><select value={f.angry} onChange={(e) => { setF({ ...f, angry: e.target.value }); setPage(1); }}><option value="">All calls</option><option value="1">Angry calls only</option></select></Field>
          <Field label="Compliance risk"><select value={f.risk} onChange={(e) => { setF({ ...f, risk: e.target.value }); setPage(1); }}><option value="">Any</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></Field>
          <Field label="Score up to"><input type="number" min={0} max={100} value={f.max_score} placeholder="e.g. 50" onChange={(e) => { setF({ ...f, max_score: e.target.value }); setPage(1); }} /></Field>
        </div>
        <Card>
          <Table rows={list.data?.data} empty="No analysed calls match." onRow={(r: any) => setOpen(r.call_id)} columns={[
            { key: 'analysed_at', label: 'Analysed', render: (r: any) => fmtDate(r.call?.initiated_at ?? r.analysed_at, me?.company.timezone) },
            { key: 'agent', label: 'Agent', render: (r: any) => r.user?.name ?? '—' },
            { key: 'cust', label: 'Customer', render: (r: any) => r.call?.customer?.name ?? r.call?.customer?.customer_ref ?? r.destination_masked },
            { key: 'score', label: 'Score', align: 'right', render: (r: any) => <Score v={r.score} /> },
            { key: 'tone', label: 'Tone', render: (r: any) => r.tone ? <Badge value={tone(r.tone)} label={r.tone} /> : '—' },
            { key: 'aggression', label: 'Aggression', render: (r: any) => r.aggression ? <Badge value={tone(r.aggression)} label={r.aggression} /> : '—' },
            { key: 'compliance_risk', label: 'Compliance risk', render: (r: any) => r.compliance_risk ? <Badge value={tone(r.compliance_risk)} label={r.compliance_risk} /> : '—' },
            { key: 'angry', label: '', render: (r: any) => r.angry ? <Badge value="failed" label="angry" /> : null },
          ]} />
          <Pager meta={list.data} onPage={setPage} />
        </Card>
        {open && <Modal title="SmartRMI analysis" onClose={() => setOpen(null)}><RmiDetail callId={open} /></Modal>}
      </RmiGate>
    </Page>
  );
}

function RmiDetail({ callId }: { callId: number }) {
  const a = useAsync(() => get<any>(`/rmi/calls/${callId}`), [callId]);
  return <>
    <ErrorBox error={a.error} />
    {a.data && <RmiCard rmi={a.data} />}
    <p><Link to={`/calls?call=${callId}`}>Open the call and its recording →</Link></p>
  </>;
}

/** The SmartRMI part of a call: shown on Analysed calls and on the call's own detail (Calls & recordings). */
export function RmiCard({ rmi }: { rmi: any }) {
  return (
    <Card title="SmartRMI" actions={rmi.angry ? <Badge value="failed" label="angry call" /> : <Badge value={rmi.status === 'done' || rmi.status === 'completed' ? 'completed' : 'pending'} label={rmi.status} />}>
      <p className="row wrap">
        <span>Score <Score v={rmi.score} /></span>
        {rmi.tone && <span>Tone <Badge value={tone(rmi.tone)} label={rmi.tone} /></span>}
        {rmi.sentiment && <span>Customer <Badge value={tone(rmi.sentiment)} label={rmi.sentiment} /></span>}
        {rmi.aggression && <span>Aggression <Badge value={tone(rmi.aggression)} label={rmi.aggression} /></span>}
        {rmi.compliance_risk && <span>Compliance risk <Badge value={tone(rmi.compliance_risk)} label={rmi.compliance_risk} /></span>}
      </p>
      {rmi.summary && <p>{rmi.summary}</p>}
      {rmi.coaching?.length > 0 && <><strong>Coaching</strong><ul>{rmi.coaching.map((c: string, i: number) => <li key={i}>{c}</li>)}</ul></>}
      {rmi.transcript && <details><summary>Transcript</summary><pre style={{ whiteSpace: 'pre-wrap', maxHeight: 320, overflow: 'auto' }}>{rmi.transcript}</pre></details>}
    </Card>
  );
}

export function RmiAlertsPage() {
  const { me } = useAuth();
  const s = useRmiSettings();
  const [status, setStatus] = useState('open');
  const [page, setPage] = useState(1);
  const list = useAsync(() => (s.data?.licensed ? get<any>(`/rmi/alerts${qs({ status, page })}`) : Promise.resolve(null)), [status, page, s.data?.licensed]);
  const a = useAction();
  return (
    <Page title="Angry-call alerts" subtitle="Sent by SmartRMI when a call turns angry or risky. Each alert is also e-mailed to the agent's team supervisors, with the recording.">
      <ErrorBox error={s.error ?? list.error ?? a.error} />
      <RmiGate s={s}>
        <div className="row">
          <Field label="Show"><select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}><option value="open">Not yet acknowledged</option><option value="all">All alerts</option></select></Field>
        </div>
        <Card>
          <Table rows={list.data?.data} empty={status === 'open' ? 'No open alerts.' : 'No alerts yet.'} columns={[
            { key: 'created_at', label: 'When', render: (r: any) => fmtDate(r.created_at, me?.company.timezone) },
            { key: 'kind', label: 'Alert', render: (r: any) => <Badge value="failed" label={r.kind === 'angry_call' ? 'angry call' : r.kind.replace(/_/g, ' ')} /> },
            { key: 'agent', label: 'Agent', render: (r: any) => r.user?.name ?? '—' },
            { key: 'cust', label: 'Customer', render: (r: any) => r.call?.customer?.name ?? r.call?.customer?.customer_ref ?? r.details?.b_party_no ?? '—' },
            { key: 'message', label: 'Detail', render: (r: any) => r.message ?? '' },
            { key: 'mail', label: 'E-mailed', align: 'right', render: (r: any) => r.emailed_to || '—' },
            { key: 'call', label: '', render: (r: any) => r.call_id ? <Link to={`/calls?call=${r.call_id}`}>Call →</Link> : null },
            { key: 'ack', label: '', align: 'right', render: (r: any) => r.acknowledged_at
              ? <span className="muted">Seen by {r.acknowledger?.name} · {fmtDate(r.acknowledged_at, me?.company.timezone)}</span>
              : <button className="secondary sm" disabled={a.busy} onClick={() => a.run(async () => { await post(`/rmi/alerts/${r.id}/ack`); await list.reload(); })}>Acknowledge</button> },
          ]} />
          <Pager meta={list.data} onPage={setPage} />
        </Card>
      </RmiGate>
    </Page>
  );
}

export function MonitoringSettingsPage() {
  const { can } = useAuth();
  const s = useRmiSettings();
  const a = useAction();
  const [emails, setEmails] = useState('');
  const [notify, setNotify] = useState(true);
  const [secret, setSecret] = useState<string | null>(null);
  useEffect(() => { if (s.data) { setEmails((s.data.alert_emails ?? []).join(', ')); setNotify(!!s.data.notify_supervisors); } }, [s.data]);
  const edit = can('telephony.configure');
  const d = s.data;
  return (
    <Page title="Call monitoring settings" subtitle="Listening in to live calls and the SmartRMI engine connection.">
      <ErrorBox error={s.error ?? a.error} />
      {d && <>
        <Card title="Listen in (live calls)">
          {d.monitor_configured
            ? <Notice tone="ok">Switched on. Supervisors with "Listen in to live Click2Call calls" can join from Call monitoring → Live calls; the gateway rings the phone number on their profile.</Notice>
            : <Notice tone="warn">Waiting for Ametecs: Telozar's conference API has to be added in Super Admin → Settings → Click2Call.</Notice>}
          <p className="muted small">Who may listen in: Branches, teams &amp; people → Roles → "Listen in to live Click2Call calls (call snooping)". Supervisors only see their own teams' calls.</p>
        </Card>
        <Card title="SmartRMI engine">
          {!d.licensed && <Notice tone="warn">SmartRMI is not in your plan.</Notice>}
          {d.licensed && <>
            <p>Status: <Badge value={d.connected ? 'completed' : 'pending'} label={d.connected ? 'connected' : 'not connected'} />{d.last_ok_at && <span className="muted"> · last contact {fmtDate(d.last_ok_at)}</span>}</p>
            {d.endpoints && <Table rows={Object.entries(d.endpoints).map(([k, v], i) => ({ id: i, k, v }))} columns={[
              { key: 'k', label: 'Engine uses', render: (r: any) => ({ calls: 'Calls to analyse (GET)', recording: 'Recording file (GET)', results: 'Send results (POST)', alerts: 'Angry-call alert (POST)' } as any)[r.k] },
              { key: 'v', label: 'Address', render: (r: any) => <span className="code">{r.v}</span> },
            ]} />}
            <p className="muted small">The engine sends the key in the header <span className="code">X-Api-Key</span>. Give these addresses and the key to the SmartRMI team.</p>
            {secret && <Notice tone="ok">Engine key (shown once — copy it now): <span className="code">{secret}</span></Notice>}
            {edit && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { const r = await post<any>('/rmi/secret'); setSecret(r.secret); s.setData(r); })}>{d.has_secret ? 'New engine key' : 'Connect the engine (create key)'}</button>}
          </>}
        </Card>
        {d.licensed && <Card title="Angry-call alerts by e-mail">
          <label className="row"><input type="checkbox" checked={notify} disabled={!edit} onChange={(e) => setNotify(e.target.checked)} /> E-mail the agent's team supervisors</label>
          <Field label="Also e-mail" hint="Comma-separated addresses, e.g. the quality manager."><input value={emails} disabled={!edit} onChange={(e) => setEmails(e.target.value)} placeholder="quality@company.com" /></Field>
          {edit && <button disabled={a.busy} onClick={() => a.run(async () => {
            const r = await put<any>('/rmi/settings', { notify_supervisors: notify, alert_emails: emails.split(/[,\s]+/).map((x) => x.trim()).filter(Boolean) });
            s.setData(r); toast('Saved');
          })}>Save</button>}
        </Card>}
      </>}
    </Page>
  );
}
