import { useCallback, useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ApiError, post } from '../lib/api';

export function useAsync<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  // Only the newest request may set state: with fast filter changes an older, slower answer used to land last and
  // overwrite the newer one (the page then showed data for the wrong filter).
  const seq = useRef(0);
  const reload = useCallback(async () => {
    const n = ++seq.current;
    setLoading(true);
    setError(null);
    try {
      const d = await fn();
      if (n === seq.current) setData(d);
    } catch (e) {
      if (n === seq.current) setError(e instanceof ApiError ? e.message : String(e));
    } finally {
      if (n === seq.current) setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => {
    void reload();
  }, [reload]);
  return { data, error, loading, reload, setData };
}

/** Wraps a mutating action: disables the button, shows the server's message on failure. */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const run = async <T,>(fn: () => Promise<T>): Promise<T | undefined> => {
    setBusy(true);
    setError(null);
    try {
      return await fn();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : String(e));
      return undefined;
    } finally {
      setBusy(false);
    }
  };
  return { busy, error, run, setError };
}

export function Page({ title, actions, children, subtitle }: { title: string; subtitle?: string; actions?: ReactNode; children: ReactNode }) {
  return (
    <section className="page">
      <header className="page-head">
        <div>
          <h1>{title}</h1>
          {subtitle && <p className="muted">{subtitle}</p>}
        </div>
        <div className="row">{actions}</div>
      </header>
      {children}
    </section>
  );
}

export function Card({ title, children, actions }: { title?: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <div className="card">
      {(title || actions) && (
        <div className="card-head">
          {title && <h2>{title}</h2>}
          <div className="row">{actions}</div>
        </div>
      )}
      {children}
    </div>
  );
}

const TONES: Record<string, string> = {
  approved: 'ok', verified: 'ok', applied: 'ok', available: 'ok', completed: 'ok', online: 'ok', active: 'ok', supported: 'ok', true: 'ok', ready: 'ok', published: 'ok',
  pending: 'warn', applying: 'warn', processing: 'warn', recording: 'warn', requested: 'warn', accepted: 'warn', validating: 'warn', committing: 'warn',
  partially_applied: 'warn', requires_setup: 'warn', planned: 'warn', draft: 'warn', on_call: 'info', updating_customer: 'info', idle: 'warn',
  rejected: 'bad', revoked: 'bad', failed: 'bad', unsupported: 'bad', false: 'bad', offline: 'muted', expired: 'muted', ended: 'muted',
  signed_out: 'muted', not_required: 'muted', deleted_by_retention: 'muted', superseded: 'muted', busy: 'warn', no_answer: 'warn', agent_unanswered: 'warn',
  disconnected: 'warn', cancelled: 'muted', declined: 'muted',
};

export function Badge({ value, label }: { value: string | boolean | null | undefined; label?: string }) {
  const v = String(value ?? '—');
  return <span className={`badge ${TONES[v] ?? 'muted'}`}>{label ?? v.replace(/_/g, ' ')}</span>;
}

/** Order payment state (server field `pay_state`): paid | pending | failed (retryable) | cancelled. */
const PAY: Record<string, [string, string]> = { paid: ['completed', 'paid'], pending: ['pending', 'unpaid'], failed: ['revoked', 'payment failed'], cancelled: ['cancelled', 'cancelled'] };
export function PayBadge({ order }: { order: { pay_state?: string; status?: string } }) {
  const [v, l] = PAY[order.pay_state ?? (order.status === 'created' ? 'pending' : order.status ?? '')] ?? ['muted', order.status ?? '—'];
  return <Badge value={v} label={l} />;
}

export function ErrorBox({ error }: { error: string | null | undefined }) {
  return error ? <div className="alert bad" role="alert">{error}</div> : null;
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'warn' | 'bad' | 'ok'; children: ReactNode }) {
  return <div className={`alert ${tone}`}>{children}</div>;
}

export type Column<T> = { key: string; label: string; render?: (row: T) => ReactNode; align?: 'right' };

export function Table<T extends { id?: number | string }>({ rows, columns, empty = 'Nothing to show.', onRow, error }: { rows: T[] | undefined | null; columns: Column<T>[]; empty?: string; onRow?: (r: T) => void; error?: string | null }) {
  if (!rows) return <p className="muted">{error ? 'Could not load this list.' : 'Loading…'}</p>;
  if (rows.length === 0) return <p className="muted">{empty}</p>;
  return (
    <div className="table-wrap">
      <table>
        <thead>
          <tr>{columns.map((c) => <th key={c.key} className={c.align === 'right' ? 'num' : undefined}>{c.label}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={(r.id as any) ?? i} onClick={onRow ? () => onRow(r) : undefined} className={onRow ? 'clickable' : undefined}>
              {columns.map((c) => (
                <td key={c.key} className={c.align === 'right' ? 'num' : undefined}>{c.render ? c.render(r) : String((r as any)[c.key] ?? '—')}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  // Portal to <body>: an animated (transformed) page section would otherwise trap the fixed backdrop inside it.
  return createPortal(
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-label={title} onClick={onClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <div className="card-head">
          <h2>{title}</h2>
          <button className="ghost" onClick={onClose} aria-label="Close">✕</button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  );
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
      {hint && <small className="muted">{hint}</small>}
    </label>
  );
}

export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) {
  return (
    <div className={`stat ${tone ?? ''}`}>
      <div className="stat-value">{value}</div>
      <div className="stat-label">{label}</div>
    </div>
  );
}

/** Neutral placeholder used until an official logo is uploaded. Deliberately generic: no invented brand mark. */
export function LogoPlaceholder({ name, size = 40 }: { name: string; size?: number }) {
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '··';
  return (
    <div className="logo-ph" style={{ width: size, height: size, fontSize: size * 0.38 }} aria-label="Logo placeholder" title="No logo uploaded">
      {initials}
    </div>
  );
}

/** Recording play/download on demand. Every play/download is audited server-side; URLs expire after a few minutes. */
let playing: HTMLAudioElement | null = null;   // only one recording plays at a time across the page
const mmss = (t: number) => (Number.isFinite(t) ? `${Math.floor(t / 60)}:${String(Math.floor(t % 60)).padStart(2, '0')}` : '0:00');

/** Compact premium player: round play/pause, seekable progress, time, download. Signed URL is fetched on first play. */
export function RecordingPlayer({ callId, status, format, canPlay, canDownload, compact }: { callId: number; status?: string | null; format?: string | null; canPlay: boolean; canDownload: boolean; compact?: boolean }) {
  const audio = useRef<HTMLAudioElement | null>(null);
  const [on, setOn] = useState(false);
  const [t, setT] = useState(0);
  const [dur, setDur] = useState(0);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => () => { audio.current?.pause(); }, []);
  if (status !== 'available') return <Badge value={status ?? 'not_required'} />;
  const browserCannotPlay = ['amr', 'awb', '3gp', '3gpp'].includes((format ?? '').toLowerCase());
  const stop = (e: MouseEvent) => e.stopPropagation();
  const toggle = async (e: MouseEvent) => {
    e.stopPropagation(); setErr(null);
    if (audio.current) { audio.current.paused ? audio.current.play() : audio.current.pause(); return; }
    setBusy(true);
    try {
      const r = await post(`/calls/${callId}/recording/playback`);
      const a = new Audio(r.url);
      a.ontimeupdate = () => setT(a.currentTime);
      a.onloadedmetadata = () => setDur(a.duration);
      a.onplay = () => { if (playing && playing !== a) playing.pause(); playing = a; setOn(true); };
      a.onpause = () => setOn(false);
      a.onended = () => { setOn(false); setT(0); };
      a.onerror = () => setErr('Could not play this file');
      audio.current = a;
      await a.play();
    } catch (x) { setErr(x instanceof ApiError ? x.message : String(x)); } finally { setBusy(false); }
  };
  const seek = (e: MouseEvent<HTMLDivElement>) => {
    e.stopPropagation();
    const a = audio.current; if (!a || !dur) return;
    const r = e.currentTarget.getBoundingClientRect();
    a.currentTime = ((e.clientX - r.left) / r.width) * dur;
  };
  const download = async (e: MouseEvent) => {
    e.stopPropagation(); setErr(null);
    try { window.location.assign((await post(`/calls/${callId}/recording/download`)).url); } catch (x) { setErr(x instanceof ApiError ? x.message : String(x)); }
  };
  return (
    <span className={`player ${compact ? 'compact' : ''} ${on ? 'on' : ''}`} onClick={stop}>
      {canPlay && !browserCannotPlay && (
        <>
          <button className="player-btn" disabled={busy} onClick={toggle} aria-label={on ? 'Pause recording' : 'Play recording'}>
            {busy ? <span className="spin" /> : <Icon name={on ? 'pause' : 'play'} size={16} />}
          </button>
          <div className="player-track" onClick={seek} role="slider" aria-label="Seek" aria-valuemin={0} aria-valuemax={Math.round(dur)} aria-valuenow={Math.round(t)}>
            <div style={{ width: `${dur ? (t / dur) * 100 : 0}%` }} />
          </div>
          <span className="player-time">{mmss(t)}{dur ? ` / ${mmss(dur)}` : ''}</span>
        </>
      )}
      {canDownload && <button className="icon-btn" onClick={download} title={browserCannotPlay ? `${format?.toUpperCase()} cannot play in browsers — download it` : 'Download recording'} aria-label="Download recording"><Icon name="download" size={16} /></button>}
      {browserCannotPlay && !compact && <small className="muted">{format?.toUpperCase()} — download to listen</small>}
      {err && <small className="bad-text">{err}</small>}
    </span>
  );
}

/** Stacked daily bars: connected (blue) + not connected (gold), with hover tooltip, legend and a table fallback via title. */
export function TrendBars({ days }: { days: { date: string; calls: number; connected: number; talk_seconds: number }[] }) {
  const [hover, setHover] = useState<number | null>(null);
  const W = 640, H = 200, P = { l: 32, r: 8, t: 12, b: 26 };
  const max = Math.max(4, ...days.map((d) => d.calls));
  const step = Math.ceil(max / 4);
  const top = step * 4;
  const bw = (W - P.l - P.r) / days.length;
  const y = (v: number) => P.t + (H - P.t - P.b) * (1 - v / top);
  const label = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
  return (
    <div className="chart">
      <div className="legend"><span><i style={{ background: 'var(--series-1)' }} />Connected</span><span><i style={{ background: 'var(--series-2)' }} />Not connected</span></div>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Calls per day, last 7 days" onMouseLeave={() => setHover(null)}>
        {[0, 1, 2, 3, 4].map((i) => (
          <g key={i}><line x1={P.l} x2={W - P.r} y1={y(i * step)} y2={y(i * step)} className="grid-line" /><text x={P.l - 6} y={y(i * step) + 4} textAnchor="end" className="axis">{i * step}</text></g>
        ))}
        {days.map((d, i) => {
          const x = P.l + i * bw + bw * 0.22, w = bw * 0.56, miss = d.calls - d.connected;
          return (
            <g key={d.date} onMouseEnter={() => setHover(i)}>
              <rect x={P.l + i * bw} y={P.t} width={bw} height={H - P.t - P.b} fill="transparent" />
              {d.connected > 0 && <rect x={x} y={y(d.connected)} width={w} height={y(0) - y(d.connected)} rx={miss > 0 ? 0 : 4} fill="var(--series-1)" />}
              {miss > 0 && <rect x={x} y={y(d.calls)} width={w} height={Math.max(0, y(d.connected) - y(d.calls) - (d.connected > 0 ? 2 : 0))} rx={4} fill="var(--series-2)" />}
              <text x={P.l + i * bw + bw / 2} y={H - 8} textAnchor="middle" className="axis">{label(d.date)}</text>
            </g>
          );
        })}
      </svg>
      {hover !== null && days[hover] && (
        <div className="tooltip" style={{ left: `${((P.l + hover * bw + bw / 2) / W) * 100}%` }}>
          <strong>{label(days[hover].date)}</strong><br />{days[hover].calls} calls · {days[hover].connected} connected<br />Talk {Math.round(days[hover].talk_seconds / 60)} min
        </div>
      )}
    </div>
  );
}

const ICONS: Record<string, string> = {
  refresh: 'M17.65 6.35A7.958 7.958 0 0 0 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0 1 12 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z',
  menu: 'M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z',
  expand: 'M16.59 8.59 12 13.17 7.41 8.59 6 10l6 6 6-6z',
  chevron_left: 'M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z',
  chevron_right: 'M10 6 8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z',
  logout: 'M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z',
  eye: 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z',
  contacts: 'M20 0H4v2h16V0zM4 24h16v-2H4v2zM20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-8 2.75c1.24 0 2.25 1.01 2.25 2.25s-1.01 2.25-2.25 2.25S9.75 10.24 9.75 9 10.76 6.75 12 6.75zM17 17H7v-1.5c0-1.67 3.33-2.5 5-2.5s5 .83 5 2.5V17z',
  screen: 'M20 18c1.1 0 1.99-.9 1.99-2L22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zM4 6h16v10H4V6z',
  camera: 'M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z',
  palette: 'M12 3a9 9 0 0 0 0 18c.83 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1.01-.23-.26-.38-.61-.38-.99 0-.83.67-1.5 1.5-1.5H16c2.76 0 5-2.24 5-5 0-4.42-4.03-8-9-8zm-5.5 9c-.83 0-1.5-.67-1.5-1.5S5.67 9 6.5 9 8 9.67 8 10.5 7.33 12 6.5 12zm3-4C8.67 8 8 7.33 8 6.5S8.67 5 9.5 5s1.5.67 1.5 1.5S10.33 8 9.5 8zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 5 14.5 5s1.5.67 1.5 1.5S15.33 8 14.5 8zm3 4c-.83 0-1.5-.67-1.5-1.5S16.67 9 17.5 9s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z',
  play: 'M8 5v14l11-7z',
  pause: 'M6 19h4V5H6v14zm8-14v14h4V5h-4z',
  download: 'M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z',
  trash: 'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
  search: 'M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z',
  call: 'M20.01 15.38c-1.23 0-2.42-.2-3.53-.56a.977.977 0 0 0-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z',
  check: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
  clock: 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
  event: 'M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z',
  mic: 'M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z',
  phone: 'M17 1.01 7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z',
  people: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
  shield: 'M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z',
  chart: 'M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z',
  warning: 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z',
  upload: 'M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z',
  settings: 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z',
  home: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
  chat: 'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z',
  pin: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z',
};

/** Inline SVG icon (Material path data) — no icon font or extra dependency. */
export function Icon({ name, size = 20 }: { name: keyof typeof ICONS | string; size?: number }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d={ICONS[name] ?? ICONS.chart} /></svg>;
}

export function Kpi({ icon, tone, label, value, sub, subTone }: { icon: string; tone: string; label: string; value: ReactNode; sub?: ReactNode; subTone?: 'up' | 'down' | 'flat' }) {
  return (
    <div className="kpi rise">
      <div className={`kpi-icon ${tone}`}><Icon name={icon} size={20} /></div>
      <div className="kpi-value">{value}</div>
      <div className="kpi-label">{label}</div>
      {sub != null && <div className={`kpi-sub ${subTone ?? 'flat'}`}>{sub}</div>}
    </div>
  );
}

export function Initials({ name, size = 32 }: { name: string; size?: number }) {
  const palette = ['#1f3a5f', '#0f766e', '#7c3aed', '#b7791f', '#2563eb', '#be185d'];
  let h = 0; for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) | 0;
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '•';
  return <span className="initials" style={{ width: size, height: size, fontSize: size * 0.38, background: palette[Math.abs(h) % palette.length] }}>{initials}</span>;
}

/** Page through a Laravel paginator ({current_page, last_page, total, from, to}). Hidden when everything fits on one page. */
export function Pager({ meta, onPage }: { meta: { current_page?: number; last_page?: number; total?: number; from?: number | null; to?: number | null } | null | undefined; onPage: (p: number) => void }) {
  if (!meta || !meta.last_page || meta.last_page <= 1) return meta?.total ? <p className="pager muted">{meta.total} in all</p> : null;
  const p = meta.current_page ?? 1;
  return (
    <nav className="pager" aria-label="Pages">
      <span className="muted">{meta.from ?? 0}–{meta.to ?? 0} of {meta.total}</span>
      <button className="secondary sm" disabled={p <= 1} onClick={() => onPage(p - 1)} aria-label="Previous page">‹ Previous</button>
      <span className="muted">Page {p} of {meta.last_page}</span>
      <button className="secondary sm" disabled={p >= meta.last_page} onClick={() => onPage(p + 1)} aria-label="Next page">Next ›</button>
    </nav>
  );
}

/** Small success / error message in the corner. toast('Saved') from anywhere; <Toaster/> once in the app shell. */
export function toast(message: string, tone: 'ok' | 'bad' = 'ok') {
  window.dispatchEvent(new CustomEvent('app-toast', { detail: { message, tone } }));
}

export function Toaster() {
  const [items, setItems] = useState<{ id: number; message: string; tone: string }[]>([]);
  useEffect(() => {
    let n = 0;
    const on = (e: Event) => {
      const d = (e as CustomEvent).detail as { message: string; tone: string };
      const id = ++n;
      setItems((x) => [...x.slice(-2), { id, ...d }]);
      setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), 3500);
    };
    window.addEventListener('app-toast', on);
    return () => window.removeEventListener('app-toast', on);
  }, []);
  return (
    <div className="toasts" role="status" aria-live="polite">
      {items.map((i) => <div key={i.id} className={`toast-msg ${i.tone}`}>{i.tone === 'ok' ? '✓' : '!'} <span>{i.message}</span></div>)}
    </div>
  );
}
