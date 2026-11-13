import { useState } from 'react';
import { download, get, qs } from '../lib/api';
import { RANGES } from '../lib/periods';
import { ErrorBox, Kpi, Notice, Table, useAction, useAsync } from './ui';

/*
 * Google Maps usage report (client: Integrations → Google Maps usage; Super Admin: one company). Reads
 * {totals, daily, weekly, monthly, yearly} from `path`; `${path}?format=csv` downloads the daily rows.
 */

export const SKU_ORDER = ['geocode', 'routes', 'routes_2w', 'matrix', 'maps_js'];
const n = (v: number | null | undefined) => Number(v ?? 0).toLocaleString('en-IN');
export const money = (c: { usd: number; inr: number } | null | undefined) => (c ? `$${c.usd.toFixed(2)} · ≈₹${Math.round(c.inr).toLocaleString('en-IN')}` : '—');
const PRESETS = RANGES.filter(([l]) => ['Last 7 days', 'This month', 'Last month', 'This year'].includes(l));

/** Simple bar chart: one bar per period, the value is billable units of all APIs. */
export function UsageBars({ items }: { items: { label: string; value: number; title: string }[] }) {
  const W = 640, H = 160, P = { l: 44, r: 6, t: 8, b: 22 };
  const max = Math.max(4, ...items.map((i) => i.value));
  const bw = (W - P.l - P.r) / Math.max(1, items.length);
  const y = (v: number) => P.t + (H - P.t - P.b) * (1 - v / max);
  const every = Math.ceil(items.length / 12);
  if (!items.length) return null;
  return (
    <div className="chart">
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Google Maps usage per period">
        {[0, 0.5, 1].map((f) => <g key={f}><line x1={P.l} x2={W - P.r} y1={y(max * f)} y2={y(max * f)} className="grid-line" /><text x={P.l - 6} y={y(max * f) + 4} textAnchor="end" className="axis">{n(Math.round(max * f))}</text></g>)}
        {items.map((it, i) => (
          <g key={it.label}>
            <title>{it.title}</title>
            {it.value > 0 && <rect x={P.l + i * bw + bw * 0.18} y={y(it.value)} width={bw * 0.64} height={y(0) - y(it.value)} rx={3} fill="var(--series-1)" />}
            {i % every === 0 && <text x={P.l + i * bw + bw / 2} y={H - 6} textAnchor="middle" className="axis">{it.label}</text>}
          </g>
        ))}
      </svg>
    </div>
  );
}

export function MapsUsageReport({ path }: { path: string }) {
  const [range, setRange] = useState<[string, string]>(() => PRESETS.find(([l]) => l === 'This month')![1]());
  const [view, setView] = useState<'daily' | 'weekly' | 'monthly' | 'yearly'>('daily');
  const r = useAsync(() => get(`${path}${qs({ from: range[0], to: range[1] })}`), [path, range[0], range[1]]);
  const a = useAction();
  const d = r.data;
  const label = (sku: string) => d?.totals?.find((t: any) => t.sku === sku)?.api ?? sku;
  const sum = (k: string) => (d?.totals ?? []).reduce((s: number, t: any) => s + t[k], 0);
  const units = (row: any) => SKU_ORDER.reduce((s, k) => s + Number((row.units ?? row)[k] ?? 0), 0);
  const thisMonth = d?.monthly?.[d.monthly.length - 1];
  const yearCost = d?.monthly?.filter((m: any) => m.month.startsWith(String(new Date().getFullYear()))).reduce((s: any, m: any) => ({ usd: s.usd + m.cost.usd, inr: s.inr + m.cost.inr }), { usd: 0, inr: 0 });
  const perApi = () => SKU_ORDER.map((k) => ({ key: k, label: label(k), align: 'right' as const, render: (x: any) => n((x.units ?? x)[k]) }));
  const series = !d ? [] : view === 'daily' ? d.daily.map((x: any) => ({ label: x.day.slice(5), value: units(x), title: `${x.day}: ${n(units(x))} billable · ${n(x.requests)} requests, ${n(x.failed)} failed` }))
    : view === 'weekly' ? d.weekly.map((x: any) => ({ label: x.week.slice(5), value: units(x), title: `Week of ${x.week}: ${n(units(x))} billable` }))
      : view === 'monthly' ? d.monthly.map((x: any) => ({ label: x.month.slice(2), value: units(x), title: `${x.month}: ${n(units(x))} billable · ${money(x.cost)}` }))
        : d.yearly.map((x: any) => ({ label: x.year, value: units(x), title: `${x.year}: ${n(units(x))} billable` }));
  return (
    <div>
      <ErrorBox error={r.error ?? a.error} />
      <div className="row" style={{ alignItems: 'flex-end', flexWrap: 'wrap', gap: 8 }}>
        {PRESETS.map(([l, fn]) => { const [f, t] = fn(); return <button key={l} className={f === range[0] && t === range[1] ? '' : 'secondary'} onClick={() => setRange([f, t])}>{l}</button>; })}
        <label>From <input type="date" value={range[0]} onChange={(e) => setRange([e.target.value, range[1]])} /></label>
        <label>To <input type="date" value={range[1]} onChange={(e) => setRange([range[0], e.target.value])} /></label>
        <button className="secondary" disabled={a.busy} onClick={() => a.run(() => download(`${path}${qs({ from: range[0], to: range[1], format: 'csv' })}`))}>Download CSV</button>
      </div>
      {d && <>
        <div className="kpi-grid small" style={{ margin: '12px 0' }}>
          <Kpi icon="chart" tone="info" label="Requests to Google" value={n(sum('requests'))} sub={`${range[0]} – ${range[1]}`} />
          <Kpi icon="check" tone="ok" label="Successful" value={n(sum('ok'))} />
          <Kpi icon="shield" tone={sum('failed') ? 'warn' : 'ok'} label="Failed" value={n(sum('failed'))} />
          <Kpi icon="chart" tone="info" label="Estimated cost this month" value={thisMonth ? money(thisMonth.cost) : '—'} sub={yearCost ? `this year ${money(yearCost)}` : undefined} />
        </div>
        <h3>By Google API</h3>
        <Table rows={d.totals.map((t: any) => ({ ...t, id: t.sku }))} columns={[
          { key: 'api', label: 'Google API', render: (t: any) => <><strong>{t.api}</strong><div className="muted small">{t.label}</div></> },
          { key: 'requests', label: 'Requests', align: 'right', render: (t: any) => n(t.requests) },
          { key: 'units', label: 'Billable', align: 'right', render: (t: any) => <>{n(t.units)}<div className="muted small">{t.unit}</div></> },
          { key: 'ok', label: 'Successful', align: 'right', render: (t: any) => n(t.ok) },
          { key: 'failed', label: 'Failed', align: 'right', render: (t: any) => n(t.failed) },
        ]} />
        <div className="tabs" role="tablist" style={{ marginTop: 16 }}>{(['daily', 'weekly', 'monthly', 'yearly'] as const).map((k) => <button key={k} role="tab" aria-selected={view === k} className={`tab${view === k ? ' on' : ''}`} onClick={() => setView(k)}>{k[0].toUpperCase() + k.slice(1)}</button>)}</div>
        {(view === 'monthly' || view === 'yearly') && <p className="muted small">Last 12 months, whatever the dates above.</p>}
        <UsageBars items={series} />
        {view === 'daily' && <Table rows={[...d.daily].reverse().map((x: any) => ({ ...x, id: x.day }))} empty="No Google Maps calls in these dates." columns={[{ key: 'day', label: 'Date' }, { key: 'requests', label: 'Requests', align: 'right', render: (x: any) => n(x.requests) }, { key: 'failed', label: 'Failed', align: 'right', render: (x: any) => n(x.failed) }, ...perApi()]} />}
        {view === 'weekly' && <Table rows={[...d.weekly].reverse().map((x: any) => ({ ...x, id: x.week }))} empty="No Google Maps calls in these dates." columns={[{ key: 'week', label: 'Week from' }, { key: 'requests', label: 'Requests', align: 'right', render: (x: any) => n(x.requests) }, { key: 'failed', label: 'Failed', align: 'right', render: (x: any) => n(x.failed) }, ...perApi()]} />}
        {view === 'monthly' && <Table rows={[...d.monthly].reverse().map((x: any) => ({ ...x, id: x.month }))} columns={[{ key: 'month', label: 'Month' }, { key: 'requests', label: 'Requests', align: 'right', render: (x: any) => n(x.requests) }, ...perApi(),
          { key: 'cost', label: 'Estimated cost', align: 'right', render: (x: any) => <span title={x.cost.lines.filter((l: any) => l.usd > 0).map((l: any) => `${l.google_sku}: ${n(l.billable)} above the free ${n(l.free)} → $${l.usd}`).join('\n') || 'Within Google\'s free allowance'}>{money(x.cost)}</span> }]} />}
        {view === 'yearly' && <Table rows={d.yearly.map((x: any) => ({ ...x, id: x.year }))} columns={[{ key: 'year', label: 'Year' }, { key: 'requests', label: 'Requests', align: 'right', render: (x: any) => n(x.requests) }, ...perApi(),
          { key: 'cost', label: 'Estimated cost', align: 'right', render: (x: any) => money(x) }]} />}
        {thisMonth && <><h3>Estimated cost this month, by Google SKU</h3>
          <Table rows={thisMonth.cost.lines.map((l: any) => ({ ...l, id: l.sku }))} columns={[
            { key: 'google_sku', label: 'Google SKU', render: (l: any) => <>{l.google_sku}<div className="muted small">{l.google_sku_id} · {l.tier}</div></> },
            { key: 'units', label: 'Used', align: 'right', render: (l: any) => n(l.units) },
            { key: 'free', label: 'Free / month', align: 'right', render: (l: any) => n(l.free) },
            { key: 'billable', label: thisMonth.cost.shared_account ? 'Billable (whole account)' : 'Billable', align: 'right', render: (l: any) => n(l.billable) },
            { key: 'usd', label: 'Estimate', align: 'right', render: (l: any) => <>{money(l)}{l.sales_quote && <div className="muted small">above 10 million: Google quotes</div>}</> },
          ]} /></>}
        <Notice tone="info">Estimate only, from Google's official India price list (updated {thisMonth?.cost.as_of ?? '—'}): free monthly allowance per API, then US$ per 1,000. {thisMonth?.cost.shared_account ? 'This company uses Ametecs\'s Google account, so the free allowance is shared and the cost is shared out by usage. ' : ''}
          Google bills per Google billing account (other software on the same account shares the free allowance), counts its month from midnight US Pacific time, converts to rupees at its own rate and adds GST. The Google Cloud billing report is the actual bill. Rupees here at ₹{thisMonth?.cost.usd_inr ?? '—'} per US$.</Notice>
      </>}
    </div>
  );
}
