import { useState } from 'react';
import { download, get, qs } from '../lib/api';
import { RANGES } from '../lib/periods';
import { ErrorBox, useAction, useAsync } from './ui';

/*
 * Google Maps usage, shared by Super Admin (whole page + one company) and the client's Integrations page.
 * Free allowances are per Google billing account and per API, so each API is drawn as a road to its free allowance
 * (the flag). Reads {totals, daily, weekly, monthly, yearly} from `path`; `${path}?format=csv` downloads the daily rows.
 */

export const GM_APIS: { sku: string; name: string; what: string; color: string }[] = [
  { sku: 'maps_js', name: 'Maps JavaScript', what: 'map loads on phones', color: '#0A2F6B' },
  { sku: 'matrix', name: 'Route Matrix', what: 'road distances to nearest customers', color: '#2A6FB8' },
  { sku: 'routes', name: 'Compute Routes', what: 'routes on phones', color: '#C89B2D' },
  { sku: 'routes_2w', name: 'Routes Enterprise', what: 'optimised two-wheeler day plans', color: '#8A5A00' },
  { sku: 'geocode', name: 'Geocoding', what: 'address to map pin', color: '#5B8C5A' },
];
export const SKU_ORDER = GM_APIS.map((x) => x.sku);
export const nn = (v: number | null | undefined) => Number(v ?? 0).toLocaleString('en-IN');
export const usdInr = (c: { usd: number; inr: number }) => `$${c.usd.toFixed(2)} (≈₹${Math.round(c.inr).toLocaleString('en-IN')})`;
export const money = (c: { usd: number; inr: number } | null | undefined) => (c ? `$${c.usd.toFixed(2)} · ≈₹${Math.round(c.inr).toLocaleString('en-IN')}` : '—');
export const monthName = (m: string) => new Date(m + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
/** Days in the month / days gone, in India time (1 for a finished month): today's pace to month end. */
export function paceFor(month: string): number {
  const now = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  const current = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  return month === current ? new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate() / Math.max(1, now.getDate()) : 1;
}

/** One API as a road from 0 to its free allowance (flag); gold = used, thin marker = month end at today's pace. */
export function Road({ name, what, used, free, heading, note, compact }: { name: string; what: string; used: number; free: number; heading: number; note?: string; compact?: boolean }) {
  const end = Math.max(free, heading, 1);
  const pct = (v: number) => `${Math.min(100, (v / end) * 100)}%`;
  const state = free > 0 && used > free ? ' passed' : heading > free ? ' warn' : '';
  return (
    <div className={`gm-road${state}${compact ? ' compact' : ''}`}>
      <div className="gm-road-name"><strong>{name}</strong><span>{what}</span></div>
      <div className="gm-track" role="img" aria-label={`${name}: ${nn(used)} of ${nn(free)} free used`}>
        <div className="gm-asphalt" />
        <div className="gm-fill" style={{ width: used ? `max(6px, ${pct(used)})` : 0 }} />
        {heading > used && <div className="gm-pace" style={{ left: pct(heading) }} title={`Heading for ${nn(heading)} by month end`} />}
        <div className="gm-flag" style={{ left: pct(free) }} title={`Free allowance ${nn(free)}`} />
      </div>
      <div className="gm-road-num">
        <strong>{nn(used)}</strong> <span>of {nn(free)} free</span>
        <small>{note}{heading > used ? <>{note ? <br /> : null}On course for {nn(heading)} by month end</> : null}</small>
      </div>
    </div>
  );
}

/** Stacked columns, one per period, coloured by API. */
export function StackBars({ items, onPick }: { items: { key: string; label: string; title: string; values: Record<string, number>; on?: boolean }[]; onPick?: (key: string) => void }) {
  const peak = Math.max(1, ...items.map((t) => SKU_ORDER.reduce((s, k) => s + (t.values[k] ?? 0), 0)));
  const every = Math.ceil(items.length / 12);
  return (
    <div className="gm-bars" style={{ gridTemplateColumns: `repeat(${Math.max(1, items.length)}, 1fr)`, gap: items.length > 20 ? 2 : 6 }}>
      {items.map((t, i) => {
        const total = SKU_ORDER.reduce((s, k) => s + (t.values[k] ?? 0), 0);
        return (
          <button key={t.key} type="button" className={`gm-bar${t.on ? ' on' : ''}`} onClick={() => onPick?.(t.key)} tabIndex={onPick ? 0 : -1} title={t.title} aria-label={t.title}>
            <span className="gm-stack" style={{ height: `${(total / peak) * 100}%` }}>
              {GM_APIS.map((x) => (t.values[x.sku] ? <i key={x.sku} style={{ flexGrow: t.values[x.sku], background: x.color }} /> : null))}
            </span>
            <small>{i % every === 0 ? t.label : ' '}</small>
          </button>
        );
      })}
    </div>
  );
}

const PRESETS = RANGES.filter(([l]) => ['Last 7 days', 'This month', 'Last month', 'This year'].includes(l));
const VIEWS = { daily: 'Days', weekly: 'Weeks', monthly: 'Months', yearly: 'Years' } as const;
const COL = { daily: 'Day', weekly: 'Week', monthly: 'Month', yearly: 'Year' } as const;
const short = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
const span = (a: string, b: string) => (a === b ? short(a) : `${short(a)} to ${short(b)}`);

export function MapsUsageReport({ path }: { path: string }) {
  const [range, setRange] = useState<[string, string]>(() => PRESETS.find(([l]) => l === 'This month')![1]());
  const [view, setView] = useState<keyof typeof VIEWS>('daily');
  const r = useAsync(() => get(`${path}${qs({ from: range[0], to: range[1] })}`), [path, range[0], range[1]]);
  const a = useAction();
  const d = r.data;
  const tot = (k: string) => (d?.totals ?? []).reduce((s: number, t: any) => s + t[k], 0);
  const month = d?.monthly?.[d.monthly.length - 1];
  const pace = month ? paceFor(month.month) : 1;
  const year = d?.yearly?.[d.yearly.length - 1];
  const failed = tot('failed');
  const series = !d ? [] : view === 'daily' ? d.daily.map((x: any) => ({ key: x.day, label: short(x.day).split(' ')[0], title: `${short(x.day)}: ${nn(x.requests)} calls, ${nn(x.failed)} failed`, values: x }))
    : view === 'weekly' ? d.weekly.map((x: any) => ({ key: x.week, label: short(x.week), title: `Week of ${short(x.week)}: ${nn(x.requests)} calls`, values: x }))
      : view === 'monthly' ? d.monthly.map((x: any) => ({ key: x.month, label: new Date(x.month + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'short' }), title: `${monthName(x.month)}: ${nn(x.requests)} calls, ${money(x.cost)}`, values: x.units }))
        : d.yearly.map((x: any) => ({ key: x.year, label: x.year, title: `${x.year}: ${nn(x.requests)} calls, ${money(x)}`, values: x.units }));
  const rows = !d ? [] : view === 'daily' ? [...d.daily].reverse().map((x: any) => ({ when: short(x.day), requests: x.requests, failed: x.failed, units: x }))
    : view === 'weekly' ? [...d.weekly].reverse().map((x: any) => ({ when: `Week of ${short(x.week)}`, requests: x.requests, failed: x.failed, units: x }))
      : view === 'monthly' ? [...d.monthly].reverse().map((x: any) => ({ when: monthName(x.month), requests: x.requests, failed: x.failed, units: x.units, cost: x.cost }))
        : d.yearly.map((x: any) => ({ when: x.year, requests: x.requests, failed: x.failed, units: x.units, cost: x }));
  const withCost = view === 'monthly' || view === 'yearly';
  return (
    <div className="gr">
      <div className="gr-range">
        <div className="gr-chips" role="group" aria-label="Dates">
          {PRESETS.map(([l, fn]) => { const [f, t] = fn(); return <button key={l} type="button" aria-pressed={f === range[0] && t === range[1]} onClick={() => setRange([f, t])}>{l}</button>; })}
        </div>
        <div className="gr-dates">
          <label>From <input type="date" value={range[0]} onChange={(e) => e.target.value && setRange([e.target.value, range[1]])} /></label>
          <label>To <input type="date" value={range[1]} onChange={(e) => e.target.value && setRange([range[0], e.target.value])} /></label>
          <button type="button" className="secondary" disabled={a.busy} onClick={() => a.run(() => download(`${path}${qs({ from: range[0], to: range[1], format: 'csv' })}`))}>Download CSV</button>
        </div>
      </div>
      <ErrorBox error={r.error ?? a.error} />
      {!d ? <p className="muted">Loading…</p> : <>
        <div className="gr-strip">
          <p className="gr-verdict">{!tot('requests') ? `No calls to Google from ${span(range[0], range[1])}.`
            : `${nn(tot('requests'))} calls to Google from ${span(range[0], range[1])}, ${failed ? `${nn(failed)} failed` : 'all successful'}.`}</p>
          <dl>
            <div><dt>This month so far</dt><dd>{month && month.cost.usd > 0 ? usdInr(month.cost) : 'Free'}</dd></div>
            <div><dt>This year</dt><dd>{year && year.usd > 0 ? usdInr(year) : 'Free'}</dd></div>
            <div><dt>Successful</dt><dd>{tot('requests') ? `${failed ? Math.min(99.9, Math.floor((tot('ok') / tot('requests')) * 1000) / 10) : 100}%` : '—'}</dd></div>
          </dl>
        </div>

        {month && <section className="gr-sec">
          <h3>{monthName(month.month)} against Google's free allowance</h3>
          {GM_APIS.map((x) => {
            const l = month.cost.lines.find((y: any) => y.sku === x.sku);
            const used = month.units[x.sku] ?? 0;
            return <Road key={x.sku} compact name={x.name} what={x.what} used={used} free={l?.free ?? 0} heading={Math.round(used * pace)}
              note={l?.usd > 0 ? `Estimated ${usdInr(l)}` : used ? 'Inside the free allowance' : 'Not used this month'} />;
          })}
          {month.cost.shared_account && <p className="gr-note">This client uses Ametecs's Google account, so the free allowance is shared with other clients and the cost is shared out by use.</p>}
        </section>}

        <section className="gr-sec">
          <div className="gr-sec-head">
            <h3>Calls by API</h3>
            <div className="gr-seg" role="tablist" aria-label="Group by">
              {(Object.keys(VIEWS) as (keyof typeof VIEWS)[]).map((k) => <button key={k} type="button" role="tab" aria-selected={view === k} onClick={() => setView(k)}>{VIEWS[k]}</button>)}
            </div>
          </div>
          {withCost && <p className="gr-note">The last 12 months, whatever the dates above.</p>}
          {series.length ? <StackBars items={series} /> : <p className="gr-note">No calls in these dates.</p>}
          <ul className="gm-key">{GM_APIS.map((x) => {
            const t = d.totals.find((y: any) => y.sku === x.sku);
            return <li key={x.sku}><i style={{ background: x.color }} />{x.name}<span className="gr-key-fail">{t?.failed ? `${nn(t.failed)} failed` : ''}</span><b>{nn(t?.units)}</b></li>;
          })}</ul>
          <details className="gr-more">
            <summary>Show the numbers</summary>
            <div className="table-wrap"><table>
              <thead><tr><th>{COL[view]}</th><th className="num">Calls</th><th className="num">Failed</th>{GM_APIS.map((x) => <th key={x.sku} className="num">{x.name}</th>)}{withCost && <th className="num">Estimate</th>}</tr></thead>
              <tbody>{rows.map((x: any) => <tr key={x.when}><td>{x.when}</td><td className="num">{nn(x.requests)}</td><td className="num">{nn(x.failed)}</td>
                {GM_APIS.map((y) => <td key={y.sku} className="num">{nn(x.units[y.sku])}</td>)}{withCost && <td className="num">{x.cost.usd > 0 ? money(x.cost) : 'Free'}</td>}</tr>)}</tbody>
            </table></div>
          </details>
        </section>

        <p className="gm-foot">Estimates from Google's India price list (updated {month?.cost.as_of ?? '—'}), at ₹{month?.cost.usd_inr ?? '—'} per US$. Google bills each Google account separately,
          counts its month from midnight US Pacific time, converts to rupees at its own rate and adds GST; the Google Cloud billing report is the actual bill.</p>
      </>}
    </div>
  );
}
