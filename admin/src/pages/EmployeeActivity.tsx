import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { download, fileUrl, get, put, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useLiveReload } from '../lib/live';
import { Card, ErrorBox, Field, Kpi, Notice, Page, Table, toast, useAction, useAsync } from '../components/ui';

/**
 * Reports → Employee activity. Team view (everyone in the viewer's groups and the top-bar filter) or one employee:
 * attendance, worked / productive / idle / break / travel time, distance, the day timeline, Excel and PDF.
 * Numbers come from /api/admin/activity/* (App\Services\Activity\DayBuilder).
 */

export const STATE_COLOR: Record<string, string> = { active: '#2563a8', customer: '#c2621f', travel: '#1e7b4f', break: '#7c5cc4', idle: '#e3b52d', offline: '#9aa5b1' };
export const STATE_LABEL: Record<string, string> = { active: 'Active work', customer: 'At customer', travel: 'Travel', break: 'Break', idle: 'Idle', offline: 'Offline (no data)' };
const ATT_LABEL: Record<string, string> = { present: 'Present', half_day: 'Half day', short: 'Short day', absent: 'Absent', off: 'Weekly off', worked_off: 'Worked on day off' };
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const hm = (s?: number | null) => (s == null ? '—' : `${Math.floor(s / 3600)}h\u00a0${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m`);
const km = (m?: number | null) => (m == null ? '—' : `${(m / 1000).toFixed(1)} km`);
const pc = (v?: number | null) => (v == null ? '—' : `${v}%`);

function ranges(tz: string): [string, string, string][] {
  const today = new Date().toLocaleDateString('en-CA', { timeZone: tz });
  const d = (n: number) => { const x = new Date(today + 'T00:00:00'); x.setDate(x.getDate() + n); return x.toLocaleDateString('en-CA'); };
  const t = new Date(today + 'T00:00:00');
  const mon = d(-((t.getDay() + 6) % 7));
  const first = today.slice(0, 8) + '01';
  const lastMonthEnd = new Date(t.getFullYear(), t.getMonth(), 0).toLocaleDateString('en-CA');
  return [['Today', today, today], ['Yesterday', d(-1), d(-1)], ['This week', mon, today], ['Last 7 days', d(-6), today],
    ['This month', first, today], ['Last month', lastMonthEnd.slice(0, 8) + '01', lastMonthEnd]];
}

/** The day as one bar: every signed-in minute in exactly one colour. */
function Timeline({ segments, tz }: { segments: any[]; tz: string }) {
  if (!segments.length) return <p className="muted">Not signed in on this day.</p>;
  const a = Date.parse(segments[0].from), b = Date.parse(segments[segments.length - 1].to);
  const span = Math.max(1, b - a);
  const t = (iso: string) => new Date(iso).toLocaleTimeString('en-IN', { timeZone: tz, hour: '2-digit', minute: '2-digit' });
  const hours: number[] = [];
  for (let h = Math.ceil(a / 3600e3) * 3600e3; h <= b; h += 3600e3) hours.push(h);
  return (
    <div>
      <div style={{ position: 'relative', height: 34, borderRadius: 8, overflow: 'hidden', background: 'var(--surface-2, #f3f5f8)' }} role="img" aria-label="Day timeline">
        {segments.map((s, i) => (
          <div key={i} title={`${STATE_LABEL[s.state]} · ${t(s.from)}–${t(s.to)} · ${s.minutes} min`}
            style={{ position: 'absolute', top: 0, bottom: 0, left: `${((Date.parse(s.from) - a) / span) * 100}%`, width: `${Math.max(0.15, ((Date.parse(s.to) - Date.parse(s.from)) / span) * 100)}%`, background: STATE_COLOR[s.state] }} />
        ))}
      </div>
      <div style={{ position: 'relative', height: 18, fontSize: 11 }} className="muted">
        {hours.map((h) => <span key={h} style={{ position: 'absolute', left: `${((h - a) / span) * 100}%`, transform: 'translateX(-50%)', whiteSpace: 'nowrap' }}>{new Date(h).toLocaleTimeString('en-IN', { timeZone: tz, hour: '2-digit', minute: '2-digit' })}</span>)}
      </div>
      <div className="legend" style={{ display: 'flex', flexWrap: 'wrap', gap: 14, marginTop: 8, fontSize: 12 }}>
        {Object.keys(STATE_LABEL).map((k) => <span key={k}><i style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 2, background: STATE_COLOR[k], marginRight: 5 }} />{STATE_LABEL[k]}</span>)}
      </div>
    </div>
  );
}

/** Worked time split into its parts, for a list of rows (team or days). */
const Split = ({ r }: { r: any }) => {
  const total = (r.active_s ?? 0) + (r.customer_s ?? 0) + (r.travel_s ?? 0) + (r.break_s ?? 0) + (r.idle_s ?? 0) + (r.offline_s ?? 0);
  if (!total) return <span className="muted">—</span>;
  return (
    <div style={{ display: 'flex', height: 10, minWidth: 120, borderRadius: 5, overflow: 'hidden' }} title={Object.keys(STATE_LABEL).map((k) => `${STATE_LABEL[k]} ${hm(r[`${k}_s`])}`).join(' · ')}>
      {Object.keys(STATE_LABEL).map((k) => r[`${k}_s`] > 0 && <div key={k} style={{ width: `${(r[`${k}_s`] / total) * 100}%`, background: STATE_COLOR[k] }} />)}
    </div>
  );
};

function Settings({ onSaved }: { onSaved: () => void }) {
  const s = useAsync(() => get('/activity/settings'), []);
  const [h, setH] = useState<any>(null);
  const [br, setBr] = useState<any[]>([]);
  const save = useAction();
  useEffect(() => { if (s.data) { setH(s.data.hours); setBr(s.data.branches); } }, [s.data]);
  if (!h) return <ErrorBox error={s.error} />;
  const set = (k: string, v: unknown) => setH({ ...h, [k]: v });
  const setB = (i: number, k: string, v: string) => setBr(br.map((b, j) => (j === i ? { ...b, [k]: v === '' ? null : Number(v) } : b)));
  return (
    <div>
      <ErrorBox error={save.error} />
      <div className="filters">
        <Field label="Working days">
          <div className="seg">{DAYS.map((d, i) => { const on = h.days.includes(i + 1); return <button key={d} type="button" className={on ? 'on' : ''} onClick={() => set('days', on ? h.days.filter((x: number) => x !== i + 1) : [...h.days, i + 1].sort())}>{d}</button>; })}</div>
        </Field>
        <Field label="Day starts"><input type="time" value={h.start} onChange={(e) => set('start', e.target.value)} /></Field>
        <Field label="Day ends"><input type="time" value={h.end} onChange={(e) => set('end', e.target.value)} /></Field>
        <Field label="Grace (minutes)" hint="Late / early only beyond this"><input type="number" min={0} max={120} value={h.grace_minutes} onChange={(e) => set('grace_minutes', Number(e.target.value))} /></Field>
        <Field label="Full day (hours worked)"><input type="number" min={1} max={16} step={0.5} value={h.full_day_hours} onChange={(e) => set('full_day_hours', Number(e.target.value))} /></Field>
        <Field label="Half day (hours worked)"><input type="number" min={0.5} max={12} step={0.5} value={h.half_day_hours} onChange={(e) => set('half_day_hours', Number(e.target.value))} /></Field>
        <Field label="Idle after (minutes)" hint="No recorded activity for this long"><input type="number" min={3} max={120} value={h.idle_minutes} onChange={(e) => set('idle_minutes', Number(e.target.value))} /></Field>
        <Field label="Travel counts as productive"><select value={h.travel_productive ? '1' : '0'} onChange={(e) => set('travel_productive', e.target.value === '1')}><option value="1">Yes (field staff)</option><option value="0">No</option></select></Field>
      </div>
      <h3 style={{ margin: '12px 0 6px' }}>Office locations</h3>
      <p className="muted small">Time inside this circle counts as <b>at office</b>. Copy latitude and longitude from Google Maps (right-click the office → the numbers at the top).</p>
      <Table rows={br} empty="No branches yet — add them on Branches, teams &amp; people." columns={[
        { key: 'name', label: 'Branch' },
        { key: 'lat', label: 'Latitude', render: (b: any) => <input type="number" step="0.0000001" value={b.lat ?? ''} onChange={(e) => setB(br.indexOf(b), 'lat', e.target.value)} style={{ width: 140 }} /> },
        { key: 'lng', label: 'Longitude', render: (b: any) => <input type="number" step="0.0000001" value={b.lng ?? ''} onChange={(e) => setB(br.indexOf(b), 'lng', e.target.value)} style={{ width: 140 }} /> },
        { key: 'radius_m', label: 'Radius (m)', render: (b: any) => <input type="number" min={30} max={2000} value={b.radius_m ?? 150} onChange={(e) => setB(br.indexOf(b), 'radius_m', e.target.value)} style={{ width: 100 }} /> },
      ]} />
      <div className="row" style={{ marginTop: 12 }}>
        <button disabled={save.busy} onClick={() => void save.run(async () => {
          await put('/activity/settings', { hours: h, branches: br.map((b) => ({ id: b.id, lat: b.lat, lng: b.lng, radius_m: b.radius_m })) });
          toast('Work hours saved. Today is being recalculated; earlier days update tonight.');
          onSaved();
        })}>{save.busy ? 'Saving…' : 'Save work hours'}</button>
      </div>
    </div>
  );
}

export function EmployeeActivityPage() {
  const { me, can } = useAuth() as any;
  const tz: string = me?.company.timezone ?? 'Asia/Kolkata';
  const presets = ranges(tz);
  const [params, setParams] = useSearchParams();
  const user = params.get('user') ?? '';
  const from = params.get('from') ?? presets[0][1];
  const to = params.get('to') ?? presets[0][2];
  const set = (p: Record<string, string>) => setParams({ user, from, to, ...p }, { replace: true });
  const [showSettings, setShowSettings] = useState(false);
  const oneDay = from === to;

  const team = useAsync(() => get(`/activity/team${qs({ from, to })}`), [from, to]);
  const emp = useAsync(() => (user ? get(`/activity/employee${qs({ user_id: user, from, to })}`) : Promise.resolve(null)), [user, from, to]);
  const day = useAsync(() => (user && oneDay ? get(`/activity/day${qs({ user_id: user, date: from })}`) : Promise.resolve(null)), [user, from, oneDay]);
  useLiveReload(() => { void team.reload(); void emp.reload(); void day.reload(); }, ['call.'], 120_000);   // rebuilding a day is heavier than a list: every 2 min at most
  const dl = useAction();

  const people: any[] = team.data?.employees ?? [];
  const t = user ? emp.data?.totals : team.data?.totals;
  const time = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-IN', { timeZone: tz, hour: '2-digit', minute: '2-digit' }) : '—');
  const exportQs = (format: string) => qs({ kind: user ? 'employee' : 'team', format, user_id: user || undefined, from, to });

  return (
    <Page title="Employee activity" subtitle="Attendance, working hours, productive and idle time, breaks, travel and distance — for the whole team or one employee. Use the Branch / Team bar at the top to narrow the team."
      actions={<>
        {can('reports.export') && <button className="secondary" disabled={dl.busy} onClick={() => void dl.run(() => download(`/activity/export${exportQs('xlsx')}`))}>Excel</button>}
        {can('reports.export') && <a className="button secondary" href={fileUrl(`/api/admin/activity/export${exportQs('pdf')}`)} target="_blank" rel="noopener">PDF</a>}
        {can('employees.manage') && <button className="ghost" onClick={() => setShowSettings(!showSettings)}>Work hours</button>}
      </>}>
      <ErrorBox error={team.error || emp.error || day.error || dl.error} />
      {showSettings && <Card title="Work hours & office locations"><Settings onSaved={() => { setShowSettings(false); void team.reload(); void emp.reload(); void day.reload(); }} /></Card>}
      <Card>
        <div className="filters filter-bar">
          <select value={user} onChange={(e) => set({ user: e.target.value })} aria-label="Employee" style={{ minWidth: 240 }}>
            <option value="">All employees (team view)</option>
            {people.map((p) => <option key={p.employee.id} value={p.employee.id}>{p.employee.name} ({p.employee.employee_code})</option>)}
          </select>
          <input type="date" value={from} max={to} onChange={(e) => set({ from: e.target.value })} aria-label="From" />
          <input type="date" value={to} min={from} onChange={(e) => set({ to: e.target.value })} aria-label="To" />
          <div className="seg">{presets.map(([l, a, b]) => <button key={l} className={from === a && to === b ? 'on' : ''} onClick={() => set({ from: a, to: b })}>{l}</button>)}</div>
        </div>
        {team.data?.building && <Notice>Earlier days are being calculated for the first time — refresh in a minute.</Notice>}
        {(t?.coverage_pct ?? 100) < 80 && <Notice tone="warn">Only {t?.coverage_pct}% of signed-in time has data from the phone (switched off, no network, or the Play Store app closed). Offline time is shown separately and never counted as idle.</Notice>}
      </Card>

      {t && (
        <div className="kpi-grid">
          <Kpi icon="clock" tone="blue" label="Worked" value={hm(t.worked_s)} sub={`signed in ${hm(t.signed_in_s)}`} />
          <Kpi icon="check" tone="green" label="Productive" value={hm(t.productive_s)} sub={`${pc(t.productivity_pct)} of worked time`} subTone={t.productivity_pct == null ? 'flat' : t.productivity_pct >= 70 ? 'up' : 'down'} />
          <Kpi icon="warning" tone="gold" label="Idle" value={hm(t.idle_s)} sub={`${t.idle_sessions} sessions · longest ${hm(t.longest_idle_s)}`} subTone={t.high_idle ? 'down' : 'flat'} />
          <Kpi icon="event" tone="purple" label="Break" value={hm(t.break_s)} sub="from the app's Break button" />
          <Kpi icon="pin" tone="teal" label="Travel" value={hm(t.travel_s)} sub={`${km(t.distance_m)} · ${t.visits} visits`} />
          <Kpi icon="people" tone="blue" label="Attendance" value={pc(t.attendance_pct)} sub={`${t.present} present · ${t.half_day} half · ${t.absent} absent · ${t.late_days} late`} subTone={t.late_days ? 'down' : 'flat'} />
          <Kpi icon="call" tone="green" label="Calls" value={t.calls} sub={`${t.connected} connected · talk ${hm(t.talk_s)}`} />
          {t.planned_stops > 0 && <Kpi icon="pin" tone="gold" label="Planned stops visited" value={`${t.planned_visited} / ${t.planned_stops}`} />}
        </div>
      )}

      {!user && (
        <Card title="Team">
          <Table rows={people.map((p) => ({ ...p, id: p.employee.id }))} onRow={(p: any) => set({ user: String(p.employee.id) })} empty="No employees in view."
            error={team.error} columns={[
              { key: 'name', label: 'Employee', render: (p: any) => <><strong>{p.employee.name}</strong><br /><small className="muted">{p.employee.employee_code}</small></> },
              { key: 'login', label: oneDay ? 'Login' : 'Avg login', align: 'right', render: (p: any) => <>{p.login ?? '—'}{oneDay && p.late_days > 0 && <><br /><small style={{ color: 'var(--bad)' }}>late</small></>}</> },
              { key: 'logout', label: oneDay ? 'Logout' : 'Avg logout', align: 'right', render: (p: any) => (p.logout === 'no sign-out' ? <small className="muted">no sign-out</small> : p.logout ?? '—') },
              { key: 'att', label: 'Attendance', align: 'right', render: (p: any) => <>{pc(p.attendance_pct)}{p.late_days > 0 && <><br /><small className="muted">{p.late_days} late</small></>}</> },
              { key: 'worked', label: 'Worked', align: 'right', render: (p: any) => hm(p.worked_s) },
              { key: 'prod', label: 'Productive', align: 'right', render: (p: any) => <>{hm(p.productive_s)}<br /><small className="muted">{pc(p.productivity_pct)}</small></> },
              { key: 'idle', label: 'Idle', align: 'right', render: (p: any) => <span style={{ color: p.high_idle ? 'var(--bad)' : undefined, fontWeight: p.high_idle ? 600 : undefined }} title={p.high_idle ? 'Idle is 25% or more of worked time' : undefined}>{hm(p.idle_s)}</span> },
              { key: 'break', label: 'Break', align: 'right', render: (p: any) => hm(p.break_s) },
              { key: 'travel', label: 'Travel', align: 'right', render: (p: any) => <>{hm(p.travel_s)}<br /><small className="muted">{km(p.distance_m)}</small></> },
              { key: 'calls', label: 'Calls', align: 'right', render: (p: any) => `${p.calls} / ${p.connected}` },
              { key: 'visits', label: 'Visits', align: 'right' },
              { key: 'split', label: 'Day split', render: (p: any) => <Split r={p} /> },
            ]} />
          <p className="muted small" style={{ marginBottom: 0 }}>Click an employee for their detail. Login = first sign-in, Logout = last sign-out{oneDay ? '' : ' (average over the days worked)'}, company time. Idle in red = 25% or more of worked time.</p>
        </Card>
      )}

      {user && oneDay && day.data && (
        <>
          <Card title={`${day.data.employee.name} · ${new Date(from + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })}`}
            actions={<span className="tag">{day.data.summary.final ? ATT_LABEL[day.data.summary.attendance] ?? day.data.summary.attendance : 'In progress'}</span>}>
            <p style={{ marginTop: 0 }}>
              First login <b>{time(day.data.summary.first_login_at)}</b>{day.data.summary.late_min > 0 && <span style={{ color: 'var(--bad)' }}> ({day.data.summary.late_min} min late)</span>}
              {' · '}Last logout <b>{day.data.summary.no_signout ? 'did not sign out' : time(day.data.summary.last_logout_at)}</b>{day.data.summary.early_min > 0 && <span style={{ color: 'var(--bad)' }}> ({day.data.summary.early_min} min early)</span>}
              {' · '}Planned {day.data.hours.start}–{day.data.hours.end}
            </p>
            <Timeline segments={day.data.segments} tz={tz} />
          </Card>
          <div className="grid2">
            <Card title="Sign-in history">
              <Table rows={day.data.logins.map((l: any, i: number) => ({ ...l, id: i }))} empty="No sign-in or sign-out on this day." columns={[
                { key: 'at', label: 'Time', render: (l: any) => time(l.at) }, { key: 'type', label: 'Event', render: (l: any) => (l.type === 'sign_in' ? 'Signed in' : 'Signed out') }]} />
            </Card>
            <Card title="Idle sessions">
              <Table rows={day.data.idle.map((l: any, i: number) => ({ ...l, id: i }))} empty="No idle time." columns={[
                { key: 'from', label: 'From', render: (l: any) => time(l.from) }, { key: 'to', label: 'To', render: (l: any) => time(l.to) }, { key: 'minutes', label: 'Minutes', align: 'right' }]} />
              <p className="muted small" style={{ marginBottom: 0 }}>Idle = signed in and online with no call, case work, visit, travel or app use recorded for {day.data.hours.idle_minutes}+ minutes. It is not counted as unproductive.</p>
            </Card>
            <Card title="Breaks">
              <Table rows={day.data.breaks.map((l: any, i: number) => ({ ...l, id: i }))} empty="No breaks taken (needs ROVEXY app with the Break button)." columns={[
                { key: 'from', label: 'From', render: (l: any) => time(l.from) }, { key: 'to', label: 'To', render: (l: any) => time(l.to) }, { key: 'minutes', label: 'Minutes', align: 'right' }, { key: 'reason', label: 'Reason' }]} />
            </Card>
            <Card title="Customer visits">
              <Table rows={day.data.visits.map((l: any, i: number) => ({ ...l, id: i }))} empty="No visits." columns={[
                { key: 'at', label: 'Checked in', render: (l: any) => time(l.at) }, { key: 'name', label: 'Customer', render: (l: any) => <>{l.name ?? '—'}<br /><small className="muted">{l.customer_ref}</small></> },
                { key: 'minutes', label: 'Time there', align: 'right', render: (l: any) => `${l.minutes} min` }]} />
              {Object.keys(day.data.offices ?? {}).length > 0 && <p className="muted small">At office: {Object.entries(day.data.offices).map(([n, m]) => `${n} ${m} min`).join(', ')}</p>}
            </Card>
          </div>
        </>
      )}

      {user && emp.data && !oneDay && (
        <Card title={`${emp.data.employee.name} · day by day`}>
          <Table rows={emp.data.days.map((d: any) => ({ ...d, id: d.work_date }))} onRow={(d: any) => set({ from: d.work_date, to: d.work_date })} empty="No days." columns={[
            { key: 'work_date', label: 'Date', render: (d: any) => new Date(d.work_date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) },
            { key: 'attendance', label: 'Attendance', render: (d: any) => (d.final ? ATT_LABEL[d.attendance] ?? d.attendance : <span className="muted">Today, in progress</span>) },
            { key: 'in', label: 'In', render: (d: any) => <>{time(d.first_login_at)}{d.late_min > 0 && <small style={{ color: 'var(--bad)' }}> +{d.late_min}m</small>}</> },
            { key: 'out', label: 'Out', render: (d: any) => (d.no_signout ? <small className="muted">no sign-out</small> : <>{time(d.last_logout_at)}{d.early_min > 0 && <small style={{ color: 'var(--bad)' }}> −{d.early_min}m</small>}</>) },
            { key: 'worked', label: 'Worked', align: 'right', render: (d: any) => hm(d.worked_s) },
            { key: 'prod', label: 'Productive', align: 'right', render: (d: any) => <>{hm(d.productive_s)} <small className="muted">{pc(d.productivity_pct)}</small></> },
            { key: 'idle', label: 'Idle', align: 'right', render: (d: any) => hm(d.idle_s) },
            { key: 'travel', label: 'Travel', align: 'right', render: (d: any) => <>{hm(d.travel_s)} <small className="muted">{km(d.distance_m)}</small></> },
            { key: 'calls', label: 'Calls', align: 'right', render: (d: any) => `${d.calls} / ${d.connected}` },
            { key: 'visits', label: 'Visits', align: 'right' },
            { key: 'split', label: 'Day split', render: (d: any) => <Split r={d} /> },
          ]} />
          <p className="muted small" style={{ marginBottom: 0 }}>Click a day for its timeline.</p>
        </Card>
      )}
    </Page>
  );
}
