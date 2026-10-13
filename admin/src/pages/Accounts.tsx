import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { del, download, get, openPrint, post, put, qs, setOnUnauthorized } from '../lib/api';
import { Badge, Card, ErrorBox, Field, Icon, Kpi, Modal, Notice, Page, Pager, Table, Toaster, toast, useAction, useAsync } from '../components/ui';
import { MyProfile, StaffSignIn, type MfaPending, type StaffMe } from './StaffAuth';

/*
 * Accountant panel (/accounts): Ametecs' own books. Accountants (and super admins) only; the server enforces it on every
 * call (`platform:accounts`). Money is in ₹; business dates are Indian.
 */

const inr = (n: number | string | null | undefined) => (n == null || n === '' ? '—' : '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const inr0 = (n: number | null | undefined) => (n == null ? '—' : '₹' + Math.round(Number(n)).toLocaleString('en-IN'));
const day = (d?: string | null) => (d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const METHOD: Record<string, string> = { bank: 'Bank transfer', upi: 'UPI', cash: 'Cash', cheque: 'Cheque', card: 'Card', razorpay: 'Razorpay', other: 'Other' };
const SOURCE: Record<string, string> = { manual: 'Entry', order: 'Subscription', invoice: 'Invoice', bill: 'Bill' };
const ACC_TYPE: Record<string, string> = { bank: 'Bank account', cash: 'Cash', upi: 'UPI', card: 'Card', gateway: 'Payment gateway', other: 'Other' };
const STATUS_TONE: Record<string, string> = { draft: 'draft', issued: 'pending', partial: 'pending', paid: 'completed', overdue: 'failed', cancelled: 'cancelled', completed: 'completed', pending: 'pending', 'unpaid order': 'pending' };

type Lookups = { categories: { id: number; kind: 'income' | 'expense'; name: string }[]; accounts: { id: number; name: string; type: string; is_default: boolean }[]; methods: string[]; account_types: string[]; gst_rate: number; today: string; fy_start: string };

const MENU = [
  { to: '/', label: 'Dashboard', icon: 'home' },
  { to: '/transactions', label: 'Income & expenses', icon: 'chart' },
  { to: '/invoices', label: 'Invoices & bills', icon: 'event' },
  { to: '/payments', label: 'Payments', icon: 'clock' },
  { to: '/reports', label: 'Reports', icon: 'download' },
  { to: '/subscriptions', label: 'Subscription invoices', icon: 'shield' },
  { to: '/accounts', label: 'Bank & cash', icon: 'contacts' },
  { to: '/categories', label: 'Categories', icon: 'menu' },
  { to: '/profile', label: 'Profile & settings', icon: 'settings' },
];

export function AccountsApp() {
  const [me, setMe] = useState<StaffMe | MfaPending | null | undefined>(undefined);
  useEffect(() => { get<StaffMe | MfaPending>('/auth/me').then(setMe, () => setMe(null)); }, []);
  useEffect(() => { setOnUnauthorized(() => setMe(null)); return () => setOnUnauthorized(null); }, []);
  if (me === undefined) return <p className="muted" style={{ padding: 24 }}>Loading…</p>;
  if (!me || 'mfa_required' in me) {
    return <StaffSignIn portal="accounts" pending={me && 'mfa_required' in me ? me : null} onIn={setMe}
      shell={{ eyebrow: 'Accounts · Ametecs finance', title: 'Every rupee,', accent: 'on the books.', points: ['Income, expenses and bank balances', 'Invoices, bills and what is overdue', 'Reports to Excel and PDF'],
        heading: 'Accountant sign-in', sub: 'For Ametecs accounts staff. Every sign-in and change is recorded.', button: 'Sign in' }} />;
  }
  if (me.access.accounts === 'none') {
    return <div className="lb-denied"><Notice tone="warn">Your account cannot open Accounts. <a href="/super">Go to Super Admin</a> or{' '}
      <a href="#" onClick={(e) => { e.preventDefault(); void post('/auth/logout').catch(() => undefined).finally(() => setMe(null)); }}>sign in as an accountant</a>.</Notice></div>;
  }
  const logout = async () => { await post('/auth/logout').catch(() => undefined); setMe(null); };
  return (
    <div className="shell acc">
      <aside className="sidebar">
        <div className="brand"><img src="/brand/rovexy-mark-dark.svg" alt="ROVEXY" width="36" height="40" style={{ flex: 'none', background: 'none', padding: 0 }} /><span className="lbl">ROVEXY · Accounts</span></div>
        <nav className="nav" aria-label="Accounts">
          {MENU.map((i) => <NavLink key={i.to} to={i.to} end={i.to === '/'} className="top"><Icon name={i.icon} size={19} /><span className="lbl">{i.label}</span></NavLink>)}
          {me.role === 'super' && <a className="top" href="/super"><Icon name="chevron_left" size={19} /><span className="lbl">Super Admin</span></a>}
        </nav>
        <div className="nav-user">
          <span className="avatar">{me.name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase()}</span>
          <NavLink to="/profile" className="lbl who"><strong>{me.name}</strong><small>{me.role_label}</small></NavLink>
          <button className="icon-btn nav-out" onClick={logout} title="Sign out" aria-label="Sign out"><Icon name="logout" size={18} /></button>
        </div>
      </aside>
      <main className="main">
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/transactions" element={<Transactions />} />
          <Route path="/invoices" element={<Invoices />} />
          <Route path="/payments" element={<Payments />} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/subscriptions" element={<Subscriptions />} />
          <Route path="/accounts" element={<MoneyAccounts />} />
          <Route path="/categories" element={<Categories />} />
          <Route path="/profile" element={<MyProfile me={me} onChange={setMe} onSignOut={logout} />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
      <Toaster />
    </div>
  );
}

function useLookups() {
  const l = useAsync(() => get<Lookups>('/accounts/lookups'), []);
  return l.data;
}

/** Export buttons: Excel and CSV download; PDF opens a print-ready page (Save as PDF). */
function ExportButtons({ path, params }: { path: string; params: Record<string, string | number | undefined | null> }) {
  const a = useAction();
  const dl = (format: string) => void a.run(async () => { await download(`${path}${qs({ ...params, format })}`); toast('Export downloaded'); });
  return (
    <span className="row export-row">
      {a.error && <span className="bad small">{a.error}</span>}
      <button className="secondary sm" disabled={a.busy} onClick={() => dl('xlsx')} title="Excel workbook (.xlsx)"><Icon name="download" size={16} /> Excel</button>
      <button className="secondary sm" disabled={a.busy} onClick={() => dl('csv')}>CSV</button>
      <button className="secondary sm" onClick={() => openPrint(`${path}${qs({ ...params, format: 'pdf' })}`)} title="Opens a print-ready page: choose Save as PDF">PDF</button>
    </span>
  );
}

function DateRange({ from, to, onChange }: { from: string; to: string; onChange: (from: string, to: string) => void }) {
  return (
    <>
      <label className="inline-field">From <input type="date" value={from} max={to || undefined} onChange={(e) => onChange(e.target.value, to)} /></label>
      <label className="inline-field">To <input type="date" value={to} min={from || undefined} onChange={(e) => onChange(from, e.target.value)} /></label>
    </>
  );
}

const todayIso = () => new Date(Date.now() + 5.5 * 3600000).toISOString().slice(0, 10);
const monthStart = () => todayIso().slice(0, 8) + '01';
const fyStartIso = () => { const t = todayIso(); const y = Number(t.slice(0, 4)); const m = Number(t.slice(5, 7)); return `${m >= 4 ? y : y - 1}-04-01`; };

// ── Dashboard ──
function Dashboard() {
  const [range, setRange] = useState({ from: monthStart(), to: todayIso() });
  const d = useAsync(() => get(`/accounts/dashboard${qs(range)}`), [range.from, range.to]);
  const x = d.data;
  const max = x ? Math.max(1, ...x.trend.map((t: any) => Math.max(t.income, t.expense))) : 1;
  const catMax = x ? Math.max(1, ...x.expense_by_category.map((c: any) => c.total)) : 1;
  return (
    <Page title="Accounts dashboard" subtitle="Ametecs' own books: income, expenses, money owed and money due" actions={<DateRange from={range.from} to={range.to} onChange={(from, to) => setRange({ from, to })} />}>
      <ErrorBox error={d.error} />
      {!x && !d.error && <p className="muted">Loading…</p>}
      {x && <>
        <div className="kpi-grid six">
          <Kpi icon="chart" tone="green" label="Income" value={inr0(x.income)} sub={`${inr0(x.income_fy)} this financial year`} />
          <Kpi icon="chart" tone="purple" label="Expenses" value={inr0(x.expense)} sub={`${inr0(x.expense_fy)} this financial year`} />
          <Kpi icon="check" tone={x.net >= 0 ? 'teal' : 'purple'} label="Net (income − expenses)" value={inr0(x.net)} sub={`${day(x.from)} – ${day(x.to)}`} subTone={x.net >= 0 ? 'up' : 'down'} />
          <Kpi icon="contacts" tone="blue" label="Bank & cash balance" value={inr0(x.cash_balance)} sub={(() => { const n = x.accounts.filter((a: any) => a.status === 'active').length; return `${n} account${n === 1 ? '' : 's'}`; })()} />
          <Kpi icon="clock" tone="gold" label="To receive" value={inr0(x.receivable + x.unpaid_orders)} sub={x.overdue_receivable_count ? `${inr0(x.overdue_receivable)} overdue (${x.overdue_receivable_count})` : 'Nothing overdue'} subTone={x.overdue_receivable_count ? 'down' : 'flat'} />
          <Kpi icon="warning" tone="purple" label="To pay" value={inr0(x.payable)} sub={x.overdue_payable_count ? `${inr0(x.overdue_payable)} overdue (${x.overdue_payable_count})` : 'Nothing overdue'} subTone={x.overdue_payable_count ? 'down' : 'flat'} />
        </div>
        <div className="grid-2">
          <Card title="Income and expenses, last 12 months">
            <div className="acc-bars" role="img" aria-label="Monthly income and expenses">
              {x.trend.map((t: any) => (
                <div key={t.month} className="acc-bar" title={`${t.label}: income ${inr0(t.income)}, expenses ${inr0(t.expense)}`}>
                  <div className="acc-bar-pair"><i className="in" style={{ height: `${(t.income / max) * 100}%` }} /><i className="out" style={{ height: `${(t.expense / max) * 100}%` }} /></div>
                  <small>{t.label.split(' ')[0]}</small>
                </div>
              ))}
            </div>
            <p className="acc-legend"><span className="in" /> Income <span className="out" /> Expenses</p>
          </Card>
          <Card title="Where the money went" actions={<span className="muted small">{day(x.from)} – {day(x.to)}</span>}>
            {x.expense_by_category.length === 0 ? <p className="muted">No expenses in this period.</p> : (
              <div className="acc-hbars">{x.expense_by_category.map((c: any) => (
                <div key={c.name} className="acc-hbar"><span>{c.name}</span><div><i style={{ width: `${(c.total / catMax) * 100}%` }} /></div><b>{inr0(c.total)}</b></div>
              ))}</div>
            )}
          </Card>
          <Card title="Due in the next 14 days" actions={<NavLink to="/payments">All payments →</NavLink>}>
            <Table rows={x.due_soon} empty="Nothing due in the next two weeks." columns={[
              { key: 'number', label: 'Number' }, { key: 'type', label: 'Type', render: (i: any) => (i.type === 'bill' ? 'Bill (we pay)' : 'Invoice (we receive)') },
              { key: 'party_name', label: 'Party' }, { key: 'due_date', label: 'Due', render: (i: any) => day(i.due_date) },
              { key: 'balance', label: 'Balance', align: 'right', render: (i: any) => inr(i.balance) },
            ]} />
          </Card>
          <Card title="Bank & cash" actions={<NavLink to="/accounts">Manage →</NavLink>}>
            <Table rows={x.accounts} empty="No bank or cash accounts yet. Add one under Bank & cash." columns={[
              { key: 'name', label: 'Account', render: (a: any) => <>{a.name}{a.is_default && <Badge value="active" label="default" />}</> },
              { key: 'type', label: 'Type', render: (a: any) => ACC_TYPE[a.type] ?? a.type },
              { key: 'balance', label: 'Balance', align: 'right', render: (a: any) => <span className={a.balance < 0 ? 'bad' : ''}>{inr(a.balance)}</span> },
            ]} />
          </Card>
        </div>
        <Card title="Latest entries" actions={<NavLink to="/transactions">All income & expenses →</NavLink>}>
          <TxnTable rows={x.recent} />
        </Card>
        {x.pending_entries > 0 && <Notice tone="warn">{x.pending_entries} entr{x.pending_entries === 1 ? 'y is' : 'ies are'} marked pending and not counted yet. See Payments → Pending.</Notice>}
      </>}
    </Page>
  );
}

function TxnTable({ rows, onRow }: { rows: any[] | null | undefined; onRow?: (t: any) => void }) {
  return (
    <Table rows={rows} onRow={onRow} empty="No entries match." columns={[
      { key: 'txn_date', label: 'Date', render: (t: any) => day(t.txn_date) },
      { key: 'kind', label: 'Type', render: (t: any) => <Badge value={t.kind === 'income' ? 'completed' : 'failed'} label={t.kind === 'income' ? 'Income' : 'Expense'} /> },
      { key: 'category', label: 'Category', render: (t: any) => t.category?.name ?? <span className="muted">—</span> },
      { key: 'party', label: 'Party / note', render: (t: any) => <><div>{t.party ?? '—'}</div>{t.description && <small className="muted">{t.description}</small>}</> },
      { key: 'source', label: 'From', render: (t: any) => SOURCE[t.source] ?? t.source },
      { key: 'account', label: 'Account', render: (t: any) => t.account?.name ?? <span className="muted">—</span> },
      { key: 'status', label: 'Status', render: (t: any) => <Badge value={t.status} /> },
      { key: 'amount', label: 'Amount', align: 'right', render: (t: any) => <b className={t.kind === 'income' ? 'ok-text' : 'bad'}>{t.kind === 'income' ? '+' : '−'}{inr(t.amount)}</b> },
    ]} />
  );
}

// ── Income & expenses ──
function Transactions() {
  const look = useLookups();
  const [f, setF] = useState({ from: fyStartIso(), to: todayIso(), kind: '', category_id: '', account_id: '', status: '', source: '', method: '', q: '' });
  const [page, setPage] = useState(1);
  const [edit, setEdit] = useState<any | null>(null);
  const set = (k: string, v: string) => { setF({ ...f, [k]: v }); setPage(1); };
  const list = useAsync(() => get(`/accounts/transactions${qs({ ...f, page })}`), [JSON.stringify(f), page]);
  const cats = look?.categories.filter((c) => !f.kind || c.kind === f.kind) ?? [];
  return (
    <Page title="Income & expenses" subtitle="Every entry in the books: your own entries, invoice and bill payments, and paid subscription orders"
      actions={<><button onClick={() => setEdit({ kind: 'income' })}>+ Income</button><button className="secondary" onClick={() => setEdit({ kind: 'expense' })}>+ Expense</button></>}>
      <Card>
        <div className="filters">
          <DateRange from={f.from} to={f.to} onChange={(from, to) => { setF({ ...f, from, to }); setPage(1); }} />
          <select value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value, category_id: '' })} aria-label="Type"><option value="">Income and expenses</option><option value="income">Income</option><option value="expense">Expenses</option></select>
          <select value={f.category_id} onChange={(e) => set('category_id', e.target.value)} aria-label="Category"><option value="">All categories</option>{cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
          <select value={f.account_id} onChange={(e) => set('account_id', e.target.value)} aria-label="Account"><option value="">All accounts</option>{look?.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select>
          <select value={f.status} onChange={(e) => set('status', e.target.value)} aria-label="Status"><option value="">Any status</option><option value="completed">Completed</option><option value="pending">Pending</option></select>
          <select value={f.source} onChange={(e) => set('source', e.target.value)} aria-label="From"><option value="">Any source</option>{Object.entries(SOURCE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
          <input type="search" placeholder="Search party, reference, note" value={f.q} onChange={(e) => set('q', e.target.value)} />
        </div>
        {list.data && <div className="acc-totals">
          <span>Income <b className="ok-text">{inr(list.data.totals.income)}</b></span><span>Expenses <b className="bad">{inr(list.data.totals.expense)}</b></span>
          <span>Net <b>{inr(list.data.totals.net)}</b></span><span className="muted">Completed entries only</span>
          <span className="spacer" /><ExportButtons path="/accounts/transactions/export" params={f} />
        </div>}
        <ErrorBox error={list.error} />
        <TxnTable rows={list.data?.data} onRow={setEdit} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
      {edit && look && <TxnModal txn={edit} look={look} onClose={() => setEdit(null)} onDone={(msg) => { setEdit(null); toast(msg); void list.reload(); }} />}
    </Page>
  );
}

function TxnModal({ txn, look, onClose, onDone }: { txn: any; look: Lookups; onClose: () => void; onDone: (msg: string) => void }) {
  const isNew = !txn.id;
  const linked = txn.source && txn.source !== 'manual';
  const [d, setD] = useState({
    kind: txn.kind ?? 'expense', txn_date: txn.txn_date ?? look.today, amount: txn.amount ?? '', tax_amount: txn.tax_amount || '', category_id: txn.category_id ?? '',
    account_id: txn.account_id ?? (look.accounts.find((a) => a.is_default)?.id ?? ''), party: txn.party ?? '', method: txn.method ?? '', reference: txn.reference ?? '',
    description: txn.description ?? '', status: txn.status ?? 'completed',
  });
  const [confirm, setConfirm] = useState(false);
  const a = useAction();
  const s = (k: string, v: any) => setD({ ...d, [k]: v });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body: any = txn.source === 'order' ? { account_id: d.account_id || null, description: d.description || null }
      : linked ? { txn_date: d.txn_date, account_id: d.account_id || null, method: d.method || null, reference: d.reference || null, description: d.description || null }
      : { ...d, amount: Number(d.amount), tax_amount: d.tax_amount === '' ? 0 : Number(d.tax_amount), category_id: d.category_id || null, account_id: d.account_id || null, method: d.method || null };
    void a.run(async () => { isNew ? await post('/accounts/transactions', body) : await put(`/accounts/transactions/${txn.id}`, body); onDone(isNew ? 'Entry added' : 'Entry saved'); });
  };
  const remove = () => void a.run(async () => { await del(`/accounts/transactions/${txn.id}`); onDone('Entry deleted'); });
  return (
    <Modal title={isNew ? (d.kind === 'income' ? 'Add income' : 'Add expense') : `${d.kind === 'income' ? 'Income' : 'Expense'} entry`} onClose={onClose}>
      <ErrorBox error={a.error} />
      {txn.source === 'order' && <Notice tone="info">A paid subscription order ({txn.reference}). Its amount comes from the tax invoice; you can change the bank account and the note.</Notice>}
      {(txn.source === 'invoice' || txn.source === 'bill') && <Notice tone="info">A payment against {txn.description}. To change the amount, delete this payment and record it again.</Notice>}
      <form onSubmit={submit} className="form-grid two">
        {isNew && <Field label="Type"><select value={d.kind} onChange={(e) => setD({ ...d, kind: e.target.value, category_id: '' })}><option value="income">Income</option><option value="expense">Expense</option></select></Field>}
        <Field label="Date"><input type="date" value={d.txn_date} onChange={(e) => s('txn_date', e.target.value)} required disabled={txn.source === 'order'} /></Field>
        <Field label="Amount (₹)" hint="Total paid, GST included"><input type="number" step="0.01" min="0.01" value={d.amount} onChange={(e) => s('amount', e.target.value)} required disabled={linked} /></Field>
        <Field label="GST inside the amount (₹)" hint="Optional, for the GST columns in reports"><input type="number" step="0.01" min="0" value={d.tax_amount} onChange={(e) => s('tax_amount', e.target.value)} disabled={linked} /></Field>
        <Field label="Category"><select value={d.category_id} onChange={(e) => s('category_id', e.target.value)} disabled={linked}><option value="">— Choose —</option>{look.categories.filter((c) => c.kind === d.kind).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        <Field label="Bank / cash account"><select value={d.account_id} onChange={(e) => s('account_id', e.target.value)}><option value="">— None —</option>{look.accounts.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
        <Field label={d.kind === 'income' ? 'Received from' : 'Paid to'}><input value={d.party} onChange={(e) => s('party', e.target.value)} maxLength={150} disabled={linked} /></Field>
        <Field label="Paid by"><select value={d.method} onChange={(e) => s('method', e.target.value)} disabled={txn.source === 'order'}><option value="">—</option>{look.methods.map((m) => <option key={m} value={m}>{METHOD[m] ?? m}</option>)}</select></Field>
        <Field label="Reference" hint="UTR, cheque no., receipt no."><input value={d.reference} onChange={(e) => s('reference', e.target.value)} maxLength={100} disabled={txn.source === 'order'} /></Field>
        {!linked && <Field label="Status"><select value={d.status} onChange={(e) => s('status', e.target.value)}><option value="completed">Completed (money moved)</option><option value="pending">Pending (expected, not yet moved)</option></select></Field>}
        <div className="span-2"><Field label="Note"><textarea rows={2} value={d.description} onChange={(e) => s('description', e.target.value)} maxLength={500} /></Field></div>
        <div className="row span-2">
          <button disabled={a.busy}>{a.busy ? 'Saving…' : isNew ? 'Add entry' : 'Save'}</button>
          <button type="button" className="secondary" onClick={onClose}>Cancel</button>
          <span className="spacer" />
          {!isNew && txn.source !== 'order' && (confirm
            ? <><span className="muted">Delete this entry?</span><button type="button" className="danger" onClick={remove} disabled={a.busy}>Delete</button><button type="button" className="secondary" onClick={() => setConfirm(false)}>Keep</button></>
            : <button type="button" className="ghost" onClick={() => setConfirm(true)}>Delete…</button>)}
        </div>
      </form>
    </Modal>
  );
}

// ── Invoices & bills ──
function Invoices() {
  const [type, setType] = useState<'invoice' | 'bill'>('invoice');
  const [f, setF] = useState({ status: '', q: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const [edit, setEdit] = useState<any | null>(null);
  const look = useLookups();
  const list = useAsync(() => get(`/accounts/invoices${qs({ type, ...f, page })}`), [type, JSON.stringify(f), page]);
  const params = { type, ...f };
  return (
    <Page title="Invoices & bills" subtitle="Invoices: we bill a customer. Bills: a vendor bills us. Track what is issued, part-paid, paid and overdue."
      actions={<button onClick={() => setEdit({ type })}>+ New {type === 'bill' ? 'bill' : 'invoice'}</button>}>
      <div className="seg" role="tablist">
        {(['invoice', 'bill'] as const).map((t) => <button key={t} role="tab" aria-selected={type === t} className={type === t ? 'on' : ''} onClick={() => { setType(t); setPage(1); }}>{t === 'invoice' ? 'Invoices (receivable)' : 'Bills (payable)'}</button>)}
      </div>
      <Card>
        <div className="filters">
          <select value={f.status} onChange={(e) => { setF({ ...f, status: e.target.value }); setPage(1); }} aria-label="Status">
            <option value="">All statuses</option><option value="draft">Draft</option><option value="open">Open (issued or part-paid)</option><option value="overdue">Overdue</option>
            <option value="paid">Paid</option><option value="cancelled">Cancelled</option>
          </select>
          <DateRange from={f.from} to={f.to} onChange={(from, to) => { setF({ ...f, from, to }); setPage(1); }} />
          <input type="search" placeholder="Search number or party" value={f.q} onChange={(e) => { setF({ ...f, q: e.target.value }); setPage(1); }} />
          <span className="spacer" /><ExportButtons path="/accounts/invoices/export" params={params} />
        </div>
        <ErrorBox error={list.error} />
        <Table rows={list.data?.data} error={list.error} onRow={(i: any) => setOpen(i.id)} empty={`No ${type === 'bill' ? 'bills' : 'invoices'} match.`} columns={[
          { key: 'number', label: 'Number', render: (i: any) => <b>{i.number}</b> },
          { key: 'party_name', label: type === 'bill' ? 'Vendor' : 'Customer' },
          { key: 'issue_date', label: 'Date', render: (i: any) => day(i.issue_date) },
          { key: 'due_date', label: 'Due', render: (i: any) => day(i.due_date) },
          { key: 'status', label: 'Status', render: (i: any) => <Badge value={STATUS_TONE[i.display_status] ?? i.display_status} label={i.display_status} /> },
          { key: 'total', label: 'Total', align: 'right', render: (i: any) => inr(i.total) },
          { key: 'balance', label: 'Balance', align: 'right', render: (i: any) => <b className={i.is_overdue ? 'bad' : ''}>{inr(i.balance)}</b> },
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
      {open !== null && look && <InvoiceDetail id={open} look={look} onClose={() => setOpen(null)} onEdit={(inv) => { setOpen(null); setEdit(inv); }} onChanged={() => void list.reload()} />}
      {edit && look && <InvoiceEditor inv={edit} look={look} onClose={() => setEdit(null)} onDone={(id, msg) => { setEdit(null); toast(msg); void list.reload(); setOpen(id); }} />}
    </Page>
  );
}

function InvoiceEditor({ inv, look, onClose, onDone }: { inv: any; look: Lookups; onClose: () => void; onDone: (id: number, msg: string) => void }) {
  const isNew = !inv.id;
  const type: 'invoice' | 'bill' = inv.type ?? 'invoice';
  const [d, setD] = useState({
    number: inv.number ?? '', party_name: inv.party_name ?? '', party_email: inv.party_email ?? '', party_phone: inv.party_phone ?? '', party_gstin: inv.party_gstin ?? '',
    party_address: inv.party_address ?? '', issue_date: inv.issue_date ?? look.today, due_date: inv.due_date ?? '', tax_rate: inv.tax_rate ?? look.gst_rate,
    category_id: inv.category_id ?? '', notes: inv.notes ?? '',
  });
  const [items, setItems] = useState<{ description: string; qty: string | number; rate: string | number }[]>(inv.items?.map((l: any) => ({ description: l.description, qty: l.qty, rate: l.rate })) ?? [{ description: '', qty: 1, rate: '' }]);
  const a = useAction();
  const sub = items.reduce((t, l) => t + (Number(l.qty) || 0) * (Number(l.rate) || 0), 0);
  const tax = Math.round(sub * (Number(d.tax_rate) || 0)) / 100;
  const s = (k: string, v: any) => setD({ ...d, [k]: v });
  const setItem = (i: number, k: string, v: string) => setItems(items.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
  const save = (issue: boolean) => {
    const body = { ...d, type, issue, tax_rate: Number(d.tax_rate), category_id: d.category_id || null, due_date: d.due_date || null, number: d.number || null,
      items: items.filter((l) => l.description.trim() || Number(l.rate)).map((l) => ({ description: l.description, qty: Number(l.qty), rate: Number(l.rate) })) };
    void a.run(async () => {
      const r: any = isNew ? await post('/accounts/invoices', body) : await put(`/accounts/invoices/${inv.id}`, body);
      onDone(r.id, isNew ? `${type === 'bill' ? 'Bill' : 'Invoice'} ${r.number} ${issue ? 'issued' : 'saved as draft'}` : 'Saved');
    });
  };
  const label = type === 'bill' ? 'bill' : 'invoice';
  return (
    <Modal title={isNew ? `New ${label}` : `Edit ${label} ${inv.number}`} onClose={onClose}>
      <ErrorBox error={a.error} />
      <form onSubmit={(e) => { e.preventDefault(); save(inv.status && inv.status !== 'draft' ? true : false); }} className="form-grid two inv-form">
        <Field label={type === 'bill' ? "Vendor's bill number" : 'Invoice number'} hint={type === 'bill' ? 'As printed on their bill (blank = BILL/… numbering)' : 'Blank = next INV/… number for the financial year'}>
          <input value={d.number} onChange={(e) => s('number', e.target.value)} maxLength={40} placeholder="Automatic" />
        </Field>
        <Field label="Category"><select value={d.category_id} onChange={(e) => s('category_id', e.target.value)}><option value="">— Choose —</option>{look.categories.filter((c) => c.kind === (type === 'bill' ? 'expense' : 'income')).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
        <Field label={type === 'bill' ? 'Vendor name' : 'Customer name'}><input value={d.party_name} onChange={(e) => s('party_name', e.target.value)} required maxLength={150} /></Field>
        <Field label="GSTIN" hint="Optional, 15 characters"><input value={d.party_gstin} onChange={(e) => s('party_gstin', e.target.value.toUpperCase())} maxLength={15} /></Field>
        <Field label="E-mail"><input type="email" value={d.party_email} onChange={(e) => s('party_email', e.target.value)} maxLength={190} /></Field>
        <Field label="Phone"><input value={d.party_phone} onChange={(e) => s('party_phone', e.target.value)} maxLength={20} /></Field>
        <div className="span-2"><Field label="Address"><input value={d.party_address} onChange={(e) => s('party_address', e.target.value)} maxLength={300} /></Field></div>
        <Field label="Date"><input type="date" value={d.issue_date} onChange={(e) => s('issue_date', e.target.value)} required /></Field>
        <Field label="Due date"><input type="date" value={d.due_date} min={d.issue_date} onChange={(e) => s('due_date', e.target.value)} /></Field>
        <div className="span-2 inv-lines">
          <div className="inv-line head"><span>Description</span><span>Qty</span><span>Rate (₹)</span><span>Amount</span><span /></div>
          {items.map((l, i) => (
            <div className="inv-line" key={i}>
              <input aria-label={`Line ${i + 1} description`} value={l.description} onChange={(e) => setItem(i, 'description', e.target.value)} maxLength={200} required={i === 0} />
              <input aria-label={`Line ${i + 1} quantity`} type="number" step="0.01" min="0.01" value={l.qty} onChange={(e) => setItem(i, 'qty', e.target.value)} required />
              <input aria-label={`Line ${i + 1} rate`} type="number" step="0.01" min="0" value={l.rate} onChange={(e) => setItem(i, 'rate', e.target.value)} required />
              <span className="num">{inr((Number(l.qty) || 0) * (Number(l.rate) || 0))}</span>
              <button type="button" className="ghost" aria-label="Remove line" disabled={items.length === 1} onClick={() => setItems(items.filter((_, j) => j !== i))}>✕</button>
            </div>
          ))}
          <button type="button" className="secondary sm" onClick={() => setItems([...items, { description: '', qty: 1, rate: '' }])}>+ Add line</button>
        </div>
        <Field label="GST rate (%)"><select value={d.tax_rate} onChange={(e) => s('tax_rate', e.target.value)}>{[0, 5, 12, 18, 28].map((r) => <option key={r} value={r}>{r}%</option>)}</select></Field>
        <div className="inv-sum"><span>Taxable <b>{inr(sub)}</b></span><span>GST <b>{inr(tax)}</b></span><span className="t">Total <b>{inr(sub + tax)}</b></span></div>
        <div className="span-2"><Field label="Notes" hint="Printed on the document"><textarea rows={2} value={d.notes} onChange={(e) => s('notes', e.target.value)} maxLength={2000} /></Field></div>
        <div className="row span-2">
          {(!inv.status || inv.status === 'draft') ? <>
            <button type="button" disabled={a.busy} onClick={() => save(true)}>{a.busy ? 'Saving…' : `Save & issue`}</button>
            <button type="button" className="secondary" disabled={a.busy} onClick={() => save(false)}>Save as draft</button>
          </> : <button disabled={a.busy}>{a.busy ? 'Saving…' : 'Save changes'}</button>}
          <button type="button" className="ghost" onClick={onClose}>Cancel</button>
        </div>
      </form>
    </Modal>
  );
}

function InvoiceDetail({ id, look, onClose, onEdit, onChanged }: { id: number; look: Lookups; onClose: () => void; onEdit: (inv: any) => void; onChanged: () => void }) {
  const r = useAsync(() => get(`/accounts/invoices/${id}`), [id]);
  const [mode, setMode] = useState<'' | 'pay' | 'cancel' | 'delete'>('');
  const a = useAction();
  const inv = r.data;
  const act = (fn: () => Promise<unknown>, msg: string) => void a.run(async () => { await fn(); toast(msg); setMode(''); onChanged(); await r.reload(); });
  const word = inv?.type === 'bill' ? 'bill' : 'invoice';
  return (
    <Modal title={inv ? `${inv.type === 'bill' ? 'Bill' : 'Invoice'} ${inv.number}` : 'Loading…'} onClose={onClose}>
      <ErrorBox error={r.error ?? a.error} />
      {inv && <>
        <div className="inv-head">
          <div><div className="muted small">{inv.type === 'bill' ? 'Vendor' : 'Customer'}</div><b>{inv.party_name}</b>{inv.party_gstin && <div className="small">GSTIN {inv.party_gstin}</div>}</div>
          <div><div className="muted small">Date / due</div>{day(inv.issue_date)} → {day(inv.due_date)}</div>
          <div><div className="muted small">Status</div><Badge value={STATUS_TONE[inv.display_status] ?? inv.display_status} label={inv.display_status} /></div>
          <div className="r"><div className="muted small">Balance due</div><b className={inv.is_overdue ? 'bad big' : 'big'}>{inr(inv.balance)}</b><div className="small muted">of {inr(inv.total)}</div></div>
        </div>
        <Table rows={inv.items.map((l: any, i: number) => ({ ...l, id: i }))} columns={[
          { key: 'description', label: 'Description' }, { key: 'qty', label: 'Qty', align: 'right' },
          { key: 'rate', label: 'Rate', align: 'right', render: (l: any) => inr(l.rate) }, { key: 'amount', label: 'Amount', align: 'right', render: (l: any) => inr(l.amount) },
        ]} />
        <p className="inv-sum r"><span>Taxable <b>{inr(inv.subtotal)}</b></span><span>GST {inv.tax_rate}% <b>{inr(inv.tax_amount)}</b></span><span className="t">Total <b>{inr(inv.total)}</b></span></p>
        <h3 className="sub-h">Payments</h3>
        <Table rows={inv.payments} empty="No payments yet." columns={[
          { key: 'txn_date', label: 'Date', render: (t: any) => day(t.txn_date) }, { key: 'method', label: 'Method', render: (t: any) => METHOD[t.method] ?? t.method ?? '—' },
          { key: 'reference', label: 'Reference', render: (t: any) => t.reference ?? '—' }, { key: 'account', label: 'Account', render: (t: any) => t.account?.name ?? '—' },
          { key: 'amount', label: 'Amount', align: 'right', render: (t: any) => inr(t.amount) },
        ]} />
        {inv.notes && <p className="muted pre">{inv.notes}</p>}
        {mode === 'pay' && <PayForm inv={inv} look={look} busy={a.busy} onCancel={() => setMode('')} onSubmit={(body) => act(() => post(`/accounts/invoices/${inv.id}/payments`, body), 'Payment recorded')} />}
        {mode === 'cancel' && <CancelForm word={word} busy={a.busy} onCancel={() => setMode('')} onSubmit={(reason) => act(() => post(`/accounts/invoices/${inv.id}/cancel`, { reason }), `${word} cancelled`)} />}
        {mode === 'delete' && <div className="confirm-row"><span>Delete this draft?</span><button className="danger" disabled={a.busy} onClick={() => void a.run(async () => { await del(`/accounts/invoices/${inv.id}`); toast('Draft deleted'); onChanged(); onClose(); })}>Delete draft</button><button className="secondary" onClick={() => setMode('')}>Keep</button></div>}
        {mode === '' && <div className="row inv-actions">
          {['issued', 'partial'].includes(inv.status) && <button onClick={() => setMode('pay')}>{inv.type === 'bill' ? 'Record payment made' : 'Record payment received'}</button>}
          {inv.status === 'draft' && <button onClick={() => act(() => post(`/accounts/invoices/${inv.id}/issue`), `${word} issued`)} disabled={a.busy}>Issue</button>}
          {inv.status !== 'cancelled' && <button className="secondary" onClick={() => onEdit(inv)}>Edit</button>}
          <button className="secondary" onClick={() => openPrint(`/accounts/invoices/${inv.id}/print`)}>Print / PDF</button>
          <span className="spacer" />
          {inv.status === 'draft' && <button className="ghost" onClick={() => setMode('delete')}>Delete…</button>}
          {inv.status !== 'draft' && inv.status !== 'cancelled' && inv.amount_paid === 0 && <button className="ghost" onClick={() => setMode('cancel')}>Cancel {word}…</button>}
        </div>}
      </>}
    </Modal>
  );
}

function PayForm({ inv, look, busy, onCancel, onSubmit }: { inv: any; look: Lookups; busy: boolean; onCancel: () => void; onSubmit: (b: any) => void }) {
  const [d, setD] = useState({ amount: String(inv.balance), date: look.today, method: 'bank', account_id: String(look.accounts.find((a) => a.is_default)?.id ?? ''), reference: '' });
  return (
    <form className="pay-form form-grid two" onSubmit={(e) => { e.preventDefault(); onSubmit({ ...d, amount: Number(d.amount), account_id: d.account_id || null }); }}>
      <Field label="Amount (₹)" hint={`Still due: ${inr(inv.balance)}`}><input type="number" step="0.01" min="0.01" max={inv.balance} value={d.amount} onChange={(e) => setD({ ...d, amount: e.target.value })} required autoFocus /></Field>
      <Field label="Date"><input type="date" value={d.date} onChange={(e) => setD({ ...d, date: e.target.value })} required /></Field>
      <Field label="Paid by"><select value={d.method} onChange={(e) => setD({ ...d, method: e.target.value })}>{look.methods.map((m) => <option key={m} value={m}>{METHOD[m] ?? m}</option>)}</select></Field>
      <Field label="Bank / cash account"><select value={d.account_id} onChange={(e) => setD({ ...d, account_id: e.target.value })}><option value="">— None —</option>{look.accounts.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
      <div className="span-2"><Field label="Reference" hint="UTR, cheque no."><input value={d.reference} onChange={(e) => setD({ ...d, reference: e.target.value })} maxLength={100} /></Field></div>
      <div className="row span-2"><button disabled={busy}>{busy ? 'Saving…' : 'Save payment'}</button><button type="button" className="secondary" onClick={onCancel}>Cancel</button></div>
    </form>
  );
}

function CancelForm({ word, busy, onCancel, onSubmit }: { word: string; busy: boolean; onCancel: () => void; onSubmit: (reason: string) => void }) {
  const [reason, setReason] = useState('');
  return (
    <form className="pay-form form-grid" onSubmit={(e) => { e.preventDefault(); onSubmit(reason); }}>
      <Field label={`Why is this ${word} cancelled?`}><input value={reason} onChange={(e) => setReason(e.target.value)} required maxLength={255} autoFocus /></Field>
      <div className="row"><button className="danger" disabled={busy}>Cancel {word}</button><button type="button" className="secondary" onClick={onCancel}>Keep it</button></div>
    </form>
  );
}

// ── Payments ──
function Payments() {
  const [view, setView] = useState<'pending' | 'overdue' | 'completed'>('overdue');
  const [range, setRange] = useState({ from: monthStart(), to: todayIso() });
  const [open, setOpen] = useState<number | null>(null);
  const look = useLookups();
  const r = useAsync(() => get(`/accounts/payments${qs({ view, ...(view === 'completed' ? range : {}) })}`), [view, range.from, range.to]);
  const copy = (url: string) => { navigator.clipboard?.writeText(url).then(() => toast('Pay link copied'), () => toast('Could not copy', 'bad')); };
  return (
    <Page title="Payments" subtitle="What is still to come in or go out, what is late, and what was settled">
      <div className="seg" role="tablist">
        {([['overdue', 'Overdue'], ['pending', 'Pending'], ['completed', 'Completed']] as const).map(([k, l]) => <button key={k} role="tab" aria-selected={view === k} className={view === k ? 'on' : ''} onClick={() => setView(k)}>{l}</button>)}
      </div>
      <Card>
        <div className="acc-totals">
          {r.data && <><span>{view === 'completed' ? 'Received' : 'To receive'} <b className="ok-text">{inr(r.data.total_in)}</b></span><span>{view === 'completed' ? 'Paid out' : 'To pay'} <b className="bad">{inr(r.data.total_out)}</b></span></>}
          <span className="spacer" />{view === 'completed' && <DateRange from={range.from} to={range.to} onChange={(from, to) => setRange({ from, to })} />}
        </div>
        <ErrorBox error={r.error} />
        <Table rows={r.data?.rows.map((x: any) => ({ ...x, id: x.key }))} error={r.error} onRow={(x: any) => x.invoice_id && setOpen(x.invoice_id)}
          empty={view === 'overdue' ? 'Nothing is overdue.' : view === 'pending' ? 'Nothing pending.' : 'No payments in this period.'} columns={[
          { key: 'direction', label: '', render: (x: any) => <span className={`dir ${x.direction}`} title={x.direction === 'in' ? 'Money in' : 'Money out'}>{x.direction === 'in' ? '↓ In' : '↑ Out'}</span> },
          { key: 'ref', label: 'Document', render: (x: any) => <><b>{x.ref ?? '—'}</b><div className="small muted">{SOURCE[x.source] ?? (x.source === 'entry' ? 'Pending entry' : x.source)}</div></> },
          { key: 'party', label: 'Party', render: (x: any) => x.party ?? '—' },
          { key: 'date', label: view === 'completed' ? 'Paid on' : 'Due', render: (x: any) => <>{day(x.date)}{x.days != null && x.days < 0 && <div className="small bad">{-x.days} days late</div>}{x.days != null && x.days >= 0 && <div className="small muted">in {x.days} days</div>}</> },
          { key: 'status', label: 'Status', render: (x: any) => <Badge value={STATUS_TONE[x.status] ?? x.status} label={x.status} /> },
          { key: 'amount', label: view === 'completed' ? 'Amount' : 'Balance', align: 'right', render: (x: any) => <b>{inr(view === 'completed' ? x.amount : x.balance)}</b> },
          { key: 'act', label: '', render: (x: any) => x.pay_url ? <button className="ghost sm" onClick={(e) => { e.stopPropagation(); copy(x.pay_url); }}>Copy pay link</button> : null },
        ]} />
      </Card>
      {open !== null && look && <InvoiceDetail id={open} look={look} onClose={() => setOpen(null)} onEdit={() => setOpen(null)} onChanged={() => void r.reload()} />}
    </Page>
  );
}

// ── Reports ──
function Reports() {
  const [period, setPeriod] = useState<'daily' | 'monthly' | 'yearly'>('monthly');
  const [range, setRange] = useState({ from: fyStartIso(), to: todayIso() });
  const r = useAsync(() => get(`/accounts/reports${qs({ period, ...range })}`), [period, range.from, range.to]);
  const x = r.data;
  const pick = (p: 'daily' | 'monthly' | 'yearly') => {
    setPeriod(p);
    if (p === 'daily') setRange({ from: monthStart(), to: todayIso() });
    if (p === 'monthly') setRange({ from: fyStartIso(), to: todayIso() });
    if (p === 'yearly') setRange({ from: `${Number(fyStartIso().slice(0, 4)) - 2}-04-01`, to: todayIso() });
  };
  return (
    <Page title="Financial reports" subtitle="Income, expenses and GST by day, month or financial year (April–March)">
      <div className="filters">
        <div className="seg" role="tablist">{(['daily', 'monthly', 'yearly'] as const).map((p) => <button key={p} role="tab" aria-selected={period === p} className={period === p ? 'on' : ''} onClick={() => pick(p)}>{p[0].toUpperCase() + p.slice(1)}</button>)}</div>
        <DateRange from={range.from} to={range.to} onChange={(from, to) => setRange({ from, to })} />
        <span className="spacer" /><ExportButtons path="/accounts/reports/export" params={{ period, ...range }} />
      </div>
      <ErrorBox error={r.error} />
      {x && <>
        <div className="kpi-grid six small">
          <Kpi icon="chart" tone="green" label="Income" value={inr0(x.totals.income)} />
          <Kpi icon="chart" tone="purple" label="Expenses" value={inr0(x.totals.expense)} />
          <Kpi icon="check" tone={x.totals.net >= 0 ? 'teal' : 'purple'} label="Net" value={inr0(x.totals.net)} />
          <Kpi icon="shield" tone="blue" label="GST collected / paid" value={`${inr0(x.totals.gst_in)} / ${inr0(x.totals.gst_out)}`} />
          <Kpi icon="clock" tone="gold" label="Receivable now" value={inr0(x.outstanding.receivable)} />
          <Kpi icon="warning" tone="purple" label="Payable now" value={inr0(x.outstanding.payable)} />
        </div>
        <div className="report-stack">
          <Card title={`${period === 'daily' ? 'Day' : period === 'monthly' ? 'Month' : 'Financial year'} by ${period === 'daily' ? 'day' : period === 'monthly' ? 'month' : 'year'}`}>
            <div className="table-wrap"><table>
              <thead><tr><th>{period === 'yearly' ? 'Financial year' : period === 'monthly' ? 'Month' : 'Day'}</th><th className="num">Income</th><th className="num">Expenses</th><th className="num">Net</th><th className="num">GST in</th><th className="num">GST out</th></tr></thead>
              <tbody>{x.rows.length === 0 ? <tr><td colSpan={6} className="muted">Nothing in this period.</td></tr> : x.rows.map((l: any) => (
                <tr key={l.key}><td>{l.label}</td><td className="num">{inr(l.income)}</td><td className="num">{inr(l.expense)}</td><td className={`num ${l.net < 0 ? 'bad' : ''}`}>{inr(l.net)}</td><td className="num">{inr(l.gst_in)}</td><td className="num">{inr(l.gst_out)}</td></tr>
              ))}</tbody>
              <tfoot><tr><td>Total</td><td className="num">{inr(x.totals.income)}</td><td className="num">{inr(x.totals.expense)}</td><td className="num">{inr(x.totals.net)}</td><td className="num">{inr(x.totals.gst_in)}</td><td className="num">{inr(x.totals.gst_out)}</td></tr></tfoot>
            </table></div>
          </Card>
          <Card title="By category">
            <Table rows={x.by_category.map((c: any, i: number) => ({ ...c, id: i }))} empty="No entries." columns={[
              { key: 'kind', label: 'Type', render: (c: any) => (c.kind === 'income' ? 'Income' : 'Expense') }, { key: 'name', label: 'Category' },
              { key: 'count', label: 'Entries', align: 'right' }, { key: 'total', label: 'Total', align: 'right', render: (c: any) => inr(c.total) },
            ]} />
          </Card>
        </div>
      </>}
    </Page>
  );
}

// ── Subscription invoices (paid sales orders) ──
function Subscriptions() {
  const [f, setF] = useState({ from: fyStartIso(), to: todayIso(), q: '' });
  const [page, setPage] = useState(1);
  const r = useAsync(() => get(`/accounts/sales-invoices${qs({ ...f, page })}`), [JSON.stringify(f), page]);
  return (
    <Page title="Subscription invoices" subtitle="Tax invoices issued automatically for paid ROVEXY subscriptions (online and bank payments). They are already in Income.">
      <Card>
        <div className="filters">
          <DateRange from={f.from} to={f.to} onChange={(from, to) => { setF({ ...f, from, to }); setPage(1); }} />
          <input type="search" placeholder="Search invoice, order or company" value={f.q} onChange={(e) => { setF({ ...f, q: e.target.value }); setPage(1); }} />
        </div>
        {r.data && <div className="acc-totals"><span>Taxable <b>{inr(r.data.totals.taxable)}</b></span><span>GST <b>{inr(r.data.totals.gst)}</b></span><span>Total <b>{inr(r.data.totals.total)}</b></span></div>}
        <ErrorBox error={r.error} />
        <Table rows={r.data?.data} error={r.error} empty="No paid subscriptions in this period." columns={[
          { key: 'invoice_number', label: 'Invoice', render: (o: any) => <b>{o.invoice_number}</b> }, { key: 'paid_at', label: 'Paid', render: (o: any) => day(o.paid_at) },
          { key: 'buyer_company', label: 'Company', render: (o: any) => <>{o.buyer_company}{o.buyer_gstin && <div className="small muted">{o.buyer_gstin}</div>}</> },
          { key: 'plan', label: 'Package' }, { key: 'payment_method', label: 'Paid by', render: (o: any) => METHOD[o.payment_method] ?? o.payment_method },
          { key: 'amount', label: 'Taxable', align: 'right', render: (o: any) => inr(o.amount) }, { key: 'gst', label: 'GST', align: 'right', render: (o: any) => inr(o.cgst + o.sgst + o.igst) },
          { key: 'total', label: 'Total', align: 'right', render: (o: any) => <b>{inr(o.total)}</b> },
          { key: 'link', label: '', render: (o: any) => <a href={o.invoice_url} target="_blank" rel="noopener">View</a> },
        ]} />
        <Pager meta={r.data} onPage={setPage} />
      </Card>
    </Page>
  );
}

// ── Bank & cash accounts ──
function MoneyAccounts() {
  const r = useAsync(() => get<any[]>('/accounts/accounts'), []);
  const [edit, setEdit] = useState<any | null>(null);
  const total = useMemo(() => (r.data ?? []).filter((a) => a.status === 'active').reduce((t, a) => t + a.balance, 0), [r.data]);
  return (
    <Page title="Bank & cash" subtitle="Where the money is kept. Balance = opening balance + income − expenses (completed entries)." actions={<button onClick={() => setEdit({})}>+ Add account</button>}>
      <Card actions={<span>Total in active accounts <b>{inr(total)}</b></span>}>
        <ErrorBox error={r.error} />
        <Table rows={r.data} error={r.error} onRow={setEdit} empty="No accounts yet. Add your bank account(s) and cash box." columns={[
          { key: 'name', label: 'Account', render: (a: any) => <><b>{a.name}</b> {a.is_default && <Badge value="active" label="default" />}<div className="small muted">{[a.bank_name, a.account_number_masked, a.ifsc].filter(Boolean).join(' · ')}</div></> },
          { key: 'type', label: 'Type', render: (a: any) => ACC_TYPE[a.type] ?? a.type },
          { key: 'status', label: 'Status', render: (a: any) => <Badge value={a.status === 'active' ? 'active' : 'expired'} label={a.status} /> },
          { key: 'opening_balance', label: 'Opening', align: 'right', render: (a: any) => inr(a.opening_balance) },
          { key: 'balance', label: 'Balance', align: 'right', render: (a: any) => <b className={a.balance < 0 ? 'bad' : ''}>{inr(a.balance)}</b> },
        ]} />
      </Card>
      {edit && <AccountModal acc={edit} onClose={() => setEdit(null)} onDone={(m) => { setEdit(null); toast(m); void r.reload(); }} />}
    </Page>
  );
}

function AccountModal({ acc, onClose, onDone }: { acc: any; onClose: () => void; onDone: (msg: string) => void }) {
  const isNew = !acc.id;
  const [d, setD] = useState({ name: acc.name ?? '', type: acc.type ?? 'bank', bank_name: acc.bank_name ?? '', account_number: acc.account_number ?? '', ifsc: acc.ifsc ?? '',
    opening_balance: acc.opening_balance ?? 0, opening_date: acc.opening_date ?? '', is_default: acc.is_default ?? false, status: acc.status ?? 'active', notes: acc.notes ?? '' });
  const [confirm, setConfirm] = useState(false);
  const a = useAction();
  const s = (k: string, v: any) => setD({ ...d, [k]: v });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { ...d, opening_balance: Number(d.opening_balance) || 0, opening_date: d.opening_date || null, bank_name: d.bank_name || null, account_number: d.account_number || null, ifsc: d.ifsc || null };
    void a.run(async () => { isNew ? await post('/accounts/accounts', body) : await put(`/accounts/accounts/${acc.id}`, body); onDone(isNew ? 'Account added' : 'Account saved'); });
  };
  return (
    <Modal title={isNew ? 'Add bank / cash account' : acc.name} onClose={onClose}>
      <ErrorBox error={a.error} />
      <form className="form-grid two" onSubmit={submit}>
        <Field label="Name"><input value={d.name} onChange={(e) => s('name', e.target.value)} required maxLength={120} placeholder="e.g. HDFC Current" /></Field>
        <Field label="Type"><select value={d.type} onChange={(e) => s('type', e.target.value)}>{Object.entries(ACC_TYPE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
        {(d.type === 'bank' || d.type === 'card') && <>
          <Field label="Bank"><input value={d.bank_name} onChange={(e) => s('bank_name', e.target.value)} maxLength={120} /></Field>
          <Field label="Account number" hint="Shown masked (last 4) in lists"><input value={d.account_number} onChange={(e) => s('account_number', e.target.value)} maxLength={40} /></Field>
          <Field label="IFSC"><input value={d.ifsc} onChange={(e) => s('ifsc', e.target.value.toUpperCase())} maxLength={11} /></Field>
        </>}
        <Field label="Opening balance (₹)"><input type="number" step="0.01" value={d.opening_balance} onChange={(e) => s('opening_balance', e.target.value)} /></Field>
        <Field label="Opening balance as on"><input type="date" value={d.opening_date} onChange={(e) => s('opening_date', e.target.value)} /></Field>
        <Field label="Status"><select value={d.status} onChange={(e) => s('status', e.target.value)}><option value="active">Active</option><option value="closed">Closed</option></select></Field>
        <label className="check span-2"><input type="checkbox" checked={d.is_default} onChange={(e) => s('is_default', e.target.checked)} /> Default account (online subscription payments are recorded here)</label>
        <div className="span-2"><Field label="Notes"><textarea rows={2} value={d.notes} onChange={(e) => s('notes', e.target.value)} maxLength={1000} /></Field></div>
        <div className="row span-2">
          <button disabled={a.busy}>{a.busy ? 'Saving…' : 'Save'}</button><button type="button" className="secondary" onClick={onClose}>Cancel</button><span className="spacer" />
          {!isNew && (confirm ? <><span className="muted">Delete?</span><button type="button" className="danger" onClick={() => void a.run(async () => { await del(`/accounts/accounts/${acc.id}`); onDone('Account deleted'); })}>Delete</button><button type="button" className="secondary" onClick={() => setConfirm(false)}>Keep</button></>
            : <button type="button" className="ghost" onClick={() => setConfirm(true)}>Delete…</button>)}
        </div>
      </form>
    </Modal>
  );
}

// ── Categories ──
function Categories() {
  const r = useAsync(() => get<any[]>('/accounts/categories'), []);
  const [kind, setKind] = useState<'income' | 'expense'>('expense');
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null);
  const a = useAction();
  const run = (fn: () => Promise<unknown>, msg: string) => void a.run(async () => { await fn(); toast(msg); setEditing(null); await r.reload(); });
  const rows = (r.data ?? []).filter((c) => c.kind === kind);
  return (
    <Page title="Categories" subtitle="How income and expenses are grouped in reports">
      <div className="seg" role="tablist">{(['expense', 'income'] as const).map((k) => <button key={k} role="tab" aria-selected={kind === k} className={kind === k ? 'on' : ''} onClick={() => setKind(k)}>{k === 'income' ? 'Income' : 'Expenses'}</button>)}</div>
      <Card>
        <ErrorBox error={r.error ?? a.error} />
        <form className="row" onSubmit={(e) => { e.preventDefault(); run(() => post('/accounts/categories', { kind, name }).then(() => setName('')), 'Category added'); }}>
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder={`New ${kind} category`} maxLength={80} required aria-label="New category name" />
          <button disabled={a.busy}>Add</button>
        </form>
        <Table rows={rows} error={r.error} empty="No categories." columns={[
          { key: 'name', label: 'Name', render: (c: any) => editing?.id === c.id
            ? <form className="row" onSubmit={(e) => { e.preventDefault(); run(() => put(`/accounts/categories/${c.id}`, { name: editing?.name, active: c.active }), 'Renamed'); }}>
                <input value={editing?.name ?? ''} onChange={(e) => setEditing({ id: c.id, name: e.target.value })} required maxLength={80} autoFocus aria-label="Category name" /><button className="sm">Save</button><button type="button" className="ghost sm" onClick={() => setEditing(null)}>Cancel</button></form>
            : <>{c.name} {c.system && <span className="small muted">(used by subscriptions)</span>}</> },
          { key: 'used', label: 'Entries', align: 'right' },
          { key: 'active', label: 'Status', render: (c: any) => <Badge value={c.active ? 'active' : 'expired'} label={c.active ? 'In use' : 'Off'} /> },
          { key: 'act', label: '', render: (c: any) => (
            <span className="row">
              <button className="ghost sm" onClick={() => setEditing({ id: c.id, name: c.name })}>Rename</button>
              <button className="ghost sm" onClick={() => run(() => put(`/accounts/categories/${c.id}`, { name: c.name, active: !c.active }), c.active ? 'Switched off' : 'Switched on')}>{c.active ? 'Switch off' : 'Switch on'}</button>
              {!c.system && c.used === 0 && <button className="ghost sm" onClick={() => run(() => del(`/accounts/categories/${c.id}`), 'Deleted')}>Delete</button>}
            </span>) },
        ]} />
      </Card>
    </Page>
  );
}

