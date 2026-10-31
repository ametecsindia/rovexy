import { useEffect, useMemo, useState } from 'react';
import { useLiveReload } from '../lib/live';
import { Link } from 'react-router-dom';
import { get, fileUrl, fmtDate, qs } from '../lib/api';
import { RANGES, rangeName } from '../lib/periods';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Kpi, Modal, Notice, Page, Table, useAsync } from '../components/ui';

const inr = (v: number) => `₹ ${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;
const pct = (a: number, b: number) => (b ? Math.round((a / b) * 100) : 0);
const EMPTY = { portfolio: '', group: '', employee: '', assigned: '', call_status: '', collection: '', callback: '', case_status: '', from: '', to: '' };
const csvCell = (v: unknown) => /[",\n]/.test(String(v ?? '')) ? `"${String(v).replace(/"/g, '""')}"` : String(v ?? '');
function downloadCsv(name: string, header: string[], rows: unknown[][]) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['﻿' + [header, ...rows].map((r) => r.map(csvCell).join(',')).join('\n') + '\n'], { type: 'text/csv' }));
  a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
const short = (iso: string | null | undefined, tz?: string) => iso ? new Date(iso).toLocaleString('en-IN', { timeZone: tz, day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—';

/** Calls (connected + not connected, stacked) and collections per day / week / month — two charts, one axis each. */
function TrendCharts({ trend, period }: { trend: any[]; period: string }) {
  const [hover, setHover] = useState<{ chart: string; i: number } | null>(null);
  if (!trend.length) return <p className="muted">No calls or payments in this range.</p>;
  const label = (p: string) => { const d = new Date(p + 'T00:00:00'); return period === 'year' ? String(d.getFullYear()) : period === 'month' ? d.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' }) : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); };
  const W = 640, H = 190, P = { l: 52, r: 8, t: 10, b: 26 };
  const bw = (W - P.l - P.r) / trend.length;
  const every = Math.ceil(trend.length / 10);
  const chart = (key: 'calls' | 'collected', title: string) => {
    const max = Math.max(key === 'calls' ? 4 : 1000, ...trend.map((d) => d[key]));
    const nice = key === 'calls' ? Math.ceil(max / 4) * 4 : Math.ceil(max / 4 / 1000) * 4000;
    const y = (v: number) => P.t + (H - P.t - P.b) * (1 - v / nice);
    const tick = (v: number) => key === 'calls' ? String(v) : v >= 100000 ? `${(v / 100000).toFixed(1)}L` : v >= 1000 ? `${Math.round(v / 1000)}k` : String(v);
    const h = hover?.chart === key ? hover.i : null;
    return (
      <div className="chart">
        <div className="legend"><strong style={{ marginRight: 12 }}>{title}</strong>{key === 'calls' ? <><span><i style={{ background: 'var(--series-1)' }} />Connected</span><span><i style={{ background: 'var(--series-2)' }} />Not connected</span></> : null}</div>
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={title} onMouseLeave={() => setHover(null)}>
          {[0, 1, 2, 3, 4].map((i) => <g key={i}><line x1={P.l} x2={W - P.r} y1={y((nice / 4) * i)} y2={y((nice / 4) * i)} className="grid-line" /><text x={P.l - 6} y={y((nice / 4) * i) + 4} textAnchor="end" className="axis">{tick((nice / 4) * i)}</text></g>)}
          {trend.map((d, i) => {
            const x = P.l + i * bw + bw * 0.18, w = Math.max(2, bw * 0.64);
            const miss = d.calls - d.connected;
            return (
              <g key={d.period} onMouseEnter={() => setHover({ chart: key, i })}>
                <rect x={P.l + i * bw} y={P.t} width={bw} height={H - P.t - P.b} fill="transparent" />
                {key === 'calls' ? <>
                  {d.connected > 0 && <rect x={x} y={y(d.connected)} width={w} height={y(0) - y(d.connected)} rx={miss > 0 ? 0 : 3} fill="var(--series-1)" />}
                  {miss > 0 && <rect x={x} y={y(d.calls)} width={w} height={Math.max(0, y(d.connected) - y(d.calls) - (d.connected > 0 ? 2 : 0))} rx={3} fill="var(--series-2)" />}
                </> : d.collected > 0 && <rect x={x} y={y(d.collected)} width={w} height={y(0) - y(d.collected)} rx={3} fill="var(--series-1)" />}
                {i % every === 0 && <text x={P.l + i * bw + bw / 2} y={H - 8} textAnchor="middle" className="axis">{label(d.period)}</text>}
              </g>
            );
          })}
        </svg>
        {h !== null && trend[h] && (
          <div className="tooltip" style={{ left: `${((P.l + h * bw + bw / 2) / W) * 100}%` }}>
            <strong>{period === 'week' ? `Week of ${label(trend[h].period)}` : label(trend[h].period)}</strong><br />
            {key === 'calls' ? <>{trend[h].calls} calls · {trend[h].connected} connected</> : <>{inr(trend[h].collected)} collected</>}
          </div>
        )}
      </div>
    );
  };
  return <div className="grid2">{chart('calls', 'Calls')}{chart('collected', 'Collections (₹)')}</div>;
}

/** Horizontal bars inside a table cell: one series, compares a value against the largest in the list. */
const Meter = ({ v, max, text }: { v: number; max: number; text: string }) => (
  <div className="meter-cell" title={text}><span>{text}</span><div className="hbar-track"><div style={{ width: `${max ? Math.max(2, (v / max) * 100) : 0}%` }} /></div></div>
);

export function PortfolioReportsPage() {
  const { me } = useAuth();
  const [f, setF] = useState<Record<string, string>>(EMPTY);
  const [period, setPeriod] = useState('day');
  const [q, setQ] = useState('');
  const [sort, setSort] = useState<{ k: string; d: 1 | -1 }>({ k: 'cases', d: -1 });
  const [drill, setDrill] = useState<any | null>(null);
  const r = useAsync(() => get(`/reports/portfolio${qs({ ...f, period })}`), [JSON.stringify(f), period]);
  useLiveReload(r.reload);
  const d = r.data;
  const s = d?.summary;
  const set = (k: string, v: string) => setF({ ...f, [k]: v });
  const active = Object.entries(f).filter(([, v]) => v).length;

  const employees: any[] = useMemo(() => {
    const list = (d?.employees ?? []).filter((e: any) => !q || `${e.name} ${e.employee_code} ${e.groups.join(' ')}`.toLowerCase().includes(q.toLowerCase()));
    return [...list].sort((a, b) => (typeof a[sort.k] === 'string' ? String(a[sort.k]).localeCompare(String(b[sort.k])) : (a[sort.k] ?? 0) - (b[sort.k] ?? 0)) * sort.d);
  }, [d, q, sort]);
  const avgCases = d?.employees?.length ? d.employees.reduce((a: number, e: any) => a + e.cases, 0) / d.employees.length : 0;
  const maxCollected = Math.max(0, ...(d?.employees ?? []).map((e: any) => e.collected));
  const maxGroupCollected = Math.max(0, ...(d?.groups ?? []).map((g: any) => g.collected));
  const maxBranchCollected = Math.max(0, ...(d?.branches ?? []).map((g: any) => g.collected));
  const csv = (by: string) => fileUrl(`/api/admin/reports/portfolio.csv${qs({ ...f, by })}`);
  const H = ({ k, children }: { k: string; children: string }) => (
    <button className="th-sort" onClick={() => setSort({ k, d: sort.k === k ? (sort.d === 1 ? -1 : 1) : -1 })}>{children}{sort.k === k ? (sort.d === 1 ? ' ▲' : ' ▼') : ''}</button>
  );
  const flags = (e: any) => [
    e.cases > avgCases * 1.5 && e.cases >= 10 ? <Badge key="w" value="pending" label="high workload" /> : null,
    e.callbacks_overdue > 0 ? <Badge key="c" value="failed" label={`${e.callbacks_overdue} overdue callback${e.callbacks_overdue > 1 ? 's' : ''}`} /> : null,
    e.calls >= 5 && pct(e.connected, e.calls) < 30 ? <Badge key="l" value="pending" label="low connect rate" /> : null,
    e.cases > 0 && e.not_made / e.cases > 0.5 ? <Badge key="n" value="pending" label="many not called" /> : null,
  ].filter(Boolean);

  return (
    <Page title="Portfolio reports" subtitle="Calls, collections, callbacks and pending work — company, branch, team and employee wise, per portfolio. Pick branch / team / employee in the bar at the top."
      actions={<button className="secondary" onClick={r.reload}>Refresh</button>}>
      <ErrorBox error={r.error} />
      <Card>
        <div className="filters filter-bar">
          <select value={f.portfolio} onChange={(e) => set('portfolio', e.target.value)} aria-label="Portfolio" style={{ minWidth: 220 }}>
            <option value="">All portfolios</option>{(d?.portfolios ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name} ({p.cases})</option>)}
          </select>
          <select value={f.assigned} onChange={(e) => set('assigned', e.target.value)} aria-label="Assignment"><option value="">Assigned + unassigned</option><option value="assigned">Assigned cases</option><option value="unassigned">Unassigned cases</option></select>
          <select value={f.call_status} onChange={(e) => set('call_status', e.target.value)} aria-label="Call status"><option value="">Any call status</option><option value="connected">Connected</option><option value="not_connected">Called, not connected</option><option value="not_called">Not called</option></select>
          <select value={f.collection} onChange={(e) => set('collection', e.target.value)} aria-label="Collection status"><option value="">Any collection</option><option value="paid">Paid</option><option value="verified">Paid &amp; verified</option><option value="to_check">Slip to check</option><option value="none">No payment</option></select>
          <select value={f.callback} onChange={(e) => set('callback', e.target.value)} aria-label="Callback status"><option value="">Any callback</option><option value="pending">Callback pending</option><option value="overdue">Callback overdue</option><option value="none">No callback</option></select>
          <select value={f.case_status} onChange={(e) => set('case_status', e.target.value)} aria-label="Case status"><option value="">Any case status</option>{['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'].map((x) => <option key={x} value={x}>{x.replace(/_/g, ' ')}</option>)}</select>
        </div>
        <div className="filters filter-bar" style={{ marginTop: 8 }}>
          <input type="date" value={f.from} max={f.to || undefined} onChange={(e) => set('from', e.target.value)} aria-label="From" />
          <input type="date" value={f.to} min={f.from || undefined} onChange={(e) => set('to', e.target.value)} aria-label="To" />
          <div className="seg">{RANGES.map(([l, fn]) => <button key={l} className={rangeName(f.from, f.to) === l ? 'on' : ''} onClick={() => { const [a, b] = fn(); setF({ ...f, from: a, to: b }); }}>{l}</button>)}
            <button className={rangeName(f.from, f.to) === 'Custom' ? 'on' : ''} disabled title="Pick From / To dates for a custom range">Custom</button></div>
          {active > 0 && <button className="ghost" onClick={() => setF(EMPTY)}>Clear filters ({active})</button>}
        </div>
      </Card>

      {d?.portfolio && (
        <Card>
          <div className="pf-head">
            <div><h2 style={{ margin: 0 }}>{d.portfolio.name}</h2><small className="muted">{d.portfolio.file ? `${d.portfolio.file} · uploaded ${fmtDate(d.portfolio.uploaded_at, me?.company.timezone)}` : 'Customers added by hand'}</small></div>
            <div className="pf-tags">{d.groups.filter((g: any) => g.team_id).map((g: any) => <span key={g.team_id} className="tag">{g.name} · {g.cases}</span>)}
              {s?.unassigned > 0 && <Link className="button secondary" to={`/allocation?portfolio=${d.portfolio.id}`}>Assign {s.unassigned} unassigned →</Link>}</div>
          </div>
        </Card>
      )}

      {s && (
        <div className="kpi-grid">
          <Kpi icon="call" tone="blue" label="Total calls" value={s.calls} sub={`${s.cases} cases · ${s.unassigned} unassigned`} />
          <Kpi icon="check" tone="green" label="Connected calls" value={s.connected} sub={`${pct(s.connected, s.calls)}% of calls`} subTone={pct(s.connected, s.calls) >= 50 ? 'up' : 'flat'} />
          <Kpi icon="chart" tone="gold" label="Collections received" value={inr(s.collected)} sub={`${s.payments} payments · ${inr(s.verified)} verified`} />
          <Kpi icon="event" tone="purple" label="Callbacks pending" value={s.callbacks} sub={s.callbacks_overdue ? `${s.callbacks_overdue} overdue` : 'none overdue'} subTone={s.callbacks_overdue ? 'down' : 'flat'} />
          <Kpi icon="warning" tone="gold" label="Cases not called" value={s.not_made} sub={`of ${s.cases - s.unassigned} assigned · ${s.reached_cases} reached · ${s.not_connected_cases} called, not reached`} subTone={s.not_made ? 'down' : 'flat'} />
          <Kpi icon="people" tone="teal" label="Employees on it" value={s.employees} sub={`${s.groups} teams · ${(d?.branches ?? []).filter((b: any) => b.branch_id).length} branches`} />
          <Kpi icon="clock" tone="blue" label="Pending cases" value={s.pending_cases} sub="new, in progress or follow-up" />
        </div>
      )}

      {d && (
        <Card title="Downloads">
          <div className="row">
            {[['company', 'Company total'], ['branch', 'Branch-wise'], ['team', 'Team-wise'], ['employee', 'Employee-wise'], ['portfolio', 'Portfolio-wise']].map(([k, l]) => <a key={k} className="button secondary" href={csv(k)}>{l} CSV</a>)}
          </div>
          <p className="muted small" style={{ marginBottom: 0 }}>Same filters and dates as this page, and the branch / team / employee chosen at the top.</p>
        </Card>
      )}

      {d && (
        <Card title="Trend" actions={<div className="seg">{['day', 'week', 'month', 'year'].map((p) => <button key={p} className={period === p ? 'on' : ''} onClick={() => setPeriod(p)}>{({ day: 'Daily', week: 'Weekly', month: 'Monthly', year: 'Yearly' } as any)[p]}</button>)}</div>}>
          <TrendCharts trend={d.trend} period={d.period} />
        </Card>
      )}

      {d && (d.branches ?? []).some((b: any) => b.branch_id) && (
        <Card title="By branch" actions={<a className="button ghost" href={csv('branch')}>Export CSV</a>}>
          <Table rows={d.branches.map((g: any, i: number) => ({ ...g, id: i }))} empty="No cases." columns={[
            { key: 'name', label: 'Branch' }, { key: 'teams', label: 'Teams', align: 'right' }, { key: 'cases', label: 'Cases', align: 'right' }, { key: 'unassigned', label: 'Unassigned', align: 'right' },
            { key: 'employees', label: 'Employees', align: 'right' }, { key: 'calls', label: 'Calls', align: 'right' },
            { key: 'connected', label: 'Connected', align: 'right', render: (g: any) => `${g.connected} (${pct(g.connected, g.calls)}%)` },
            { key: 'callbacks', label: 'Callbacks', align: 'right' },
            { key: 'collected', label: 'Collected', render: (g: any) => <Meter v={g.collected} max={maxBranchCollected} text={inr(g.collected)} /> }]} />
        </Card>
      )}

      {d && (
        <Card title="By team" actions={<a className="button ghost" href={csv('team')}>Export CSV</a>}>
          <Table rows={d.groups.map((g: any, i: number) => ({ ...g, id: i }))} empty="No cases." columns={[
            { key: 'name', label: 'Team' }, { key: 'branch', label: 'Branch', render: (g: any) => g.branch ?? '—' }, { key: 'cases', label: 'Cases', align: 'right' }, { key: 'unassigned', label: 'Unassigned', align: 'right' },
            { key: 'employees', label: 'Employees', align: 'right' }, { key: 'calls', label: 'Calls', align: 'right' },
            { key: 'connected', label: 'Connected', align: 'right', render: (g: any) => `${g.connected} (${pct(g.connected, g.calls)}%)` },
            { key: 'callbacks', label: 'Callbacks', align: 'right' },
            { key: 'collected', label: 'Collected', render: (g: any) => <Meter v={g.collected} max={maxGroupCollected} text={inr(g.collected)} /> }]} />
        </Card>
      )}

      {d && (
        <Card title={`Employees (${employees.length})`} actions={<>
          <input placeholder="Search employee" value={q} onChange={(e) => setQ(e.target.value)} style={{ maxWidth: 200 }} />
          <button className="ghost" onClick={() => downloadCsv('employee-performance.csv', ['Employee', 'User ID', 'Team', 'Portfolio', 'Assigned cases', 'Reached', 'Called not reached', 'Not called', 'Calls', 'Calls answered', 'Callbacks pending', 'Callbacks overdue', 'Collected', 'Verified', 'Last activity'],
            employees.map((e) => [e.name, e.employee_code, e.groups.join(' / '), e.portfolios.join(' / '), e.cases, e.reached_cases, e.not_connected_cases, e.not_made, e.calls, e.connected, e.callbacks, e.callbacks_overdue, e.collected, e.verified, e.last_activity ?? '']))}>Export CSV</button></>}>
          <p className="muted" style={{ marginTop: 0 }}>Case columns add up: <strong>Assigned = Reached + Called, not reached + Not called</strong>. "Calls answered" counts calls, not cases (one case can be called many times). Collected = payments recorded, excluding rejected slips. Click an employee for their cases.</p>
          <div className="table-wrap"><table>
            <thead><tr>
              <th><H k="name">Employee</H></th><th>Team</th><th>Portfolio</th><th className="num" title="Cases assigned = Reached + Called, not reached + Not called"><H k="cases">Assigned cases</H></th>
              <th className="num" title="Cases where at least one call was answered"><H k="reached_cases">Reached</H></th>
              <th className="num" title="Cases called, but no call answered yet"><H k="not_connected_cases">Called, not reached</H></th>
              <th className="num" title="Cases with no call yet"><H k="not_made">Not called</H></th>
              <th className="num" title="Calls answered of all calls made (one case can have many calls)"><H k="connected">Calls answered</H></th>
              <th className="num"><H k="callbacks">Callbacks</H></th>
              <th><H k="collected">Collected</H></th><th><H k="last_activity">Last activity</H></th>
            </tr></thead>
            <tbody>
              {employees.length === 0 && <tr><td colSpan={11} className="muted">No employees match.</td></tr>}
              {employees.map((e) => (
                <tr key={e.user_id} className="clickable" onClick={() => setDrill(e)}>
                  <td><span className="linkish">{e.name}</span> <small className="muted">{e.employee_code}</small><div className="flag-row">{flags(e)}</div></td>
                  <td className="nowrap">{e.groups.join(', ') || '—'}</td><td><small>{e.portfolios.join(', ') || '—'}</small></td>
                  <td className="num">{e.cases}</td><td className="num">{e.reached_cases}</td><td className="num">{e.not_connected_cases}</td><td className="num">{e.not_made}</td>
                  <td className="num">{e.connected} <small className="muted">of {e.calls}</small></td>
                  <td className="num">{e.callbacks}{e.callbacks_overdue ? <small className="bad-text"> ({e.callbacks_overdue} late)</small> : null}</td><td><Meter v={e.collected} max={maxCollected} text={inr(e.collected)} /></td>
                  <td className="nowrap">{e.last_activity ? short(e.last_activity, me?.company.timezone) : <span className="muted">never</span>}</td>
                </tr>
              ))}
            </tbody>
          </table></div>
        </Card>
      )}

      {d && d.outcomes.length > 0 && (
        <Card title="Call outcomes">
          <Table rows={d.outcomes.map((o: any, i: number) => ({ ...o, id: i }))} columns={[{ key: 'code', label: 'Outcome', render: (o: any) => o.code.replace(/_/g, ' ').toLowerCase() },
            { key: 'n', label: 'Calls', render: (o: any) => <Meter v={o.n} max={d.outcomes[0].n} text={String(o.n)} /> }]} />
        </Card>
      )}

      <CasesCard filter={f} title="Cases" />
      {drill && (
        <Modal title={`${drill.name} — cases`} onClose={() => setDrill(null)}>
          <div className="wide-modal" />
          <div className="kpi-grid small">
            <Kpi icon="contacts" tone="blue" label="Assigned cases" value={drill.cases} sub={`${drill.reached_cases} reached · ${drill.not_connected_cases} not reached · ${drill.not_made} not called`} />
            <Kpi icon="check" tone="green" label="Calls answered" value={drill.connected} sub={`of ${drill.calls} calls`} />
            <Kpi icon="event" tone="purple" label="Callbacks" value={drill.callbacks} sub={drill.callbacks_overdue ? `${drill.callbacks_overdue} overdue` : undefined} subTone={drill.callbacks_overdue ? 'down' : 'flat'} />
            <Kpi icon="chart" tone="gold" label="Collected" value={inr(drill.collected)} />
          </div>
          <CasesCard filter={{ ...f, employee: String(drill.user_id) }} title="Cases" />
        </Modal>
      )}
    </Page>
  );
}

/** Case-level list (50 per page) for the current filters; sortable and exportable. */
function CasesCard({ filter, title }: { filter: Record<string, string>; title: string }) {
  const { me } = useAuth();
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState('id');
  const [exporting, setExporting] = useState(false);
  useEffect(() => setPage(1), [JSON.stringify(filter), sort]);
  const c = useAsync(() => get(`/reports/portfolio/cases${qs({ ...filter, page, sort })}`), [JSON.stringify(filter), page, sort]);
  useLiveReload(c.reload);
  const exportAll = async () => {
    setExporting(true);
    try {
      const rows: any[] = [];
      for (let p = 1; p <= 200; p++) { const r = await get(`/reports/portfolio/cases${qs({ ...filter, page: p, sort })}`); rows.push(...r.data); if (p >= r.last_page) break; }
      downloadCsv('cases.csv', ['Customer ID', 'Name', 'Portfolio', 'Group', 'Employee', 'Status', 'City', 'Calls', 'Connected', 'Last call', 'Last outcome', 'Callback due', 'Collected'],
        rows.map((x) => [x.customer_ref, x.name, x.portfolio, x.group, x.employee, x.status, x.city, x.calls, x.connected, x.last_call_at, x.last_outcome, x.callback_due, x.collected]));
    } finally { setExporting(false); }
  };
  const d = c.data;
  return (
    <Card title={`${title}${d ? ` (${d.total})` : ''}`} actions={<>
      <select value={sort} onChange={(e) => setSort(e.target.value)} aria-label="Sort"><option value="id">Sort: oldest first</option><option value="name">Name</option><option value="calls_count">Most calls</option><option value="last_call_at">Last call</option><option value="collected">Most collected</option></select>
      <button className="ghost" disabled={exporting || !d?.total} onClick={exportAll}>{exporting ? 'Exporting…' : 'Export CSV'}</button></>}>
      <ErrorBox error={c.error} />
      <Table rows={d?.data} empty="No cases match." columns={[
        { key: 'customer_ref', label: 'Customer', render: (x: any) => <><Link to={`/customers/${x.id}`} className="nowrap">{x.name}</Link><br /><small className="muted">{x.customer_ref}</small></> },
        { key: 'portfolio', label: 'Portfolio' }, { key: 'employee', label: 'Employee', render: (x: any) => x.employee ?? <Badge value="pending" label="unassigned" /> },
        { key: 'group', label: 'Group', render: (x: any) => x.group ?? '—' }, { key: 'status', label: 'Status', render: (x: any) => <Badge value={x.status === 'closed' ? 'ok' : x.status === 'new' ? 'info' : 'pending'} label={x.status.replace(/_/g, ' ')} /> },
        { key: 'calls', label: 'Calls', align: 'right', render: (x: any) => x.calls ? `${x.connected}/${x.calls}` : <span className="muted">not called</span> },
        { key: 'last_call_at', label: 'Last call', render: (x: any) => <span className="nowrap">{short(x.last_call_at, me?.company.timezone)}</span> },
        { key: 'last_outcome', label: 'Last outcome', render: (x: any) => x.last_outcome?.replace(/_/g, ' ').toLowerCase() ?? '—' },
        { key: 'callback_due', label: 'Callback', render: (x: any) => x.callback_due ? <span className={new Date(x.callback_due) < new Date() ? 'bad-text nowrap' : 'nowrap'}>{short(x.callback_due, me?.company.timezone)}</span> : '—' },
        { key: 'collected', label: 'Collected', align: 'right', render: (x: any) => x.collected ? inr(x.collected) : '—' },
      ]} />
      {d && d.last_page > 1 && (
        <div className="row" style={{ marginTop: 10 }}>
          <button className="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Previous</button>
          <span className="muted">Page {d.current_page} of {d.last_page}</span>
          <button className="ghost" disabled={page >= d.last_page} onClick={() => setPage(page + 1)}>Next →</button>
        </div>
      )}
      {!d && !c.error && <Notice tone="info">Loading…</Notice>}
    </Card>
  );
}
