import { useEffect, useState, type FormEvent } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { del, download, fmtDate, get, patch, post, put, qs, setOnUnauthorized } from '../lib/api';
import { Badge, Card, ErrorBox, Field, Icon, Kpi, Modal, Notice, Page, Pager, PayBadge, Table, Toaster, toast, useAction, useAsync } from '../components/ui';
import { MyProfile, StaffSignIn, type MfaPending, type StaffMe } from './StaffAuth';

/*
 * Super Admin console (/super) for Ametecs staff: companies, licences, plans, orders & payments, leads, staff, settings,
 * audit. Every screen talks to /api/super; the server enforces the role matrix, the menu just hides what a role can't open.
 */

type Me = StaffMe;
const inr = (n: number | null | undefined) => (n == null ? '—' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }));
const day = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const daysLeft = (iso?: string | null) => (iso ? Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000) : null);
const STATES: Record<string, string> = { '36': 'Telangana', '37': 'Andhra Pradesh', '29': 'Karnataka', '33': 'Tamil Nadu', '27': 'Maharashtra', '07': 'Delhi', '24': 'Gujarat', '09': 'Uttar Pradesh', '19': 'West Bengal', '32': 'Kerala', '08': 'Rajasthan', '06': 'Haryana', '23': 'Madhya Pradesh', '21': 'Odisha', '10': 'Bihar', '03': 'Punjab', '30': 'Goa', '18': 'Assam', '20': 'Jharkhand', '22': 'Chhattisgarh', '05': 'Uttarakhand', '02': 'Himachal Pradesh', '01': 'Jammu & Kashmir', '04': 'Chandigarh', '34': 'Puducherry' };

const MENU: { to: string; module: string; label: string; icon: string }[] = [
  { to: '/', module: 'dashboard', label: 'Dashboard', icon: 'home' },
  { to: '/companies', module: 'companies', label: 'Companies', icon: 'people' },
  { to: '/licences', module: 'licences', label: 'Licences', icon: 'shield' },
  { to: '/orders', module: 'orders', label: 'Orders & payments', icon: 'chart' },
  { to: '/leads', module: 'leads', label: 'Leads', icon: 'contacts' },
  { to: '/plans', module: 'plans', label: 'Plans & pricing', icon: 'settings' },
  { to: '/coupons', module: 'plans', label: 'Discount codes', icon: 'chart' },
  { to: '/custom-prices', module: 'plans', label: 'Custom pricing', icon: 'chart' },
  { to: '/staff', module: 'admins', label: 'Staff users', icon: 'people' },
  { to: '/settings', module: 'settings', label: 'Settings', icon: 'settings' },
  { to: '/updates', module: 'settings', label: 'Software updates', icon: 'shield' },
  { to: '/audit', module: 'audit', label: 'Audit log', icon: 'check' },
  { to: '/emails', module: 'audit', label: 'Sent e-mails', icon: 'chat' },
];

export function SuperApp() {
  const [me, setMe] = useState<Me | MfaPending | null | undefined>(undefined);
  useEffect(() => { get<Me | MfaPending>('/auth/me').then(setMe, () => setMe(null)); }, []);
  useEffect(() => { setOnUnauthorized(() => setMe(null)); return () => setOnUnauthorized(null); }, []);   // expired session → sign-in
  if (me === undefined) return <p className="muted" style={{ padding: 24 }}>Loading…</p>;
  if (!me || 'mfa_required' in me) {
    return <StaffSignIn portal="staff" pending={me && 'mfa_required' in me ? me : null} onIn={(m) => (m.role === 'accountant' ? location.replace('/accounts') : setMe(m))}
      shell={{ eyebrow: 'Super Admin · Ametecs staff', title: 'Every client,', accent: 'one command centre.', points: ['Companies, licences and plans', 'Orders, invoices and payments', 'Every change kept in the audit log'],
        heading: 'Staff sign-in', sub: 'For Ametecs staff only. Every sign-in and change is recorded.', button: 'Sign in' }} />;
  }
  if (me.role === 'accountant') { location.replace('/accounts'); return null; }
  const can = (m: string, level: 'view' | 'manage' = 'view') => me.access[m] === 'manage' || (level === 'view' && me.access[m] === 'view');
  const logout = async () => { await post('/auth/logout').catch(() => undefined); setMe(null); };
  const items = MENU.filter((m) => can(m.module));
  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand"><img src="/brand/rovexy-mark-dark.svg" alt="ROVEXY" width="36" height="40" style={{ flex: "none", background: "none", padding: 0 }} /><span className="lbl">ROVEXY · Super Admin</span></div>
        <nav className="nav" aria-label="Modules">
          {items.map((i) => <NavLink key={i.to} to={i.to} end={i.to === '/'} className="top"><Icon name={i.icon} size={19} /><span className="lbl">{i.label}</span></NavLink>)}
          {can('accounts') && <a className="top" href="/accounts"><Icon name="chart" size={19} /><span className="lbl">Accounts ↗</span></a>}
        </nav>
        <div className="nav-user">
          <span className="avatar">{me.name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase()}</span>
          <NavLink to="/profile" className="lbl who" title="My profile"><strong>{me.name}</strong><small>{me.role_label}</small></NavLink>
          <button className="icon-btn nav-out" onClick={logout} title="Sign out" aria-label="Sign out"><Icon name="logout" size={18} /></button>
        </div>
      </aside>
      <main className="main">
        <Routes>
          {can('dashboard') && <Route path="/" element={<Dashboard />} />}
          {can('companies') && <Route path="/companies" element={<Companies can={can('companies', 'manage')} isSuper={me.role === 'super'} />} />}
          {can('licences') && <Route path="/licences" element={<Licences can={can('licences', 'manage')} isSuper={me.role === 'super'} />} />}
          {can('orders') && <Route path="/orders" element={<Orders can={can('orders', 'manage')} />} />}
          {can('leads') && <Route path="/leads" element={<Leads can={can('leads', 'manage')} isSuper={me.role === 'super'} canCompanies={can('companies', 'manage')} />} />}
          {can('plans') && <Route path="/plans" element={<Plans can={can('plans', 'manage')} />} />}
          {can('plans') && <Route path="/coupons" element={<Coupons can={can('plans', 'manage')} />} />}
          {can('plans') && <Route path="/custom-prices" element={<CustomPricing can={can('plans', 'manage')} />} />}
          {can('audit') && <Route path="/emails" element={<Emails />} />}
          {can('admins') && <Route path="/staff" element={<Staff me={me} />} />}
          {can('settings') && <Route path="/settings" element={<Settings />} />}
          {can('settings') && <Route path="/updates" element={<Updates />} />}
          {can('audit') && <Route path="/audit" element={<Audit />} />}
          <Route path="/profile" element={<MyProfile me={me} onChange={setMe} onSignOut={logout} />} />
          <Route path="*" element={<Navigate to={items[0]?.to ?? '/'} replace />} />
        </Routes>
      </main>
      <Toaster />
    </div>
  );
}

// ── Dashboard ──
function Dashboard() {
  const d = useAsync(() => get('/dashboard'));
  const x = d.data;
  return (
    <Page title="Dashboard" subtitle="ROVEXY business at a glance">
      <ErrorBox error={d.error} />
      {x && !x.payments_ready && <Notice tone="warn">Online payments are off: add the Razorpay keys in Settings. Orders can still be paid by bank transfer and recorded by staff.</Notice>}
      {x && <>
        <div className="kpi-grid">
          <Kpi icon="people" tone="blue" label="Companies" value={x.companies} sub="cloud workspaces" />
          <Kpi icon="shield" tone="green" label="Paid licences active" value={x.licences_active} sub={`${x.onprem_active} on-premises · ${x.expiring_30d} expire within 30 days${x.amc_due_30d ? ` · ${x.amc_due_30d} AMC due` : ''}`} subTone={x.expiring_30d ? 'down' : 'flat'} />
          <Kpi icon="clock" tone="gold" label="Trials running" value={x.trials_active} sub={`${x.trials_ending_7d} end within 7 days`} />
          <Kpi icon="chart" tone="teal" label="Revenue this month" value={inr(x.revenue_month)} sub={`${inr(x.revenue_fy)} this financial year (before GST)`} />
          <Kpi icon="warning" tone="purple" label="Expired, not renewed" value={x.expired} sub={`${x.orders_unpaid} unpaid orders · ${x.leads_new} new leads`} />
        </div>
        <div className="grid-2">
          <Card title="Expiring or expired">
            <Table rows={x.expiring} empty="Nothing expiring in the next 30 days." columns={[
              { key: 'holder_name', label: 'Holder' }, { key: 'plan', label: 'Plan', render: (l: any) => l.plan?.name ?? l.kind },
              { key: 'expires_at', label: 'Expires', render: (l: any) => { const n = daysLeft(l.expires_at)!; return <span className={n < 0 ? 'bad' : ''}>{day(l.expires_at)} ({n < 0 ? `${-n} days ago` : `in ${n} days`})</span>; } },
            ]} />
          </Card>
          <Card title="Latest orders">
            <Table rows={x.recent_orders} empty="No orders yet." columns={[
              { key: 'number', label: 'Order' }, { key: 'buyer_company', label: 'Company' },
              { key: 'total', label: 'Total', align: 'right', render: (o: any) => inr(o.total) }, { key: 'status', label: 'Status', render: (o: any) => <PayBadge order={o} /> },
            ]} />
          </Card>
        </div>
      </>}
    </Page>
  );
}

function usePlans() {
  return useAsync(() => get<{ plans: any[]; features: Record<string, string> }>('/plans').catch(() => ({ plans: [], features: {} })));
}

// ── Companies ──
function Companies({ can, isSuper }: { can: boolean; isSuper: boolean }) {
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  useEffect(() => { const t = setTimeout(() => setSearch(q), 350); return () => clearTimeout(t); }, [q]);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [search]);
  const list = useAsync(() => get(`/companies${qs({ q: search, page })}`), [search, page]);
  const [adding, setAdding] = useState(false);
  const [suspend, setSuspend] = useState<any | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const a = useAction();
  const setStatus = (c: any, status: string, reason?: string) => a.run(async () => { await post(`/companies/${c.id}/status`, { status, reason }); setSuspend(null); toast(status === 'active' ? `${c.name} re-activated` : `${c.name} suspended`); await list.reload(); });
  return (
    <Page title="Companies" subtitle="Cloud workspaces and their licences" actions={can ? <button onClick={() => setAdding(true)}>New company</button> : undefined}>
      <ErrorBox error={list.error ?? a.error} />
      <Card>
        <div className="filters"><input type="search" placeholder="Search company or workspace" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search companies" /></div>
        <Table rows={list.data?.data} error={list.error} onRow={(c: any) => setOpen(c.id)} empty="No companies yet. They appear here when a client starts a trial or pays." columns={[
          { key: 'name', label: 'Company', render: (c: any) => <><strong>{c.name}</strong><div className="muted small">{c.slug} · {c.admin_email ?? 'no admin'}</div></> },
          { key: 'licence', label: 'Licence', render: (c: any) => c.licence ? <><Badge value={c.licence.status} /> {c.licence.plan ?? c.licence.kind}<div className="muted small">{c.licence.kind === 'internal' ? 'internal, no expiry' : `until ${day(c.licence.expires_at)}`}</div></> : <span className="muted">none (unrestricted)</span> },
          { key: 'active_users', label: 'Users', align: 'right', render: (c: any) => `${c.active_users}${c.licence?.max_users ? ` / ${c.licence.max_users}` : ''}` },
          { key: 'phones', label: 'Phones', align: 'right' },
          { key: 'calls_30d', label: 'Calls (30 days)', align: 'right', render: (c: any) => <>{c.calls_30d}<div className="muted small">{c.last_seen_at ? `phone seen ${day(c.last_seen_at)}` : 'no phone yet'}</div></> },
          { key: 'status', label: 'Workspace', render: (c: any) => <Badge value={c.status === 'active' ? 'active' : 'revoked'} label={c.status} /> },
          { key: 'act', label: '', render: (c: any) => <div className="row" onClick={(e) => e.stopPropagation()}>
            <a className="ghost small" href={c.console_url} target="_blank" rel="noreferrer">Console</a>
            {can && (c.status === 'active' ? <button className="ghost small" onClick={() => setSuspend(c)}>Suspend</button> : <button className="ghost small" disabled={a.busy} onClick={() => void setStatus(c, 'active')}>Re-activate</button>)}
          </div> },
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
      {adding && <NewCompany isSuper={isSuper} onClose={() => setAdding(false)} onDone={() => { setAdding(false); void list.reload(); }} />}
      {open !== null && <CompanyDetail id={open} can={can} isSuper={isSuper} onClose={() => { setOpen(null); void list.reload(); }} />}
      {suspend && <SuspendModal name={suspend.name} busy={a.busy} onClose={() => setSuspend(null)} onConfirm={(r) => void setStatus(suspend, 'suspended', r)} />}
    </Page>
  );
}

function SuspendModal({ name, busy, onClose, onConfirm }: { name: string; busy: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('');
  return (
    <Modal title={`Suspend ${name}`} onClose={onClose}>
      <p>Nobody in this workspace can sign in — admins or phones — until it is re-activated. Data is kept.</p>
      <Field label="Reason (kept in the audit log)"><input value={reason} onChange={(e) => setReason(e.target.value)} required /></Field>
      <div className="row"><button className="danger" disabled={busy || !reason.trim()} onClick={() => onConfirm(reason)}>Suspend workspace</button><button className="secondary" onClick={onClose}>Cancel</button></div>
    </Modal>
  );
}

// Same rules as the server (Workspaces::RESERVED / slugFrom): our own web addresses get a "-team" suffix.
const RESERVED = ['admin', 'super', 'api', 'www', 'app', 'buy', 'pay', 'accounts', 'password', 'mail', 'smtp', 'ftp', 'support', 'help', 'status', 'static', 'cdn', 'rovexy', 'ametecs'];
const slugFrom = (name: string) => { const s = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30); return s.length < 3 || RESERVED.includes(s) ? `${s}-team`.replace(/^-/, '') : s; };

function CompanyDetail({ id, can, isSuper, onClose }: { id: number; can: boolean; isSuper: boolean; onClose: () => void }) {
  const c = useAsync(() => get(`/companies/${id}`), [id]);
  const plans = usePlans();
  const [mode, setMode] = useState<'' | 'edit' | 'licence' | 'password'>('');
  const [det, setDet] = useState({ name: '', billing_email: '', billing_phone: '', state_code: '', gstin: '', address: '' });
  const [lic, setLic] = useState({ plan_id: '', kind: 'trial', users: '', billing: 'annual', reason: '' });
  const [pw, setPw] = useState({ password: '', reason: '' });
  const [licOpen, setLicOpen] = useState<number | null>(null);
  const [inviteLink, setInviteLink] = useState<string | null>(null);
  const a = useAction();
  const x = c.data;
  const live = x?.licences?.find((l: any) => l.status === 'active' || l.status === 'suspended');
  const done = async (msg: string) => { toast(msg); setMode(''); await c.reload(); };
  return (
    <Modal title={x ? x.name : 'Company'} onClose={onClose}>
      <ErrorBox error={c.error ?? a.error} />
      {!x ? <p className="muted">Loading…</p> : <>
        <div className="kpi-grid small">
          <Kpi icon="people" tone="blue" label="Active users" value={x.users} sub={live ? `of ${live.max_users || 'unlimited'}` : 'no licence'} />
          <Kpi icon="phone" tone="green" label="Approved phones" value={x.phones} sub={x.last_seen_at ? `seen ${day(x.last_seen_at)}` : 'none yet'} />
          <Kpi icon="call" tone="teal" label="Calls, 30 days" value={x.calls_30d} />
          <Kpi icon="shield" tone="gold" label="Workspace" value={x.status} sub={x.slug} />
        </div>
        <div className="row" style={{ gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
          <a className="button secondary" href={x.console_url} target="_blank" rel="noreferrer">Open console ↗</a>
          {can && <button className="secondary" onClick={() => { setDet({ name: x.name, billing_email: x.billing_email ?? '', billing_phone: x.billing_phone ?? '', state_code: x.state_code ?? '', gstin: x.gstin ?? '', address: x.address ?? '' }); setMode('edit'); }}>Edit details</button>}
          {can && !live && <button onClick={() => setMode('licence')}>Issue licence</button>}
          {can && <button className="secondary" disabled={a.busy} onClick={() => void a.run(async () => { const r: any = await post(`/companies/${id}/invite`); toast(r.mail_ready ? `Set-password e-mail sent to ${r.email}` : `E-mail sending is not set up; copy the link from the audit-safe box below`, r.mail_ready ? 'ok' : 'bad'); setInviteLink(r.mail_ready ? null : r.set_password_url); })}>E-mail set-password link</button>}
          {isSuper && <button className="secondary" onClick={() => setMode('password')}>Set admin password</button>}
        </div>
        <p className="small">Console address: <a href={x.console_url} target="_blank" rel="noreferrer">{x.console_url}</a> · workspace <code>{x.slug}</code></p>
        {inviteLink && <div className="row"><input readOnly value={inviteLink} onFocus={(e) => e.target.select()} aria-label="Set-password link" /><button type="button" className="secondary" onClick={() => navigator.clipboard?.writeText(inviteLink).then(() => toast('Link copied'))}>Copy</button></div>}
        <p className="small muted">Billing: {[x.billing_email, x.billing_phone, x.state_code ? STATES[x.state_code] ?? x.state_code : null, x.gstin ? `GSTIN ${x.gstin}` : null, x.address].filter(Boolean).join(' · ') || 'not set — renewals ask for the GST state each time'}</p>
        {mode === 'edit' && <form className="card" onSubmit={(e) => { e.preventDefault(); void a.run(async () => { await put(`/companies/${id}`, det); await done('Company details saved'); }); }}>
          <div className="form-grid">
            <Field label="Company name"><input value={det.name} onChange={(e) => setDet({ ...det, name: e.target.value })} required maxLength={150} /></Field>
            <Field label="Billing e-mail" hint="Receipts and renewal orders go here"><input type="email" value={det.billing_email} onChange={(e) => setDet({ ...det, billing_email: e.target.value })} maxLength={190} /></Field>
            <Field label="Billing phone"><input value={det.billing_phone} onChange={(e) => setDet({ ...det, billing_phone: e.target.value })} maxLength={20} /></Field>
            <Field label="State (for GST)"><select value={det.state_code} onChange={(e) => setDet({ ...det, state_code: e.target.value })}><option value="">Not set</option>{Object.entries(STATES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="GSTIN"><input value={det.gstin} onChange={(e) => setDet({ ...det, gstin: e.target.value.toUpperCase() })} maxLength={15} pattern="\d{2}[A-Z0-9]{13}" /></Field>
            <Field label="Billing address"><input value={det.address} onChange={(e) => setDet({ ...det, address: e.target.value })} maxLength={300} /></Field>
          </div>
          <div className="row"><button disabled={a.busy}>Save details</button><button type="button" className="secondary" onClick={() => setMode('')}>Cancel</button></div></form>}
        {mode === 'licence' && <form className="card" onSubmit={(e) => { e.preventDefault(); void a.run(async () => { const r: any = await post(`/companies/${id}/licence`, { ...lic, plan_id: Number(lic.plan_id), users: lic.users ? Number(lic.users) : null }); await done(r.order ? `Trial started. Order ${r.order.number} (${inr(r.order.total)}) raised: the subscription starts when it is paid (Orders & payments)` : 'Licence issued'); }); }}>
          <div className="form-grid">
            <Field label="Plan"><select value={lic.plan_id} onChange={(e) => setLic({ ...lic, plan_id: e.target.value })} required><option value="">Choose…</option>{(plans.data?.plans ?? []).filter((p) => p.active).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
            <Field label="Type"><select value={lic.kind} onChange={(e) => setLic({ ...lic, kind: e.target.value })}><option value="trial">Trial</option><option value="subscription">Subscription (trial until its order is paid)</option>{isSuper && <option value="internal">Internal (free)</option>}</select></Field>
            {lic.kind !== 'internal' && <Field label="Users" hint={lic.kind === 'trial' ? 'Blank = the trial default' : undefined}><input type="number" min={1} value={lic.users} onChange={(e) => setLic({ ...lic, users: e.target.value })} required={lic.kind === 'subscription'} /></Field>}
            {lic.kind === 'subscription' && <Field label="Period"><select value={lic.billing} onChange={(e) => setLic({ ...lic, billing: e.target.value })}>{Object.entries(CYCLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>}
            <Field label={lic.kind === 'internal' ? 'Reason for free access (licence history)' : 'Reason (audit log)'}><input value={lic.reason} onChange={(e) => setLic({ ...lic, reason: e.target.value })} required={lic.kind === 'internal'} /></Field>
          </div>
          <div className="row"><button disabled={a.busy}>Issue licence</button><button type="button" className="secondary" onClick={() => setMode('')}>Cancel</button></div></form>}
        {mode === 'password' && <form className="card" onSubmit={(e) => { e.preventDefault(); void a.run(async () => { const r: any = await post(`/companies/${id}/admin-password`, pw); setPw({ password: '', reason: '' }); await done(`New password set for ${r.email}`); }); }}>
          <Notice tone="warn">Sets a new password for this workspace's main admin. Tell them over a trusted channel and ask them to change it.</Notice>
          <div className="form-grid">
            <Field label="New password" hint="At least 10 characters, letters and numbers"><input type="text" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} minLength={10} required /></Field>
            <Field label="Reason (audit log)"><input value={pw.reason} onChange={(e) => setPw({ ...pw, reason: e.target.value })} required /></Field>
          </div>
          <div className="row"><button className="danger" disabled={a.busy}>Set password</button><button type="button" className="secondary" onClick={() => setMode('')}>Cancel</button></div></form>}
        <CompanyPrices id={id} />
        <h3 className="sub-h">Admins</h3>
        <Table rows={x.admins} empty="No admin accounts." columns={[{ key: 'name', label: 'Name' }, { key: 'email', label: 'E-mail' }, { key: 'status', label: 'Status', render: (u: any) => <Badge value={u.status} /> }, { key: 'last_login_at', label: 'Last sign-in', render: (u: any) => fmtDate(u.last_login_at) }]} />
        <h3 className="sub-h">Licences</h3>
        <Table rows={x.licences} onRow={(l: any) => setLicOpen(l.id)} empty="No licence: the workspace runs unrestricted until one is issued." columns={[
          { key: 'key', label: 'Key', render: (l: any) => <code>{l.key}</code> }, { key: 'plan', label: 'Plan', render: (l: any) => `${l.plan?.name ?? '—'} (${l.kind})` },
          { key: 'expires_at', label: 'Expires', render: (l: any) => (l.expires_at ? day(l.expires_at) : 'never') }, { key: 'status', label: 'Status', render: (l: any) => <Badge value={l.status} /> }]} />
        <h3 className="sub-h">Orders</h3>
        <Table rows={x.orders} empty="No orders." columns={[{ key: 'number', label: 'Order' }, { key: 'kind', label: 'Kind' }, { key: 'total', label: 'Total', align: 'right', render: (o: any) => inr(o.total) },
          { key: 'status', label: 'Status', render: (o: any) => <PayBadge order={o} /> }, { key: 'invoice_number', label: 'Invoice', render: (o: any) => o.invoice_number ?? '—' }]} />
      </>}
      {licOpen !== null && <LicenceDetail id={licOpen} can={can} isSuper={isSuper} onClose={() => { setLicOpen(null); void c.reload(); }} />}
    </Modal>
  );
}

function NewCompany({ isSuper, onClose, onDone, prefill }: { isSuper: boolean; onClose: () => void; onDone: (made?: any) => void; prefill?: { name?: string; admin_name?: string; admin_email?: string } }) {
  const plans = usePlans();
  const [f, setF] = useState({ name: prefill?.name ?? '', slug: prefill?.name ? slugFrom(prefill.name) : '', admin_name: prefill?.admin_name ?? '', admin_email: prefill?.admin_email ?? '', admin_password: '', plan_id: '', kind: 'trial', users: '', billing: 'annual', send_email: true, reason: '' });
  const [ownPassword, setOwnPassword] = useState(false);
  const [slugTouched, setSlugTouched] = useState(false);
  const [done, setDone] = useState<any | null>(null);
  const a = useAction();
  const set = (k: string, v: any) => setF((x) => ({ ...x, [k]: v }));
  const submit = (e: FormEvent) => {
    e.preventDefault();
    void a.run(async () => setDone(await post('/companies', { ...f, plan_id: Number(f.plan_id), users: f.users ? Number(f.users) : null, slug: f.slug || null, admin_password: ownPassword ? f.admin_password : null, send_email: ownPassword ? f.send_email : true })));
  };
  if (done) return (
    <Modal title="Company created" onClose={() => onDone(done)}>
      <Notice tone="ok">The workspace <b>{done.slug}</b> is ready.</Notice>
      <dl className="kv">
        <dt>Console address</dt><dd><a href={done.console_url} target="_blank" rel="noreferrer">{done.console_url}</a></dd>
        <dt>Workspace</dt><dd><code>{done.slug}</code></dd>
        <dt>Admin sign-in</dt><dd>{done.admin_email}</dd>
        <dt>Welcome e-mail</dt><dd>{done.email_sent ? (done.mail_ready ? `Sent to ${done.admin_email} with a link to set their password (valid 72 hours).` : 'Not delivered: e-mail sending is not set up (Settings → Outgoing e-mail). Share the link below instead.') : 'Not sent (you set the password yourself).'}</dd>
      </dl>
      {done.order && <><Notice tone="warn">Runs on a <b>trial</b> until order <b>{done.order.number}</b> ({inr(done.order.total)}) is paid. Pay link valid until {day(done.order.valid_until)}; or record the payment in Orders &amp; payments.</Notice>
        <div className="row"><input readOnly value={done.order.pay_url} onFocus={(e) => e.target.select()} aria-label="Pay link" />
          <button type="button" className="secondary" onClick={() => navigator.clipboard?.writeText(done.order.pay_url).then(() => toast('Pay link copied'), () => toast('Select the link and copy it', 'bad'))}>Copy pay link</button></div></>}
      {done.set_password_url && <><p className="muted small">Set-password link (works once, 72 hours). Share it only with {done.admin_email}:</p>
        <div className="row"><input readOnly value={done.set_password_url} onFocus={(e) => e.target.select()} aria-label="Set-password link" />
          <button type="button" className="secondary" onClick={() => navigator.clipboard?.writeText(done.set_password_url).then(() => toast('Link copied'), () => toast('Select the link and copy it', 'bad'))}>Copy</button></div></>}
      <div className="row" style={{ marginTop: 14 }}><button onClick={() => onDone(done)}>Done</button></div>
    </Modal>
  );
  return (
    <Modal title="New company" onClose={onClose}>
      <form onSubmit={submit}>
        <ErrorBox error={a.error} />
        <div className="form-grid">
          <Field label="Company name"><input value={f.name} onChange={(e) => { set('name', e.target.value); if (!slugTouched) set('slug', slugFrom(e.target.value)); }} required /></Field>
          <Field label="Workspace (web address)" hint={RESERVED.includes(f.slug) ? `"${f.slug}" is kept for ROVEXY's own addresses — use ${f.slug}-team or another name` : `Made from the company name; must be unique. Console: …/w/${f.slug || 'workspace'}/admin, or ${f.slug || 'workspace'}.<your domain> once the domain is set`}><input value={f.slug} onChange={(e) => { setSlugTouched(true); set('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '')); }} pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" placeholder="automatic" /></Field>
          <Field label="Admin name"><input value={f.admin_name} onChange={(e) => set('admin_name', e.target.value)} required /></Field>
          <Field label="Admin e-mail"><input type="email" value={f.admin_email} onChange={(e) => set('admin_email', e.target.value)} required /></Field>
          <div className="span-all">
            <label className="check"><input type="checkbox" checked={ownPassword} onChange={(e) => setOwnPassword(e.target.checked)} /> I'll set the admin's first password myself</label>
            {!ownPassword && <p className="muted small">The admin gets a welcome e-mail with the console address and a link to set their own password.</p>}
          </div>
          {ownPassword && <Field label="Admin password" hint="At least 10 characters, letters and numbers"><input type="text" value={f.admin_password} onChange={(e) => set('admin_password', e.target.value)} minLength={10} required /></Field>}
          {ownPassword && <label className="check"><input type="checkbox" checked={f.send_email} onChange={(e) => set('send_email', e.target.checked)} /> Also e-mail a welcome with a set-password link</label>}
          <Field label="Plan"><select value={f.plan_id} onChange={(e) => set('plan_id', e.target.value)} required><option value="">Choose…</option>{(plans.data?.plans ?? []).filter((p) => p.active).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
          <Field label="Licence type"><select value={f.kind} onChange={(e) => set('kind', e.target.value)}>
            <option value="trial">Trial</option><option value="subscription">Subscription (trial until its order is paid)</option>{isSuper && <option value="internal">Internal - free, no expiry, no user limit</option>}</select></Field>
          {f.kind !== 'internal' && <Field label="Users" hint={f.kind === 'trial' ? 'Blank = the trial default' : undefined}><input type="number" min={1} value={f.users} onChange={(e) => set('users', e.target.value)} required={f.kind === 'subscription'} /></Field>}
          {f.kind === 'subscription' && <Field label="Period"><select value={f.billing} onChange={(e) => set('billing', e.target.value)}>{Object.entries(CYCLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>}
          {f.kind === 'internal' && <Field label="Reason for free access" hint="Kept in the licence history"><input value={f.reason} onChange={(e) => set('reason', e.target.value)} maxLength={255} required /></Field>}
        </div>
        <p className="muted small">{f.kind === 'subscription' ? 'The workspace starts on a trial and an order is raised for this plan, users and period. The client pays with the pay link, or you record a bank / UPI / cheque payment in Orders & payments; only then the subscription starts, with the tax invoice and the entry in the books.' : 'A trial; the client buys from Plan & licence, or you raise an order on the licence.'}</p>
        <button disabled={a.busy} type="submit">Create company</button>
      </form>
    </Modal>
  );
}

// ── Licences ──
function Licences({ can, isSuper }: { can: boolean; isSuper: boolean }) {
  const [f, setF] = useState({ q: '', status: '', kind: '', deployment: '' });
  const [search, setSearch] = useState(f);
  useEffect(() => { const t = setTimeout(() => setSearch(f), 350); return () => clearTimeout(t); }, [f]);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [JSON.stringify(search)]);
  const list = useAsync(() => get(`/licences${qs({ ...search, page })}`), [JSON.stringify(search), page]);
  const [open, setOpen] = useState<number | null>(null);
  const [newOrder, setNewOrder] = useState(false);
  return (
    <Page title="Licences" subtitle="Cloud workspaces and on-premises installs: trial, subscription, perpetual or internal"
      actions={can ? <button onClick={() => setNewOrder(true)}>New on-premises order</button> : undefined}>
      <ErrorBox error={list.error} />
      <Card>
        <div className="filters filter-bar">
          <input type="search" placeholder="Key, holder or e-mail" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} aria-label="Search licences" />
          <select value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })} aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="expiring">Expiring in 30 days</option><option value="expired">Expired</option><option value="suspended">Suspended</option><option value="revoked">Revoked</option><option value="superseded">Superseded</option></select>
          <select value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })} aria-label="Type"><option value="">All types</option><option value="subscription">Subscription</option><option value="perpetual">Perpetual</option><option value="trial">Trial</option><option value="internal">Internal</option></select>
          <select value={f.deployment} onChange={(e) => setF({ ...f, deployment: e.target.value })} aria-label="Where"><option value="">Cloud and on-premises</option><option value="cloud">Cloud</option><option value="onprem">On-premises</option></select>
        </div>
        <Table rows={list.data?.data} onRow={(l: any) => setOpen(l.id)} empty="No licences match." columns={[
          { key: 'key', label: 'Key', render: (l: any) => <code>{l.key}</code> },
          { key: 'holder_name', label: 'Holder', render: (l: any) => <>{l.holder_name}<div className="muted small">{l.deployment === 'onprem' ? 'on-premises' : l.company?.slug ?? '—'}</div></> },
          { key: 'plan', label: 'Plan', render: (l: any) => <>{l.plan?.name ?? '—'}<div className="muted small">{LIC_KIND[l.kind] ?? l.kind}{l.billing ? ` · ${CYCLE[l.billing] ?? l.billing}` : ''}</div></> },
          { key: 'max_users', label: 'Users', align: 'right', render: (l: any) => l.max_users || 'unlimited' },
          { key: 'expires_at', label: 'Expires', render: (l: any) => <>{l.expires_at ? <span className={daysLeft(l.expires_at)! < 0 ? 'bad' : ''}>{day(l.expires_at)}</span> : 'never'}
            {l.kind === 'perpetual' && <div className={`small ${daysLeft(l.amc_expires_at)! < 0 ? 'bad' : 'muted'}`}>AMC {day(l.amc_expires_at)}</div>}</> },
          { key: 'status', label: 'Status', render: (l: any) => <Badge value={l.status} /> },
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
      {open != null && <LicenceDetail id={open} can={can} isSuper={isSuper} onClose={() => { setOpen(null); void list.reload(); }} />}
      {newOrder && <OrderBuilder isSuper={isSuper} onClose={() => setNewOrder(false)} onDone={() => { setNewOrder(false); void list.reload(); }} />}
    </Page>
  );
}

function LicenceDetail({ id, can, isSuper, onClose }: { id: number; can: boolean; isSuper: boolean; onClose: () => void }) {
  const l = useAsync(() => get(`/licences/${id}`), [id]);
  const plans = usePlans();
  const [edit, setEdit] = useState<any | null>(null);
  const [renew, setRenew] = useState(false);
  const [statusAsk, setStatusAsk] = useState<'' | 'suspended' | 'revoked'>('');
  const [op, setOp] = useState<any | null>(null);
  const [statusReason, setStatusReason] = useState('');
  const a = useAction();
  const x = l.data;
  const save = (body: any, msg = 'Licence saved') => a.run(async () => { l.setData(await patch(`/licences/${id}`, body)); setEdit(null); setStatusAsk(''); setStatusReason(''); toast(msg); });
  // Send only what was changed: re-sending the plan would reset custom features, re-sending the date would move the expiry.
  const startEdit = () => setEdit({ max_users: String(x.max_users), expires_at: x.expires_at ? new Date(new Date(x.expires_at).getTime() + 5.5 * 3600000).toISOString().slice(0, 10) : '',
    grace_days: String(x.grace_days), plan_id: '', features: [...(x.features ?? [])], notes: x.notes ?? '', reason: '' });
  const changes = () => {
    const orig = { max_users: String(x.max_users), grace_days: String(x.grace_days), notes: x.notes ?? '', expires_at: x.expires_at ? new Date(new Date(x.expires_at).getTime() + 5.5 * 3600000).toISOString().slice(0, 10) : '' };
    const b: any = { reason: edit.reason };
    if (edit.max_users !== orig.max_users) b.max_users = Number(edit.max_users);
    if (edit.grace_days !== orig.grace_days) b.grace_days = Number(edit.grace_days);
    if (edit.notes !== orig.notes) b.notes = edit.notes || null;
    if (edit.expires_at !== orig.expires_at) b.expires_at = edit.expires_at || null;
    if (edit.plan_id) b.plan_id = Number(edit.plan_id);
    if (!edit.plan_id && JSON.stringify([...edit.features].sort()) !== JSON.stringify([...(x.features ?? [])].sort())) b.features = edit.features;
    return b;
  };
  return (
    <Modal title={x ? `Licence ${x.key}` : 'Licence'} onClose={onClose}>
      <ErrorBox error={l.error ?? a.error} />
      {!x ? <p className="muted">Loading…</p> : <>
        <div className="grid-2">
          <div><p><b>{x.holder_name}</b>{x.holder_email ? `, ${x.holder_email}` : ''}</p>
            <p>{x.deployment === 'onprem' ? 'On-premises install' : `Workspace ${x.company?.slug ?? '—'}`} · {x.plan?.name ?? '—'} · {LIC_KIND[x.kind] ?? x.kind}{x.billing ? `, ${CYCLE[x.billing] ?? x.billing} billing` : ''}</p>
            <p>Users: {x.used_users != null ? `${x.used_users} active of ` : x.reported_users != null ? `${x.reported_users} reported of ` : ''}{x.max_users || 'unlimited'} · Grace {x.grace_days} days</p>
            <p>Valid {day(x.starts_at)} – {x.expires_at ? day(x.expires_at) : 'no expiry'} · <Badge value={x.status} /></p>
            {x.kind === 'perpetual' && <p>Updates &amp; support (AMC) until <b className={daysLeft(x.amc_expires_at)! < 0 ? 'bad' : ''}>{day(x.amc_expires_at)}</b></p>}
            {x.deployment === 'onprem' && <p className="small">Server: {x.server_fingerprint ? <code>{x.server_fingerprint}</code> : <span className="muted">not activated yet</span>}
              {x.last_validated_at && <span className="muted"> · last check-in {fmtDate(x.last_validated_at)}</span>}</p>}
            {x.notes && <p className="muted small">{x.notes}</p>}
          </div>
          {can && <div className="row" style={{ alignItems: 'flex-start', flexWrap: 'wrap', gap: 8 }}>
            {x.kind !== 'internal' && ['active', 'suspended'].includes(x.status) && <button onClick={() => setRenew(true)}>New order…</button>}
            {x.status !== 'revoked' && x.status !== 'superseded' && <button className="secondary" onClick={startEdit}>Edit</button>}
            {x.status === 'active' ? <button className="secondary" disabled={a.busy} onClick={() => setStatusAsk('suspended')}>Suspend</button>
              : x.status === 'suspended' ? <button className="secondary" disabled={a.busy} onClick={() => void save({ status: 'active', reason: 'resumed' }, 'Licence resumed')}>Resume</button> : null}
            {isSuper && ['active', 'suspended'].includes(x.status) && <button className="ghost bad-text" onClick={() => setStatusAsk('revoked')}>Revoke…</button>}
            {x.status === 'active' && x.kind !== 'trial' && <button className="secondary" onClick={() => setOp({ kind: 'file', fingerprint: x.server_fingerprint ?? '', reason: '' })}>Licence file…</button>}
            {x.deployment === 'onprem' && x.status === 'active' && <>
              {x.server_fingerprint && <button className="secondary" onClick={() => setOp({ kind: 'release', reason: '' })}>Release server</button>}
              <button className="secondary" onClick={() => setOp({ kind: 'bind', fingerprint: '', reason: '' })}>Move to another server…</button>
              {isSuper && x.kind === 'perpetual' && <button className="ghost" onClick={() => setOp({ kind: 'amc', reason: '' })}>Extend AMC free…</button>}
            </>}
          </div>}
        </div>
        {op && <form className="card" onSubmit={(e) => { e.preventDefault(); void a.run(async () => {
          if (op.kind === 'file') { await download(`/licences/${id}/file`, { fingerprint: op.fingerprint || null }); toast('Licence file downloaded'); }
          else { const r: any = await post(`/licences/${id}/${op.kind}`, { reason: op.reason, fingerprint: op.fingerprint }); toast(r.warning ?? 'Saved', r.warning ? 'bad' : 'ok'); }
          setOp(null); await l.reload(); }); }}>
          {op.kind === 'file' && <Notice>{x.deployment === 'onprem' ? 'For a server without internet. Ask the customer for the server ID shown on their Plan & licence page; with it the file works on that server only.'
            : 'Ask the customer for the fingerprint on their workspace\'s Licence page. The file activates this licence in that workspace only.'}</Notice>}
          {op.kind === 'release' && <Notice tone="warn">The next server that checks in with this key becomes the licensed one.</Notice>}
          {(op.kind === 'file' || op.kind === 'bind') && <Field label={x.deployment === 'onprem' ? 'Server ID' : 'Workspace fingerprint'} hint="40 characters, from the customer's Licence page"><input value={op.fingerprint} onChange={(e) => setOp({ ...op, fingerprint: e.target.value.trim().toLowerCase() })}
            pattern="[a-f0-9]{40,64}" required={op.kind === 'bind' || x.deployment !== 'onprem'} /></Field>}
          {op.kind !== 'file' && <Field label="Reason (audit log)"><input value={op.reason} onChange={(e) => setOp({ ...op, reason: e.target.value })} required autoFocus /></Field>}
          <div className="row"><button disabled={a.busy}>{({ file: 'Download licence file', release: 'Release server', bind: 'Move licence', amc: 'Add 12 months of AMC' } as any)[op.kind]}</button>
            <button type="button" className="secondary" onClick={() => setOp(null)}>Cancel</button></div>
        </form>}
        {statusAsk && <form className="card" onSubmit={(e) => { e.preventDefault(); void save({ status: statusAsk, reason: statusReason }, statusAsk === 'revoked' ? 'Licence revoked' : 'Licence suspended'); }}>
          {statusAsk === 'revoked' && <Notice tone="warn">Revoking ends this licence for good: the workspace can no longer sign in under it. Use Suspend for a temporary stop.</Notice>}
          <Field label={statusAsk === 'revoked' ? 'Reason for revoking' : 'Reason for suspending'}><input value={statusReason} onChange={(e) => setStatusReason(e.target.value)} required autoFocus /></Field>
          <div className="row"><button className="danger" disabled={a.busy}>{statusAsk === 'revoked' ? 'Revoke licence' : 'Suspend licence'}</button><button type="button" className="secondary" onClick={() => setStatusAsk('')}>Cancel</button></div>
        </form>}
        {edit && <form className="card" onSubmit={(e) => { e.preventDefault(); void save(changes()); }}>
          <div className="form-grid">
            <Field label="Users allowed" hint="0 = unlimited (super admin only)"><input type="number" min={0} value={edit.max_users} onChange={(e) => setEdit({ ...edit, max_users: e.target.value })} /></Field>
            <Field label="Expires on"><input type="date" value={edit.expires_at} onChange={(e) => setEdit({ ...edit, expires_at: e.target.value })} /></Field>
            <Field label="Grace days"><input type="number" min={0} max={90} value={edit.grace_days} onChange={(e) => setEdit({ ...edit, grace_days: e.target.value })} /></Field>
            <Field label="Plan (sets features)"><select value={edit.plan_id} onChange={(e) => setEdit({ ...edit, plan_id: e.target.value })}><option value="">Keep current</option>{(plans.data?.plans ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
            <Field label="Notes"><input value={edit.notes} onChange={(e) => setEdit({ ...edit, notes: e.target.value })} /></Field>
            <Field label="Reason for the change"><input value={edit.reason} onChange={(e) => setEdit({ ...edit, reason: e.target.value })} required /></Field>
          </div>
          {!edit.plan_id && <fieldset className="card"><legend>Features on this licence</legend>
            {Object.entries(plans.data?.features ?? {}).map(([k, label]) => <label key={k} className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={edit.features.includes(k)}
              onChange={() => setEdit({ ...edit, features: edit.features.includes(k) ? edit.features.filter((f: string) => f !== k) : [...edit.features, k] })} />{String(label)}</label>)}
          </fieldset>}
          <div className="row"><button disabled={a.busy} type="submit">Save changes</button><button type="button" className="secondary" onClick={() => setEdit(null)}>Cancel</button></div>
        </form>}
        {renew && <OrderBuilder licence={x} isSuper={isSuper} onClose={() => setRenew(false)} onDone={() => { setRenew(false); void l.reload(); }} />}
        <h3>Orders</h3>
        <Table rows={x.orders} empty="No orders on this licence." columns={[{ key: 'number', label: 'Order', render: (o: any) => <>{o.number}<div className="muted small">{o.kind}{o.users ? ` · ${o.users} users` : ''}</div></> },
          { key: 'total', label: 'Total', align: 'right', render: (o: any) => inr(o.total) }, { key: 'status', label: 'Status', render: (o: any) => <PayBadge order={o} /> }, { key: 'invoice_number', label: 'Invoice' }]} />
        <h3>History</h3>
        <Table rows={x.events} empty="No history." columns={[
          { key: 'created_at', label: 'When', render: (e: any) => fmtDate(e.created_at) }, { key: 'type', label: 'Event', render: (e: any) => e.type.replace(/_/g, ' ') },
          { key: 'data', label: 'Details', render: (e: any) => <span className="small">{historyText(e.data)}</span> }, { key: 'actor', label: 'By', render: (e: any) => e.actor ?? 'system' },
        ]} />
      </>}
    </Modal>
  );
}

const LIC_FIELD: Record<string, string> = { max_users: 'users', expires_at: 'expiry', grace_days: 'grace days', plan_id: 'plan', features: 'features', status: 'status', billing: 'billing', amc_expires_at: 'AMC until' };
function historyText(d: any): string {
  if (!d) return '';
  const parts: string[] = [];
  if (d.before && d.after) {
    for (const k of Object.keys(d.after)) {
      const b = JSON.stringify(d.before[k]); const a = JSON.stringify(d.after[k]);
      if (b !== a) parts.push(`${LIC_FIELD[k] ?? k}: ${k === 'expires_at' ? `${d.before[k] ? day(d.before[k]) : 'none'} → ${d.after[k] ? day(d.after[k]) : 'never'}` : k === 'features' ? `${(d.before[k] ?? []).length} → ${(d.after[k] ?? []).length}` : `${d.before[k] ?? '—'} → ${d.after[k] ?? '—'}`}`);
    }
  }
  if (d.reason) parts.push(`reason: ${d.reason}`);
  if (!parts.length && d.plan) parts.push(`plan ${d.plan}`);
  if (!parts.length && d.expires_at) parts.push(`until ${d.expires_at}`);
  return parts.join(' · ');
}

/** Renewal / upgrade order for a workspace's licence. The client pays via the link (Razorpay) or staff record the payment. */
const KIND_LABEL: Record<string, string> = { new: 'New licence', upgrade: 'Trial → first package', renewal: 'Renewal (next period)', seats: 'More users now (rest of the term)', amc: 'AMC — 12 more months of updates & support' };
const LIC_KIND: Record<string, string> = { trial: 'Trial', subscription: 'Subscription', perpetual: 'Perpetual', internal: 'Internal', package: 'Subscription' };
const CYCLE: Record<string, string> = { annual: 'Annual', half_yearly: 'Half-yearly', quarterly: 'Quarterly' };

/**
 * Staff order: a new on-prem licence (or a first cloud licence for an existing workspace), or on a licence: trial → paid,
 * renewal, more users, AMC. Every number comes from the server (POST /orders/preview = the same pricing as the order).
 */
function OrderBuilder({ licence, isSuper, onClose, onDone }: { licence?: any; isSuper: boolean; onClose: () => void; onDone: () => void }) {
  const plans = usePlans();
  const kinds = !licence ? ['new'] : licence.kind === 'trial' ? ['upgrade'] : licence.kind === 'perpetual' ? ['seats', 'amc'] : ['renewal', 'seats'];
  const [f, setF] = useState<any>(() => ({ kind: kinds[0], deployment: licence?.deployment ?? 'onprem', licence_kind: licence ? (licence.kind === 'perpetual' ? 'perpetual' : 'subscription') : 'perpetual',
    company_id: '', plan_id: licence?.plan_id ? String(licence.plan_id) : '', users: String(licence?.max_users || 10), billing: licence?.billing ?? 'annual', setup: 'auto', setup_amount: '', custom_amount: '',
    buyer_company: licence?.holder_name ?? '', buyer_name: '', buyer_email: licence?.holder_email ?? '', buyer_phone: '', buyer_gstin: '', state_code: '36', coupon: '' }));
  const companies = useAsync(() => (licence ? Promise.resolve([]) : get<any>('/companies?per_page=500').then((r) => r.data ?? r)), []);
  const [quote, setQuote] = useState<any | null>(null);
  const [qErr, setQErr] = useState<string | null>(null);
  const [made, setMade] = useState<any | null>(null);
  const a = useAction();
  const set = (k: string, v: string) => setF((x: any) => ({ ...x, [k]: v }));
  const body = () => ({ kind: f.kind, licence_id: licence?.id ?? null, company_id: f.deployment === 'cloud' && f.company_id ? Number(f.company_id) : null,
    deployment: licence ? undefined : f.deployment, licence_kind: licence ? undefined : (f.deployment === 'cloud' ? 'subscription' : f.licence_kind),
    plan_id: f.plan_id ? Number(f.plan_id) : null, users: f.kind === 'amc' ? undefined : Number(f.users), billing: f.licence_kind === 'subscription' || f.deployment === 'cloud' ? f.billing : undefined,
    setup: f.setup === 'auto' ? null : f.setup === 'none' ? 'none' : Number(f.setup_amount), custom_amount: f.custom_amount ? Number(f.custom_amount) : null,
    coupon: f.coupon || null, buyer_company: f.buyer_company, buyer_name: f.buyer_name, buyer_email: f.buyer_email, buyer_phone: f.buyer_phone || null, buyer_gstin: f.buyer_gstin || null, state_code: f.state_code });
  useEffect(() => {
    setQErr(null);
    if (!f.plan_id && !licence) { setQuote(null); return; }
    const t = setTimeout(() => { post('/orders/preview', body()).then(setQuote, (e) => { setQuote(null); setQErr(e.message); }); }, 300);
    return () => clearTimeout(t);
  }, [JSON.stringify(f)]);
  if (made) return <Modal title={`Order ${made.number}`} onClose={onDone}><p>Total <b>{inr(made.total)}</b> including GST, valid until {day(made.valid_until)}. Send the client this payment link:</p>
    <p><a href={made.pay_url} target="_blank" rel="noreferrer">{made.pay_url}</a></p><p className="muted">Paid by bank transfer instead? Use "Record payment" on the order. The licence changes only when it is paid.</p><button onClick={onDone}>Done</button></Modal>;
  const sub = f.deployment === 'cloud' || f.licence_kind === 'subscription';
  return (
    <Modal title={licence ? `Order for ${licence.key}` : 'New order'} onClose={onClose}>
      <form onSubmit={(e) => { e.preventDefault(); void a.run(async () => setMade(await post('/orders', body()))); }}>
        <ErrorBox error={a.error} />
        <div className="form-grid">
          <Field label="Order"><select value={f.kind} onChange={(e) => set('kind', e.target.value)}>{kinds.map((k) => <option key={k} value={k}>{KIND_LABEL[k]}</option>)}</select></Field>
          {!licence && <Field label="Where it runs"><select value={f.deployment} onChange={(e) => set('deployment', e.target.value)}><option value="onprem">On-premises (customer's server)</option><option value="cloud">Cloud (workspace, or a key the customer adds)</option></select></Field>}
          {!licence && f.deployment === 'onprem' && <Field label="Licence type"><select value={f.licence_kind} onChange={(e) => set('licence_kind', e.target.value)}><option value="perpetual">Perpetual (one-time + AMC)</option><option value="subscription">Subscription</option></select></Field>}
          {!licence && f.deployment === 'cloud' && <Field label="Workspace"><select value={f.company_id} onChange={(e) => { const c = (companies.data ?? []).find((x: any) => String(x.id) === e.target.value); setF((x: any) => ({ ...x, company_id: e.target.value, buyer_company: c?.name ?? x.buyer_company })); }} required>
            <option value="">Choose…</option>{(companies.data ?? []).map((c: any) => <option key={c.id} value={c.id}>{c.name} ({c.slug})</option>)}</select></Field>}
          {f.kind !== 'amc' && <Field label="Plan"><select value={f.plan_id} onChange={(e) => set('plan_id', e.target.value)} required={!licence}><option value="">{licence ? 'Keep current' : 'Choose…'}</option>{(plans.data?.plans ?? []).filter((p) => p.active).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>}
          {f.kind !== 'amc' && <Field label={f.kind === 'seats' ? `Users after this order (now ${licence?.max_users})` : 'Users'}><input type="number" min={1} value={f.users} onChange={(e) => set('users', e.target.value)} required /></Field>}
          {sub && ['new', 'upgrade', 'renewal'].includes(f.kind) && <Field label="Billing"><select value={f.billing} onChange={(e) => set('billing', e.target.value)}>{Object.entries(CYCLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>}
          {['new', 'upgrade'].includes(f.kind) && <Field label="Setup & onboarding" hint={isSuper ? undefined : 'Changing it is super-admin only'}><select value={f.setup} onChange={(e) => set('setup', e.target.value)} disabled={!isSuper}>
            <option value="auto">As per pricing rules</option><option value="none">No setup fee</option><option value="custom">Custom amount</option></select></Field>}
          {f.setup === 'custom' && <Field label="Setup amount (₹)"><input type="number" min={1} value={f.setup_amount} onChange={(e) => set('setup_amount', e.target.value)} required /></Field>}
          {isSuper && <Field label="Licence price (₹, optional)" hint="Replaces the price list — for custom quotes"><input type="number" min={1} value={f.custom_amount} onChange={(e) => set('custom_amount', e.target.value)} /></Field>}
          <Field label="Company"><input value={f.buyer_company} onChange={(e) => set('buyer_company', e.target.value)} required /></Field>
          <Field label="Contact name"><input value={f.buyer_name} onChange={(e) => set('buyer_name', e.target.value)} required /></Field>
          <Field label="Contact e-mail" hint={!licence && f.deployment === 'onprem' ? 'The licence key is e-mailed here' : undefined}><input type="email" value={f.buyer_email} onChange={(e) => set('buyer_email', e.target.value)} required /></Field>
          <Field label="Phone"><input value={f.buyer_phone} onChange={(e) => set('buyer_phone', e.target.value)} /></Field>
          <Field label="State (for GST)"><select value={f.state_code} onChange={(e) => set('state_code', e.target.value)}>{Object.entries(STATES).map(([c, n]) => <option key={c} value={c}>{n}</option>)}</select></Field>
          <Field label="GSTIN"><input value={f.buyer_gstin} onChange={(e) => set('buyer_gstin', e.target.value.toUpperCase())} maxLength={15} /></Field>
          {['new', 'upgrade', 'renewal'].includes(f.kind) && <Field label="Discount code"><input value={f.coupon} onChange={(e) => set('coupon', e.target.value.toUpperCase())} maxLength={30} /></Field>}
        </div>
        {qErr && <Notice tone="bad">{qErr}</Notice>}
        {quote && <table className="quote"><tbody>
          {quote.lines.map((l: any, i: number) => <tr key={i}><td>{l.label}</td><td>{l.amount < 0 ? '−' : ''}{inr(Math.abs(l.amount))}</td></tr>)}
          {quote.cgst > 0 && <><tr><td>CGST</td><td>{inr(quote.cgst)}</td></tr><tr><td>SGST</td><td>{inr(quote.sgst)}</td></tr></>}
          {quote.igst > 0 && <tr><td>IGST</td><td>{inr(quote.igst)}</td></tr>}
          <tr className="t"><td>Total</td><td>{inr(quote.total)}</td></tr>
        </tbody>{quote.pricing === 'custom' && <caption>Negotiated / special price (standard {inr(quote.list_price)}).</caption>}</table>}
        <button disabled={a.busy || !quote} type="submit">Create order</button>
      </form>
    </Modal>
  );
}

function Orders({ can }: { can: boolean }) {
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  useEffect(() => { const t = setTimeout(() => setSearch(q), 350); return () => clearTimeout(t); }, [q]);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [status, search]);
  const list = useAsync(() => get(`/orders${qs({ status, q: search, page })}`), [status, search, page]);
  const [pay, setPay] = useState<any | null>(null);
  const [cancel, setCancel] = useState<any | null>(null);
  const [reason, setReason] = useState('');
  const a = useAction();
  const csvA = useAction();
  const copy = (url: string) => { navigator.clipboard?.writeText(url).then(() => toast('Pay link copied'), () => toast('Could not copy: select the link and copy it', 'bad')); };
  const [csv, setCsv] = useState(() => { const t = new Date(); const fy = t.getMonth() >= 3 ? t.getFullYear() : t.getFullYear() - 1; return { from: `${fy}-04-01`, to: t.toISOString().slice(0, 10) }; });
  return (
    <Page title="Orders & payments" subtitle="Website purchases, renewals, more users, AMC and on-premises licences. Orders on an existing licence are raised from Licences → the licence."
      actions={<form className="row" style={{ gap: 6 }} onSubmit={(e) => { e.preventDefault(); void csvA.run(async () => { await download(`/orders.csv${qs(csv)}`); toast('Invoices CSV downloaded'); }); }}>
        <input type="date" value={csv.from} onChange={(e) => setCsv({ ...csv, from: e.target.value })} aria-label="From" required />
        <input type="date" value={csv.to} onChange={(e) => setCsv({ ...csv, to: e.target.value })} aria-label="To" required />
        <button className="secondary" type="submit" disabled={csvA.busy}>Invoices CSV</button></form>}>
      <ErrorBox error={list.error ?? a.error ?? csvA.error} />
      <Card>
        <div className="filters filter-bar">
          <input type="search" placeholder="Order, invoice, company or e-mail" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search orders" />
          <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status"><option value="">All</option><option value="created">Unpaid (all)</option><option value="pending">Waiting for payment</option><option value="failed">Payment failed</option><option value="paid">Paid</option><option value="cancelled">Cancelled</option></select>
        </div>
        <Table rows={list.data?.data} empty="No orders yet." columns={[
          { key: 'number', label: 'Order', render: (o: any) => <>{o.number}<div className="muted small">{day(o.created_at)} · {o.kind} · {o.created_by ?? 'website'}</div></> },
          { key: 'buyer_company', label: 'Buyer', render: (o: any) => <>{o.buyer_company}<div className="muted small">{o.buyer_email}</div></> },
          { key: 'plan', label: 'Plan', render: (o: any) => <>{o.plan?.name ?? ''}{o.coupon_code && <div className="muted small">{o.coupon_code}: −{inr(o.discount)}</div>}
            {o.pricing === 'custom' && <div className="muted small"><Badge value="pending" label="negotiated" /> standard {inr(o.list_price)}</div>}</> },
          { key: 'total', label: 'Total', align: 'right', render: (o: any) => inr(o.total) },
          { key: 'status', label: 'Status', render: (o: any) => <><PayBadge order={o} />
            {o.status === 'paid' && <div className="muted small">{o.invoice_number} · {o.payment_method}{o.payment_ref ? ` · ${o.payment_ref}` : ''}</div>}
            {o.pay_state === 'failed' && <div className="muted small">{o.failure_reason}</div>}
            {o.status === 'cancelled' && o.cancel_reason && <div className="muted small">{o.cancel_reason}</div>}
            {o.attempts > 0 && o.status !== 'paid' && <div className="muted small">{o.attempts} payment attempt{o.attempts === 1 ? '' : 's'}</div>}</> },
          { key: 'act', label: '', render: (o: any) => <div className="row">
            {o.status === 'created' && <button className="ghost small" onClick={() => copy(o.pay_url)} title={o.pay_url}>Copy pay link</button>}
            {o.status === 'created' && can && <button className="ghost small" onClick={() => setPay(o)}>Record payment</button>}
            {o.status === 'created' && can && <button className="ghost small" onClick={() => setCancel(o)}>Cancel</button>}
            {o.invoice_url && <a className="ghost small" href={o.invoice_url} target="_blank" rel="noreferrer">Invoice</a>}
            {o.status !== 'paid' && can && <DeleteButton what="order" path={`/orders/${o.id}`} onDone={() => void list.reload()} />}
          </div> },
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
      {cancel && <Modal title={`Cancel order ${cancel.number}?`} onClose={() => { setCancel(null); setReason(''); }}>
        <p>{cancel.buyer_company} · {inr(cancel.total)}. The pay link stops working. If the client already paid at the gateway, that payment is still honoured.</p>
        <Field label="Reason (shown to the client and in the audit log)"><input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={200} placeholder="e.g. Customer asked to cancel" /></Field>
        <div className="row"><button className="danger" disabled={a.busy} onClick={() => void a.run(async () => { await post(`/orders/${cancel.id}/cancel`, { reason }); toast(`Order ${cancel.number} cancelled`); setCancel(null); setReason(''); await list.reload(); })}>Cancel order</button>
          <button className="secondary" onClick={() => { setCancel(null); setReason(''); }}>Keep it</button></div>
      </Modal>}
      {pay && <RecordPayment order={pay} onClose={() => setPay(null)} onDone={() => { setPay(null); toast('Payment recorded: invoice issued and licence updated'); void list.reload(); }} />}
    </Page>
  );
}

function RecordPayment({ order, onClose, onDone }: { order: any; onClose: () => void; onDone: () => void }) {
  const [method, setMethod] = useState('bank');
  const [reference, setReference] = useState('');
  const a = useAction();
  return (
    <Modal title={`Record payment · ${order.number}`} onClose={onClose}>
      <ErrorBox error={a.error} />
      <p>{order.buyer_company} pays <b>{inr(order.total)}</b>. Recording it issues the tax invoice and the licence straight away, and e-mails the client.</p>
      <div className="form-grid">
        <Field label="Paid by"><select value={method} onChange={(e) => setMethod(e.target.value)}><option value="bank">Bank transfer (NEFT / RTGS / IMPS)</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="cash">Cash</option></select></Field>
        <Field label="Reference (UTR / cheque no.)"><input value={reference} onChange={(e) => setReference(e.target.value)} required /></Field>
      </div>
      <button disabled={a.busy || !reference.trim()} onClick={() => void a.run(async () => { await post(`/orders/${order.id}/payment`, { method, reference }); onDone(); })}>Record {inr(order.total)} received</button>
    </Modal>
  );
}

// ── Leads ──
const LEAD_STATUSES = ['new', 'contacted', 'demo', 'quoted', 'won', 'lost'];
function Leads({ can, isSuper, canCompanies }: { can: boolean; isSuper: boolean; canCompanies: boolean }) {
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  useEffect(() => { const t = setTimeout(() => setSearch(q), 350); return () => clearTimeout(t); }, [q]);
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [status, search]);
  const list = useAsync(() => get(`/leads${qs({ status, q: search, page })}`), [status, search, page]);
  const [open, setOpen] = useState<any | null>(null);
  const [convert, setConvert] = useState<any | null>(null);
  const today = new Date(Date.now() + 5.5 * 3600000).toISOString().slice(0, 10);
  return (
    <Page title="Leads" subtitle="Website enquiries and trial signups; follow-ups due come first" actions={can ? <button onClick={() => setOpen({ status: 'new' })}>Add lead</button> : undefined}>
      <ErrorBox error={list.error} />
      <Card>
        <div className="filters filter-bar">
          <input type="search" placeholder="Name, company, e-mail or phone" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search leads" />
          <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status"><option value="">All statuses</option>{LEAD_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}</select>
        </div>
        <Table rows={list.data?.data} error={list.error} onRow={setOpen} empty="No leads yet. Website enquiries and trials appear here." columns={[
          { key: 'name', label: 'Lead', render: (l: any) => <><strong>{l.name}</strong><div className="muted small">{l.company ?? ''}</div></> },
          { key: 'contact', label: 'Contact', render: (l: any) => <span className="small">{[l.phone, l.email].filter(Boolean).join(' · ')}</span> },
          { key: 'source', label: 'Source' }, { key: 'users', label: 'Users', align: 'right', render: (l: any) => l.users ?? '—' },
          { key: 'status', label: 'Status', render: (l: any) => <Badge value={l.status === 'won' ? 'completed' : l.status === 'lost' ? 'cancelled' : 'pending'} label={l.status} /> },
          { key: 'follow_up_on', label: 'Follow-up', render: (l: any) => l.follow_up_on ? <span className={l.follow_up_on <= today ? 'bad' : ''}>{day(l.follow_up_on)}</span> : '—' },
          { key: 'created_at', label: 'Received', render: (l: any) => day(l.created_at) },
          ...(can ? [{ key: 'act', label: '', render: (l: any) => <DeleteButton what="lead" path={`/leads/${l.id}`} onDone={() => { toast('Lead deleted'); void list.reload(); }} /> }] : []),
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
      {open && <LeadModal lead={open} readOnly={!can} canConvert={canCompanies && !!open.id && open.status !== 'won'} onConvert={() => { setConvert(open); setOpen(null); }}
        onClose={() => setOpen(null)} onDone={() => { setOpen(null); toast('Lead saved'); void list.reload(); }} />}
      {convert && <NewCompany isSuper={isSuper} prefill={{ name: convert.company || convert.name, admin_name: convert.name, admin_email: convert.email ?? '' }} onClose={() => setConvert(null)}
        onDone={(made) => { const l = convert; setConvert(null); if (made) void patch(`/leads/${l.id}`, { status: 'won', notes: `${l.notes ? l.notes + '\n' : ''}Workspace created.` }).then(() => { toast('Workspace created and lead marked won'); void list.reload(); }); }} />}
    </Page>
  );
}

function LeadModal({ lead, onClose, onDone, readOnly = false, canConvert = false, onConvert }: { lead: any; onClose: () => void; onDone: () => void; readOnly?: boolean; canConvert?: boolean; onConvert?: () => void }) {
  const [f, setF] = useState({ name: lead.name ?? '', company: lead.company ?? '', email: lead.email ?? '', phone: lead.phone ?? '', users: lead.users ?? '', status: lead.status ?? 'new', notes: lead.notes ?? '', follow_up_on: lead.follow_up_on ?? '' });
  const a = useAction();
  const set = (k: string, v: string) => setF((x) => ({ ...x, [k]: v }));
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { ...f, users: f.users ? Number(f.users) : null, email: f.email || null, follow_up_on: f.follow_up_on || null };
    void a.run(async () => { await (lead.id ? patch(`/leads/${lead.id}`, body) : post('/leads', body)); onDone(); });
  };
  return (
    <Modal title={lead.id ? lead.name : 'Add lead'} onClose={onClose}>
      <form onSubmit={submit}>
        <ErrorBox error={a.error} />
        {lead.message && <Notice>{lead.message}</Notice>}
        {readOnly && <Notice tone="info">Read-only: your role can view leads but not change them.</Notice>}
        <fieldset disabled={readOnly} className="plain">
        <div className="form-grid">
          <Field label="Name"><input value={f.name} onChange={(e) => set('name', e.target.value)} required /></Field>
          <Field label="Company"><input value={f.company} onChange={(e) => set('company', e.target.value)} /></Field>
          <Field label="Phone"><input value={f.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
          <Field label="E-mail"><input type="email" value={f.email} onChange={(e) => set('email', e.target.value)} /></Field>
          <Field label="Users"><input type="number" min={1} value={f.users} onChange={(e) => set('users', e.target.value)} /></Field>
          <Field label="Status"><select value={f.status} onChange={(e) => set('status', e.target.value)}>{LEAD_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}</select></Field>
          <Field label="Follow up on"><input type="date" value={f.follow_up_on} onChange={(e) => set('follow_up_on', e.target.value)} /></Field>
        </div>
        <Field label="Notes"><textarea rows={4} value={f.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
        </fieldset>
        <div className="row">
          {!readOnly && <button disabled={a.busy} type="submit">Save lead</button>}
          {canConvert && <button type="button" className="secondary" onClick={onConvert}>Create workspace for this lead</button>}
        </div>
      </form>
    </Modal>
  );
}

// Two-step delete inside a table row: no browser pop-up; the server refuses anything that must be kept and says why.
function DeleteButton({ what, path, onDone }: { what: string; path: string; onDone: () => void }) {
  const [armed, setArmed] = useState(false);
  const a = useAction();
  if (!armed) return <button type="button" className="ghost small bad-text" onClick={(e) => { e.stopPropagation(); setArmed(true); }}>Delete</button>;
  return (
    <span className="row" style={{ gap: 6, flexWrap: 'wrap', maxWidth: 320 }} onClick={(e) => e.stopPropagation()}>
      <button type="button" className="danger small" disabled={a.busy} onClick={() => void a.run(async () => { await del(path); onDone(); })}>{a.busy ? 'Deleting…' : `Delete ${what}`}</button>
      <button type="button" className="ghost small" onClick={() => { setArmed(false); }}>Keep</button>
      {a.error && <span className="bad-text small" role="alert">{a.error}</span>}
    </span>
  );
}

// ── Plans ──
const bandText = (rows: any[], key: string, unit: string) => {
  const priced = (rows ?? []).filter((r) => r.max_users && Number(r[key]) > 0);
  if (!priced.length) return 'quotation';
  const lo = Math.min(...priced.map((r) => Number(r[key]))), hi = Math.max(...priced.map((r) => Number(r[key])));
  return `${inr(lo)}${lo !== hi ? ` – ${inr(hi)}` : ''}${unit} · up to ${Math.max(...priced.map((r) => r.max_users))} users`;
};
function Plans({ can }: { can: boolean }) {
  const plans = usePlans();
  const [edit, setEdit] = useState<any | null>(null);
  const features = plans.data?.features ?? {};
  const blank = { code: '', name: '', tagline: '', min_users: 5, features: Object.keys(features), is_public: true, active: true, sort: 10,
    cloud_tiers: [{ min_users: 5, max_users: 25, rate_inr: '' }, { min_users: 26, max_users: '', rate_inr: '' }], perpetual_bands: [{ min_users: 5, max_users: 10, price_inr: '' }, { min_users: 11, max_users: '', price_inr: '' }] };
  return (
    <Page title="Plans & pricing" subtitle="Each plan is a feature set with two price lists: cloud ₹ per user per month by team size, and on-premises one-time price by team size. Prices exclude GST."
      actions={can ? <button onClick={() => setEdit(blank)}>New plan</button> : undefined}>
      <ErrorBox error={plans.error} />
      {!can && <Notice>Only a super admin can change plans and prices.</Notice>}
      <Card>
        <Table rows={plans.data?.plans} onRow={can ? (p: any) => setEdit(p) : undefined} columns={[
          { key: 'name', label: 'Plan', render: (p: any) => <><strong>{p.name}</strong> <span className="muted small">{p.code}</span><div className="muted small">{p.tagline}</div></> },
          { key: 'cloud', label: 'Cloud (per user / month, annual)', render: (p: any) => <span className="small">{bandText(p.cloud_tiers, 'rate_inr', '')}</span> },
          { key: 'perp', label: 'On-premises (one-time)', render: (p: any) => <span className="small">{bandText(p.perpetual_bands, 'price_inr', '')}</span> },
          { key: 'min_users', label: 'From', align: 'right', render: (p: any) => `${p.min_users} users` },
          { key: 'features', label: 'Features', render: (p: any) => <span className="small">{p.features.length} of {Object.keys(features).length}</span> },
          { key: 'active', label: 'Status', render: (p: any) => <Badge value={p.active ? 'active' : 'cancelled'} label={!p.active ? 'off' : p.is_public ? 'on website' : 'hidden'} /> },
          ...(can ? [{ key: 'act', label: '', render: (p: any) => <div className="row" style={{ gap: 4 }}>
            <button type="button" className="ghost small" onClick={(e) => { e.stopPropagation(); setEdit(p); }}>Edit</button>
            <DeleteButton what={p.name} path={`/plans/${p.id}`} onDone={() => void plans.reload()} /></div> }] : []),
        ]} />
        <p className="muted small">Discounts for half-yearly / quarterly billing, the setup fee and the AMC % are under Settings → Pricing rules. Per-company prices are under Custom pricing.</p>
      </Card>
      {edit && <PlanModal plan={edit} features={features} onClose={() => setEdit(null)} onDone={() => { setEdit(null); void plans.reload(); }} />}
    </Page>
  );
}

/** Rows of users → price. The last row may be open-ended ("custom quote above"). */
function Ladder({ rows, priceKey, priceLabel, onChange, gapless }: { rows: any[]; priceKey: string; priceLabel: string; onChange: (r: any[]) => void; gapless: boolean }) {
  const set = (i: number, k: string, v: string) => onChange(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  const add = () => { const last = rows[rows.length - 1]; const from = last?.max_users ? Number(last.max_users) + 1 : (last ? Number(last.min_users) + 10 : 1);
    onChange([...rows.slice(0, -1), ...(last ? [{ ...last, max_users: last.max_users || from - 1 }] : []), { min_users: from, max_users: '', [priceKey]: '' }]); };
  return (
    <table className="ladder">
      <thead><tr><th>From users</th><th>To users</th><th>{priceLabel}</th><th /></tr></thead>
      <tbody>{rows.map((r, i) => <tr key={i}>
        <td><input type="number" min={1} value={r.min_users} onChange={(e) => set(i, 'min_users', e.target.value)} required readOnly={gapless && i > 0} aria-label="From users" /></td>
        <td><input type="number" min={1} value={r.max_users ?? ''} placeholder={i === rows.length - 1 ? 'no limit' : ''} aria-label="To users"
          onChange={(e) => onChange(rows.map((x, j) => (j === i ? { ...x, max_users: e.target.value } : gapless && j === i + 1 && e.target.value ? { ...x, min_users: Number(e.target.value) + 1 } : x)))} /></td>
        <td>{i === rows.length - 1 && !r.max_users ? <span className="muted small">custom quote</span>
          : <input type="number" min={0} step="0.01" value={r[priceKey] ?? ''} onChange={(e) => set(i, priceKey, e.target.value)} required aria-label={priceLabel} />}</td>
        <td>{rows.length > 1 && <button type="button" className="ghost small" onClick={() => onChange(rows.filter((_, j) => j !== i))} aria-label="Remove row">✕</button>}</td>
      </tr>)}</tbody>
      <tfoot><tr><td colSpan={4}><button type="button" className="ghost small" onClick={add}>+ Add a size band</button></td></tr></tfoot>
    </table>
  );
}

function PlanModal({ plan, features, onClose, onDone }: { plan: any; features: Record<string, string>; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState(() => ({ ...plan, cloud_tiers: plan.cloud_tiers ?? [], perpetual_bands: plan.perpetual_bands ?? [] }));
  const a = useAction();
  const set = (k: string, v: any) => setF((x: any) => ({ ...x, [k]: v }));
  const toggle = (k: string) => set('features', f.features.includes(k) ? f.features.filter((x: string) => x !== k) : [...f.features, k]);
  const num = (v: any) => (v === '' || v == null ? null : Number(v));
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const last = (rows: any[], k: string) => rows.map((r, i) => ({ min_users: Number(r.min_users), max_users: num(r.max_users), [k]: i === rows.length - 1 && !r.max_users ? (k === 'rate_inr' ? 0 : null) : num(r[k]) }));
    const body = { code: f.code, name: f.name, tagline: f.tagline, min_users: Number(f.min_users), features: f.features, is_public: f.is_public, active: f.active, sort: Number(f.sort ?? 0),
      cloud_tiers: last(f.cloud_tiers, 'rate_inr'), perpetual_bands: last(f.perpetual_bands, 'price_inr') };
    void a.run(async () => { await (plan.id ? put(`/plans/${plan.id}`, body) : post('/plans', body)); toast(`Plan ${body.name} saved`); onDone(); });
  };
  return (
    <Modal title={plan.id ? `Edit ${plan.name}` : 'New plan'} onClose={onClose}>
      <form onSubmit={submit}>
        <ErrorBox error={a.error} />
        <div className="form-grid">
          <Field label="Name"><input value={f.name} onChange={(e) => set('name', e.target.value)} required /></Field>
          <Field label="Code" hint="Used in the website link, e.g. business"><input value={f.code} onChange={(e) => set('code', e.target.value.toLowerCase())} pattern="[a-z0-9-]+" required /></Field>
          <Field label="One-line description"><input value={f.tagline ?? ''} onChange={(e) => set('tagline', e.target.value)} /></Field>
          <Field label="Minimum users"><input type="number" min={1} value={f.min_users} onChange={(e) => set('min_users', e.target.value)} required /></Field>
          <Field label="Order on the website"><input type="number" min={0} value={f.sort ?? 0} onChange={(e) => set('sort', e.target.value)} /></Field>
        </div>
        <fieldset className="card"><legend>Cloud — ₹ per user per month (annual billing)</legend>
          <Ladder rows={f.cloud_tiers} priceKey="rate_inr" priceLabel="₹ / user / month" gapless={false} onChange={(r) => set('cloud_tiers', r)} />
          <p className="muted small">A team pays the rate of the band its size falls in. Half-yearly and quarterly are dearer (Settings → Pricing rules). Leave the last row open for "custom quote above".</p>
        </fieldset>
        <fieldset className="card"><legend>On-premises — one-time price (perpetual licence)</legend>
          <Ladder rows={f.perpetual_bands} priceKey="price_inr" priceLabel="Price at the top of the band (₹)" gapless onChange={(r) => set('perpetual_bands', r)} />
          <p className="muted small">The price is for the top of each band; sizes in between are priced on a straight line. Bands follow on without gaps; prices can't go down as users rise. The first year of updates &amp; support is included; then AMC.</p>
        </fieldset>
        <fieldset className="card"><legend>Features in this plan</legend>
          {Object.entries(features).map(([k, label]) => <label key={k} className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={f.features.includes(k)} onChange={() => toggle(k)} />{label}</label>)}
        </fieldset>
        <label className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={f.active} onChange={(e) => set('active', e.target.checked)} />Active (can be sold and renewed)</label>
        <label className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={f.is_public} onChange={(e) => set('is_public', e.target.checked)} />Show on the website</label>
        <p className="muted small">Changes apply to new orders and renewals. Orders already raised keep their price; licences keep their users and features until renewed.</p>
        <button disabled={a.busy || f.features.length === 0} type="submit">Save plan</button>
      </form>
    </Modal>
  );
}

function Staff({ me }: { me: Me }) {
  const list = useAsync(() => get<{ staff: any[]; roles: Record<string, string>; modules: Record<string, string>; matrix: Record<string, Record<string, string>> }>('/admins'));
  const [edit, setEdit] = useState<any | null>(null);
  const [roleFilter, setRoleFilter] = useState('');
  const a = useAction();
  const roles = list.data?.roles ?? { super: 'Super admin', sales: 'Sales', support: 'Support', accountant: 'Accountant' };
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { ...edit, phone: edit.phone || null, password: edit.password || null };
    void a.run(async () => { await (edit.id ? put(`/admins/${edit.id}`, body) : post('/admins', body)); toast(edit.id ? `${edit.name} saved` : `${edit.name} added`); setEdit(null); await list.reload(); });
  };
  const resetMfa = (s: any) => void a.run(async () => { await post(`/admins/${s.id}/reset-mfa`); toast(`2-step sign-in reset for ${s.name}`); await list.reload(); });
  const rows = (list.data?.staff ?? null)?.filter((s) => !roleFilter || s.role === roleFilter);
  return (
    <Page title="Staff users" subtitle="Ametecs staff and accountants: who can sign in, with which role. Accountants sign in at /accounts."
      actions={<><button onClick={() => setEdit({ name: '', email: '', phone: '', role: 'sales', status: 'active', password: '' })}>Add staff user</button>
        <button className="secondary" onClick={() => setEdit({ name: '', email: '', phone: '', role: 'accountant', status: 'active', password: '' })}>Add accountant</button></>}>
      <ErrorBox error={list.error ?? a.error} />
      <Card>
        <div className="filters"><select value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)} aria-label="Role"><option value="">All roles</option>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></div>
        <Table rows={rows} error={list.error} onRow={(s: any) => setEdit({ ...s, phone: s.phone ?? '', password: '' })} columns={[
          { key: 'name', label: 'Name', render: (s: any) => <>{s.name}{s.id === me.id ? ' (you)' : ''}{s.phone && <div className="muted small">{s.phone}</div>}</> }, { key: 'email', label: 'E-mail' },
          { key: 'role', label: 'Role', render: (s: any) => roles[s.role] ?? s.role },
          { key: 'status', label: 'Status', render: (s: any) => <Badge value={s.status === 'active' ? 'active' : 'revoked'} label={s.status === 'active' ? 'active' : 'deactivated'} /> },
          { key: 'mfa', label: '2-step', render: (s: any) => (s.mfa_enabled_at ? <Badge value="active" label="on" /> : <span className="muted small">off</span>) },
          { key: 'last_login_at', label: 'Last sign-in', render: (s: any) => fmtDate(s.last_login_at) },
          { key: 'act', label: '', render: (s: any) => <div className="row" style={{ gap: 4 }} onClick={(e) => e.stopPropagation()}>
            <button type="button" className="ghost small" onClick={() => setEdit({ ...s, phone: s.phone ?? '', password: '' })}>Edit</button>
            {s.id !== me.id && <button type="button" className="ghost small" disabled={a.busy} onClick={() => void a.run(async () => { await put(`/admins/${s.id}`, { name: s.name, email: s.email, phone: s.phone, role: s.role, status: s.status === 'active' ? 'disabled' : 'active' }); toast(s.status === 'active' ? `${s.name} deactivated` : `${s.name} activated`); await list.reload(); })}>{s.status === 'active' ? 'Deactivate' : 'Activate'}</button>}
            {s.mfa_enabled_at && me.role === 'super' && <button type="button" className="ghost small" onClick={() => resetMfa(s)}>Reset 2-step</button>}
            {s.id !== me.id && <DeleteButton what={s.name} path={`/admins/${s.id}`} onDone={() => { toast('Deleted'); void list.reload(); }} />}</div> },
        ]} />
      </Card>
      {list.data && <Card title="What each role can do">
        <div className="table-wrap"><table className="matrix">
          <thead><tr><th>Area</th>{Object.entries(list.data.roles).map(([k, v]) => <th key={k}>{v}</th>)}</tr></thead>
          <tbody>{Object.entries(list.data.modules).map(([m, label]) => <tr key={m}><td>{label}</td>{Object.keys(list.data!.roles).map((r) => {
            const lv = list.data!.matrix[r]?.[m] ?? 'none'; return <td key={r}><span className={`lvl ${lv}`}>{lv === 'manage' ? 'Full' : lv === 'view' ? 'View' : '—'}</span></td>; })}</tr>)}</tbody>
        </table></div>
        <p className="muted small">Accountants see only Accounts (books, invoices, reports) and their own profile. Plans, discount codes, settings and staff are super admin only.</p>
      </Card>}
      {edit && <Modal title={edit.id ? edit.name : 'Add staff user'} onClose={() => setEdit(null)}>
        <form onSubmit={submit}>
          <ErrorBox error={a.error} />
          <div className="form-grid">
            <Field label="Name"><input value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} required /></Field>
            <Field label="E-mail"><input type="email" value={edit.email} onChange={(e) => setEdit({ ...edit, email: e.target.value })} required /></Field>
            <Field label="Phone"><input value={edit.phone ?? ''} onChange={(e) => setEdit({ ...edit, phone: e.target.value })} inputMode="tel" maxLength={20} /></Field>
            <Field label="Role" hint={edit.role === 'accountant' ? 'Signs in at /accounts; sees the books only' : undefined}><select value={edit.role} onChange={(e) => setEdit({ ...edit, role: e.target.value })}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Status"><select value={edit.status} onChange={(e) => setEdit({ ...edit, status: e.target.value })}><option value="active">Active</option><option value="disabled">Deactivated</option></select></Field>
            <Field label={edit.id ? 'New password (blank = keep)' : 'Password'} hint="At least 12 characters, letters and numbers. A new password signs them out everywhere."><input type="password" autoComplete="new-password" value={edit.password} onChange={(e) => setEdit({ ...edit, password: e.target.value })} minLength={12} required={!edit.id} /></Field>
          </div>
          <button disabled={a.busy} type="submit">Save</button>
        </form>
      </Modal>}
    </Page>
  );
}

// ── Settings ──
function Settings() {
  const s = useAsync(() => get('/settings'));
  const [f, setF] = useState<any | null>(null);
  const [saved, setSaved] = useState(false);
  const a = useAction();
  useEffect(() => { if (s.data) setF({ ...s.data, razorpay_key_secret: '', razorpay_webhook_secret: '', mail_password: '' }); }, [s.data]);
  const [testTo, setTestTo] = useState('');
  const [tested, setTested] = useState('');
  const t = useAction();
  const set = (k: string, v: string) => { setSaved(false); setF((x: any) => ({ ...x, [k]: v })); };
  const keys = ['seller_name', 'seller_address', 'seller_gstin', 'seller_state', 'seller_email', 'seller_phone', 'invoice_prefix', 'gst_rate', 'trial_days', 'trial_users', 'grace_days',
    'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name', 'site_announcement', 'site_hero_title', 'site_hero_text', 'site_meta_description', 'reminders_enabled', 'trial_verify_email', 'staff_mfa_required',
    'price_annual_discount_pct', 'price_half_yearly_discount_pct', 'setup_base', 'setup_included_users', 'setup_per_extra', 'setup_on_cloud', 'amc_pct', 'quote_validity_days'];
  const [clear, setClear] = useState<string[]>([]);
  const submit = (e: FormEvent) => { e.preventDefault(); void a.run(async () => { s.setData(await put('/settings', { ...Object.fromEntries(keys.map((k) => [k, f[k]])), clear_secrets: clear })); setClear([]); setSaved(true); toast('Settings saved'); }); };
  const removeBox = (k: string) => s.data?.[`${k}_set`] ? <label className="row small" style={{ gap: 6 }}><input type="checkbox" style={{ width: 'auto' }} checked={clear.includes(k)} onChange={(e) => setClear(e.target.checked ? [...clear, k] : clear.filter((x) => x !== k))} />Remove the saved value</label> : null;
  const box = (k: string, label: string, hint?: string, type = 'text') => <Field label={label} hint={hint}><input type={type} value={f[k] ?? ''} onChange={(e) => set(k, e.target.value)} /></Field>;
  const toggle = (k: string, label: string, hint: string) => <label className="row" style={{ gap: 10, alignItems: 'flex-start', margin: '6px 0 12px' }}><input type="checkbox" style={{ width: 'auto', marginTop: 4 }} checked={f[k] === '1'} onChange={(e) => set(k, e.target.checked ? '1' : '0')} /><span><b>{label}</b><br /><small className="muted">{hint}</small></span></label>;
  if (!f) return <Page title="Settings"><ErrorBox error={s.error} /><p className="muted">Loading…</p></Page>;
  return (
    <Page title="Settings" subtitle="Invoices, trials, payments, website text and automatic e-mails">
      <form onSubmit={submit}>
        <ErrorBox error={a.error} />
        {saved && <Notice tone="ok">Settings saved.</Notice>}
        <Card title="Seller (on every tax invoice)"><div className="form-grid">
          {box('seller_name', 'Company name')}{box('seller_address', 'Address')}{box('seller_gstin', 'GSTIN')}
          <Field label="State"><select value={f.seller_state} onChange={(e) => set('seller_state', e.target.value)}>{Object.entries(STATES).map(([c, n]) => <option key={c} value={c}>{n}</option>)}</select></Field>
          {box('seller_email', 'Sales e-mail', 'New enquiries are e-mailed here; shown on the website')}{box('seller_phone', 'Sales phone', 'Shown on the website')}
          <Field label="Invoice prefix" hint="Capital letters and numbers, e.g. RVX → RVX/2026-27/0001"><input value={f.invoice_prefix ?? ''} onChange={(e) => set('invoice_prefix', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))} maxLength={8} /></Field>{box('gst_rate', 'GST %', undefined, 'number')}
        </div></Card>
        <Card title="Trials and expiry"><div className="form-grid">
          {box('trial_days', 'Trial length (days)', undefined, 'number')}{box('trial_users', 'Users in a trial', undefined, 'number')}{box('grace_days', 'Grace after expiry (days)', 'Phones keep working this long after a paid package ends', 'number')}
        </div>
          {toggle('trial_verify_email', 'Confirm the e-mail before creating a trial', s.data.mail_configured ? 'A 6-digit code is e-mailed; the workspace is created only after it is entered.' : 'Needs e-mail sending — set up "Outgoing e-mail (SMTP)" below first.')}
        </Card>
        <Card title="Pricing rules">
          <p className="muted small">Package prices per user are on Plans &amp; pricing. These rules apply to every plan.</p>
          <div className="form-grid">
            {box('price_annual_discount_pct', 'Annual billing discount %', 'Cloud: the plan rate is the annual price; quarterly = rate ÷ (1 − this)', 'number')}
            {box('price_half_yearly_discount_pct', 'Half-yearly discount %', 'Off the quarterly price', 'number')}
            {box('setup_base', 'Setup & onboarding fee (₹)', 'On the first order', 'number')}{box('setup_included_users', 'Users covered by that fee', undefined, 'number')}
            {box('setup_per_extra', 'Setup per extra user (₹)', undefined, 'number')}{box('amc_pct', 'AMC % per year', 'On-premises perpetual: % of the current licence price', 'number')}
            {box('quote_validity_days', 'Order / quote valid for (days)', 'After this the pay link stops; raise a fresh order', 'number')}
          </div>
          {toggle('setup_on_cloud', 'Charge the setup fee on cloud first orders too', 'On-premises first orders always carry it (staff can waive it per order).')}
        </Card>
        <Card title="Staff sign-in security">
          {toggle('staff_mfa_required', 'Require 2-step sign-in for all staff and accountants', 'Every sign-in to Super Admin and Accounts then needs a 6-digit code from an authenticator app. People without it set up are asked to set it up at their next sign-in. Anyone can switch it on for themselves under My profile.')}
        </Card>
        <Card title="Automatic e-mails">
          {toggle('reminders_enabled', 'Send renewal, trial-ending and unpaid-order reminders', 'Package: 30, 15, 7, 3, 1 and 0 days before expiry, and once after it expires. Trial: 3, 1 and 0 days before it ends. Unpaid website order: once, 3 hours later. Each goes once; see Sent e-mails.')}
          {!s.data.mail_configured && <Notice tone="warn">E-mail sending isn't set up yet, so reminders and receipts are only written to the log. Fill in "Outgoing e-mail (SMTP)" below.</Notice>}
        </Card>
        <Card title="Outgoing e-mail (SMTP)">
          <p className="muted small">Every e-mail ROVEXY sends — trial codes, receipts, invoices, reminders, payment slips — goes through this mailbox. Pick your provider to fill the server details, then enter the mailbox and its password. Gmail and Microsoft 365 need an <b>app password</b>, not your normal password. Leave the server blank to use MAIL_* in backend/.env.</p>
          <Field label="Provider"><select value="" onChange={(e) => { const p = SMTP_PRESETS[e.target.value]; if (p) { set('mail_host', p[0]); set('mail_port', p[1]); set('mail_encryption', p[2]); } }}>
            <option value="">Choose to fill the server details…</option>{Object.keys(SMTP_PRESETS).map((k) => <option key={k} value={k}>{k}</option>)}</select></Field>
          <div className="form-grid">
            {box('mail_host', 'SMTP server', 'e.g. smtp.gmail.com')}{box('mail_port', 'Port', '587 for TLS, 465 for SSL', 'number')}
            <Field label="Security"><select value={f.mail_encryption ?? 'tls'} onChange={(e) => set('mail_encryption', e.target.value)}><option value="tls">TLS (STARTTLS) — usually port 587</option><option value="ssl">SSL — usually port 465</option><option value="none">None (not recommended)</option></select></Field>
            {box('mail_username', 'Mailbox / username', 'Usually the full e-mail address')}
            <div>{box('mail_password', `Password ${s.data.mail_password_set ? '(saved)' : '(not set)'}`, 'Stored encrypted; leave blank to keep the saved one', 'password')}{removeBox('mail_password')}</div>
            {box('mail_from_address', 'Send from address', 'Blank = the mailbox above')}{box('mail_from_name', 'Send from name', 'e.g. ROVEXY')}
          </div>
          <div className="row" style={{ gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
            <Field label="Send a test e-mail to"><input type="email" value={testTo} onChange={(e) => { setTestTo(e.target.value); setTested(''); }} placeholder="you@company.com" /></Field>
            <button type="button" className="secondary" disabled={t.busy || !testTo} onClick={() => void t.run(async () => { setTested(''); await post('/settings/test-mail', { to: testTo }); setTested(testTo); })}>{t.busy ? 'Sending…' : 'Send test'}</button>
          </div>
          <p className="muted small">Save the settings first — the test uses what is saved.</p>
          <ErrorBox error={t.error} />
          {tested && <Notice tone="ok">Test e-mail sent to {tested}. Check the inbox (and spam folder).</Notice>}
        </Card>
        <Card title="Website text">
          <p className="muted small">Leave blank to use the built-in wording.</p>
          <div className="form-grid">
            {box('site_announcement', 'Announcement bar', 'A one-line notice above the menu, e.g. an offer. Blank = hidden')}
            {box('site_hero_title', 'Main headline')}
          </div>
          <Field label="Text under the headline"><textarea rows={3} value={f.site_hero_text ?? ''} onChange={(e) => set('site_hero_text', e.target.value)} /></Field>
          <Field label="Search engine description" hint="Shown under the link in Google results (up to 300 characters)"><textarea rows={2} value={f.site_meta_description ?? ''} onChange={(e) => set('site_meta_description', e.target.value)} /></Field>
        </Card>
        <Card title="Razorpay">
          <p className="muted small">From the Razorpay dashboard → Account & Settings → API keys. Add a webhook to <code>{s.data.webhook_url}</code> for the events payment.captured and order.paid. Secrets are stored encrypted and never shown again; leave blank to keep the saved one.</p>
          <div className="form-grid">
            {box('razorpay_key_id', 'Key ID', 'rzp_live_… (or rzp_test_… while testing)')}
            <div>{box('razorpay_key_secret', `Key secret ${s.data.razorpay_key_secret_set ? '(saved)' : '(not set)'}`, undefined, 'password')}{removeBox('razorpay_key_secret')}</div>
            <div>{box('razorpay_webhook_secret', `Webhook secret ${s.data.razorpay_webhook_secret_set ? '(saved)' : '(not set)'}`, undefined, 'password')}{removeBox('razorpay_webhook_secret')}</div>
          </div>
        </Card>
        <button disabled={a.busy} type="submit">Save settings</button>
      </form>
    </Page>
  );
}

const SMTP_PRESETS: Record<string, [string, string, string]> = {
  'Gmail / Google Workspace': ['smtp.gmail.com', '587', 'tls'],
  'Microsoft 365 / Outlook': ['smtp.office365.com', '587', 'tls'],
  'Zoho Mail (India)': ['smtp.zoho.in', '587', 'tls'],
  'Zoho Mail (other regions)': ['smtp.zoho.com', '587', 'tls'],
  'Hostinger': ['smtp.hostinger.com', '465', 'ssl'],
  'GoDaddy (Titan)': ['smtp.titan.email', '465', 'ssl'],
  'Amazon SES (Mumbai)': ['email-smtp.ap-south-1.amazonaws.com', '587', 'tls'],
};

// ── Coupons ──
function Coupons({ can }: { can: boolean }) {
  const list = useAsync(() => get<any[]>('/coupons'));
  const plans = usePlans();
  const [edit, setEdit] = useState<any | null>(null);
  const a = useAction();
  const planName = (id: number) => plans.data?.plans.find((p) => p.id === id)?.name ?? `#${id}`;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { ...edit, value: Number(edit.value), max_uses: edit.max_uses ? Number(edit.max_uses) : null, plan_ids: edit.plan_ids.length ? edit.plan_ids : null,
      valid_from: edit.valid_from || null, valid_until: edit.valid_until || null };
    void a.run(async () => { await (edit.id ? put(`/coupons/${edit.id}`, body) : post('/coupons', body)); toast(`Code ${body.code} saved`); setEdit(null); await list.reload(); });
  };
  return (
    <Page title="Discount codes" subtitle="Codes buyers enter on the website or when renewing. The discount comes off the package price before GST."
      actions={can ? <button onClick={() => setEdit({ code: '', type: 'percent', value: 10, plan_ids: [], new_only: false, max_uses: '', valid_from: '', valid_until: '', active: true, note: '' })}>New code</button> : undefined}>
      <ErrorBox error={list.error} />
      {!can && <Notice>Only a super admin can create or change codes.</Notice>}
      <Card>
        <Table rows={list.data} onRow={can ? (c: any) => setEdit({ ...c, plan_ids: c.plan_ids ?? [], max_uses: c.max_uses ?? '', valid_from: c.valid_from ?? '', valid_until: c.valid_until ?? '', note: c.note ?? '' }) : undefined}
          empty="No discount codes yet." columns={[
          { key: 'code', label: 'Code', render: (c: any) => <><code>{c.code}</code><div className="muted small">{c.note}</div></> },
          { key: 'value', label: 'Discount', render: (c: any) => c.type === 'percent' ? `${c.value}%` : inr(c.value) },
          { key: 'plans', label: 'Packages', render: (c: any) => c.plan_ids?.length ? c.plan_ids.map(planName).join(', ') : 'All' },
          { key: 'used', label: 'Used', align: 'right', render: (c: any) => `${c.used_count}${c.max_uses ? ` / ${c.max_uses}` : ''}` },
          { key: 'valid', label: 'Valid', render: (c: any) => `${c.valid_from ? day(c.valid_from) : 'now'} – ${c.valid_until ? day(c.valid_until) : 'no end'}${c.new_only ? ', new buyers only' : ''}` },
          { key: 'active', label: 'Status', render: (c: any) => <Badge value={c.active ? 'active' : 'cancelled'} label={c.active ? 'on' : 'off'} /> },
          ...(can ? [{ key: 'act', label: '', render: (c: any) => c.used_count > 0 ? <span className="muted small">used — switch off</span> : <DeleteButton what={c.code} path={`/coupons/${c.id}`} onDone={() => void list.reload()} /> }] : []),
        ]} />
      </Card>
      {edit && <Modal title={edit.id ? edit.code : 'New discount code'} onClose={() => setEdit(null)}>
        <form onSubmit={submit}>
          <ErrorBox error={a.error} />
          <div className="form-grid">
            <Field label="Code" hint="Letters and numbers, e.g. DIWALI25"><input value={edit.code} onChange={(e) => setEdit({ ...edit, code: e.target.value.toUpperCase() })} pattern="[A-Za-z0-9_-]+" required /></Field>
            <Field label="Type"><select value={edit.type} onChange={(e) => setEdit({ ...edit, type: e.target.value })}><option value="percent">Percent off</option><option value="flat">Fixed ₹ off</option></select></Field>
            <Field label={edit.type === 'percent' ? 'Percent' : 'Amount (₹)'}><input type="number" min={1} max={edit.type === 'percent' ? 100 : undefined} value={edit.value} onChange={(e) => setEdit({ ...edit, value: e.target.value })} required /></Field>
            <Field label="Maximum uses" hint="Blank = unlimited"><input type="number" min={1} value={edit.max_uses} onChange={(e) => setEdit({ ...edit, max_uses: e.target.value })} /></Field>
            <Field label="Valid from"><input type="date" value={edit.valid_from} onChange={(e) => setEdit({ ...edit, valid_from: e.target.value })} /></Field>
            <Field label="Valid until"><input type="date" value={edit.valid_until} onChange={(e) => setEdit({ ...edit, valid_until: e.target.value })} /></Field>
            <Field label="Note (staff only)"><input value={edit.note} onChange={(e) => setEdit({ ...edit, note: e.target.value })} /></Field>
          </div>
          <fieldset className="card"><legend>Packages (none ticked = all)</legend>
            {(plans.data?.plans ?? []).map((p) => <label key={p.id} className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={edit.plan_ids.includes(p.id)}
              onChange={() => setEdit({ ...edit, plan_ids: edit.plan_ids.includes(p.id) ? edit.plan_ids.filter((x: number) => x !== p.id) : [...edit.plan_ids, p.id] })} />{p.name}</label>)}
          </fieldset>
          <label className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={edit.new_only} onChange={(e) => setEdit({ ...edit, new_only: e.target.checked })} />New purchases only (not renewals)</label>
          <label className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} />Active</label>
          <button disabled={a.busy} type="submit">Save code</button>
        </form>
      </Modal>}
    </Page>
  );
}

// ── Software updates (on-premises servers) ──
const mb = (b: number) => `${(b / 1048576).toFixed(1)} MB`;

function Updates() {
  const list = useAsync(() => get<any>('/updates'));
  const [add, setAdd] = useState<any | null>(null);
  const [edit, setEdit] = useState<any | null>(null);
  const a = useAction();
  const x = list.data;
  const publish = (u: any, on: boolean) => a.run(async () => { await post(`/updates/${u.id}/publish`, { published: on }); toast(on ? `Version ${u.version} published` : `Version ${u.version} withdrawn`); await list.reload(); });
  const save = (e: FormEvent) => {
    e.preventDefault();
    void a.run(async () => {
      if (edit) { await put(`/updates/${edit.id}`, { title: edit.title || null, notes: edit.notes || null, channel: edit.channel, min_version: edit.min_version || null }); setEdit(null); }
      else {
        const f = new FormData();
        if (add.file) f.append('file', add.file); else f.append('existing', add.existing);
        (['title', 'notes', 'channel', 'min_version'] as const).forEach((k) => add[k] && f.append(k, add[k]));
        const r: any = await post('/updates', f); toast(`Version ${r.version} added — publish it when ready`); setAdd(null);
      }
      await list.reload();
    });
  };
  const form = edit ?? add;
  const set = (k: string, v: any) => (edit ? setEdit({ ...edit, [k]: v }) : setAdd({ ...add, [k]: v }));
  return (
    <Page title="Software updates" subtitle="New versions for on-premises servers. A customer's admin installs them from Licence → Check for update; perpetual licences need active AMC."
      actions={<button onClick={() => setAdd({ file: null, existing: x?.files_on_server?.[0] ?? '', title: '', notes: '', channel: 'stable', min_version: '' })}>Add update</button>}>
      <ErrorBox error={list.error ?? a.error} />
      {x && !x.can_sign && <Notice tone="bad">The signing key is missing on this server, so packages can't be signed. Run php artisan rovexy:licence-keys.</Notice>}
      <Notice>Build the package on the build PC with <code>php artisan rovexy:build-update</code> (it reads version.json and bumps it afterwards). The version is taken from inside the package — it can't be mistyped.</Notice>
      <Card>
        <Table rows={x?.updates} empty="No updates yet." columns={[
          { key: 'version', label: 'Version', render: (u: any) => <><b>{u.version}</b>{u.channel === 'beta' && <> <Badge value="pending" label="beta" /></>}<div className="muted small">{u.title}</div></> },
          { key: 'min', label: 'Needs', render: (u: any) => u.min_version ? `${u.min_version} or later` : 'any version' },
          { key: 'file', label: 'Package', render: (u: any) => <>{u.filename}<div className="muted small">{mb(u.size_bytes)} · SHA-256 {u.sha256.slice(0, 12)}… {u.file_present ? '' : '· FILE MISSING'}</div></> },
          { key: 'dl', label: 'Downloads', align: 'right', render: (u: any) => u.downloads },
          { key: 'pub', label: 'Status', render: (u: any) => <><Badge value={u.is_published ? 'active' : 'cancelled'} label={u.is_published ? 'published' : 'draft'} />{u.released_at && <div className="muted small">{fmtDate(u.released_at)}</div>}</> },
          { key: 'act', label: '', render: (u: any) => <div className="row" style={{ gap: 6 }}>
            <button className="secondary small" disabled={a.busy} onClick={() => void publish(u, !u.is_published)}>{u.is_published ? 'Withdraw' : 'Publish'}</button>
            <button className="ghost small" onClick={() => setEdit({ ...u, title: u.title ?? '', notes: u.notes ?? '', min_version: u.min_version ?? '' })}>Edit</button>
            {!u.is_published && <DeleteButton what={`version ${u.version}`} path={`/updates/${u.id}`} onDone={() => void list.reload()} />}</div> },
        ]} />
      </Card>
      {form && <Modal title={edit ? `Version ${edit.version}` : 'Add update'} onClose={() => { setAdd(null); setEdit(null); }}>
        <form onSubmit={save}>
          <ErrorBox error={a.error} />
          {!edit && <>
            <Field label="Package file on the server" hint="ZIPs in storage/app/updates (where rovexy:build-update writes them) — no upload size limit">
              <select value={add.existing} onChange={(e) => setAdd({ ...add, existing: e.target.value, file: null })}><option value="">—</option>{(x?.files_on_server ?? []).map((f: string) => <option key={f}>{f}</option>)}</select></Field>
            <Field label={`…or upload the ZIP (up to ${x?.upload_limit_mb ?? '?'} MB here)`}><input type="file" accept=".zip" onChange={(e) => setAdd({ ...add, file: e.target.files?.[0] ?? null, existing: '' })} /></Field>
          </>}
          <div className="form-grid">
            <Field label="Title (shown to customers)"><input value={form.title} onChange={(e) => set('title', e.target.value)} maxLength={150} /></Field>
            <Field label="Channel"><select value={form.channel} onChange={(e) => set('channel', e.target.value)}><option value="stable">Stable — everyone</option><option value="beta">Beta — test servers only</option></select></Field>
            <Field label="Minimum version" hint="Blank = any. Servers below it must install that version first."><input value={form.min_version} onChange={(e) => set('min_version', e.target.value)} pattern="\d+(\.\d+){0,3}" placeholder="e.g. 1.5" /></Field>
          </div>
          <Field label="What's new (shown before installing)"><textarea rows={6} value={form.notes} onChange={(e) => set('notes', e.target.value)} maxLength={8000} /></Field>
          <button disabled={a.busy || (!edit && !add.file && !add.existing)}>{a.busy ? 'Checking the package…' : 'Save'}</button>
        </form>
      </Modal>}
    </Page>
  );
}

// ── Sent e-mails ──
function Emails() {
  const [page, setPage] = useState(1);
  const list = useAsync(() => get(`/mail-log${qs({ page })}`), [page]);
  const LABEL: Record<string, string> = { order_unpaid: 'Unpaid order reminder', package_expired: 'Package expired' };
  const label = (k: string) => LABEL[k] ?? k.replace(/^(package|trial)_(\d+)d$/, (_m, w, n) => `${w === 'trial' ? 'Trial' : 'Package'} ends ${n === '0' ? 'today' : `in ${n} day${n === '1' ? '' : 's'}`}`);
  return (
    <Page title="Sent e-mails" subtitle="Automatic reminders sent by the system (hourly job: php artisan ammp:reminders)">
      <ErrorBox error={list.error} />
      <Card>
        <Table rows={list.data?.data} empty="Nothing sent yet." columns={[
          { key: 'created_at', label: 'When', render: (r: any) => fmtDate(r.created_at) }, { key: 'kind', label: 'E-mail', render: (r: any) => label(r.kind) },
          { key: 'email', label: 'To' }, { key: 'ref', label: 'About', render: (r: any) => <span className="small muted">{r.ref}</span> },
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
    </Page>
  );
}

// ── Audit ──
function Audit() {
  const [action, setAction] = useState('');
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [action]);
  const list = useAsync(() => get(`/audit${qs({ action, page })}`), [action, page]);
  return (
    <Page title="Audit log" subtitle="Every staff sign-in and change, payments and trials">
      <ErrorBox error={list.error} />
      <Card>
        <div className="filters"><select value={action} onChange={(e) => setAction(e.target.value)} aria-label="Area"><option value="">Everything</option>
          {[['super', 'Sign-ins'], ['company', 'Companies'], ['licence', 'Licences'], ['order', 'Orders'], ['trial', 'Trials'], ['plan', 'Plans'], ['coupon', 'Discount codes'], ['lead', 'Leads'], ['staff', 'Staff'], ['settings', 'Settings'], ['accounts', 'Accounts']].map(([x, l]) => <option key={x} value={x}>{l}</option>)}</select></div>
        <Table rows={list.data?.data} columns={[
          { key: 'created_at', label: 'When', render: (r: any) => fmtDate(r.created_at) }, { key: 'action', label: 'Action', render: (r: any) => r.action.replace(/[._]/g, ' ') },
          { key: 'admin_name', label: 'By', render: (r: any) => r.admin_name ?? (JSON.parse(r.meta ?? '{}').by ?? 'system') },
          { key: 'subject', label: 'Subject', render: (r: any) => r.subject_type ? `${r.subject_type} #${r.subject_id}` : '—' },
          { key: 'meta', label: 'Details', render: (r: any) => <span className="small muted">{Object.entries(JSON.parse(r.meta ?? '{}')).filter(([k]) => k !== 'by').map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(', ').slice(0, 140)}</span> },
          { key: 'ip', label: 'IP' },
        ]} />
        <Pager meta={list.data} onPage={setPage} />
      </Card>
    </Page>
  );
}


/** Company panel: what this company pays today for each package (standard or negotiated), GST included. */
function CompanyPrices({ id }: { id: number }) {
  const p = useAsync(() => get<any[]>(`/custom-prices/preview${qs({ company_id: id })}`), [id]);
  if (p.error) return null;   // roles without the pricing module simply don't see it
  return <>
    <h3 className="sub-h">Pricing <a className="small" href={`/super/custom-prices?company=${id}`} style={{ marginLeft: 8, fontWeight: 400 }}>Manage custom prices</a></h3>
    <Table rows={p.data} empty="Loading…" columns={[
      { key: 'plan', label: 'Package' },
      { key: 'standard', label: 'Standard', align: 'right', render: (r: any) => (r.standard == null ? 'quotation' : inr(r.standard)) },
      { key: 'amount', label: 'This company', align: 'right', render: (r: any) => (r.pricing === 'quotation' ? '—' : <strong>{inr(r.amount)}</strong>) },
      { key: 'total', label: 'With GST', align: 'right', render: (r: any) => (r.pricing === 'quotation' ? '—' : inr(r.total)) },
      { key: 'pricing', label: '', render: (r: any) => (r.pricing === 'custom' ? <Badge value="pending" label="negotiated" /> : <span className="muted small">{r.pricing}</span>) }]} />
  </>;
}

// ── Custom pricing (negotiated prices for one company / buyer e-mail) ──
const RULE_STATE: Record<string, [string, string]> = { active: ['active', 'active'], scheduled: ['pending', 'starts later'], expired: ['revoked', 'expired'], off: ['cancelled', 'off'] };
const ruleText = (r: any) => (r.mode === 'fixed' ? `${inr(r.value)} + GST for the period` : r.mode === 'rate' ? `${inr(r.value)} / user / month` : `${Number(r.value)}% off`);
const APPLIES: Record<string, string> = { any: 'New + renewals', new: 'New workspace only', renewal: 'Renewals & upgrades only' };

function CustomPricing({ can }: { can: boolean }) {
  const [state, setState] = useState('');
  const [company, setCompany] = useState(() => new URLSearchParams(location.search).get('company') ?? '');
  const list = useAsync(() => get(`/custom-prices${qs({ state, company_id: company || undefined })}`), [state, company]);
  const [edit, setEdit] = useState<any | null>(null);
  const [hist, setHist] = useState<any | null>(null);
  const plansAll: any[] = list.data?.plans ?? [];
  const blank = () => ({ target: company ? 'company' : 'company', company_id: company, email: '', plan_id: '', applies_to: 'any', mode: 'fixed', value: '', starts_on: '', ends_on: '', active: true, note: '' });
  return (
    <Page title="Custom pricing" subtitle="Negotiated prices for one company. They replace the standard package price for that company only — the standard price never changes."
      actions={can ? <button onClick={() => setEdit(blank())}>New custom price</button> : undefined}>
      <ErrorBox error={list.error} />
      {!can && <Notice>Only a super admin can create or change prices.</Notice>}
      <Card>
        <div className="filters filter-bar">
          <select value={company} onChange={(e) => setCompany(e.target.value)} aria-label="Company"><option value="">All companies</option>{(list.data?.companies ?? []).map((c: any) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
          <select value={state} onChange={(e) => setState(e.target.value)} aria-label="Status"><option value="">Any status</option><option value="active">Active</option><option value="scheduled">Starts later</option><option value="expired">Expired</option><option value="off">Off</option></select>
        </div>
        <Table rows={list.data?.rules} onRow={can ? (r: any) => setEdit({ ...r, target: r.company_id ? 'company' : 'email', company_id: r.company_id ?? '', email: r.email ?? '', plan_id: r.plan_id ?? '', starts_on: r.starts_on ?? '', ends_on: r.ends_on ?? '', note: r.note ?? '' }) : undefined}
          empty="No custom prices. Every company pays the standard package price." columns={[
          { key: 'who', label: 'For', render: (r: any) => r.company ? <>{r.company.name}<div className="muted small">{r.company.slug}</div></> : <>{r.email}<div className="muted small">new buyer (website)</div></> },
          { key: 'rule', label: 'Price', render: (r: any) => <><strong>{ruleText(r)}</strong><div className="muted small">{r.plan?.name ?? 'every package'} · {APPLIES[r.applies_to]}</div></> },
          { key: 'prices', label: 'Standard → custom, cloud, per period', render: (r: any) => <div className="small">{r.prices.map((p: any) => <div key={p.plan}>{p.plan} ({p.users} users): {p.standard == null ? 'quotation' : inr(p.standard)} → <strong>{p.custom == null ? '—' : inr(p.custom)}</strong>
            {p.standard != null && p.custom != null && p.custom !== p.standard && <span className="muted"> ({p.custom < p.standard ? '−' : '+'}{inr(Math.abs(p.standard - p.custom))})</span>}</div>)}</div> },
          { key: 'valid', label: 'Valid', render: (r: any) => `${r.starts_on ? day(r.starts_on) : 'now'} – ${r.ends_on ? day(r.ends_on) : 'no end'}` },
          { key: 'state', label: 'Status', render: (r: any) => <><Badge value={RULE_STATE[r.state][0]} label={RULE_STATE[r.state][1]} />{r.orders > 0 && <div className="muted small">{r.orders} order{r.orders === 1 ? '' : 's'}</div>}{r.note && <div className="muted small">{r.note}</div>}</> },
          { key: 'act', label: '', render: (r: any) => <div className="row" onClick={(e) => e.stopPropagation()}>
            <button className="ghost small" onClick={() => setHist(r)}>History</button>
            {can && (r.orders > 0 ? <span className="muted small">used — switch off</span> : <DeleteButton what="this custom price" path={`/custom-prices/${r.id}`} onDone={() => void list.reload()} />)}
          </div> },
        ]} />
      </Card>
      {edit && <PriceRule rule={edit} plans={plansAll} companies={list.data?.companies ?? []} onClose={() => setEdit(null)} onDone={() => { setEdit(null); void list.reload(); }} />}
      {hist && <PriceHistory rule={hist} onClose={() => setHist(null)} />}
    </Page>
  );
}

function PriceRule({ rule, plans, companies, onClose, onDone }: { rule: any; plans: any[]; companies: any[]; onClose: () => void; onDone: () => void }) {
  const [r, setR] = useState(rule);
  const a = useAction();
  const set = (k: string, v: unknown) => setR((x: any) => ({ ...x, [k]: v }));
  const v = Number(r.value) || 0;
  const save = (e: FormEvent) => {
    e.preventDefault();
    const body = { company_id: r.target === 'company' ? Number(r.company_id) : null, email: r.target === 'email' ? r.email : null, plan_id: r.plan_id === '' ? null : Number(r.plan_id),
      applies_to: r.target === 'email' ? 'new' : r.applies_to, mode: r.mode, value: v, starts_on: r.starts_on || null, ends_on: r.ends_on || null, active: r.active, note: r.note || null };
    void a.run(async () => { await (r.id ? put(`/custom-prices/${r.id}`, body) : post('/custom-prices', body)); toast('Custom price saved'); onDone(); });
  };
  return (
    <Modal title={r.id ? 'Edit custom price' : 'New custom price'} onClose={onClose}>
      <form onSubmit={save}>
        <ErrorBox error={a.error} />
        <div className="form-grid">
          <Field label="Price for"><select value={r.target} onChange={(e) => set('target', e.target.value)}><option value="company">An existing company</option><option value="email">A new buyer (by e-mail, website purchase)</option></select></Field>
          {r.target === 'company'
            ? <Field label="Company"><select value={r.company_id} onChange={(e) => set('company_id', e.target.value)} required><option value="">Choose…</option>{companies.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.slug})</option>)}</select></Field>
            : <Field label="Buyer e-mail" hint="Applies when this e-mail buys a new workspace on the website"><input type="email" value={r.email} onChange={(e) => set('email', e.target.value)} required /></Field>}
          <Field label="Package"><select value={r.plan_id} onChange={(e) => set('plan_id', e.target.value)}><option value="">Every package</option>{plans.filter((p) => p.active).map((p) => <option key={p.id} value={p.id}>{p.name}{p.price_inr == null ? ' (quotation)' : ''}</option>)}</select></Field>
          {r.target === 'company' && <Field label="Applies to"><select value={r.applies_to} onChange={(e) => set('applies_to', e.target.value)}><option value="any">New purchase, renewals and upgrades</option><option value="renewal">Renewals and upgrades only</option><option value="new">New purchase only</option></select></Field>}
          <Field label="Price type"><select value={r.mode} onChange={(e) => set('mode', e.target.value)}><option value="rate">₹ per user per month (cloud)</option><option value="percent">Discount % off the standard price</option><option value="fixed">Fixed licence price for the period (₹)</option></select></Field>
          <Field label={({ fixed: 'Licence price (₹, before GST)', rate: '₹ per user per month', percent: 'Discount %' } as any)[r.mode]}
            hint={({ fixed: 'The whole licence line for one period / one-time, whatever the users. Lower or higher than standard', rate: 'Replaces the price-list rate; any billing cycle. Lower or higher than standard', percent: 'Off the standard price, any size, cloud or on-premises. Up to 99%' } as any)[r.mode]}>
            <input type="number" step="0.01" min={0.01} max={r.mode === 'percent' ? 99 : undefined} value={r.value} onChange={(e) => set('value', e.target.value)} required /></Field>
          <Field label="Valid from" hint="Blank = from today"><input type="date" value={r.starts_on} onChange={(e) => set('starts_on', e.target.value)} /></Field>
          <Field label="Valid until" hint="Blank = no end. After this date the standard price applies again"><input type="date" value={r.ends_on} min={r.starts_on || undefined} onChange={(e) => set('ends_on', e.target.value)} /></Field>
          <Field label="Note (staff only)" hint="e.g. negotiated by Ravi, 3-year deal"><input value={r.note} onChange={(e) => set('note', e.target.value)} maxLength={190} /></Field>
        </div>
        <label className="row" style={{ gap: 8 }}><input type="checkbox" style={{ width: 'auto' }} checked={r.active} onChange={(e) => set('active', e.target.checked)} />Active</label>
        <p className="muted small">The standard and negotiated price for each package at this company's size show in the list after saving. Before GST; discount codes can't be added on top. It also applies to more-users and AMC orders when it is a % discount.</p>

        <div className="row"><button disabled={a.busy} type="submit">Save custom price</button><button type="button" className="secondary" onClick={onClose}>Cancel</button></div>
      </form>
    </Modal>
  );
}

const PRICE_FIELD: Record<string, string> = { company_id: 'company', email: 'e-mail', plan_id: 'package', applies_to: 'applies to', mode: 'type', value: 'value', starts_on: 'from', ends_on: 'until', active: 'active', note: 'note' };
function PriceHistory({ rule, onClose }: { rule: any; onClose: () => void }) {
  const h = useAsync(() => get<any[]>(`/custom-prices/${rule.id}/history`), [rule.id]);
  const diff = (e: any) => {
    if (!e.before) return e.after ? `created: ${e.after.mode === 'fixed' ? inr(e.after.value) : `${Number(e.after.value)}% off`}` : '';
    if (!e.after) return 'removed';
    return Object.keys(e.after).filter((k) => JSON.stringify(e.before[k]) !== JSON.stringify(e.after[k])).map((k) => `${PRICE_FIELD[k] ?? k}: ${e.before[k] ?? '—'} → ${e.after[k] ?? '—'}`).join(' · ') || 'saved, no change';
  };
  return (
    <Modal title={`History · ${rule.company?.name ?? rule.email}`} onClose={onClose}>
      <ErrorBox error={h.error} />
      <Table rows={h.data} empty="No changes recorded." columns={[
        { key: 'at', label: 'When', render: (e: any) => fmtDate(e.at) }, { key: 'by', label: 'Who', render: (e: any) => e.by ?? '—' },
        { key: 'action', label: 'What', render: (e: any) => e.action.replace('custom_price.', '') }, { key: 'diff', label: 'Change', render: (e: any) => <span className="small">{diff(e)}</span> }]} />
    </Modal>
  );
}
