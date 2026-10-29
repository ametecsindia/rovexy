import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { del, get, post, put, qs } from '../lib/api';
import { Badge, Card, ErrorBox, Field, Notice, Page, Table, toast, useAction, useAsync } from '../components/ui';

/**
 * Field planning (G7 circles + G8 route plans). Left: field employees with their circle and today's plan.
 * Right: one employee — draw the circle on the map, give them the unassigned customers inside it, tick who to visit,
 * Save (free straight-line order) → Optimise (one two-wheeler Google call) → Publish (shows on the phone as Today's route).
 */
const km = (m?: number | null) => (m == null ? '—' : m < 1000 ? `${m} m` : `${(m / 1000).toFixed(1)} km`);
const mins = (s?: number | null) => (s == null ? '—' : s < 3600 ? `${Math.max(1, Math.round(s / 60))} min` : `${Math.floor(s / 3600)} h ${Math.round((s % 3600) / 60)} min`);
const today = () => new Date().toISOString().slice(0, 10);

export function FieldPlanningPage() {
  const [date, setDate] = useState(today);
  const list = useAsync(() => get(`/field/employees${qs({ date })}`), [date]);
  const [sel, setSel] = useState<number | null>(null);
  return (
    <Page title="Field planning" subtitle="Each field employee's circle, and the customers they visit each day. Optimising a plan uses one Google two-wheeler request; the phone shows the published plan without any Google cost.">
      <ErrorBox error={list.error} />
      <div className="row" style={{ marginBottom: 12 }}>
        <Field label="Day"><input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(260px, 340px) 1fr', gap: 16, alignItems: 'start' }}>
        <Card title="Field employees">
          <Table rows={list.data?.employees} onRow={(r: any) => setSel(r.id)} empty="No field employees (role Employee) in your groups."
            columns={[
              { key: 'name', label: 'Employee', render: (r: any) => <span style={{ fontWeight: r.id === sel ? 700 : 400 }}>{r.name}<br /><small className="muted">{r.employee_code} · {r.pinned}/{r.cases} with pin</small></span> },
              { key: 'circle', label: 'Circle', render: (r: any) => (r.circle ? <span>{km(r.circle.radius_m)}<br /><small className="muted">{r.inside} inside</small></span> : <Badge value="muted" label="none" />) },
              { key: 'plan', label: 'Plan', render: (r: any) => (r.plan ? <Badge value={r.plan.status === 'published' ? 'ok' : 'warn'} label={`${r.plan.status} · ${r.plan.stops}`} /> : <Badge value="muted" label="—" />) },
            ]} />
        </Card>
        {sel ? <Planner key={`${sel}-${date}`} userId={sel} date={date} onChanged={list.reload} /> : <Card><p className="muted">Choose an employee on the left.</p></Card>}
      </div>
    </Page>
  );
}

function Planner({ userId, date, onChanged }: { userId: number; date: string; onChanged: () => void }) {
  const d = useAsync(() => get(`/field/plans/${userId}${qs({ date })}`), [userId, date]);
  const [circle, setCircle] = useState<{ lat: number; lng: number; radius_m: number; name?: string } | null>(null);
  const [picked, setPicked] = useState<number[]>([]);
  const a = useAction();
  useEffect(() => {
    if (!d.data) return;
    setCircle(d.data.circle);
    setPicked(d.data.plan?.stops ?? []);
  }, [d.data]);
  const data = d.data;
  const plan = data?.plan;
  const max = data?.max_stops ?? 25;
  const byId = new Map<number, any>((data?.candidates ?? []).map((c: any) => [c.id, c]));
  const changed = plan && JSON.stringify([...picked].sort()) !== JSON.stringify([...(plan.stops ?? [])].sort());
  const run = (fn: () => Promise<any>, msg: string) => a.run(async () => { d.setData({ ...data, plan: await fn() }); onChanged(); toast(msg); });
  const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : p.length >= max ? p : [...p, id]));

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <ErrorBox error={d.error ?? a.error} />
      <Card title={data ? `${data.employee.name} · ${date}` : 'Loading…'}>
        <PlanMap circle={circle} candidates={data?.candidates ?? []} picked={picked} plan={changed ? null : plan}
          onCentre={(lat, lng) => setCircle((c) => ({ radius_m: c?.radius_m ?? 3000, name: c?.name, lat, lng }))} onToggle={toggle} />
        <p className="muted small">Click the map to place the circle's centre. Customers: green = inside the circle, grey = outside, gold = in the plan (numbered in visiting order).</p>
        <div className="row" style={{ alignItems: 'flex-end' }}>
          <Field label="Circle radius (km)"><input type="number" min={0.2} max={100} step={0.5} value={circle ? circle.radius_m / 1000 : ''} disabled={!circle}
            onChange={(e) => circle && setCircle({ ...circle, radius_m: Math.round(Number(e.target.value) * 1000) })} style={{ width: 120 }} /></Field>
          <Field label="Name (optional)"><input value={circle?.name ?? ''} disabled={!circle} onChange={(e) => circle && setCircle({ ...circle, name: e.target.value })} placeholder="e.g. Kondapur" /></Field>
          <button disabled={a.busy || !circle} onClick={() => a.run(async () => { await put(`/field/circles/${userId}`, circle); await d.reload(); onChanged(); toast('Circle saved'); })}>Save circle</button>
          {data?.circle && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => {
            const r = await post(`/field/circles/${userId}/assign`); await d.reload(); onChanged(); toast(`${r.assigned} customers given to ${data.employee.name}`);
          })}>Give unassigned customers inside</button>}
          {data?.circle && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await del(`/field/circles/${userId}`); await d.reload(); onChanged(); })}>Remove circle</button>}
        </div>
      </Card>

      <Card title={`Plan for ${date}`} actions={plan && <Badge value={plan.status === 'published' ? 'ok' : 'warn'} label={plan.status} />}>
        {plan && !changed && (
          <p style={{ marginTop: 0 }}>{plan.stops.length} stops · {plan.optimised ? <>{km(plan.distance_m)} · {mins(plan.duration_s)} by two-wheeler</> : 'straight-line order (not optimised yet)'}
            {plan.published_at && <span className="muted"> · published {new Date(plan.published_at).toLocaleString()}</span>}</p>
        )}
        {changed && <Notice tone="warn">You changed the customers. Save the plan, then optimise and publish again.</Notice>}
        <div className="row">
          <button disabled={a.busy || picked.length === 0} onClick={() => run(() => put(`/field/plans/${userId}${qs({ date })}`, { stops: picked }), 'Plan saved')}>Save plan ({picked.length}/{max})</button>
          <button className="secondary" disabled={a.busy || !plan || !!changed || plan.optimised} title="One Google two-wheeler request"
            onClick={() => run(() => post(`/field/plans/${userId}/optimise${qs({ date })}`), 'Best two-wheeler order found')}>Optimise route</button>
          <button disabled={a.busy || !plan || !!changed || plan.status === 'published'} onClick={() => run(() => post(`/field/plans/${userId}/publish${qs({ date })}`), 'Sent to the phone')}>Publish to phone</button>
          {plan && <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await del(`/field/plans/${userId}${qs({ date })}`); setPicked([]); await d.reload(); onChanged(); })}>Delete plan</button>}
        </div>
        {plan && !changed && plan.stops.length > 0 && (
          <ol style={{ marginBottom: 0 }}>{plan.stops.map((id: number, i: number) => <li key={id}>{byId.get(id)?.name ?? `#${id}`} <small className="muted">{byId.get(id)?.address}{plan.legs?.[i] ? ` · ${km(plan.legs[i].distance_m)}` : ''}</small></li>)}</ol>
        )}
      </Card>

      <Card title={`Customers with a map pin · ${data?.candidates?.length ?? 0}`}>
        <p className="muted small" style={{ marginTop: 0 }}>Inside the circle first, then follow-ups due, then the longest without a visit. Tick up to {max}.</p>
        <Table rows={data?.candidates} empty="This employee has no customers with a map pin yet."
          columns={[
            { key: 'pick', label: '', render: (c: any) => <input type="checkbox" checked={picked.includes(c.id)} onChange={() => toggle(c.id)} /> },
            { key: 'name', label: 'Customer', render: (c: any) => <span>{c.name}<br /><small className="muted">{c.customer_ref} · {c.address}</small></span> },
            { key: 'inside', label: 'Circle', render: (c: any) => (c.inside == null ? '—' : <Badge value={c.inside ? 'ok' : 'muted'} label={c.inside ? 'inside' : 'outside'} />) },
            { key: 'due', label: 'Follow-up', render: (c: any) => (c.follow_up_due ? <Badge value="warn" label="due" /> : '') },
            { key: 'visit', label: 'Last visit', render: (c: any) => c.last_visit ?? 'never' },
            { key: 'od', label: 'Overdue ₹', align: 'right', render: (c: any) => (c.overdue_amount ? Number(c.overdue_amount).toLocaleString('en-IN') : '') },
            { key: 'from', label: 'From start', align: 'right', render: (c: any) => km(c.from_start_m) },
          ]} />
      </Card>
    </div>
  );
}

function PlanMap({ circle, candidates, picked, plan, onCentre, onToggle }: { circle: any; candidates: any[]; picked: number[]; plan: any;
  onCentre: (lat: number, lng: number) => void; onToggle: (id: number) => void }) {
  const el = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const layer = useRef<L.LayerGroup | null>(null);
  const cb = useRef({ onCentre, onToggle });
  cb.current = { onCentre, onToggle };
  const fitted = useRef(false);
  useEffect(() => {
    if (!el.current) return;
    const m = L.map(el.current, { scrollWheelZoom: true }).setView([17.44, 78.39], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
    m.on('click', (e: L.LeafletMouseEvent) => cb.current.onCentre(e.latlng.lat, e.latlng.lng));
    layer.current = L.layerGroup().addTo(m);
    map.current = m;
    return () => { m.remove(); map.current = null; };
  }, []);
  useEffect(() => {
    const m = map.current, g = layer.current;
    if (!m || !g) return;
    g.clearLayers();
    const pts: L.LatLngExpression[] = [];
    if (circle) {
      L.circle([circle.lat, circle.lng], { radius: circle.radius_m, color: '#1f3a5f', weight: 2, fillColor: '#1f3a5f', fillOpacity: 0.06 }).addTo(g);
      L.circleMarker([circle.lat, circle.lng], { radius: 5, color: '#1f3a5f', fillColor: '#1f3a5f', fillOpacity: 1 }).bindTooltip('Start / centre').addTo(g);
      pts.push([circle.lat, circle.lng]);
    }
    if (plan?.points?.length) {
      L.polyline(plan.points, { color: '#fff', weight: 8, opacity: 0.9 }).addTo(g);
      L.polyline(plan.points, { color: '#1f3a5f', weight: 4 }).addTo(g);
    }
    const order: number[] = plan?.stops ?? picked;
    candidates.forEach((c) => {
      const n = order.indexOf(c.id);
      const on = picked.includes(c.id);
      const mk = on
        ? L.marker([c.lat, c.lng], { icon: L.divIcon({ className: '', html: `<div style="background:#b8893b;color:#fff;border:2px solid #fff;border-radius:50%;width:24px;height:24px;line-height:20px;text-align:center;font:700 12px Arial;box-shadow:0 1px 4px #0005">${n + 1}</div>`, iconSize: [24, 24], iconAnchor: [12, 12] }) })
        : L.circleMarker([c.lat, c.lng], { radius: 6, color: '#fff', weight: 2, fillColor: c.inside === false ? '#9aa5b1' : '#2f855a', fillOpacity: 1 });
      mk.bindTooltip(`${c.name}${c.follow_up_due ? ' · follow-up due' : ''}`).on('click', (e: any) => { L.DomEvent.stopPropagation(e); cb.current.onToggle(c.id); }).addTo(g);
      pts.push([c.lat, c.lng]);
    });
    if (!fitted.current && pts.length) { m.fitBounds(L.latLngBounds(pts).pad(0.15)); fitted.current = true; }
  }, [circle, candidates, picked, plan]);
  return <div ref={el} style={{ height: 420, borderRadius: 12, overflow: 'hidden', marginBottom: 8 }} />;
}
