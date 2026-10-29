import { Link, NavLink, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';
import { useUnread, type Unread } from './lib/unread';
import { askDesktopNotify, chime, desktopNotify, soundPref } from './lib/sound';
import { useAuth } from './lib/auth';
import { get, post, put } from './lib/api';
import { disconnectRealtime } from './lib/realtime';
import { ErrorBox, Field, Icon, Initials, LogoPlaceholder, Modal, Notice, useAction } from './components/ui';
import { LoginPage } from './pages/Login';
import { OverviewPage } from './pages/Overview';
import { EmployeesPage } from './pages/Employees';
import { DevicesPage } from './pages/Devices';
import { PoliciesPage } from './pages/Policies';
import { ImportsPage, CustomersPage, CustomerDetailPage } from './pages/Customers';
import { CallingConfigPage, CallsPage, FollowUpsPage } from './pages/Calls';
import { CallingOptionsPage, CallingSetupPage, CompanyNumbersPage, PeopleAccessPage, TestCallsPage } from './pages/CallingConfig';
import { LocationPage, MediaPage, ChatPage } from './pages/Supervision';
import { AuditPage, BrandingPage, HealthPage, ReportsPage, RetentionPage } from './pages/Settings';
import { IntegrationsPage } from './pages/Integrations';
import { PaymentsPage } from './pages/Payments';
import { AllocationPage } from './pages/Allocation';
import { PortfolioReportsPage } from './pages/PortfolioReports';
import { AddressReportPage } from './pages/AddressReport';
import { LicencePage } from './pages/Licence';
import { FieldPlanningPage } from './pages/FieldPlanning';

type NavItem = { to: string; icon: string; label: string; perm?: string; group: string };
/** Sidebar: Dashboard on top, then collapsible sections. Order here = order on screen. */
export const NAV: NavItem[] = [
  { to: '/', icon: 'home', label: 'Dashboard', group: '' },
  { to: '/portfolio-reports', icon: 'chart', label: 'Portfolio reports', perm: 'calls.view', group: '' },
  { to: '/employees', icon: 'people', label: 'Groups & people', perm: 'employees.view', group: 'Team' },
  { to: '/calls', icon: 'call', label: 'Calls & recordings', perm: 'calls.view', group: 'Calling' },
  { to: '/follow-ups', icon: 'event', label: 'Follow-ups', perm: 'calls.view', group: 'Calling' },
  { to: '/payments', icon: 'chart', label: 'Payments & slips', perm: 'calls.view', group: 'Calling' },
  { to: '/calling/setup', icon: 'check', label: 'Setup', perm: 'telephony.configure', group: 'Calling configuration' },
  { to: '/calling/options', icon: 'call', label: 'Calling options', perm: 'telephony.configure', group: 'Calling configuration' },
  { to: '/calling/numbers', icon: 'contacts', label: 'Company numbers', perm: 'telephony.configure', group: 'Calling configuration' },
  { to: '/calling/people', icon: 'people', label: 'People & access', perm: 'telephony.configure', group: 'Calling configuration' },
  { to: '/calling/outcomes', icon: 'settings', label: 'Call outcome codes', perm: 'telephony.configure', group: 'Calling configuration' },
  { to: '/calling/test-calls', icon: 'call', label: 'Test calls', perm: 'telephony.configure', group: 'Calling configuration' },
  { to: '/imports', icon: 'upload', label: 'Portfolios', perm: 'customers.view', group: 'Customers' },
  { to: '/allocation', icon: 'people', label: 'Assignment', perm: 'customers.assign', group: 'Customers' },
  { to: '/field-planning', icon: 'pin', label: 'Field planning (circles & routes)', perm: 'customers.assign', group: 'Customers' },
  { to: '/customers', icon: 'contacts', label: 'Customers', perm: 'customers.view', group: 'Customers' },
  { to: '/address-report', icon: 'pin', label: 'Address & reachability', perm: 'customers.view', group: 'Customers' },
  { to: '/devices', icon: 'phone', label: 'Devices & approvals', perm: 'devices.view', group: 'Devices' },
  { to: '/policies', icon: 'shield', label: 'Policies', perm: 'policies.view', group: 'Devices' },
  { to: '/location', icon: 'pin', label: 'Status & location', perm: 'location.view', group: 'Supervision' },
  { to: '/chat', icon: 'chat', label: 'Messages', perm: 'chat.use', group: 'Supervision' },
  { to: '/screen', icon: 'screen', label: 'Live screen', perm: 'media.screen', group: 'Supervision' },
  { to: '/camera', icon: 'camera', label: 'Camera sessions', perm: 'media.camera', group: 'Supervision' },
  { to: '/licence', icon: 'shield', label: 'Plan & licence', group: 'Administration' },
  { to: '/reports', icon: 'chart', label: 'Reports & exports', perm: 'reports.export', group: 'Administration' },
  { to: '/branding', icon: 'palette', label: 'Company branding', perm: 'branding.manage', group: 'Administration' },
  { to: '/retention', icon: 'clock', label: 'Storage & retention', perm: 'settings.storage', group: 'Administration' },
  { to: '/audit', icon: 'check', label: 'Audit logs', perm: 'audit.view', group: 'Administration' },
  { to: '/integrations', icon: 'settings', label: 'Integrations (PRS / EPT / Maps)', perm: 'system.health', group: 'Administration' },
  { to: '/health', icon: 'warning', label: 'System health', perm: 'system.health', group: 'Administration' },
];
const GROUP_ICON: Record<string, string> = { Team: 'people', Calling: 'call', 'Calling configuration': 'settings', Customers: 'contacts', Devices: 'phone', Supervision: 'eye', Administration: 'settings' };

const pref = (k: string, d: string) => { try { return localStorage.getItem(k) ?? d; } catch { return d; } };
const save = (k: string, v: string) => { try { localStorage.setItem(k, v); } catch { /* private mode */ } };

function Sidebar({ items, name, logo, me, logout, unread }: { items: NavItem[]; name: string; logo: string | null; me: any; logout: () => void; unread: number }) {
  const loc = useLocation();
  const active = (to: string) => (to === '/' ? loc.pathname === '/' : loc.pathname === to || loc.pathname.startsWith(to + '/'));
  const current = items.find((i) => i.group && active(i.to))?.group;
  // Accordion: one section open at a time — the one holding the current page (none on the Dashboard).
  const [open, setOpen] = useState<string | null>(current ?? null);
  useEffect(() => setOpen(current ?? null), [current]);
  const [mini, setMini] = useState(() => pref('nav.mini', '0') === '1');
  const [drawer, setDrawer] = useState(false);
  const [pwOpen, setPwOpen] = useState(false);
  useEffect(() => setDrawer(false), [loc.pathname]);
  const groups = [...new Set(items.map((i) => i.group).filter(Boolean))];
  const isOpen = (g: string) => open === g;
  const toggle = (g: string) => setOpen(open === g ? null : g);
  const link = (i: NavItem, sub: boolean) => (
    <NavLink key={i.to} to={i.to} end={i.to === '/'} className={sub ? 'sub' : 'top'} title={mini ? i.label : undefined}>
      <Icon name={i.icon} size={sub ? 17 : 19} /><span className="lbl">{i.label}</span>
      {i.to === '/chat' && unread > 0 && <span className="nav-badge" aria-label={`${unread} unread`}>{unread}</span>}
    </NavLink>
  );
  return (
    <>
      <button className="nav-burger" onClick={() => setDrawer(true)} aria-label="Open menu"><Icon name="menu" size={22} /></button>
      {drawer && <div className="nav-scrim" onClick={() => setDrawer(false)} />}
      <aside className={`sidebar ${mini ? 'mini' : ''} ${drawer ? 'drawer' : ''}`}>
        <div className="brand">
          {logo ? <img src={logo} alt={`${name} logo`} /> : <LogoPlaceholder name={name} />}
          <span className="lbl">{name}</span>
          <button className="nav-mini" onClick={() => { setMini(!mini); save('nav.mini', mini ? '0' : '1'); }} aria-label={mini ? 'Expand menu' : 'Collapse menu'} title={mini ? 'Expand menu' : 'Collapse menu'}>
            <Icon name={mini ? 'chevron_right' : 'chevron_left'} size={18} />
          </button>
        </div>
        <nav className="nav" aria-label="Modules">
          {items.filter((i) => !i.group).map((i) => link(i, false))}
          {groups.map((g) => {
            const sub = items.filter((i) => i.group === g);
            const o = isOpen(g) || mini;
            return (
              <div key={g} className={`nav-group ${o ? 'open' : ''} ${current === g ? 'has-active' : ''}`}>
                <button className="nav-head" onClick={() => toggle(g)} aria-expanded={o} title={mini ? g : undefined}>
                  <Icon name={GROUP_ICON[g] ?? 'settings'} size={19} /><span className="lbl">{g}</span>
                  <span className="count lbl">{g === 'Supervision' && unread > 0 ? <span className="nav-badge">{unread}</span> : sub.length}</span>
                  <span className="chev lbl"><Icon name="expand" size={18} /></span>
                </button>
                <div className="nav-sub" style={{ gridTemplateRows: o ? '1fr' : '0fr' }}><div>{sub.map((i) => link(i, true))}</div></div>
              </div>
            );
          })}
        </nav>
        <div className="nav-user">
          <span className="avatar">{(me.name ?? '?').split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase()}</span>
          <button type="button" className="lbl who who-btn" onClick={() => setPwOpen(true)} title="Change password"><strong>{me.name}</strong><small>{me.email ?? me.product_name}</small></button>
          <button className="icon-btn nav-out" onClick={logout} title="Sign out" aria-label="Sign out"><Icon name="logout" size={18} /></button>
        </div>
      </aside>
      {pwOpen && <ChangePassword onClose={() => setPwOpen(false)} />}
    </>
  );
}

/** Phones waiting for approval: polled on every page (not only the Dashboard), chime + desktop note when a new one arrives. */
function usePendingPhones(enabled: boolean, everyMs = 15000) {
  const [n, setN] = useState(0);
  const prev = useRef<number | null>(null);
  useEffect(() => {
    if (!enabled) return;
    let alive = true;
    const tick = () => get<{ total: number }>('/devices?state=pending').then((d) => {
      if (!alive) return;
      if (prev.current !== null && d.total > prev.current) { chime(); desktopNotify('Phone waiting for approval', 'A new phone asked to join. Open Devices to approve it.'); }
      prev.current = d.total;
      setN(d.total);
    }).catch(() => undefined);
    tick();
    const t = setInterval(tick, everyMs);
    return () => { alive = false; clearInterval(t); };
  }, [enabled, everyMs]);
  return n;
}

function TopBar({ items, me, logout, pendingPhones }: { items: NavItem[]; me: any; logout: () => void; pendingPhones: number }) {
  const loc = useLocation();
  const page = items.find((i) => (i.to === '/' ? loc.pathname === '/' : loc.pathname === i.to || loc.pathname.startsWith(i.to + '/')));
  const [now, setNow] = useState(() => new Date());
  useEffect(() => { const t = setInterval(() => setNow(new Date()), 30000); return () => clearInterval(t); }, []);
  return (
    <header className="topbar">
      <nav className="crumb" aria-label="Breadcrumb">
        <span>{me.product_name}</span>
        {page?.group && <><i>/</i><span>{page.group}</span></>}
        <i>/</i><strong>{page?.label ?? (loc.pathname.startsWith('/customers/') ? 'Customer' : 'Dashboard')}</strong>
      </nav>
      <div className="top-right">
        {pendingPhones > 0 && <Link to="/devices" className="top-alert" title="Review phone requests">📱 {pendingPhones} phone{pendingPhones > 1 ? 's' : ''} waiting for approval</Link>}
        <span className="top-time">{now.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })} · {now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}</span>
        <SoundToggle />
        <span className="top-user"><span className="avatar sm">{(me.name ?? '?').split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase()}</span>{me.name}</span>
        <button className="secondary sm" onClick={logout}><Icon name="logout" size={16} /> Sign out</button>
      </div>
    </header>
  );
}

function SoundToggle() {
  const [on, setOn] = useState(soundPref.on);
  return (
    <button className="ghost sm" onClick={() => { soundPref.on = !on; setOn(!on); if (!on) chime(); }}
      title={on ? 'Sounds on — click to mute message chimes and ringing' : 'Sounds muted — click to turn on'} aria-pressed={on}>
      {on ? '🔔' : '🔕'}
    </button>
  );
}

/** Unread count in the browser tab, so a background tab still shows new messages: "(3) Admin console". */
function useTabBadge(n: number) {
  const base = useRef(document.title.replace(/^\(\d+\) /, ''));
  useEffect(() => { document.title = n > 0 ? `(${n}) ${base.current}` : base.current; }, [n]);
}

/** Pops up for a few seconds whenever a NEW employee message arrives (unread total goes up). */
function MessageToast({ unread }: { unread: Unread | null }) {
  const prev = useRef<number | null>(null);
  const [show, setShow] = useState<Unread['items'][number] | null>(null);
  const loc = useLocation();
  const nav = useNavigate();
  useEffect(() => { window.addEventListener('pointerdown', askDesktopNotify, { once: true }); }, []);
  useEffect(() => {
    if (!unread) return;
    const top = unread.items[0];
    if (prev.current !== null && unread.total > prev.current && top) {
      chime();
      desktopNotify(`New message · ${top.from}`, top.body, () => nav(`/chat?c=${top.conversation_id}`));
      if (!loc.pathname.startsWith('/chat')) setShow(top);
    }
    prev.current = unread.total;
  }, [unread, loc.pathname, nav]);
  useEffect(() => { if (!show) return; const t = setTimeout(() => setShow(null), 8000); return () => clearTimeout(t); }, [show]);
  if (!show) return null;
  return (
    <Link to={`/chat?c=${show.conversation_id}`} className="toast" role="status" onClick={() => setShow(null)}>
      <Initials name={show.from} size={36} />
      <span className="who"><strong>New message · {show.from}</strong><small>{show.body}</small></span>
    </Link>
  );
}

export function App() {
  const { me, loading, can, setMe } = useAuth();
  const [unread] = useUnread(!!me && can('chat.use'));
  const pendingPhones = usePendingPhones(!!me && can('devices.approve'));
  useTabBadge(unread?.total ?? 0);
  const [branding, setBranding] = useState<{ display_name: string; logo_url: string | null } | null>(null);
  useEffect(() => {
    if (me) get('/branding').then(setBranding).catch(() => setBranding(null));
  }, [me]);
  // Licence ending / ended / suspended: one line on every page until it is renewed.
  const [licenceMsg, setLicenceMsg] = useState<string | null>(null);
  useEffect(() => { if (me) get<{ message: string | null }>('/licence').then((l) => setLicenceMsg(l.message), () => setLicenceMsg(null)); }, [me]);
  if (loading) return <p className="muted" style={{ padding: 24 }}>Loading…</p>;
  if (!me) return <LoginPage />;

  const logout = async () => {
    await post('/auth/logout').catch(() => undefined);
    disconnectRealtime();
    setMe(null);
  };
  const items = NAV.filter((n) => !n.perm || can(n.perm));
  const name = branding?.display_name ?? me.company.name;
  return (
    <div className="shell">
      <Sidebar items={items} name={name} logo={branding?.logo_url ?? null} me={me} logout={logout} unread={unread?.total ?? 0} />
      <MessageToast unread={unread} />
      <main className="main">
        <TopBar items={items} me={me} logout={logout} pendingPhones={pendingPhones} />
        {licenceMsg && <div className="alert warn" role="status" style={{ margin: '0 24px 12px' }}>{licenceMsg} <Link to="/licence">Plan & licence</Link></div>}
        <Routes>
          <Route path="/" element={<OverviewPage />} />
          <Route path="/portfolio-reports" element={<PortfolioReportsPage />} />
          <Route path="/employees" element={<EmployeesPage />} />
          <Route path="/devices" element={<DevicesPage />} />
          <Route path="/policies" element={<PoliciesPage />} />
          <Route path="/imports" element={<ImportsPage />} />
          <Route path="/customers" element={<CustomersPage />} />
          <Route path="/allocation" element={<AllocationPage />} />
          <Route path="/field-planning" element={<FieldPlanningPage />} />
          <Route path="/customers/:id" element={<CustomerDetailPage />} />
          <Route path="/address-report" element={<AddressReportPage />} />
          <Route path="/calling" element={<Navigate to="/calling/setup" replace />} />
          <Route path="/calling/setup" element={<CallingSetupPage />} />
          <Route path="/calling/options" element={<CallingOptionsPage />} />
          <Route path="/calling/test-calls" element={<TestCallsPage />} />
          <Route path="/calling/numbers" element={<CompanyNumbersPage />} />
          <Route path="/calling/people" element={<PeopleAccessPage />} />
          {['click2call', 'sim'].map((p) => <Route key={p} path={`/calling/${p}`} element={<Navigate to="/calling/options" replace />} />)}
          <Route path="/calling/smart31" element={<Navigate to="/calling/numbers" replace />} />
          {['users', 'access'].map((p) => <Route key={p} path={`/calling/${p}`} element={<Navigate to="/calling/people" replace />} />)}
          <Route path="/calling/outcomes" element={<CallingConfigPage />} />
          <Route path="/calls" element={<CallsPage />} />
          <Route path="/follow-ups" element={<FollowUpsPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/location" element={<LocationPage />} />
          <Route path="/screen" element={<MediaPage kind="screen" />} />
          <Route path="/camera" element={<MediaPage kind="camera" />} />
          <Route path="/chat" element={<ChatPage />} />
          <Route path="/licence" element={<LicencePage />} />
          <Route path="/reports" element={<ReportsPage />} />
          <Route path="/branding" element={<BrandingPage />} />
          <Route path="/retention" element={<RetentionPage />} />
          <Route path="/audit" element={<AuditPage />} />
          <Route path="/integrations" element={<IntegrationsPage />} />
          <Route path="/health" element={<HealthPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  );
}

/** Change your own password (company console). Forgotten passwords: "Forgot password?" on the sign-in page. */
function ChangePassword({ onClose }: { onClose: () => void }) {
  const [f, setF] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [done, setDone] = useState(false);
  const a = useAction();
  return (
    <Modal title="Change password" onClose={onClose}>
      {done ? <><Notice tone="ok">Password changed. Use the new password next time you sign in.</Notice><button onClick={onClose}>Done</button></> : (
        <form className="form-grid" onSubmit={(e) => {
          e.preventDefault();
          if (f.password !== f.password_confirmation) { a.setError('The two new passwords do not match.'); return; }
          void a.run(async () => { await put('/auth/password', f); setDone(true); });
        }}>
          <ErrorBox error={a.error} />
          <Field label="Current password"><input type="password" autoComplete="current-password" value={f.current_password} onChange={(e) => setF({ ...f, current_password: e.target.value })} required autoFocus /></Field>
          <Field label="New password" hint="At least 8 characters"><input type="password" autoComplete="new-password" minLength={8} value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} required /></Field>
          <Field label="New password again"><input type="password" autoComplete="new-password" minLength={8} value={f.password_confirmation} onChange={(e) => setF({ ...f, password_confirmation: e.target.value })} required /></Field>
          <div className="row"><button disabled={a.busy}>{a.busy ? 'Saving…' : 'Change password'}</button><button type="button" className="secondary" onClick={onClose}>Cancel</button></div>
        </form>
      )}
    </Modal>
  );
}
