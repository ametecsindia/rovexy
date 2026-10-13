import { useEffect, useRef, useState } from 'react';
import { get, post, qs, fmtDate, apiBase, headers } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Notice, Page, PayBadge, Table, useAction, useAsync } from '../components/ui';

/**
 * Administration → Plan & licence.
 *  Cloud: plan, users, expiry → renew (plan / users / billing cycle) or add users now → the charges line by line (the server's
 *         own price) → pay → the licence changes only once the payment is confirmed by the server → receipts and history.
 *  On-premises install: licence key, this server's ID, last check with the licence server, Check now, offline licence file,
 *         updates & support (AMC), and the pay link of an order Ametecs has raised.
 */
const inr = (n: number | null | undefined) => (n == null ? '—' : '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const day = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : 'no expiry');
const STATES: Record<string, string> = { '36': 'Telangana', '37': 'Andhra Pradesh', '29': 'Karnataka', '33': 'Tamil Nadu', '27': 'Maharashtra', '07': 'Delhi', '24': 'Gujarat', '09': 'Uttar Pradesh', '19': 'West Bengal', '32': 'Kerala', '08': 'Rajasthan', '06': 'Haryana', '23': 'Madhya Pradesh', '21': 'Odisha', '10': 'Bihar', '03': 'Punjab', '30': 'Goa', '18': 'Assam', '20': 'Jharkhand', '22': 'Chhattisgarh', '05': 'Uttarakhand', '02': 'Himachal Pradesh', '01': 'Jammu & Kashmir', '04': 'Chandigarh', '34': 'Puducherry' };
const STATE_LABEL: Record<string, string> = { active: 'Active', grace: 'In grace period', expired: 'Expired', unrestricted: 'Not limited', suspended: 'Suspended', revoked: 'Not valid',
  unlicensed: 'No licence', unknown_key: 'Key not recognised', server_mismatch: 'Registered to another server' };
const KIND_LABEL: Record<string, string> = { trial: 'free trial', subscription: 'subscription', perpetual: 'perpetual licence', evaluation: 'evaluation', unverified: 'key not verified yet', internal: 'internal' };

type Line = { type: string; label: string; amount: number };
type Quote = { lines: Line[]; amount: number; discount: number; cgst: number; sgst: number; igst: number; total: number; pricing: 'standard' | 'custom'; users: number; billing: string; kind: string; valid_until: string | null };

export function LicencePage() {
  const l = useAsync(() => get('/licence'));
  const x = l.data;
  const { can } = useAuth();
  const a = useAction();
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {   // a payment is pending: refresh until the server confirms it (paused while the tab is hidden)
    if (!x?.open_order) return;
    const t = setInterval(() => { if (document.visibilityState === 'visible') void l.reload(); }, 30000);
    return () => clearInterval(t);
  }, [x?.open_order?.number]);
  const check = () => a.run(async () => { const r: any = await post('/licence/check'); setMsg(r.verdict === 'active' ? 'Licence checked: active.' : r.error ?? r.verdict); await l.reload(); });
  return (
    <Page title="Licence" subtitle={x?.mode === 'onprem' ? 'Key, plan, users and daily validation' : 'Plan, users, renewal and receipts'}
      actions={x?.mode === 'onprem' && x.licence_key && x.source !== 'file' && can('settings.storage')
        ? <button className="secondary" disabled={a.busy} onClick={() => void check()}>{a.busy ? 'Checking…' : '↻ Check now'}</button> : undefined}>
      <ErrorBox error={l.error ?? a.error} />
      {msg && <Notice tone={msg.startsWith('Licence checked') ? 'ok' : 'warn'}>{msg}</Notice>}
      {x?.message && <Notice tone={x.state === 'grace' ? 'warn' : 'bad'}>{x.message}</Notice>}
      {x?.unreachable_since && x.offline_until && x.state === 'active' && <Notice tone="warn">This server has not reached the ROVEXY licence server since {fmtDate(x.unreachable_since)}.
        It keeps working until {day(x.offline_until)}; after that it stops until it connects again or a licence file is installed.</Notice>}
      {x && <>
        <StatusCard x={x} />
        {x.mode === 'onprem' ? <OnPrem x={x} reload={l.reload} /> : <Cloud x={x} reload={l.reload} />}
      </>}
    </Page>
  );
}

const mask = (k?: string | null) => (k ? (k.length > 12 ? `${k.slice(0, 7)}••••-••••-${k.slice(-4)}` : k) : null);
const DEP: Record<string, string> = { cloud: 'cloud', onprem: 'on-premises' };

/** The licence at a glance (same for cloud and on-premises): label column + values. */
function StatusCard({ x }: { x: any }) {
  const ok = ['active', 'unrestricted'].includes(x.state);
  const kind = x.licence_kind ?? x.kind;
  const perpetual = kind === 'perpetual';
  const rows: [string, any][] = [
    ['Status', <Badge value={x.state === 'grace' ? 'pending' : ok ? 'active' : 'revoked'} label={(STATE_LABEL[x.state] ?? x.state.replace(/_/g, ' ')).toUpperCase()} />],
    ['Key', x.key || x.licence_key ? <code>{mask(x.key ?? x.licence_key)}</code> : <span className="muted">not entered</span>],
    ['Company', x.holder ?? '—'],
    ['Plan', [x.plan ?? '—', KIND_LABEL[kind] ?? kind, x.billing && typeof x.billing === 'string' ? x.billing.replace('_', '-') : x.licence_billing?.replace('_', '-'), DEP[x.deployment] ?? x.deployment].filter(Boolean).join(' · ')],
    ['Users in use', <><b>{x.used_users}</b> active user{x.used_users === 1 ? "" : "s"} — out of <b>{x.max_users || 'unlimited'}</b> licensed
      <div className="muted small">New users and phones are refused once all licensed seats are in use.</div></>],
    ['Expires', perpetual ? 'never — perpetual licence' : x.expires_at ? `${day(x.expires_at)}${x.grace_days ? ` (+${x.grace_days} grace days)` : ''}` : 'no expiry'],
  ];
  if (perpetual) rows.push(['Updates & support', x.amc_expires_at ? `until ${day(x.amc_expires_at)}${new Date(x.amc_expires_at) < new Date() ? ' — renew the AMC for updates; the licence keeps working' : ''}` : '—']);
  if (x.mode === 'onprem') {
    rows.push(['Last check', x.last_checked_at ? <>{fmtDate(x.last_checked_at)}{x.unreachable_since && <span className="bad"> · licence server unreachable since {fmtDate(x.unreachable_since)}</span>}</> : '—']);
    rows.push(['Source', x.source === 'file' ? 'Offline licence file (this server)' : x.source === 'online' ? `Licence server (${x.server_url ?? 'online'})` : x.kind === 'evaluation' ? 'Evaluation — no key yet' : 'Key entered, not verified yet']);
    rows.push(['Server ID', <code className="small">{x.server_id}</code>]);
    if (x.last_error) rows.push(['Last message', <span className="small">{x.last_error}</span>]);
  } else {
    rows.push(['Source', 'ROVEXY cloud — managed for you']);
    if (x.server_id) rows.push(['Fingerprint', <code className="small">{x.server_id}</code>]);
  }
  return (
    <Card title="Licence status" actions={<span className="muted small">{x.mode === 'onprem' ? 'This server checks its licence with ROVEXY once a day — licence details only, never your calls or customer data.' : 'Changes to your plan apply as soon as a payment is confirmed.'}</span>}>
      <dl className="lic-table">{rows.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
    </Card>
  );
}

function Features({ x }: { x: any }) {
  return (
    <Card title="What your plan includes">
      <ul className="plain feat-grid">{Object.entries(x.feature_labels as Record<string, string>).map(([k, label]) => <li key={k} className={x.features.includes(k) ? '' : 'muted'}>{x.features.includes(k) ? '✓' : '–'} {label}</li>)}</ul>
    </Card>
  );
}

function Cloud({ x, reload }: { x: any; reload: () => Promise<void> }) {
  const { can } = useAuth();
  const mayKey = can('settings.storage') && x.licence_kind !== 'internal';
  return <>
    {mayKey && <AddKey reload={reload} />}
    {mayKey && <OfflineFile x={x} reload={reload} />}
    <Purchase x={x} reload={reload} />
    <Card title="Payments & receipts">
      <Table rows={x.paid_orders} empty="No payments yet." columns={[
        { key: 'paid_at', label: 'Paid', render: (o: any) => fmtDate(o.paid_at) },
        { key: 'plan', label: 'Plan', render: (o: any) => <>{o.plan ?? '—'}{o.users ? ` · ${o.users} users` : ''}<div className="muted small">{({ new: 'first purchase', upgrade: 'first package', renewal: 'renewal', seats: 'more users', amc: 'AMC' } as any)[o.kind] ?? o.kind}</div></> },
        { key: 'payment_method', label: 'Method', render: (o: any) => <>{o.payment_method}{o.payment_ref && <div className="muted small">{o.payment_ref}</div>}</> },
        { key: 'total', label: 'Amount', align: 'right', render: (o: any) => inr(o.total) },
        { key: 'invoice_number', label: 'Receipt', render: (o: any) => <a href={o.invoice_url} target="_blank" rel="noreferrer">{o.invoice_number}</a> }]} />
    </Card>
    <Card title="Licence history">
      <Table rows={x.history} empty="No changes yet." columns={[
        { key: 'at', label: 'When', render: (h: any) => fmtDate(h.at) }, { key: 'what', label: 'What' }, { key: 'detail', label: 'Details', render: (h: any) => h.detail || '—' }]} />
    </Card>
  </>;
}

type Kind = 'renewal' | 'seats' | 'amc';

/**
 * Self-serve purchase (cloud and on-premises): renew, add users now, or renew AMC — priced by the server from the account's
 * own pricing (custom prices included), paid online; the licence changes only when the server confirms the payment.
 */
function Purchase({ x, reload }: { x: any; reload: () => Promise<void> }) {
  const { can } = useAuth();
  const manage = can('settings.storage');
  const trial = x.kind === 'trial';
  const perpetual = x.licence_kind === 'perpetual';
  const kinds: Kind[] = [...(x.can_renew && !perpetual ? ['renewal' as Kind] : []), ...(x.can_add_users ? ['seats' as Kind] : []), ...(x.can_amc ? ['amc' as Kind] : [])];
  const mayBuy = manage && (trial ? !!x.can_renew : kinds.length > 0);
  const [kind, setKind] = useState<Kind>(kinds[0] ?? 'renewal');
  const [plan, setPlan] = useState(String(x.plan_id ?? ''));
  const [users, setUsers] = useState(String(Math.max(x.max_users || 0, 1)));
  const [billing, setBilling] = useState(x.licence_billing ?? 'annual');
  const [state, setState] = useState(x.billing?.state_code || '36');
  const [gstin, setGstin] = useState(x.billing?.gstin || '');
  const [coupon, setCoupon] = useState('');
  const [quote, setQuote] = useState<Quote | null>(null);
  const [quoteErr, setQuoteErr] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const a = useAction();
  const chosen = x.plans?.find((p: any) => String(p.id) === plan);
  const amc = kind === 'amc';
  const code = chosen?.negotiated || kind !== 'renewal' ? '' : coupon;
  const params = { kind: trial ? undefined : kind, plan_id: amc ? undefined : plan, users: amc ? undefined : users, billing: kind === 'renewal' ? billing : undefined, state_code: state, coupon: code || undefined };
  const ready = amc || (!!plan && !!users);
  useEffect(() => {
    setQuote(null); setQuoteErr(null);
    if (!ready || !mayBuy) return;
    const t = setTimeout(() => { get<Quote>(`/licence/quote${qs(params)}`).then(setQuote, (e) => setQuoteErr(e.message)); }, 300);
    return () => clearTimeout(t);
  }, [plan, users, billing, state, code, kind, mayBuy]);
  const pay = () => a.run(async () => {
    const o = await post<{ pay_url: string }>('/licence/renew', { ...params, users: amc ? undefined : Number(users), plan_id: amc ? undefined : Number(plan), buyer_gstin: gstin || null, coupon: code || null });
    location.href = o.pay_url;
  });
  const cancel = (n: string) => a.run(async () => { await post(`/licence/orders/${n}/cancel`); setDone(`Order ${n} cancelled. Nothing was charged.`); await reload(); });
  const open = x.open_order;
  const intro: Record<Kind, string> = {
    renewal: 'Renewing early loses no days: the new period starts when the current one ends. A bigger plan or more users apply as soon as it is paid.',
    seats: perpetual ? 'Pay the difference to the price for the new number of users. The new limit applies as soon as it is paid.'
      : `Pay only for the rest of your current term (until ${day(x.expires_at)}). The new limit applies as soon as it is paid.`,
    amc: `12 more months of updates & support from ${x.amc_expires_at && new Date(x.amc_expires_at) > new Date() ? day(x.amc_expires_at) : 'today'}.`,
  };
  const label: Record<Kind, string> = { renewal: 'Renew', seats: 'Add users now', amc: 'Renew AMC' };
  return <>
    <ErrorBox error={a.error} />
    {done && <Notice tone="ok">{done}</Notice>}
    {x.shop_error && <Notice tone="warn">Online purchase is not available right now: {x.shop_error}</Notice>}
    {open && <Card title="Payment in progress">
      <div className="row" style={{ justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
        <div>
          <strong>{open.plan}</strong>{open.users ? ` · ${open.users} users` : ''} · {inr(open.total)} · order {open.number} {open.pay_state && <PayBadge order={open} />}
          {open.pay_state === 'failed' ? <p className="small" style={{ margin: '6px 0 0' }}>The last attempt failed{open.failure_reason ? `: ${open.failure_reason}` : ''}. No money was taken for it. You can try again.</p>
            : open.pay_state === 'expired' ? <p className="small" style={{ margin: '6px 0 0' }}>This order was valid until {day(open.valid_until)}. Choose again below for a fresh one.</p>
            : <p className="muted small" style={{ margin: '6px 0 0' }}>Your licence changes by itself as soon as the payment is confirmed{x.mode === 'onprem' ? ' (this page checks every 30 seconds)' : ''}.{open.valid_until ? ` Valid until ${day(open.valid_until)}.` : ''}</p>}
        </div>
        {manage && <div className="row">
          {open.pay_state !== 'expired' && <a className="button" href={open.pay_url} target={x.mode === 'onprem' ? '_blank' : undefined} rel="noreferrer">{open.pay_state === 'failed' ? 'Try again' : 'Pay now'}</a>}
          <button className="secondary" disabled={a.busy} onClick={() => void cancel(open.number)}>Cancel order</button>
        </div>}
      </div>
    </Card>}
    <div className="grid-2">
      <Features x={x} />
      {mayBuy && <Card title={trial ? 'Buy a plan' : perpetual ? 'Add users or renew AMC' : 'Renew or add users'}>
        {!trial && kinds.length > 1 && <div className="seg-tabs" role="tablist">
          {kinds.map((k) => <button key={k} type="button" role="tab" aria-selected={kind === k} className={kind === k ? 'on' : ''}
            onClick={() => { setKind(k); if (k === 'seats') setUsers(String(x.max_users + 5)); }}>{label[k]}</button>)}
        </div>}
        <p className="muted small">{intro[kind]}</p>
        {!amc && <div className="plan-pick" role="radiogroup" aria-label="Plan">
          {(x.plans ?? []).map((p: any) => (
            <label key={p.id} className={`plan-opt${plan === String(p.id) ? ' on' : ''}`}>
              <input type="radio" name="plan" value={p.id} checked={plan === String(p.id)} onChange={() => setPlan(String(p.id))} />
              <span><strong>{p.name}</strong>{p.id === x.plan_id && <em> current</em>}{p.negotiated && <em> · your negotiated price</em>}
                <small>{p.from_rate ? `from ${inr(p.from_rate)} per user a month` : perpetual ? 'one-time price by users' : 'priced on quotation'} · {p.min_users}+ users</small></span>
            </label>))}
        </div>}
        <div className="form-grid">
          {!amc && <Field label={kind === 'seats' ? `Users after the change (now ${x.max_users})` : 'Users'}><input type="number" min={1} value={users} onChange={(e) => setUsers(e.target.value)} /></Field>}
          {kind === 'renewal' && <Field label="Billing"><select value={billing} onChange={(e) => setBilling(e.target.value)}>{Object.entries((x.cycles ?? {}) as Record<string, string>).map(([k, v]) => <option key={k} value={k}>{v}{k === 'annual' ? ' (best price)' : ''}</option>)}</select></Field>}
          <Field label="State (for GST)"><select value={state} onChange={(e) => setState(e.target.value)}>{Object.entries(STATES).map(([c, n]) => <option key={c} value={c}>{n}</option>)}</select></Field>
          <Field label="GSTIN (optional)"><input value={gstin} onChange={(e) => setGstin(e.target.value.toUpperCase())} maxLength={15} /></Field>
          {kind === 'renewal' && <Field label="Discount code (optional)" hint={chosen?.negotiated ? 'Not needed: your negotiated price already applies' : undefined}><input disabled={chosen?.negotiated} value={coupon} onChange={(e) => setCoupon(e.target.value.toUpperCase())} maxLength={30} /></Field>}
        </div>
        {quoteErr && <Notice tone="bad">{quoteErr}</Notice>}
        {quote && <table className="quote">
          <tbody>
            {quote.lines.map((ln, i) => <tr key={i}><td>{ln.label}</td><td>{ln.amount < 0 ? '−' : ''}{inr(Math.abs(ln.amount))}</td></tr>)}
            {quote.cgst > 0 && <><tr><td>CGST</td><td>{inr(quote.cgst)}</td></tr><tr><td>SGST</td><td>{inr(quote.sgst)}</td></tr></>}
            {quote.igst > 0 && <tr><td>IGST</td><td>{inr(quote.igst)}</td></tr>}
            <tr className="t"><td>To pay</td><td>{inr(quote.total)}</td></tr>
          </tbody>
          <caption>{amc ? 'After payment: updates & support for 12 more months.' : `After payment: up to ${quote.users} users${quote.valid_until ? `, valid until ${day(quote.valid_until)}` : ''}.`}</caption>
        </table>}
        {open && <p className="muted small">This replaces the unpaid order {open.number}.</p>}
        <button disabled={a.busy || !quote} onClick={() => void pay()}>{a.busy ? 'Preparing…' : quote ? `Pay ${inr(quote.total)}` : amc ? 'Preparing the price…' : 'Choose a plan and users'}</button>
      </Card>}
    </div>
  </>;
}

/** Cloud: add a licence key sold by Ametecs — it becomes this workspace's licence. */
function AddKey({ reload }: { reload: () => Promise<void> }) {
  const [key, setKey] = useState('');
  const [done, setDone] = useState(false);
  const a = useAction();
  const add = () => a.run(async () => { await post('/licence/key', { key }); setKey(''); setDone(true); await reload(); });
  return (
    <Card title="Licence key">
      <ErrorBox error={a.error} />
      {done && <Notice tone="ok">Licence key added. Your plan, users and expiry above are updated.</Notice>}
      <div className="key-row">
        <input value={key} onChange={(e) => { setKey(e.target.value.toUpperCase()); setDone(false); }} placeholder="RX-XXXX-XXXX-XXXX-XXXX" aria-label="Licence key" />
        <button disabled={a.busy || key.length < 10} onClick={() => void add()}>{a.busy ? 'Validating…' : 'Save & validate'}</button>
      </div>
      <p className="muted small" style={{ marginBottom: 0 }}>Paste the key from your ROVEXY order e-mail (Billing & Licences). It becomes this workspace's licence straight away — plan, users and expiry update above.</p>
    </Card>
  );
}

/** SmartEPT-style offline activation: copy the fingerprint → Ametecs returns a signed .lic locked to it → import. */
function OfflineFile({ x, reload }: { x: any; reload: () => Promise<void> }) {
  const cloud = x.mode !== 'onprem';
  const what = cloud ? 'workspace' : 'server';
  const a = useAction();
  const file = useRef<HTMLInputElement>(null);
  const [picked, setPicked] = useState<File | null>(null);
  const [copied, setCopied] = useState(false);
  const [ok, setOk] = useState(false);
  const copy = () => { void navigator.clipboard?.writeText(x.server_id).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }); };
  const upload = (f: File) => a.run(async () => {
    const body = new FormData(); body.append('file', f);
    const res = await fetch(`${apiBase()}/licence/file`, { method: 'POST', credentials: 'same-origin', headers: headers(), body });
    const j = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(j.message ?? 'The licence file could not be installed.');
    setOk(true); setPicked(null); if (file.current) file.current.value = ''; await reload();
  });
  return (
    <Card title="Offline licence file" actions={<span className="muted small">{cloud ? 'a signed file locked to this workspace' : 'no internet needed — a signed file locked to this server'}</span>}>
      <ErrorBox error={a.error} />
      {ok && <Notice tone="ok">Licence file installed.</Notice>}
      <p className="muted small" style={{ marginTop: 0 }}>Send this {what}'s fingerprint to Ametecs. They return a <code>.lic</code> file locked to this {what} — import it below to activate.{cloud ? '' : ' Works fully offline.'}</p>
      <Field label={`This ${what}'s fingerprint`}>
        <div className="fp-box"><code>{x.server_id}</code></div>
      </Field>
      <button type="button" className="secondary" onClick={copy}>{copied ? 'Copied ✓' : 'Copy'}</button>
      <div style={{ marginTop: 16 }}>
        <Field label="Import licence file (.lic)">
          <input ref={file} type="file" accept=".lic,text/plain" className="file-box" onChange={(e) => { setPicked(e.target.files?.[0] ?? null); setOk(false); }} />
        </Field>
        <button disabled={a.busy || !picked} onClick={() => { if (picked) void upload(picked); }}>{a.busy ? 'Importing…' : 'Import & activate'}</button>
      </div>
    </Card>
  );
}

function OnPrem({ x, reload }: { x: any; reload: () => Promise<void> }) {
  const { can } = useAuth();
  const manage = can('settings.storage');
  const [key, setKey] = useState('');
  const [msg, setMsg] = useState<{ tone: 'ok' | 'bad' | 'warn'; text: string } | null>(null);
  const a = useAction();
  const verdict = (v: string, err?: string | null) => setMsg(v === 'active' ? { tone: 'ok', text: 'Licence verified.' }
    : v === 'none' || !v ? { tone: 'warn', text: err ? `Could not reach the licence server: ${err}. The key works for 7 days while we keep trying.` : 'Saved.' }
    : { tone: 'bad', text: err || v });
  const saveKey = () => a.run(async () => { const r: any = await post('/licence/key', { key }); verdict(r.verdict, r.error); setKey(''); await reload(); });
  const check = () => a.run(async () => { const r: any = await post('/licence/check'); verdict(r.verdict, r.unreachable_since ? r.error : null); await reload(); });
  return <>
    <ErrorBox error={a.error} />
    {msg && <Notice tone={msg.tone}>{msg.text}</Notice>}
    <Card title="Licence key">
      {manage ? <>
        <div className="key-row">
          <input value={key} onChange={(e) => setKey(e.target.value.toUpperCase())} placeholder="RX-XXXX-XXXX-XXXX-XXXX" aria-label="Licence key" />
          <button disabled={a.busy || key.length < 10} onClick={() => void saveKey()}>Save &amp; validate</button>
          {x.licence_key && x.source !== 'file' && <button className="secondary" disabled={a.busy} onClick={() => void check()}>Validate now</button>}
        </div>
        <p className="muted small" style={{ marginBottom: 0 }}>Paste the key from your ROVEXY order e-mail. The first server that validates it becomes the licensed one.
          Without a key this server runs a 14-day free evaluation, then stops until a key is entered.</p>
      </> : <p className="muted">Only an administrator can change the licence key.</p>}
    </Card>
    {manage && <OfflineFile x={x} reload={reload} />}
    <Purchase x={x} reload={reload} />
  </>;
}
