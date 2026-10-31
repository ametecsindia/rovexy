import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { get, post, fmtSeconds, qs } from '../lib/api';
import { useUnread, ago as agoMsg } from '../lib/unread';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Icon, Initials, Notice, Table, TrendBars, useAsync } from '../components/ui';

const pct = (a: number, b: number) => (b > 0 ? Math.round((a / b) * 100) : 0);

/** Status colours: one per live state, always shown with its label and count (never colour alone). */
const STATES = [
  { key: 'active', label: 'Active', color: '#1e7b4f', hint: 'Working in the app' },
  { key: 'on_call', label: 'On call', color: '#2563a8', hint: 'On a customer call now' },
  { key: 'idle', label: 'Idle', color: '#b7791f', hint: 'Signed in, no activity' },
  { key: 'signed_out', label: 'Signed out', color: '#7b8794', hint: 'Phone online, not signed in' },
  { key: 'offline', label: 'Offline', color: '#c3cad3', hint: 'Not heard from in 10 min' },
] as const;

function change(now: number, before: number): { text: string; tone: 'up' | 'down' | 'flat' } {
  if (before === 0) return { text: now > 0 ? 'no data in previous period' : '—', tone: 'flat' };
  const d = Math.round(((now - before) / before) * 100);
  return { text: `${d >= 0 ? '▲' : '▼'} ${Math.abs(d)}% vs previous period`, tone: d > 0 ? 'up' : d < 0 ? 'down' : 'flat' };
}

function ago(iso?: string | null) {
  if (!iso) return 'never';
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  return s < 60 ? 'just now' : s < 3600 ? `${Math.round(s / 60)} min ago` : s < 86400 ? `${Math.round(s / 3600)} h ago` : `${Math.round(s / 86400)} d ago`;
}

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

/** Ring chart of live states; segments separated by a 2px surface gap. */
function StatusDonut({ counts, total }: { counts: Record<string, number>; total: number }) {
  const [hover, setHover] = useState<string | null>(null);
  const R = 70, C = 2 * Math.PI * R, gap = total > 1 ? 2 : 0;
  let acc = 0;
  const online = total - (counts.offline ?? 0);
  return (
    <div className="donut">
      <svg viewBox="0 0 180 180" role="img" aria-label={`${online} of ${total} employees online`}>
        <circle cx="90" cy="90" r={R} fill="none" stroke="var(--muted-bg)" strokeWidth="18" />
        {STATES.map((s) => {
          const n = counts[s.key] ?? 0;
          if (!n || !total) return null;
          const len = (n / total) * C;
          const el = <circle key={s.key} cx="90" cy="90" r={R} fill="none" stroke={s.color} strokeWidth={hover === s.key ? 22 : 18}
            strokeDasharray={`${Math.max(0, len - gap)} ${C}`} strokeDashoffset={-acc} transform="rotate(-90 90 90)"
            onMouseEnter={() => setHover(s.key)} onMouseLeave={() => setHover(null)} style={{ transition: 'stroke-width .15s' }} />;
          acc += len;
          return el;
        })}
        <text x="90" y="86" textAnchor="middle" className="donut-big">{online}</text>
        <text x="90" y="106" textAnchor="middle" className="axis">of {total} online</text>
      </svg>
      {hover && <div className="donut-tip">{STATES.find((s) => s.key === hover)?.label}: {counts[hover]}</div>}
    </div>
  );
}

/** Single-series column chart with hover tooltip. */
function Columns({ data, label, color = 'var(--series-1)', fmt = (v: number) => String(v), title }: { data: { x: string; v: number; tip?: string }[]; label: (x: string) => string; color?: string; fmt?: (v: number) => string; title: string }) {
  const [hover, setHover] = useState<number | null>(null);
  const W = 640, H = 180, P = { l: 32, r: 8, t: 12, b: 24 };
  const max = Math.max(4, ...data.map((d) => d.v)); const step = Math.ceil(max / 4); const top = step * 4;
  const bw = (W - P.l - P.r) / Math.max(1, data.length);
  const y = (v: number) => P.t + (H - P.t - P.b) * (1 - v / top);
  const every = data.length > 14 ? Math.ceil(data.length / 10) : 1;
  return (
    <div className="chart">
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={title} onMouseLeave={() => setHover(null)}>
        {[0, 1, 2, 3, 4].map((i) => <g key={i}><line x1={P.l} x2={W - P.r} y1={y(i * step)} y2={y(i * step)} className="grid-line" /><text x={P.l - 6} y={y(i * step) + 4} textAnchor="end" className="axis">{i * step}</text></g>)}
        {data.map((d, i) => (
          <g key={d.x} onMouseEnter={() => setHover(i)}>
            <rect x={P.l + i * bw} y={P.t} width={bw} height={H - P.t - P.b} fill="transparent" />
            {d.v > 0 && <rect x={P.l + i * bw + bw * 0.18} y={y(d.v)} width={bw * 0.64} height={y(0) - y(d.v)} rx={Math.min(4, bw * 0.2)} fill={color} opacity={hover === null || hover === i ? 1 : 0.55} />}
            {i % every === 0 && <text x={P.l + i * bw + bw / 2} y={H - 7} textAnchor="middle" className="axis">{label(d.x)}</text>}
          </g>
        ))}
      </svg>
      {hover !== null && data[hover] && <div className="tooltip" style={{ left: `${((P.l + hover * bw + bw / 2) / W) * 100}%` }}><strong>{label(data[hover].x)}</strong><br />{data[hover].tip ?? fmt(data[hover].v)}</div>}
    </div>
  );
}

/** Ranked horizontal bars (one hue — magnitude, not identity), value labelled at the end. */
function HBars({ rows, empty }: { rows: [string, number][]; empty: string }) {
  const max = Math.max(1, ...rows.map((r) => r[1])); const total = rows.reduce((a, r) => a + r[1], 0);
  if (!rows.length) return <p className="muted small">{empty}</p>;
  return (
    <div className="hbars">
      {rows.map(([k, v]) => (
        <div className="hbar" key={k} title={`${k.replace(/_/g, ' ')}: ${v} (${pct(v, total)}%)`}>
          <span className="hbar-label">{k.replace(/_/g, ' ')}</span>
          <div className="hbar-track"><div style={{ width: `${(v / max) * 100}%` }} /></div>
          <span className="hbar-value">{v} <small className="muted">{pct(v, total)}%</small></span>
        </div>
      ))}
    </div>
  );
}

export function OverviewPage() {
  const { me, can } = useAuth();
  const [days, setDays] = useState(7);
  const [team, setTeam] = useState('');
  const [auto, setAuto] = useState(true);
  const [stateFilter, setStateFilter] = useState('');
  const [search, setSearch] = useState('');
  const { data, error, reload, loading } = useAsync(() => get(`/overview${qs({ days, team_id: team })}`), [days, team]);
  const teams = useAsync(() => (can('employees.view') ? get<any[]>('/teams') : Promise.resolve([])));
  const [inbox, setInbox] = useUnread(can('chat.use'), 10000);
  useEffect(() => { if (!auto) return; const t = setInterval(reload, 30000); return () => clearInterval(t); }, [auto, reload]);

  const k = data?.kpis, p = k?.prev, st = data?.status;
  const people: any[] = data?.people ?? [];
  const shown = useMemo(() => people.filter((x) => (!stateFilter || x.state === stateFilter) && (!search || `${x.name} ${x.employee_code} ${x.device}`.toLowerCase().includes(search.toLowerCase()))), [people, stateFilter, search]);
  const rangeLabel = days === 1 ? 'today' : `last ${days} days`;
  const dayLabel = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString(undefined, days === 30 ? { day: 'numeric', month: 'short' } : { weekday: 'short', day: 'numeric' });
  const maxTalk = Math.max(1, ...(data?.leaderboard ?? []).map((e: any) => e.talk_seconds));

  /** Status cards filter the live team table below and scroll to it. */
  const showTeam = (state: string) => { setStateFilter(state); setTimeout(() => document.getElementById('team-live')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50); };
  const kp = (now: number, before: number) => change(now, before);
  const Stat = ({ icon, tone, label, value, sub, subTone, to, onClick }: { icon: string; tone: string; label: string; value: ReactNode; sub?: ReactNode; subTone?: string; to?: string; onClick?: () => void }) => {
    const inner = (<><div className="stat-top"><span className="stat-label">{label}</span><span className={`stat-icon ${tone}`}><Icon name={icon} size={18} /></span></div>
      <div className="stat-value">{value}</div>{sub != null && <div className={`stat-sub ${subTone ?? 'flat'}`}>{sub}</div>}</>);
    return to ? <Link to={to} className={`stat ${tone}`}>{inner}</Link> : <button type="button" className={`stat ${tone}`} onClick={onClick}>{inner}</button>;
  };

  return (
    <section className="page dash">
      <header className="dash-head">
        <div>
          <div className="crumbs">Home <span>/</span> Dashboard</div>
          <h1>Dashboard</h1>
          <p className="muted">{greeting()}, {me?.name?.split(' ')[0] ?? 'there'} · {new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
        </div>
        <div className="dash-tools">
          <div className="seg" role="group" aria-label="Date range">
            {[[1, 'Today'], [7, '7 days'], [30, '30 days']].map(([d, l]) => <button key={d} className={days === d ? 'on' : ''} onClick={() => setDays(d as number)}>{l}</button>)}
          </div>
          {(teams.data?.length ?? 0) > 0 && <select value={team} onChange={(e) => setTeam(e.target.value)} aria-label="Team"><option value="">All teams</option>{teams.data!.map((t: any) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}
          <label className="switch" title="Refresh every 30 seconds"><input type="checkbox" checked={auto} onChange={(e) => setAuto(e.target.checked)} /><span /> Live</label>
          <button className="icon-btn outline" onClick={reload} disabled={loading} title="Refresh now" aria-label="Refresh"><Icon name="refresh" size={18} /></button>
        </div>
      </header>
      <ErrorBox error={error} />
      {(inbox?.total ?? 0) > 0 && (
        <div className="card inbox-notice" role="status">
          <div className="row" style={{ justifyContent: 'space-between' }}>
            <strong><Icon name="chat" size={18} /> {inbox!.total} new message{inbox!.total === 1 ? '' : 's'} from employees</strong>
            <span className="row"><Link to="/chat">Open messages</Link><button className="ghost sm" onClick={() => post('/chat/read-all').then(setInbox).catch(() => undefined)}>Mark all read</button></span>
          </div>
          <ul>
            {inbox!.items.map((m) => (
              <li key={m.conversation_id}><Link to={`/chat?c=${m.conversation_id}`}>
                <Initials name={m.from} size={32} />
                <span className="who"><strong>{m.from}{m.count > 1 ? ` · ${m.count} new` : ''}</strong><small>{m.body}</small></span>
                <small className="muted">{agoMsg(m.at)}</small>
              </Link></li>
            ))}
          </ul>
        </div>
      )}
      {!data && !error && <div className="skeleton-grid">{Array.from({ length: 8 }).map((_, i) => <div key={i} className="skeleton" />)}</div>}

      {data && (
        <>
          <div className="notices">
            {data.devices.pending > 0 && <Notice tone="warn">{data.devices.pending} phone(s) waiting for approval. <Link to="/devices">Review requests</Link></Notice>}
            {data.devices.non_compliant > 0 && <Notice tone="bad">{data.devices.non_compliant} of {data.devices.approved} approved phone(s) have not applied the company's required phone policy, so customer calls are blocked on them until they do. <Link to="/devices">See which phones and why</Link></Notice>}
            {data.recordings_failed_7d > 0 && <Notice tone="bad">{data.recordings_failed_7d} recording(s) failed in the last 7 days. <Link to="/calls?recording_status=failed">Review</Link></Notice>}
            {data.follow_ups.overdue > 0 && <Notice tone="warn">{data.follow_ups.overdue} follow-up(s) are overdue. <Link to="/follow-ups">Open follow-ups</Link></Notice>}
            {data.devices.low_battery > 0 && <Notice tone="warn">{data.devices.low_battery} online phone(s) below 20% battery.</Notice>}
          </div>

          {/* Row 1 — people right now */}
          <div className="stat-grid">
            <Stat icon="people" tone="navy" label="Online now" value={<>{st.online}<small> / {st.total}</small></>} sub={`${pct(st.online, st.total)}% of employees`} onClick={() => showTeam('')} />
            <Stat icon="check" tone="green" label="Active" value={st.active} sub="working in the app" onClick={() => showTeam('active')} />
            <Stat icon="call" tone="blue" label="On call" value={st.on_call} sub="customer calls in progress" onClick={() => showTeam('on_call')} />
            <Stat icon="clock" tone="amber" label="Idle · signed out" value={<>{st.idle}<small> · {st.signed_out}</small></>} sub="online, not working" onClick={() => showTeam(st.idle || !st.signed_out ? 'idle' : 'signed_out')} />
            <Stat icon="warning" tone="gray" label="Offline" value={st.offline} sub="not seen in 10 min" onClick={() => showTeam('offline')} />
          </div>

          {/* Row 2 — calling KPIs for the range */}
          <div className="section-title">Calling performance <span>· {rangeLabel}, compared with the previous {days === 1 ? 'day' : `${days} days`}</span></div>
          <div className="stat-grid">
            <Stat icon="call" tone="blue" label="Total calls" value={k.calls} sub={kp(k.calls, p.calls).text} subTone={kp(k.calls, p.calls).tone} to="/calls" />
            <Stat icon="check" tone="green" label="Connected" value={<>{k.connected}<small> · {pct(k.connected, k.calls)}%</small></>} sub={kp(k.connected, p.connected).text} subTone={kp(k.connected, p.connected).tone} to="/calls?state=completed" />
            <Stat icon="clock" tone="teal" label="Talk time" value={fmtSeconds(k.talk_seconds)} sub={kp(k.talk_seconds, p.talk_seconds).text} subTone={kp(k.talk_seconds, p.talk_seconds).tone} to="/calls?state=completed" />
            <Stat icon="clock" tone="purple" label="Avg talk / call" value={fmtSeconds(k.avg_talk)} sub={k.avg_ring ? `avg ring ${fmtSeconds(k.avg_ring)}` : 'per connected call'} to="/calls?state=completed" />
            <Stat icon="contacts" tone="gold" label="Customers reached" value={k.customers} sub={`${k.callers} employee(s) calling`} to="/customers" />
          </div>
          <div className="stat-grid compact">
            <Stat icon="event" tone="amber" label="Follow-ups due today" value={data.follow_ups.due_today} sub={data.follow_ups.overdue ? `${data.follow_ups.overdue} overdue` : 'nothing overdue'} subTone={data.follow_ups.overdue ? 'down' : 'flat'} to="/follow-ups" />
            <Stat icon="mic" tone="purple" label="Recordings" value={data.recordings.available} sub={`${data.recordings.pending} pending · ${data.recordings.failed} failed`} subTone={data.recordings.failed ? 'down' : 'flat'} to={`/calls?recording_status=${data.recordings.failed ? 'failed' : 'available'}`} />
            <Stat icon="phone" tone="navy" label="Approved phones" value={data.devices.approved} sub={`${data.devices.pending} pending approval`} subTone={data.devices.pending ? 'down' : 'flat'} to="/devices" />
            <Stat icon="shield" tone={data.devices.non_compliant ? 'red' : 'green'} label="Policy compliance" value={`${pct(data.devices.approved - data.devices.non_compliant, Math.max(1, data.devices.approved))}%`} sub={`${data.devices.non_compliant} of ${data.devices.approved} phones not following policy · ${data.devices.silent_24h} not heard from in 24 h+`} subTone={data.devices.non_compliant ? 'down' : 'flat'} to="/devices" />
          </div>

          {/* Row 3 — trend + team status */}
          <div className="grid-main">
            <Card title={`Calls per day — last ${data.trend.length} days`} actions={<Link to="/calls">View calls →</Link>}><TrendBars days={data.trend} /></Card>
            <Card title="Team status">
              <StatusDonut counts={st} total={st.total} />
              <div className="legend-list">
                {STATES.map((s) => (
                  <button key={s.key} className={stateFilter === s.key ? 'on' : ''} onClick={() => setStateFilter(stateFilter === s.key ? '' : s.key)}>
                    <i style={{ background: s.color }} /><span>{s.label}</span><strong>{st[s.key]}</strong><small>{pct(st[s.key], st.total)}%</small>
                  </button>
                ))}
              </div>
            </Card>
          </div>

          {/* Row 4 — activity charts */}
          <div className="grid-2">
            <Card title="Calls by hour — today"><Columns title="Calls per hour today" data={data.hourly.map((h: any) => ({ x: String(h.hour), v: h.calls, tip: `${h.calls} calls · ${h.connected} connected` }))} label={(x) => `${x.padStart(2, '0')}h`} /></Card>
            <Card title={`Active employees per day — last ${data.trend.length} days`}><Columns title="Employees who called or used the app, per day" data={data.trend.map((d: any) => ({ x: d.date, v: d.active_users, tip: `${d.active_users} active · ${d.calls} calls · ${fmtSeconds(d.talk_seconds)} talk` }))} label={dayLabel} color="var(--series-3)" /></Card>
          </div>

          {/* Row 5 — breakdowns */}
          <div className="grid-2">
            <Card title={`Call outcomes · ${rangeLabel}`}><HBars rows={Object.entries(data.outcomes ?? {}) as [string, number][]} empty="No calls in this period." /></Card>
            <Card title={`Dispositions · ${rangeLabel}`} actions={<Link to="/follow-ups">Follow-ups →</Link>}><HBars rows={Object.entries(data.dispositions ?? {}) as [string, number][]} empty="No outcomes recorded yet." /></Card>
          </div>

          {/* Row 6 — tables */}
          <div id="team-live" className="anchor" />
          <Card title={`Team — live status${stateFilter ? ` · ${STATES.find((s) => s.key === stateFilter)?.label}` : ''}`}
            actions={<div className="row" style={{ gap: 8 }}>{stateFilter && <button className="ghost" onClick={() => setStateFilter('')}>Clear filter</button>}<div className="search-box sm"><Icon name="search" size={15} /><input placeholder="Search employee or phone" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search employees" /></div></div>}>
            <Table rows={shown.map((x) => ({ id: x.user_id, ...x }))} empty="No employees match." columns={[
              { key: 'name', label: 'Employee', render: (e: any) => <Link className="person" to={`/calls?user_id=${e.user_id}`}><Initials name={e.name ?? '?'} size={30} /><span className="stack"><span>{e.name}</span><small className="muted">{e.employee_code} · {e.device}</small></span></Link> },
              { key: 'state', label: 'Status', render: (e: any) => <Badge value={e.state} /> },
              { key: 'last_contact_at', label: 'Last seen', render: (e: any) => <span className="muted">{ago(e.last_contact_at)}</span> },
              { key: 'calls_today', label: 'Calls today', align: 'right', render: (e: any) => <span className="mono">{e.calls_today}</span> },
              { key: 'talk_today', label: 'Talk today', align: 'right', render: (e: any) => <span className="mono">{fmtSeconds(e.talk_today)}</span> },
              { key: 'battery_pct', label: 'Battery', align: 'right', render: (e: any) => e.battery_pct == null ? <span className="muted">—</span> : <span className={e.battery_pct < 20 ? 'bad-text mono' : 'mono'}>{e.battery_pct}%</span> },
              { key: 'network_type', label: 'Network', render: (e: any) => <span className="muted">{e.network_type ?? '—'}</span> },
              { key: 'compliant', label: 'Policy', render: (e: any) => <Badge value={e.compliant} label={e.compliant ? 'compliant' : 'not compliant'} /> },
            ]} />
          </Card>

          <Card title={`Employee performance · ${rangeLabel}`} actions={<Link to="/reports">Export →</Link>}>
            <Table rows={(data.leaderboard ?? []).map((e: any) => ({ id: e.user_id, ...e }))} empty="No calls in this period." columns={[
              { key: 'name', label: 'Employee', render: (e: any) => <Link className="person" to={`/calls?user_id=${e.user_id}`}><Initials name={e.name ?? '?'} size={30} /> {e.name ?? `#${e.user_id}`}</Link> },
              { key: 'state', label: 'Now', render: (e: any) => <Badge value={e.state} /> },
              { key: 'calls', label: 'Calls', align: 'right', render: (e: any) => <span className="mono">{e.calls}</span> },
              { key: 'connected', label: 'Connected', align: 'right', render: (e: any) => <span className="mono">{e.connected}</span> },
              { key: 'rate', label: 'Connect rate', render: (e: any) => <div className="rate"><div className="meter thin"><div style={{ width: `${pct(e.connected, e.calls)}%` }} /></div><span>{pct(e.connected, e.calls)}%</span></div> },
              { key: 'talk_seconds', label: 'Talk time', render: (e: any) => <div className="rate"><div className="meter thin teal"><div style={{ width: `${(e.talk_seconds / maxTalk) * 100}%` }} /></div><span>{fmtSeconds(e.talk_seconds)}</span></div> },
              { key: 'avg', label: 'Avg call', align: 'right', render: (e: any) => <span className="mono">{fmtSeconds(e.connected ? Math.round(e.talk_seconds / e.connected) : 0)}</span> },
              { key: 'recorded', label: 'Recorded', align: 'right', render: (e: any) => <>{e.recorded}{e.recording_failed > 0 && <span className="bad-text"> · {e.recording_failed} failed</span>}</> },
              { key: 'undisposed', label: 'No outcome', align: 'right', render: (e: any) => e.undisposed ? <span className="warn-text">{e.undisposed}</span> : <span className="muted">0</span> },
            ]} />
          </Card>
        </>
      )}
    </section>
  );
}
