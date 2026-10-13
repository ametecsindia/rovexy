import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Link, useNavigate } from 'react-router-dom';
import { get, fmtDate, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Kpi, Modal, Notice, Page, Table, useAsync } from '../components/ui';

type Pin = { lat: number; lng: number } | null;
export type AddressChange = {
  id: number; at: string; what: 'address' | 'pin' | 'both'; source: string; source_label: string; by: string | null;
  old_address: string | null; new_address: string | null; old_pin: Pin; new_pin: Pin; moved_m: number | null; note: string | null;
  status?: 'verified' | 'pending' | 'replaced'; verified_at?: string | null; customer?: any;
};

const day = (d: Date) => d.toLocaleDateString('en-CA');
const RANGES: [string, () => [string, string]][] = [
  ['Today', () => [day(new Date()), day(new Date())]],
  ['Last 7 days', () => [day(new Date(Date.now() - 6 * 864e5)), day(new Date())]],
  ['This month', () => { const n = new Date(); return [day(new Date(n.getFullYear(), n.getMonth(), 1)), day(n)]; }],
  ['All time', () => ['', '']],
];
export const meters = (m: number | null | undefined) => m === null || m === undefined ? '' : m >= 1000 ? `${(m / 1000).toFixed(m >= 10000 ? 0 : 1)} km` : `${m} m`;
/** What changed, in words: "Pin moved 1.1 km", "Pin added", "Address only". */
export function changeLabel(c: AddressChange): string {
  const pin = !c.old_pin && c.new_pin ? 'Pin added' : c.old_pin && !c.new_pin ? 'Pin removed' : c.moved_m !== null ? `Pin moved ${meters(c.moved_m)}` : '';
  return c.what === 'address' ? 'Address changed' : c.what === 'both' ? `Address + ${pin.toLowerCase()}` : pin;
}
const STATUS: Record<string, [string, string]> = { verified: ['verified', 'Verified at the door'], pending: ['pending', 'Not verified yet'], replaced: ['superseded', 'Replaced by a newer change'] };
const REACH: Record<string, [string, string]> = { visited: ['verified', 'Visited'], phone: ['on_call', 'Reached by phone'], not_reached: ['failed', 'Not reached'] };
const PIN: Record<string, [string, string]> = { ok: ['verified', 'Pin OK'], missing: ['pending', 'No pin'], invalid: ['failed', 'Wrong pin'] };

/** Before (grey ring) and after (gold) pins with a dashed line between them, on OpenStreetMap. */
export function CompareMap({ before, after, height = 340 }: { before: Pin; after: Pin; height?: number }) {
  const el = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!el.current || (!before && !after)) return;
    const m = L.map(el.current, { scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
    const pts: L.LatLngExpression[] = [];
    if (before) { pts.push([before.lat, before.lng]); L.circleMarker([before.lat, before.lng], { radius: 9, color: '#64748b', weight: 3, fillColor: '#fff', fillOpacity: 1 }).bindTooltip('Before', { permanent: true, direction: 'top', offset: [0, -8] }).addTo(m); }
    if (after) { pts.push([after.lat, after.lng]); L.circleMarker([after.lat, after.lng], { radius: 10, color: '#fff', weight: 2, fillColor: '#c9a35e', fillOpacity: 1 }).bindTooltip('After', { permanent: true, direction: 'top', offset: [0, -8] }).addTo(m); }
    if (pts.length === 2) { L.polyline(pts, { color: '#1f3a5f', weight: 2, dashArray: '6 6' }).addTo(m); m.fitBounds(L.latLngBounds(pts), { padding: [50, 50], maxZoom: 17 }); }
    else m.setView(pts[0], 16);
    return () => { m.remove(); };
  }, [before?.lat, before?.lng, after?.lat, after?.lng]);
  if (!before && !after) return <Notice tone="info">Neither version has a map location.</Notice>;
  return <div ref={el} className="map" style={{ height }} role="region" aria-label="Previous and updated map location" />;
}

/** Previous vs updated, side by side, with the map — used by the report and the case page. */
export function ChangeDetail({ c, tz }: { c: AddressChange; tz?: string }) {
  return (
    <>
      <div className="addr-compare">
        <div className="addr-box old"><small>PREVIOUS</small><p>{c.old_address ?? <span className="muted">No address</span>}</p><small className="muted">{c.old_pin ? `${c.old_pin.lat.toFixed(6)}, ${c.old_pin.lng.toFixed(6)}` : 'No map location'}</small></div>
        <div className="addr-arrow" aria-hidden>→</div>
        <div className="addr-box new"><small>UPDATED</small><p>{c.new_address ?? <span className="muted">No address</span>}</p><small className="muted">{c.new_pin ? `${c.new_pin.lat.toFixed(6)}, ${c.new_pin.lng.toFixed(6)}` : 'No map location'}</small></div>
      </div>
      <CompareMap before={c.old_pin} after={c.new_pin} />
      <div className="map-legend">
        <span><i className="dot-before" />Before</span><span><i className="dot-latest" />After</span>
        {c.moved_m !== null && <span><i className="line" />{meters(c.moved_m)} apart</span>}
        <span>{fmtDate(c.at, tz)} · {c.source_label}{c.by ? ` · ${c.by}` : ''}{c.note ? ` · ${c.note}` : ''}</span>
      </div>
    </>
  );
}

/** Case page: full address history, newest first; click one to compare. */
export function AddressHistory({ rows, tz }: { rows: AddressChange[]; tz?: string }) {
  const [open, setOpen] = useState<AddressChange | null>(null);
  if (!rows.length) return <p className="muted">No changes since this case was uploaded.</p>;
  return (
    <>
      <ul className="timeline">
        {rows.map((c, i) => (
          <li key={c.id} className="tl-location clickable" onClick={() => setOpen(c)}>
            <strong>{changeLabel(c)}</strong>{i === 0 && <> <Badge value="verified" label="current" /></>}
            {c.what !== 'pin' && <div><span className="muted">{c.old_address ?? 'No address'}</span> → {c.new_address ?? 'No address'}</div>}
            <small className="muted">{fmtDate(c.at, tz)} · {c.source_label}{c.by ? ` · ${c.by}` : ''} · <u>compare</u></small>
          </li>
        ))}
      </ul>
      {open && <Modal title={`${changeLabel(open)} · ${fmtDate(open.at, tz)}`} onClose={() => setOpen(null)}><ChangeDetail c={open} tz={tz} /></Modal>}
    </>
  );
}

const EMPTY_C = { q: '', from: '', to: '', source: '', what: '', status: '' };
const EMPTY_R = { q: '', pin: '', reach: '', changed: '', case_status: '' };

export function AddressReportPage() {
  const { me } = useAuth();
  const tz = me?.company.timezone;
  const nav = useNavigate();
  const [tab, setTab] = useState<'changes' | 'reach'>('changes');
  const [f, setF] = useState<Record<string, string>>(EMPTY_C);
  const [g, setG] = useState<Record<string, string>>(EMPTY_R);
  const [qc, setQc] = useState('');   // search boxes: applied on Enter / after typing stops
  const [qr, setQr] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<AddressChange | null>(null);
  useEffect(() => { const t = setTimeout(() => { setF((x) => ({ ...x, q: qc })); setG((x) => ({ ...x, q: qr })); }, 400); return () => clearTimeout(t); }, [qc, qr]);
  useEffect(() => setPage(1), [JSON.stringify(f), JSON.stringify(g), tab]);
  const ch = useAsync(() => tab === 'changes' ? get(`/address-changes${qs({ ...f, page })}`) : Promise.resolve(null), [tab, JSON.stringify(f), page]);
  const rc = useAsync(() => tab === 'reach' ? get(`/reachability${qs({ ...g, page })}`) : Promise.resolve(null), [tab, JSON.stringify(g), page]);
  const pg = tab === 'changes' ? ch.data?.page : rc.data?.page;
  const setc = (k: string, v: string) => setF({ ...f, [k]: v });
  const setr = (k: string, v: string) => setG({ ...g, [k]: v });
  const activeC = Object.values(f).filter(Boolean).length;
  const activeR = Object.values(g).filter(Boolean).length;
  const s = ch.data?.summary;
  const r = rc.data?.summary;

  return (
    <Page title="Address & reachability" subtitle="Every address and map-pin change with before and after, and whether each customer has been reached — at the door or by phone."
      actions={<button className="secondary" onClick={() => (tab === 'changes' ? ch : rc).reload()}>Refresh</button>}>
      <div className="tabs" role="tablist">
        <button role="tab" className={`tab${tab === 'changes' ? ' on' : ''}`} onClick={() => setTab('changes')}>Address changes</button>
        <button role="tab" className={`tab${tab === 'reach' ? ' on' : ''}`} onClick={() => setTab('reach')}>Customer reachability</button>
      </div>

      {tab === 'changes' && (
        <>
          <ErrorBox error={ch.error} />
          <div className="filters filter-bar">
            <input type="search" placeholder="Customer, case ID or address" value={qc} onChange={(e) => setQc(e.target.value)} style={{ minWidth: 240 }} aria-label="Search" />
            <select value={f.status} onChange={(e) => setc('status', e.target.value)} aria-label="Update status"><option value="">Any status</option><option value="pending">Not verified yet</option><option value="verified">Verified at the door</option><option value="replaced">Replaced by a newer change</option></select>
            <select value={f.what} onChange={(e) => setc('what', e.target.value)} aria-label="What changed"><option value="">Address or pin</option><option value="address">Address only</option><option value="pin">Map pin only</option><option value="both">Address + pin</option></select>
            <select value={f.source} onChange={(e) => setc('source', e.target.value)} aria-label="Changed by"><option value="">Any source</option>{Object.entries(ch.data?.sources ?? {}).map(([k, v]) => <option key={k} value={k}>{v as string}</option>)}</select>
            <input type="date" value={f.from} max={f.to || undefined} onChange={(e) => setc('from', e.target.value)} aria-label="From" />
            <input type="date" value={f.to} min={f.from || undefined} onChange={(e) => setc('to', e.target.value)} aria-label="To" />
            {RANGES.map(([l, fn]) => <button key={l} className="ghost" onClick={() => { const [a, b] = fn(); setF({ ...f, from: a, to: b }); }}>{l}</button>)}
            {activeC > 0 && <button className="ghost" onClick={() => { setF(EMPTY_C); setQc(''); }}>Clear filters ({activeC})</button>}
          </div>
          {s && (
            <div className="kpi-grid">
              <Kpi icon="pin" tone="blue" label="Address changes" value={s.changes} sub={`${s.customers} customers`} />
              <Kpi icon="check" tone="green" label="Verified at the door" value={s.verified} sub={`${s.changes ? Math.round((s.verified / s.changes) * 100) : 0}% of changes`} subTone={s.verified ? 'up' : 'flat'} />
              <Kpi icon="pin" tone="gold" label="Pins added" value={s.pins_added} sub="case had no usable pin" />
              <Kpi icon="warning" tone="gold" label="Moved over 500 m" value={s.big_moves} sub={`${s.pins_moved} pins moved in all`} subTone={s.big_moves ? 'down' : 'flat'} />
              <Kpi icon="contacts" tone="purple" label="Address text only" value={s.address_only} sub="pin unchanged" />
            </div>
          )}
          <Card>
            <Table rows={pg?.data} empty="No address changes match. Changes appear here when a lead file, a phone or an address lookup updates a case's address or pin." onRow={(c: AddressChange) => setOpen(c)} columns={[
              { key: 'at', label: 'When', render: (c: AddressChange) => fmtDate(c.at, tz) },
              { key: 'customer', label: 'Customer', render: (c: AddressChange) => <><strong>{c.customer?.name}</strong><div className="muted small">{c.customer?.customer_ref}{c.customer?.assigned_to ? ` · ${c.customer.assigned_to}` : ''}</div></> },
              { key: 'old', label: 'Previous address', render: (c: AddressChange) => <span className="muted">{c.old_address ?? '—'}</span> },
              { key: 'new', label: 'Updated address', render: (c: AddressChange) => c.new_address ?? '—' },
              { key: 'what', label: 'Change', render: (c: AddressChange) => <span className={c.moved_m !== null && c.moved_m > 500 ? 'bad-text' : undefined}>{changeLabel(c)}</span> },
              { key: 'source', label: 'By', render: (c: AddressChange) => <>{c.source_label}<div className="muted small">{c.by ?? ''}</div></> },
              { key: 'status', label: 'Status', render: (c: AddressChange) => <Badge value={STATUS[c.status!]?.[0]} label={STATUS[c.status!]?.[1]} /> },
            ]} />
          </Card>
        </>
      )}

      {tab === 'reach' && (
        <>
          <ErrorBox error={rc.error} />
          <div className="filters filter-bar">
            <input type="search" placeholder="Customer, case ID or address" value={qr} onChange={(e) => setQr(e.target.value)} style={{ minWidth: 240 }} aria-label="Search" />
            <select value={g.reach} onChange={(e) => setr('reach', e.target.value)} aria-label="Reached"><option value="">Reached or not</option><option value="visited">Visited at the door</option><option value="phone">Reached by phone only</option><option value="not_reached">Not reached</option></select>
            <select value={g.pin} onChange={(e) => setr('pin', e.target.value)} aria-label="Map pin"><option value="">Any map pin</option><option value="ok">Pin OK</option><option value="missing">No pin</option><option value="invalid">Wrong pin</option></select>
            <select value={g.changed} onChange={(e) => setr('changed', e.target.value)} aria-label="Address changed"><option value="">Changed or not</option><option value="yes">Address changed</option><option value="no">Never changed</option></select>
            <select value={g.case_status} onChange={(e) => setr('case_status', e.target.value)} aria-label="Case status"><option value="">Any case status</option>{['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'].map((x) => <option key={x} value={x}>{x.replace(/_/g, ' ')}</option>)}</select>
            {activeR > 0 && <button className="ghost" onClick={() => { setG(EMPTY_R); setQr(''); }}>Clear filters ({activeR})</button>}
          </div>
          {r && (
            <div className="kpi-grid">
              <Kpi icon="contacts" tone="blue" label="Cases" value={r.cases} sub={`${r.pin_ok} with a usable pin`} />
              <Kpi icon="check" tone="green" label="Visited at the door" value={r.visited} sub="checked in within 100 m" />
              <Kpi icon="call" tone="teal" label="Reached by phone only" value={r.phone_only} sub="connected call, no visit" />
              <Kpi icon="warning" tone="gold" label="Not reached" value={r.not_reached} sub="no visit, no connected call" subTone={r.not_reached ? 'down' : 'flat'} />
              <Kpi icon="pin" tone="purple" label="Pin missing or wrong" value={r.pin_missing + r.pin_invalid} sub={`${r.pin_missing} missing · ${r.pin_invalid} wrong`} subTone={r.pin_missing + r.pin_invalid ? 'down' : 'flat'} />
            </div>
          )}
          <Card>
            <Table rows={pg?.data} empty="No cases match." onRow={(c: any) => nav(`/customers/${c.id}?tab=address`)} columns={[
              { key: 'name', label: 'Customer', render: (c: any) => <><strong>{c.name}</strong><div className="muted small">{c.customer_ref}{c.assigned_to ? ` · ${c.assigned_to}` : ''}</div></> },
              { key: 'address', label: 'Current address', render: (c: any) => c.address ?? <span className="muted">No address</span> },
              { key: 'pin', label: 'Map pin', render: (c: any) => <Badge value={PIN[c.pin_status][0]} label={PIN[c.pin_status][1]} /> },
              { key: 'reach', label: 'Reached', render: (c: any) => <Badge value={REACH[c.reach][0]} label={REACH[c.reach][1]} /> },
              { key: 'visit', label: 'Last visit', render: (c: any) => c.last_visit_at ? <>{fmtDate(c.last_visit_at, tz)}<div className="muted small">{c.visits} visit{c.visits > 1 ? 's' : ''}</div></> : <span className="muted">—</span> },
              { key: 'call', label: 'Last connected call', render: (c: any) => c.last_connected_at ? fmtDate(c.last_connected_at, tz) : <span className="muted">{c.last_call_at ? 'never connected' : 'not called'}</span> },
              { key: 'changes', label: 'Address changes', align: 'right', render: (c: any) => c.changes ? <>{c.changes}<div className="muted small">last {fmtDate(c.last_change_at, tz)}</div></> : <span className="muted">0</span> },
            ]} />
          </Card>
        </>
      )}

      {pg && pg.last_page > 1 && (
        <div className="row" style={{ justifyContent: 'center', gap: 12 }}>
          <button className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Previous</button>
          <span className="muted">Page {pg.current_page} of {pg.last_page} · {pg.total} rows</span>
          <button className="secondary" disabled={page >= pg.last_page} onClick={() => setPage(page + 1)}>Next →</button>
        </div>
      )}

      {open && (
        <Modal title={`${open.customer?.name ?? 'Customer'} · ${changeLabel(open)}`} onClose={() => setOpen(null)}>
          <ChangeDetail c={open} tz={tz} />
          <div className="row" style={{ marginTop: 12, justifyContent: 'space-between' }}>
            <Badge value={STATUS[open.status!]?.[0]} label={open.status === 'verified' && open.verified_at ? `Verified at the door ${fmtDate(open.verified_at, tz)}` : STATUS[open.status!]?.[1]} />
            <Link className="button secondary" to={`/customers/${open.customer?.id}?tab=address`}>Full address history →</Link>
          </div>
        </Modal>
      )}
    </Page>
  );
}
